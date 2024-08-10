<?php

declare(strict_types=1);

namespace Phan\Analysis;

use ast;
use ast\Node;
use Closure;
use Exception;
use Phan\Analysis\ConditionVisitor\BinaryCondition;
use Phan\Analysis\ConditionVisitor\ComparisonCondition;
use Phan\Analysis\ConditionVisitor\EqualsCondition;
use Phan\Analysis\ConditionVisitor\IdenticalCondition;
use Phan\Analysis\ConditionVisitor\NotEqualsCondition;
use Phan\Analysis\ConditionVisitor\NotIdenticalCondition;
use Phan\AST\ASTReverter;
use Phan\AST\ContextNode;
use Phan\AST\PhanAnnotationAdder;
use Phan\AST\UnionTypeVisitor;
use Phan\BlockAnalysisVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\FQSENException;
use Phan\Exception\IssueException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\LiteralIntType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\LiteralTypeInterface;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NonZeroIntType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ResourceType;
use Phan\Language\Type\ScalarType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TrueType;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;
use Phan\Parse\ParseVisitor;

use function is_int;
use function is_string;

/**
 * This implements common functionality to update variables based on checks within a conditional (of an if/elseif/else/while/for/assert(), etc.)
 *
 * Classes implementing this must also implement ConditionVisitorInterface
 *
 * @see ConditionVisitor
 * @see NegatedConditionVisitor
 * @see ConditionVisitorInterface
 */
trait ConditionVisitorUtil
{
    /** @var CodeBase The code base within which we're operating */
    protected $code_base;

    /**
     * @var Context
     * The context in which the node we're going to be looking
     * at exists.
     */
    protected $context;

    /**
     * Remove any types which are definitely truthy from that variable (objects, TrueType, ResourceType, etc.)
     * E.g. if (empty($x)) {} would result in this.
     * Note that Phan can't know some scalars are not an int/string/float, since 0/""/"0"/0.0/[] are empty.
     * (Remove arrays anyway)
     */
    final protected function removeTruthyFromVariable(Node $var_node, Context $context, bool $suppress_issues, bool $check_empty): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            /**
             * @suppress PhanUndeclaredProperty did_check_redundant_condition
             */
            function (UnionType $type) use ($var_node, $context, $suppress_issues): bool {
                $contains_truthy = $type->containsTruthy();
                if (!$suppress_issues) {
                    if (Config::getValue('redundant_condition_detection') && $type->hasRealTypeSet()) {
                        // Here, we only perform the redundant condition checks on whichever ran first, to avoid warning about both impossible and redundant conditions
                        if (isset($var_node->did_check_redundant_condition)) {
                            return $contains_truthy;
                        }
                        $var_node->did_check_redundant_condition = true;
                        // Here, we only perform the redundant condition checks on the ConditionVisitor to avoid warning about both impossible and redundant conditions
                        // for the same expression
                        if ($contains_truthy) {
                            if (!$type->getRealUnionType()->containsFalsey()) {
                                RedundantCondition::emitInstance(
                                    $var_node,
                                    $this->code_base,
                                    $context,
                                    Issue::ImpossibleCondition,
                                    [
                                        ASTReverter::toShortString($var_node),
                                        $type->getRealUnionType(),
                                        'falsey',
                                    ],
                                    static function (UnionType $type): bool {
                                        return !$type->containsFalsey();
                                    }
                                );
                            }
                        } else {
                            if (!$type->getRealUnionType()->containsTruthy()) {
                                RedundantCondition::emitInstance(
                                    $var_node,
                                    $this->code_base,
                                    $context,
                                    Issue::RedundantCondition,
                                    [
                                        ASTReverter::toShortString($var_node),
                                        $type->getRealUnionType(),
                                        'falsey',
                                    ],
                                    static function (UnionType $type): bool {
                                        return !$type->containsTruthy();
                                    }
                                );
                            }
                        }
                    }
                    if (Config::getValue('error_prone_truthy_condition_detection')) {
                        $this->checkErrorProneTruthyCast($var_node, $context, $type);
                    }
                }
                return $contains_truthy;
            },
            function (UnionType $union_type) use ($var_node, $context): UnionType {
                $result = $union_type->nonTruthyClone();
                if ($result->isEmpty()) {
                    return $this->getFalseyTypesFallback($var_node, $context);
                }
                if (!$result->hasRealTypeSet()) {
                    return $result->withRealTypeSet($this->getFalseyTypesFallback($var_node, $context)->getRealTypeSet());
                }
                return $result;
            },
            $suppress_issues,
            $check_empty
        );
    }

    final protected function getFalseyTypesFallback(Node $var_node, Context $context): UnionType
    {
        static $default_empty;
        if (\is_null($default_empty)) {
            $default_empty = UnionType::fromFullyQualifiedRealString("?0|?''|?'0'|?0.0|?array{}|?false");
        }
        $fallback_type = $this->getTypesFallback($var_node, $context);
        if (!\is_object($fallback_type)) {
            return $default_empty;
        }
        $new_fallback = $fallback_type->nonTruthyClone();
        if ($new_fallback->isEmpty()) {
            return $default_empty;
        }
        if (!$new_fallback->hasRealTypeSet()) {
            return $new_fallback->withRealTypeSet($default_empty->getRealTypeSet());
        }
        return $new_fallback;
    }

    final protected function getTypesFallback(Node $var_node, Context $context): ?UnionType
    {
        if ($var_node->kind !== ast\AST_VAR) {
            return null;
        }
        $var_name = $var_node->children['name'];
        if (!is_string($var_name)) {
            return null;
        }
        if (!$context->getScope()->isInFunctionLikeScope()) {
            return null;
        }
        // Whether or not this is in a loop, try to find fallback types to narrow down reasonable types to expect.
        // E.g. https://github.com/phan/phan/issues/4550
        $function = $context->getFunctionLikeInScope($this->code_base);
        $result = $function->getVariableTypeFallbackMap($this->code_base)[$var_name] ?? null;
        if ($result && !$result->isEmpty()) {
            return $result;
        }
        return null;
    }

    // Remove any types which are definitely falsey from that variable (NullType, FalseType)
    final protected function removeFalseyFromVariable(Node $var_node, Context $context, bool $suppress_issues): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            function (UnionType $type) use ($context, $var_node, $suppress_issues): bool {
                if (!$suppress_issues) {
                    if (Config::getValue('redundant_condition_detection') && $type->hasRealTypeSet()) {
                        $this->checkRedundantOrImpossibleTruthyCondition($var_node, $context, $type->getRealUnionType(), false);
                    }
                    if (Config::getValue('error_prone_truthy_condition_detection')) {
                        $this->checkErrorProneTruthyCast($var_node, $context, $type);
                    }
                }
                foreach ($type->getRealTypeSet() as $single_type) {
                    if ($single_type->isPossiblyFalsey()) {
                        return true;
                    }
                }
                return $type->containsFalsey() || !$type->hasRealTypeSet();
            },
            function (UnionType $type) use ($var_node, $context): UnionType {
                // nonFalseyClone will always be non-empty because it returns non-empty-mixed
                if ($type->containsTruthy()) {
                    return $type->nonFalseyClone();
                }
                $fallback = $this->getTypesFallback($var_node, $context);
                if (!$fallback) {
                    return $type->nonFalseyClone();
                }
                return $fallback->nonFalseyClone();
            },
            $suppress_issues,
            false
        );
    }

    /**
     * Warn about a scalar expression literal node that is always truthy or always falsey, in a place expecting a condition.
     * @param int|string|float $node
     */
    public function warnRedundantOrImpossibleScalar($node): void
    {
        // TODO: Add LiteralFloatType so that this can consistently warn about floats
        $this->checkRedundantOrImpossibleTruthyCondition($node, $this->context, null, false);
    }

    /**
     * Check if the provided node has a comparison to truthy that's error prone.
     *
     * E.g. checking if an object|int is truthy - A more appropriate check may be is_object()
     *
     * @suppress PhanUndeclaredProperty did_check_redundant_condition
     */
    private function checkErrorProneTruthyCast(Node $node, Context $context, UnionType $union_type): void
    {
        // Here, we only perform the redundant condition checks on whichever ran first, to avoid warning about both impossible and redundant conditions
        if (isset($node->did_check_error_prone_truthy)) {
            return;
        }
        $node->did_check_error_prone_truthy = true;
        $has_array_or_object = false;
        $has_falsey = false;
        $has_truthy = false;
        $has_string = false;
        foreach ($union_type->getTypeSet() as $type) {
            if ($type->isObject() || $type instanceof IterableType) {
                $has_array_or_object = true;
                continue;
            }
            if ($type->isPossiblyTruthy()) {
                $has_truthy = true;
                $has_falsey = $has_falsey || $type->withIsNullable(false)->isPossiblyFalsey();
                if (\get_class($type) === StringType::class) {
                    $has_string = true;
                }
            } else {
                $has_falsey = $has_falsey || !($type instanceof NullType || $type instanceof FalseType);
            }
        }
        if ($has_truthy && $has_falsey && $has_array_or_object) {
            Issue::maybeEmit(
                $this->code_base,
                $context,
                Issue::SuspiciousTruthyCondition,
                $node->lineno,
                ASTReverter::toShortString($node),
                $union_type
            );
        }
        if ($has_string) {
            Issue::maybeEmit(
                $this->code_base,
                $context,
                Issue::SuspiciousTruthyString,
                $node->lineno,
                ASTReverter::toShortString($node),
                $union_type
            );
        }
    }

    /**
     * Check if the provided node has a redundant or impossible conditional.
     * @param Node|string|int|float $node
     * @suppress PhanUndeclaredProperty did_check_redundant_condition
     */
    public function checkRedundantOrImpossibleTruthyCondition($node, Context $context, ?UnionType $type, bool $is_negated): void
    {
        if ($node instanceof Node) {
            // Here, we only perform the redundant condition checks on whichever ran first, to avoid warning about both impossible and redundant conditions
            if (isset($node->did_check_redundant_condition)) {
                return;
            }
            $node->did_check_redundant_condition = true;
        } elseif ($is_negated) {
            return;
        }
        if (!$type) {
            try {
                $type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $node, false);
            } catch (Exception $_) {
                return;
            }
            if (!$type->hasRealTypeSet()) {
                return;
            }
            $type = $type->getRealUnionType();
        } elseif ($type->isEmpty()) {
            return;
        }
        // for the same expression
        if (!$type->containsTruthy()) {
            RedundantCondition::emitInstance(
                $node,
                $this->code_base,
                $context,
                $is_negated ? Issue::RedundantCondition : Issue::ImpossibleCondition,
                [
                    ASTReverter::toShortString($node),
                    $type->getRealUnionType(),
                    $is_negated ? 'falsey' : 'truthy'
                ],
                static function (UnionType $type): bool {
                    return !$type->containsTruthy();
                }
            );
        } elseif (!$type->containsFalsey()) {
            RedundantCondition::emitInstance(
                $node,
                $this->code_base,
                $context,
                $this->chooseIssueForUnconditionallyTrue($is_negated, $node),
                [
                    ASTReverter::toShortString($node),
                    $type->getRealUnionType(),
                    $is_negated ? 'falsey' : 'truthy'
                ],
                static function (UnionType $type): bool {
                    return !$type->containsFalsey();
                }
            );
        }
    }

    /**
     * overridden in subclasses
     * @param Node|mixed $node @unused-param
     */
    protected function chooseIssueForUnconditionallyTrue(bool $is_negated, $node): string
    {
        return $is_negated ? Issue::ImpossibleCondition : Issue::RedundantCondition;
    }

    final protected function removeNullFromVariable(Node $var_node, Context $context, bool $suppress_issues): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $type): bool {
                return $type->containsNullableOrUndefined();
            },
            function (UnionType $type) use ($var_node, $context): UnionType {
                $result = $type->nonNullableClone()->withIsPossiblyUndefined(false);
                if (!$result->isEmpty()) {
                    return $result;
                }
                $fallback = $this->getTypesFallback($var_node, $context);
                if (!$fallback) {
                    return $result;
                }
                return $fallback->nonNullableClone();
            },
            $suppress_issues,
            false
        );
    }

    final protected function removeEmptyArrayFromVariable(Node $var_node, Context $context): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $type): bool {
                return $type->hasArray();
            },
            static function (UnionType $type): UnionType {
                return $type->asMappedUnionType(static function (Type $type): Type {
                    return $type instanceof ArrayType ? $type->asNonFalseyType() : $type;
                });
            },
            true,
            false
        );
    }

    /**
     * Returns the type after removing all types that are empty or don't support property or array access
     * @param 1|2|3|4 $access_type ConditionVisitor::ACCESS_IS_*
     */
    public static function asTypeSupportingAccess(UnionType $type, int $access_type): UnionType
    {
        $type = $type->asMappedListUnionType(/** @return list<Type> */ static function (Type $type) use ($access_type): array {
            if ($access_type === ConditionVisitor::ACCESS_IS_OBJECT) {
                if (!$type->isPossiblyObject()) {
                    return [];
                }
            }
            if (!$type->isPossiblyTruthy()) {
                // causes false positives when combining types
                if ($type instanceof ArrayShapeType) {
                    // Convert array{} -> non-empty-array, null -> no types
                    // (useful guess with loops or references)
                    return UnionType::typeSetFromString('non-empty-array');
                }
                return [];
            }
            if ($type instanceof ScalarType) {
                if ($type instanceof StringType) {
                    if (\in_array($access_type, [ConditionVisitor::ACCESS_IS_OBJECT, ConditionVisitor::ACCESS_ARRAY_KEY_EXISTS, ConditionVisitor::ACCESS_STRING_DIM_SET], true)) {
                        return [];
                    }
                    if ($type instanceof LiteralStringType && $type->getValue() === '') {
                        // Can't access an offset of ''
                        return [];
                    }
                    return [$type->withIsNullable(false)];
                }
                return [];
            }
            if ($type instanceof ResourceType) {
                return [];
            }
            return [$type->asNonFalseyType()];
        });
        if (!$type->hasRealTypeSet()) {
            return $type->withRealTypeSet(UnionType::typeSetFromString(ConditionVisitor::DEFAULTS_FOR_ACCESS_TYPE[$access_type]));
        }
        return $type;
    }

    /**
     * Remove empty types not supporting 0 or more levels of array/property access from the variable.
     */
    final protected function removeTypesNotSupportingAccessFromVariable(Node $var_node, Context $context, int $access_type): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $type) use ($access_type): bool {
                return $type->hasPhpdocOrRealTypeMatchingCallback(static function (Type $type) use ($access_type): bool {
                    if ($type->isPossiblyFalsey()) {
                        return true;
                    }
                    if ($access_type === ConditionVisitor::ACCESS_IS_OBJECT) {
                        if (!$type->isPossiblyObject()) {
                            return true;
                        }
                    }
                    if ($type instanceof ResourceType) {
                        return true;
                    }
                    // TODO: Remove arrays if this is an access to a property
                    if ($type instanceof ScalarType) {
                        return !($type instanceof StringType);
                    }
                    return false;
                });
            },
            static function (UnionType $type) use ($access_type): UnionType {
                return self::asTypeSupportingAccess($type, $access_type);
            },
            true,
            false
        );
    }

    /**
     * @param int|string|float $value
     */
    final protected function removeLiteralScalarFromVariable(
        Node $var_node,
        Context $context,
        $value,
        bool $strict_equality
    ): Context {
        if (!is_int($value) && !is_string($value)) {
            return $context;
        }
        if ($strict_equality) {
            if (is_int($value)) {
                $cb = static function (Type $type) use ($value): bool {
                    return $type instanceof LiteralIntType && $type->getValue() === $value;
                };
            } else { // string
                $cb = static function (Type $type) use ($value): bool {
                    return $type instanceof LiteralStringType && $type->getValue() === $value;
                };
            }
        } else {
            // Remove loosely equal types.
            // TODO: Does this properly remove null for `$var != 0`?
            $cb = static function (Type $type) use ($value): bool {
                return $type instanceof LiteralTypeInterface && $type->getValue() == $value;
            };
        }
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $union_type) use ($cb): bool {
                return $union_type->hasPhpdocOrRealTypeMatchingCallback($cb);
            },
            function (UnionType $union_type) use ($cb, $var_node, $context): UnionType {
                $has_nullable = false;
                foreach ($union_type->getTypeSet() as $type) {
                    if ($cb($type)) {
                        $union_type = $union_type->withoutType($type);
                        $has_nullable = $has_nullable || $type->isNullable();
                    }
                }
                if ($has_nullable) {
                    if ($union_type->isEmpty()) {
                        return NullType::instance(false)->asPHPDocUnionType();
                    }
                    return $union_type->nullableClone();
                }
                if (!$union_type->isEmpty()) {
                    return $union_type;
                }

                // repeat for the fallback
                $fallback = $this->getTypesFallback($var_node, $context);
                if (!$fallback) {
                    return $union_type;
                }
                foreach ($fallback->getTypeSet() as $type) {
                    if ($cb($type)) {
                        $fallback = $fallback->withoutType($type);
                        $has_nullable = $has_nullable || $type->isNullable();
                    }
                }
                if ($has_nullable) {
                    if ($fallback->isEmpty()) {
                        return NullType::instance(false)->asPHPDocUnionType();
                    }
                    return $fallback->nullableClone();
                }
                return $fallback;
            },
            false,
            false
        );
    }

    final protected function removeFalseFromVariable(Node $var_node, Context $context): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $type): bool {
                return $type->containsFalse();
            },
            function (UnionType $type) use ($var_node, $context): UnionType {
                $result = $type->nonFalseClone();
                if (!$result->isEmpty()) {
                    return $result;
                }
                $fallback = $this->getTypesFallback($var_node, $context);
                if (!$fallback) {
                    return $result;
                }
                return $fallback->nonFalseClone();
            },
            false,
            false
        );
    }

    final protected function removeTrueFromVariable(Node $var_node, Context $context): Context
    {
        return $this->updateVariableWithConditionalFilter(
            $var_node,
            $context,
            static function (UnionType $type): bool {
                return $type->containsTrue();
            },
            function (UnionType $type) use ($var_node, $context): UnionType {
                $result = $type->nonTrueClone();
                if (!$result->isEmpty()) {
                    return $result;
                }
                $fallback = $this->getTypesFallback($var_node, $context);
                if (!$fallback) {
                    return $result;
                }
                return $fallback->nonTrueClone();
            },
            false,
            false
        );
    }

    /**
     * If the inferred UnionType makes $should_filter_cb return true
     * (indicating there are Types to be removed from the UnionType or altered),
     * then replace the UnionType with the modified UnionType which $filter_union_type_cb returns,
     * and update the context.
     *
     * Note: It's expected that $should_filter_cb returns false on the new UnionType of that variable.
     *
     * @param Node $var_node a node of kind ast\AST_VAR, ast\AST_PROP, or ast\AST_DIM
     * @param Closure(UnionType):bool $should_filter_cb
     * @param Closure(UnionType):UnionType $filter_union_type_cb
     */
    final protected function updateVariableWithConditionalFilter(
        Node $var_node,
        Context $context,
        Closure $should_filter_cb,
        Closure $filter_union_type_cb,
        bool $suppress_issues,
        bool $check_empty
    ): Context {
        try {
            // Get the variable we're operating on
            $variable = $this->getVariableFromScope($var_node, $context);
            if (\is_null($variable)) {
                if ($var_node->kind === ast\AST_DIM) {
                    return $this->updateDimExpressionWithConditionalFilter($var_node, $context, $should_filter_cb, $filter_union_type_cb, $suppress_issues, $check_empty);
                } elseif ($var_node->kind === ast\AST_PROP) {
                    return $this->updatePropertyExpressionWithConditionalFilter($var_node, $context, $should_filter_cb, $filter_union_type_cb, $suppress_issues);
                }
                return $context;
            }

            $union_type = $variable->getUnionType();
            if (!$should_filter_cb($union_type)) {
                return $context;
            }

            // Make a copy of the variable
            $variable = clone($variable);

            $variable->setUnionType(
                $filter_union_type_cb($union_type)
            );

            // Overwrite the variable with its new type in this
            // scope without overwriting other scopes
            $context = $context->withScopeVariable(
                $variable
            );
        } catch (IssueException $exception) {
            if (!$suppress_issues) {
                Issue::maybeEmitInstance($this->code_base, $context, $exception->getIssueInstance());
            }
        } catch (\Exception $_) {
            // Swallow it
        }
        return $context;
    }

    /**
     * @param Node $node a node of kind ast\AST_DIM
     */
    final protected function updateDimExpressionWithConditionalFilter(
        Node $node,
        Context $context,
        Closure $should_filter_cb,
        Closure $filter_union_type_cb,
        bool $suppress_issues,
        bool $check_empty
    ): Context {
        $var_node = $node->children['expr'];
        if (!($var_node instanceof Node)) {
            return $context;
        }
        $var_name = self::getVarNameOfDimNode($var_node);
        if (!is_string($var_name)) {
            // TODO: Allow acting on properties
            return $context;
        }
        if ($check_empty && $var_node->kind !== ast\AST_VAR) {
            $var_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $var_node);
            if (!($var_type->hasArrayShapeTypeInstances())) {
                return $context;
            }
        }
        try {
            // Get the type of the field we're operating on, accounting for whether the field is possibly undefined
            $old_field_type = (new UnionTypeVisitor($this->code_base, $context))->visitDim($node, true);
            if (!$should_filter_cb($old_field_type)) {
                return $context;
            }

            // Give the field an unused stub name and compute the new type
            $new_field_type = $filter_union_type_cb($old_field_type);
            if ($old_field_type->isIdenticalTo($new_field_type)) {
                return $context;
            }

            return (new AssignmentVisitor(
                $this->code_base,
                // We clone the original context to avoid affecting the original context for the elseif.
                // AssignmentVisitor modifies the provided context in place.
                //
                // There is a difference between `if (is_string($x['field']))` and `$x['field'] = remove_string_types($x['field'])` for the way the `elseif` should be analyzed.
                $context->withClonedScope(),
                $node,
                $new_field_type
            ))->__invoke($node);
        } catch (IssueException $exception) {
            if (!$suppress_issues) {
                Issue::maybeEmitInstance($this->code_base, $context, $exception->getIssueInstance());
            }
        } catch (\Exception $_) {
            // Swallow it
        }
        return $context;
    }

    /**
     * Returns true if `$node` is an `ast\Node` representing the PHP variable `$this`.
     *
     * @param Node|string|int|float $node
     */
    public static function isThisVarNode($node): bool
    {
        return $node instanceof Node && $node->kind === ast\AST_VAR &&
            $node->children['name'] === 'this';
    }

    /**
     * Analyze an expression such as `assert(!is_int($this->prop_name))`
     * and infer the effects on $this->prop_name in the local scope.
     *
     * @param Node $node a node of kind ast\AST_PROP
     * @unused-param $suppress_issues
     */
    final protected function updatePropertyExpressionWithConditionalFilter(
        Node $node,
        Context $context,
        Closure $should_filter_cb,
        Closure $filter_union_type_cb,
        bool $suppress_issues
    ): Context {
        if (!self::isThisVarNode($node->children['expr'])) {
            return $context;
        }
        $property_name = $node->children['prop'];
        if (!is_string($property_name)) {
            return $context;
        }
        return $this->modifyPropertyOfThisSimple(
            $node,
            static function (UnionType $type) use ($should_filter_cb, $filter_union_type_cb): UnionType {
                if (!$should_filter_cb($type)) {
                    return $type;
                }
                return $filter_union_type_cb($type);
            },
            $context
        );
    }

    final protected function updateVariableWithNewType(
        Node $var_node,
        Context $context,
        UnionType $new_union_type,
        bool $suppress_issues,
        bool $is_weak_type_assertion
    ): Context {
        if ($var_node->kind === ast\AST_PROP) {
            return $this->modifyPropertySimple($var_node, function (UnionType $old_type) use ($new_union_type, $is_weak_type_assertion): UnionType {
                if ($is_weak_type_assertion) {
                    return $this->combineTypesAfterWeakEqualityCheck($old_type, $new_union_type);
                } else {
                    return $this->combineTypesAfterStrictEqualityCheck($old_type, $new_union_type);
                }
            }, $context);
        }
        // TODO: Support ast\AST_DIM
        try {
            // Get the variable we're operating on
            $variable = $this->getVariableFromScope($var_node, $context);
            if (\is_null($variable)) {
                return $context;
            }

            // Make a copy of the variable
            $variable = clone($variable);

            if ($is_weak_type_assertion) {
                $new_variable_type = $this->combineTypesAfterWeakEqualityCheck($variable->getUnionType(), $new_union_type);
            } else {
                $new_variable_type = $this->combineTypesAfterStrictEqualityCheck($variable->getUnionType(), $new_union_type);
            }
            $variable->setUnionType($new_variable_type);

            // Overwrite the variable with its new type in this
            // scope without overwriting other scopes
            $context = $context->withScopeVariable(
                $variable
            );
        } catch (IssueException $exception) {
            if (!$suppress_issues) {
                Issue::maybeEmitInstance($this->code_base, $context, $exception->getIssueInstance());
            }
        } catch (\Exception $_) {
            // Swallow it
        }
        return $context;
    }

    protected function combineTypesAfterWeakEqualityCheck(UnionType $old_union_type, UnionType $new_union_type): UnionType
    {
        // TODO: Be more precise about these checks - e.g. forbid anything such as stdClass == false in the new type
        if (!$old_union_type->hasRealTypeSet()) {
            // This is a weak check of equality. We aren't sure of the real types
            return $new_union_type->eraseRealTypeSet();
        }
        if (!$new_union_type->hasRealTypeSet()) {
            return $new_union_type->withRealTypeSet($old_union_type->getRealTypeSet());
        }
        $new_real_union_type = $new_union_type->getRealUnionType();
        $combined_real_types = [];
        foreach ($old_union_type->getRealTypeSet() as $type) {
            if (!$type->asPHPDocUnionType()->hasAnyWeakTypeOverlap($new_real_union_type, $this->code_base)) {
                continue;
            }
            // TODO: Implement Type->canWeakCastToUnionType?
            if ($type->isPossiblyFalsey() && !$new_real_union_type->containsFalsey()) {
                if ($type->isAlwaysFalsey()) {
                    continue;
                }
                // e.g. if asserting ?stdClass == true, then remove null
                $type = $type->asNonFalseyType();
            } elseif ($type->isPossiblyTruthy() && !$new_real_union_type->containsTruthy()) {
                if ($type->isAlwaysTruthy()) {
                    continue;
                }
                // e.g. if asserting ?stdClass == false, then remove stdClass and leave null
                $type = $type->asNonTruthyType();
            }
            if ($type instanceof LiteralTypeInterface) {
                // If this is a literal type, we only want types that could possibly be loosely equal to this
                foreach ($new_real_union_type->getTypeSet() as $other_type) {
                    if (!$other_type instanceof LiteralTypeInterface || $type->getValue() == $other_type->getValue()) {
                        $combined_real_types[] = $type;
                        continue 2;
                    }
                }
                continue;
            }
            $combined_real_types[] = $type;
        }
        if ($combined_real_types) {
            return $new_union_type->withRealTypeSet($combined_real_types);
        }
        return $new_union_type;
    }

    protected function combineTypesAfterStrictEqualityCheck(UnionType $old_union_type, UnionType $new_union_type): UnionType
    {
        // TODO: Be more precise about these checks - e.g. forbid anything such as stdClass == false in the new type
        if (!$new_union_type->hasRealTypeSet()) {
            return $new_union_type->withRealTypeSet($old_union_type->getRealTypeSet());
        }
        $new_real_union_type = $new_union_type->getRealUnionType();
        $combined_real_types = [];
        foreach ($old_union_type->getRealTypeSet() as $type) {
            if ($type->isPossiblyFalsey() && !$new_real_union_type->containsFalsey()) {
                if ($type->isAlwaysFalsey()) {
                    continue;
                }
                // e.g. if asserting ?stdClass == true, then remove null
                $type = $type->asNonFalseyType();
            } elseif ($type->isPossiblyTruthy() && !$new_real_union_type->containsTruthy()) {
                if ($type->isAlwaysTruthy()) {
                    continue;
                }
                // e.g. if asserting ?stdClass == false, then remove stdClass and leave null
                $type = $type->asNonTruthyType();
            }
            if (!$type->asPHPDocUnionType()->canCastToDeclaredType($this->code_base, $this->context, $new_real_union_type)) {
                continue;
            }
            $combined_real_types[] = $type;
        }
        if ($combined_real_types) {
            return $new_union_type->withRealTypeSet($combined_real_types);
        }
        return $new_union_type;
    }

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x === 'literal')`
     */
    final public function updateVariableToBeIdentical(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context {
        $context = $context ?? $this->context;
        try {
            $expr_type = UnionTypeVisitor::unionTypeFromLiteralOrConstant($this->code_base, $context, $expr);
            if (!$expr_type) {
                return $context;
            }
        } catch (\Exception $_) {
            return $context;
        }
        return $this->updateVariableWithNewType($var_node, $context, $expr_type, true, false);
    }

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x == true)`
     */
    final public function updateVariableToBeEqual(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context {
        $context = $context ?? $this->context;
        try {
            $expr_type = UnionTypeVisitor::unionTypeFromLiteralOrConstant($this->code_base, $context, $expr);
            if (!$expr_type) {
                return $context;
            }
        } catch (\Exception $_) {
            return $context;
        }
        return $this->updateVariableWithNewType($var_node, $context, $expr_type, true, true);
    }

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @param int $flags (e.g. \ast\flags\BINARY_IS_SMALLER)
     * @return Context - Context after inferring type from a comparison expression involving a variable such as `if ($x > 0)`
     */
    final public function updateVariableToBeCompared(
        Node $var_node,
        $expr,
        int $flags
    ): Context {
        $context = $this->context;
        $var_name = $var_node->children['name'] ?? null;
        // Don't analyze variables such as $$a
        if (\is_string($var_name)) {
            try {
                $expr_type = UnionTypeVisitor::unionTypeFromLiteralOrConstant($this->code_base, $context, $expr);
                if (!$expr_type) {
                    return $context;
                }
                $expr_value = $expr_type->asSingleScalarValueOrNullOrSelf();
                if (\is_object($expr_value)) {
                    return $context;
                }
                // Get the variable we're operating on
                $variable = $this->getVariableFromScope($var_node, $context);
                if (\is_null($variable)) {
                    return $context;
                }

                // Make a copy of the variable
                $variable = clone($variable);

                // TODO: Filter out nullable types
                $union_type = $variable->getUnionType()->makeFromFilter(static function (Type $type) use ($expr_value, $flags): bool {
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    return $type->canSatisfyComparison($expr_value, $flags);
                });
                if ($union_type->containsNullable()) {
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    if (!Type::performComparison(null, $expr_value, $flags)) {
                        // E.g. $x > 0 will remove the type null.
                        $union_type = $union_type->nonNullableClone();
                    }
                }
                if ($union_type->hasPhpdocOrRealTypeMatchingCallback(static function (Type $type): bool {
                    return \get_class($type) === IntType::class;
                })) {
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    if (!Type::performComparison(0, $expr_value, $flags)) {
                        // E.g. $x > 0 will convert int to non-zero-int
                        $union_type = $union_type->asMappedUnionType(static function (Type $type): Type {
                            if (\get_class($type) === IntType::class) {
                                return NonZeroIntType::instance($type->isNullable());
                            }
                            return $type;
                        });
                    }
                }
                $variable->setUnionType($union_type);

                // Overwrite the variable with its new type in this
                // scope without overwriting other scopes
                $context = $context->withScopeVariable(
                    $variable
                );
                return $context;
            } catch (\Exception $_) {
                // Swallow it (E.g. IssueException for undefined variable)
            }
        }
        return $context;
    }

    /**
     * @param Node $var_node a node of type ast\AST_VAR, ast\AST_DIM (planned), or ast\AST_PROP
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x !== 'literal')`
     */
    final public function updateVariableToBeNotIdentical(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context {
        $context = $context ?? $this->context;
        try {
            if ($expr instanceof Node) {
                $value = (new ContextNode($this->code_base, $context, $expr))->getEquivalentPHPValueForControlFlowAnalysis();
                if ($value instanceof Node) {
                    return $context;
                }
                if (\is_int($value) || \is_string($value)) {
                    return $this->removeLiteralScalarFromVariable($var_node, $context, $value, true);
                }
                if ($value === false) {
                    return $this->removeFalseFromVariable($var_node, $context);
                } elseif ($value === true) {
                    return $this->removeTrueFromVariable($var_node, $context);
                } elseif ($value === null) {
                    return $this->removeNullFromVariable($var_node, $context, false);
                } elseif ($value === []) {
                    return $this->removeEmptyArrayFromVariable($var_node, $context);
                }
            } else {
                return $this->removeLiteralScalarFromVariable($var_node, $context, $expr, true);
            }
        } catch (\Exception $_) {
            // Swallow it (E.g. IssueException for undefined variable)
        }
        return $context;
    }

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x != 'literal')`
     * @suppress PhanSuspiciousTruthyCondition, PhanSuspiciousTruthyString didn't implement special handling of `if ($x != [...])`
     */
    final public function updateVariableToBeNotEqual(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context {
        $context = $context ?? $this->context;

        $var_name = $var_node->children['name'] ?? null;
        // http://php.net/manual/en/types.comparisons.php#types.comparisions-loose @phan-suppress-current-line PhanPluginPossibleTypoComment, UnusedSuppression
        if (\is_string($var_name)) {
            try {
                if ($expr instanceof Node) {
                    $expr = (new ContextNode($this->code_base, $context, $expr))->getEquivalentPHPValueForControlFlowAnalysis();
                    if ($expr instanceof Node) {
                        return $context;
                    }
                    if ($expr === false || $expr === null) {
                        return $this->removeFalseyFromVariable($var_node, $context, false);
                    } elseif ($expr === true) {
                        return $this->removeTrueFromVariable($var_node, $context);
                    } elseif ($expr === []) {
                        return $this->removeEmptyArrayFromVariable($var_node, $context);
                    }
                }
                // Remove all of the types which are loosely equal
                if (is_int($expr) || is_string($expr)) {
                    $context = $this->removeLiteralScalarFromVariable($var_node, $context, $expr, false);
                }

                if ($expr == false) {
                    // @phan-suppress-next-line PhanImpossibleCondition, PhanSuspiciousValueComparison FIXME should not set real type for loose equality checks
                    if ($expr == null) {
                        return $this->removeFalseyFromVariable($var_node, $context, false);
                    }
                    return $this->removeFalseFromVariable($var_node, $context);
                } elseif ($expr == true) {  // e.g. 1, "1", -1
                    return $this->removeTrueFromVariable($var_node, $context);
                }
            } catch (\Exception $_) {
                // Swallow it (E.g. IssueException for undefined variable)
            }
        }
        return $context;
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @return Context - Context after inferring type from the negation of a condition such as `if ($x !== false)`
     */
    public function analyzeAndUpdateToBeIdentical($left, $right): Context
    {
        return $this->analyzeBinaryConditionPattern(
            $left,
            $right,
            new IdenticalCondition()
        );
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @return Context - Context after inferring type from the negation of a condition such as `if ($x != false)`
     */
    public function analyzeAndUpdateToBeEqual($left, $right): Context
    {
        return $this->analyzeBinaryConditionPattern(
            $left,
            $right,
            new EqualsCondition()
        );
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @return Context - Context after inferring type from an expression such as `if ($x !== false)`
     */
    public function analyzeAndUpdateToBeNotIdentical($left, $right): Context
    {
        return $this->analyzeBinaryConditionPattern(
            $left,
            $right,
            new NotIdenticalCondition()
        );
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @param BinaryCondition $condition
     */
    protected function analyzeBinaryConditionPattern($left, $right, BinaryCondition $condition): Context
    {
        if ($left instanceof Node) {
            $result = $this->analyzeBinaryConditionSide($left, $right, $condition);
            if ($result !== null) {
                return $result;
            }
        }
        if ($right instanceof Node) {
            $result = $this->analyzeBinaryConditionSide($right, $left, $condition->withFlippedOperands());
            if ($result !== null) {
                return $result;
            }
        }
        return $this->context;
    }

    /**
     * @param Node $var_node
     * @param Node|int|string|float $expr_node
     * @param BinaryCondition $condition
     * @suppress PhanPartialTypeMismatchArgument
     */
    private function analyzeBinaryConditionSide(Node $var_node, $expr_node, BinaryCondition $condition): ?Context
    {
        '@phan-var ConditionVisitorUtil|ConditionVisitorInterface $this';
        $kind = $var_node->kind;
        if ($kind === ast\AST_VAR || $kind === ast\AST_DIM) {
            return $condition->analyzeVar($this, $var_node, $expr_node);
        }
        if ($kind === ast\AST_PROP) {
            if (self::isThisVarNode($var_node->children['expr']) && is_string($var_node->children['prop'])) {
                return $condition->analyzeVar($this, $var_node, $expr_node);
            }
            return null;
        }
        if ($kind === ast\AST_CALL) {
            $name = $var_node->children['expr']->children['name'] ?? null;
            if (\is_string($name)) {
                $name = \strtolower($name);
                if ($name === 'get_class') {
                    $arg = $var_node->children['args']->children[0] ?? null;
                    if (!\is_null($arg)) {
                        return $condition->analyzeClassCheck($this, $arg, $expr_node);
                    }
                }
                return $condition->analyzeCall($this, $var_node, $expr_node);
            }
        }
        $tmp = $var_node;
        while (\in_array($kind, [ast\AST_ASSIGN, ast\AST_ASSIGN_OP, ast\AST_ASSIGN_REF], true)) {
            $var = $tmp->children['var'] ?? null;
            if (!$var instanceof Node) {
                break;
            }
            $kind = $var->kind;
            if ($kind === ast\AST_VAR) {
                // @phan-suppress-next-line PhanPossiblyUndeclaredPropertyOfClass
                $this->context = (new BlockAnalysisVisitor($this->code_base, $this->context))->__invoke($tmp);
                return $condition->analyzeVar($this, $var, $expr_node);
            }
            $tmp = $var;
        }
        // analyze `if (($a = $b) == true)` (etc.) but not `if ((list($x) = expr) == true)`
        // The latter is really a check on expr, not on an array.
        if (($tmp === $var_node || $tmp->kind !== ast\AST_ARRAY) &&
                ParseVisitor::isConstExpr($expr_node, ParseVisitor::CONSTANT_EXPRESSION_FORBID_NEW_EXPRESSION)) {
            return $condition->analyzeComplexCondition($this, $tmp, $expr_node);
        }
        return null;
    }

    /**
     * Returns a context where the variable for $object_node has the class found in $expr_node
     *
     * @param Node|string|int|float $object_node
     * @param Node|string|int|float|bool $expr_node
     */
    public function analyzeClassAssertion($object_node, $expr_node): ?Context
    {
        if (!($object_node instanceof Node)) {
            return null;
        }
        if ($expr_node instanceof Node) {
            $expr_value = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $expr_node)->asSingleScalarValueOrNull();
        } else {
            $expr_value = $expr_node;
        }
        if (!is_string($expr_value)) {
            $expr_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $expr_node);
            if (!$expr_type->canCastToUnionType(UnionType::fromFullyQualifiedPHPDocString('string|false'), $this->code_base)) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    Issue::TypeComparisonToInvalidClassType,
                    $this->context->getLineNumberStart(),
                    $expr_type,
                    'false|string'
                );
            }
            // TODO: Could warn about invalid assertions
            return null;
        }
        $fqsen_string = '\\' . $expr_value;
        try {
            $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($fqsen_string);
        } catch (FQSENException $_) {
            Issue::maybeEmit(
                $this->code_base,
                $this->context,
                Issue::TypeComparisonToInvalidClass,
                $this->context->getLineNumberStart(),
                StringUtil::encodeValue($expr_value)
            );

            return null;
        }
        $expr_type = \is_string($expr_node) ? $fqsen->asType()->asRealUnionType() : $fqsen->asType()->asPHPDocUnionType();

        $var_name = $object_node->children['name'] ?? null;
        // Don't analyze variables such as $$a
        if (!\is_string($var_name)) {
            return null;
        }
        try {
            // Get the variable we're operating on
            $variable = $this->getVariableFromScope($object_node, $this->context);
            if (\is_null($variable)) {
                return null;
            }
            // Make a copy of the variable
            $variable = clone($variable);

            $variable->setUnionType($expr_type);

            // Overwrite the variable with its new type in this
            // scope without overwriting other scopes
            return $this->context->withScopeVariable(
                $variable
            );
        } catch (\Exception $_) {
            // Swallow it (E.g. IssueException for undefined variable)
        }
        return null;
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @return Context - Context after inferring type from an expression such as `if ($x == 'literal')`
     */
    public function analyzeAndUpdateToBeNotEqual($left, $right): Context
    {
        return $this->analyzeBinaryConditionPattern(
            $left,
            $right,
            new NotEqualsCondition()
        );
    }

    /**
     * @param Node|int|float|string $left
     * @param Node|int|float|string $right
     * @return Context - Context after inferring type from a comparison expression such as `if ($x['field'] > 0)`
     */
    protected function analyzeAndUpdateToBeCompared($left, $right, int $flags): Context
    {
        return $this->analyzeBinaryConditionPattern(
            $left,
            $right,
            new ComparisonCondition($flags)
        );
    }


    /**
     * @return ?Variable - Returns null if the variable is undeclared and ignore_undeclared_variables_in_global_scope applies.
     *                     or if assertions won't be applied?
     * @throws IssueException if variable is undeclared and not ignored.
     * @see UnionTypeVisitor::visitVar()
     */
    final public function getVariableFromScope(Node $var_node, Context $context): ?Variable
    {
        if ($var_node->kind !== ast\AST_VAR) {
            return null;
        }
        $var_name_node = $var_node->children['name'] ?? null;

        if ($var_name_node instanceof Node) {
            // This is nonsense. Give up, but check if it's a type other than int/string.
            // (e.g. to catch typos such as $$this->foo = bar;)
            $name_node_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $var_name_node, true);
            static $int_or_string_type;
            if ($int_or_string_type === null) {
                $int_or_string_type = UnionType::fromFullyQualifiedPHPDocString('?int|?string');
            }
            if (!$name_node_type->canCastToUnionType($int_or_string_type, $this->code_base)) {
                Issue::maybeEmit($this->code_base, $context, Issue::TypeSuspiciousIndirectVariable, $var_name_node->lineno ?? 0, (string)$name_node_type);
            }

            return null;
        }

        $variable_name = (string)$var_name_node;

        if (!$context->getScope()->hasVariableWithName($variable_name)) {
            // FIXME other uses were not sound for $argv outside of global scope.
            $is_in_global_scope = $context->isInGlobalScope();
            $new_type = Variable::getUnionTypeOfHardcodedVariableInScopeWithName($variable_name, $is_in_global_scope);
            if ($new_type) {
                $variable = new Variable(
                    $context->withLineNumberStart($var_node->lineno),
                    $variable_name,
                    $new_type,
                    0
                );
                $context->addScopeVariable($variable);
                return $variable;
            }
            if (!($var_node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF)) {
                if ($is_in_global_scope) {
                    if (!Config::getValue('ignore_undeclared_variables_in_global_scope')) {
                        Issue::maybeEmitWithParameters(
                            $this->code_base,
                            $context,
                            Variable::chooseIssueForUndeclaredVariable($context, $variable_name),
                            $var_node->lineno,
                            [$variable_name],
                            IssueFixSuggester::suggestVariableTypoFix($this->code_base, $context, $variable_name)
                        );
                    }
                } else {
                    throw new IssueException(
                        Issue::fromType(Variable::chooseIssueForUndeclaredVariable($context, $variable_name))(
                            $context->getFile(),
                            $var_node->lineno,
                            [$variable_name],
                            IssueFixSuggester::suggestVariableTypoFix($this->code_base, $context, $variable_name)
                        )
                    );
                }
            }
            $variable = new Variable(
                $context,
                $variable_name,
                UnionType::empty(),
                0
            );
            $context->addScopeVariable($variable);
            return $variable;
        }
        return $context->getScope()->getVariableByName(
            $variable_name
        );
    }

    /**
     * Fetches the function name. Does not check for function uses or namespaces.
     *
     * TODO: Check for function uses
     * @param Node $node a node of kind ast\AST_CALL
     * @return ?string (null if function name could not be found)
     */
    final public static function getFunctionName(Node $node): ?string
    {
        $expr = $node->children['expr'];
        if (!($expr instanceof Node)) {
            return null;
        }
        $raw_function_name = $expr->children['name'] ?? null;
        if (!\is_string($raw_function_name)) {
            return null;
        }
        return $raw_function_name;
    }

    /**
     * Generate a union type by excluding matching types in $excluded_type from $affected_type
     */
    public static function excludeMatchingTypes(CodeBase $code_base, UnionType $affected_type, UnionType $excluded_type): UnionType
    {
        if ($affected_type->isEmpty() || $excluded_type->isEmpty()) {
            return $affected_type;
        }

        foreach ($excluded_type->getTypeSet() as $type) {
            if ($type instanceof NullType) {
                $affected_type = $affected_type->nonNullableClone();
            } elseif ($type instanceof FalseType) {
                $affected_type = $affected_type->nonFalseClone();
            } elseif ($type instanceof TrueType) {
                $affected_type = $affected_type->nonTrueClone();
            } else {
                continue;
            }
            // TODO: Do a better job handling LiteralStringType and LiteralIntType
            $excluded_type = $excluded_type->withoutType($type);
        }
        if ($excluded_type->isEmpty()) {
            return $affected_type;
        }
        return $affected_type->makeFromFilter(static function (Type $type) use ($code_base, $excluded_type): bool {
            return $type instanceof MixedType || !$type->asPHPDocUnionType()->canCastToUnionType($excluded_type, $code_base);
        });
    }

    /**
     * Returns this ConditionVisitorUtil's CodeBase.
     * This is needed by subclasses of BinaryCondition.
     */
    public function getCodeBase(): CodeBase
    {
        return $this->code_base;
    }

    /**
     * Returns this ConditionVisitorUtil's Context.
     * This is needed by subclasses of BinaryCondition.
     */
    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * @param Node|string|int|float $node
     * @param Closure(CodeBase,Context,Variable,list<mixed>):void $type_modification_callback
     *        A closure acting on a Variable instance (usually not really a variable) to modify its type
     * @param Context $context
     * @param list<mixed> $args
     */
    protected function modifyComplexExpression($node, Closure $type_modification_callback, Context $context, array $args): Context
    {
        for (;;) {
            if (!$node instanceof Node) {
                return $context;
            }
            switch ($node->kind) {
                case ast\AST_DIM:
                    return $this->modifyComplexDimExpression($node, $type_modification_callback, $context, $args);
                case ast\AST_PROP:
                    if (self::isThisVarNode($node->children['expr'])) {
                        return $this->modifyPropertyOfThis($node, $type_modification_callback, $context, $args);
                    }
                    return $context;
                case ast\AST_ASSIGN:
                case ast\AST_ASSIGN_REF:
                    $var_node = $node->children['var'];
                    if (!$var_node instanceof Node) {
                        return $context;
                    }
                    // Act on the left (or right) hand side of the assignment instead. That side may be a regular variable.
                    if ($var_node->kind === ast\AST_ARRAY) {
                        $node = $node->children['expr'];
                    } else {
                        $node = $var_node;
                    }
                    continue 2;
                case ast\AST_VAR:
                    $variable = $this->getVariableFromScope($node, $context);
                    if (\is_null($variable)) {
                        return $context;
                    }
                    // Make a copy of the variable
                    $variable = clone($variable);
                    $type_modification_callback($this->code_base, $context, $variable, $args);
                    // Overwrite the variable with its new type
                    return $context->withScopeVariable(
                        $variable
                    );
                case ast\AST_ASSIGN_OP:
                // Be conservative - analyze `cond(++$x)` but not `cond($x++)
                // case ast\AST_POST_INC:
                // case ast\AST_POST_DEC:
                case ast\AST_PRE_INC:
                case ast\AST_PRE_DEC:
                    $node = $node->children['var'];
                    if (!$node instanceof Node) {
                        return $context;
                    }
                    continue 2;
                default:
                    return $context;
            }
        }
    }

    /**
     * @param Node $node a node of kind ast\AST_DIM (e.g. the argument of is_array($x['field']))
     * @param Closure(CodeBase,Context,Variable,list<mixed>):void $type_modification_callback
     *        A closure acting on a Variable instance (not really a variable) to modify its type
     *
     *        This is a function such as is_array, is_null (questionable), etc.
     * @param Context $context
     * @param list<mixed> $args
     */
    protected function modifyComplexDimExpression(Node $node, Closure $type_modification_callback, Context $context, array $args): Context
    {
        $var_name = $this->getVarNameOfDimNode($node->children['expr']);
        if (!is_string($var_name)) {
            return $context;
        }
        // Give the field an unused stub name and compute the new type
        $old_field_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $node);
        $field_variable = new Variable($context, "__phan", $old_field_type, 0);
        $type_modification_callback($this->code_base, $context, $field_variable, $args);
        $new_field_type = $field_variable->getUnionType();
        if ($new_field_type->isIdenticalTo($old_field_type)) {
            return $context;
        }
        // Treat if (is_array($x['field'])) similarly to `$x['field'] = some_function_returning_array()
        // (But preserve anything known about array types of $x['field'])
        return (new AssignmentVisitor(
            $this->code_base,
            // We clone the original context to avoid affecting the original context for the elseif.
            // AssignmentVisitor modifies the provided context in place.
            //
            // There is a difference between `if (is_string($x['field']))` and `$x['field'] = (some string)` for the way the `elseif` should be analyzed.
            $context->withClonedScope(),
            $node,
            $new_field_type
        ))->__invoke($node);
    }

    /**
     * Return a context with overrides for the type of a property in the local scope,
     * caused by a function accepting $args.
     *
     * @param Node $node a node of kind ast\AST_PROP (e.g. the argument of is_array($this->prop_name))
     * @param Closure(CodeBase,Context,Variable,list<mixed>):void $type_modification_callback
     *        A closure acting on a Variable instance (not really a variable) to modify its type
     *
     *        This is a function such as is_array, is_null, etc.
     * @param Context $context
     * @param list<mixed> $args
     */
    protected function modifyPropertyOfThis(Node $node, Closure $type_modification_callback, Context $context, array $args): Context
    {
        $property_name = $node->children['prop'];
        if (!is_string($property_name)) {
            return $context;
        }
        // Give the property a type and compute the new type
        $old_property_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $node);
        $property_variable = new Variable($context, "__phan", $old_property_type, 0);
        $type_modification_callback($this->code_base, $context, $property_variable, $args);
        $new_property_type = $property_variable->getUnionType();
        if ($new_property_type->isIdenticalTo($old_property_type)) {
            return $context;
        }
        return $context->withThisPropertySetToTypeByName($property_name, $new_property_type);
    }

    /**
     * Return a context with overrides for the type of a property of $this in the local scope.
     *
     * @param Node $node a node of kind ast\AST_PROP (e.g. the argument of is_array($this->prop_name))
     * @param Closure(UnionType):UnionType $type_mapping_callback
     *        Given a union type, returns the resulting union type.
     * @param Context $context
     */
    protected function modifyPropertyOfThisSimple(Node $node, Closure $type_mapping_callback, Context $context): Context
    {
        $property_name = $node->children['prop'];
        if (!is_string($property_name)) {
            return $context;
        }
        // Give the property a type and compute the new type
        $old_property_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $node);
        $new_property_type = $type_mapping_callback($old_property_type);
        if ($new_property_type->isIdenticalTo($old_property_type)) {
            // This didn't change anything
            return $context;
        }
        return $context->withThisPropertySetToTypeByName($property_name, $new_property_type);
    }

    /**
     * @param Node $node a node of kind ast\AST_PROP (e.g. the argument of is_array($this->prop_name))
     *                   This is a no-op of the expression is not $this.
     * @param Closure(UnionType):UnionType $type_mapping_callback
     *        Given a union type, returns the resulting union type.
     * @param Context $context
     */
    protected function modifyPropertySimple(Node $node, Closure $type_mapping_callback, Context $context): Context
    {
        if (!self::isThisVarNode($node->children['expr'])) {
            return $context;
        }
        return self::modifyPropertyOfThisSimple($node, $type_mapping_callback, $context);
    }

    /**
     * @param Node|string|int|float $node
     * @return ?string the name of the variable in a chain of field accesses such as $varName['field'][$i]
     */
    private static function getVarNameOfDimNode($node): ?string
    {
        // Loop to support getting the var name in is_array($x['field'][0])
        while (true) {
            if (!($node instanceof Node)) {
                return null;
            }
            if ($node->kind === ast\AST_VAR) {
                break;
            }
            if ($node->kind === ast\AST_DIM) {
                $node = $node->children['expr'];
                if (!$node instanceof Node) {
                    return null;
                }
                continue;
            }
            if ($node->kind === ast\AST_PROP) {
                if (is_string($node->children['prop']) && self::isThisVarNode($node->children['expr'])) {
                    return 'this';
                }
            }

            // TODO: Handle more than one level of nesting
            return null;
        }
        $var_name = $node->children['name'];
        return is_string($var_name) ? $var_name : null;
    }
}
