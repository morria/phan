<?php

declare(strict_types=1);

namespace Phan\Analysis;

use AssertionError;
use ast;
use ast\Node;
use Closure;
use Exception;
use Phan\AST\AnalysisVisitor;
use Phan\AST\ASTReverter;
use Phan\AST\ContextNode;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Exception\RecursionDepthException;
use Phan\Exception\UnanalyzableException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Element\PassByReferenceVariable;
use Phan\Language\Element\Property;
use Phan\Language\Element\TypedElementInterface;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\AssociativeArrayType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\ListType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NonEmptyAssociativeArrayType;
use Phan\Language\Type\NonEmptyGenericArrayType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\StringType;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;

use function strcasecmp;

/**
 * Analyzes assignments.
 */
class AssignmentVisitor extends AnalysisVisitor
{
    /**
     * @var UnionType
     * The type of the element on the right side of the assignment
     */
    private $right_type;

    /**
     * @var int
     * Depth of array parameters in this assignment, e.g. this is
     * 1 for `$foo[3] = 42`, 0 for `$x = 2;`, etc.
     * We need to know this in order to decide
     * if we're replacing the union type
     * or if we're adding a type to the union type.
     * @phan-read-only
     */
    private $dim_depth;

    /**
     * @var ?UnionType
     * Non-null if this assignment is to an array parameter such as
     * in `$foo[3] = 42` (type would be int). We need to know this in order to decide
     * to type check the assignment (e.g. array keys are int|string, string offsets are int)
     * type to the union type.
     *
     * Null for `$foo[] = 42` or when dim_depth is 0
     * @phan-read-only
     */
    private $dim_type;

    /**
     * @var Node
     */
    private $assignment_node;

    /**
     * @param CodeBase $code_base
     * The global code base we're operating within
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param Node $assignment_node
     * The AST node containing the assignment
     *
     * @param UnionType $right_type
     * The type of the element on the right side of the assignment
     *
     * @param int $dim_depth
     * Positive if this assignment is to an array parameter such as
     * in `$foo[3] = 42`. We need to know this in order to decide
     * if we're replacing the union type or if we're adding a
     * type to the union type.
     *
     * @param ?UnionType $dim_type
     * The type of the dimension.
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        Node $assignment_node,
        UnionType $right_type,
        int $dim_depth = 0,
        ?UnionType $dim_type = null
    ) {
        parent::__construct($code_base, $context);

        $this->right_type = $right_type->withSelfResolvedInContext($context)->convertUndefinedToNullable();
        $this->dim_depth = $dim_depth;
        $this->dim_type = $dim_type;  // null for `$x[] =` or when dim_depth is 0.
        $this->assignment_node = $assignment_node;
    }

    /**
     * Default visitor for node kinds that do not have
     * an overriding method
     *
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     *
     * @throws UnanalyzableException
     */
    public function visit(Node $node): Context
    {
        // TODO: Add more details.
        // This should only happen when the polyfill parser is used on invalid ASTs
        $this->emitIssue(
            Issue::Unanalyzable,
            $node->lineno
        );
        return $this->context;
    }

    // TODO visitNullsafeMethodCall should not be possible on the left hand side?

    /**
     * The following is an example of how this would happen.
     * (TODO: Check if the right-hand side is an object with offsetSet() or a reference?
     *
     * ```php
     * class C {
     *     function f() {
     *         return [ 24 ];
     *     }
     * }
     * (new C)->f()[1] = 42;
     * ```
     *
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     *
     * @throws UnanalyzableException for first-class callable conversion
     */
    public function visitMethodCall(Node $node): Context
    {
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // Warn about this being unanalyzable
            return $this->visit($node);
        }
        if ($this->dim_depth >= 2) {
            return $this->context;
        }
        $method_name = $node->children['method'];

        if (!\is_string($method_name)) {
            if ($method_name instanceof Node) {
                $method_name = UnionTypeVisitor::anyStringLiteralForNode($this->code_base, $this->context, $method_name);
            }
            if (!\is_string($method_name)) {
                return $this->context;
            }
        }

        try {
            $method = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethod($method_name, false, true);
            $this->checkAssignmentToFunctionResult($node, [$method]);
        } catch (Exception $_) {
            // ignore it
        }
        return $this->context;
    }

    /**
     * The following is an example of how this would happen.
     *
     * This checks if the left-hand side is a reference.
     *
     * PhanTypeArraySuspicious covers checking for offsetSet.
     *
     * ```php
     * function &f() {
     *     $x = [ 24 ]; return $x;
     * }
     * f()[1] = 42;
     * ```
     *
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     *
     * @throws UnanalyzableException for first-class callable conversion
     */
    public function visitCall(Node $node): Context
    {
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // Warn about this being unanalyzable
            return $this->visit($node);
        }
        // TODO: Warn about first-class callable conversion
        $expression = $node->children['expr'];
        if ($this->dim_depth < 2) {
            // Get the function.
            // If the function is undefined, always try to create a placeholder from Phan's type signatures for internal functions so they can still be type checked.
            $this->checkAssignmentToFunctionResult($node, (new ContextNode(
                $this->code_base,
                $this->context,
                $expression
            ))->getFunctionFromNode(true));
        }
        return $this->context;
    }

    /**
     * @param iterable<FunctionInterface> $function_list_generator
     */
    private function checkAssignmentToFunctionResult(Node $node, iterable $function_list_generator): void
    {
        try {
            foreach ($function_list_generator as $function) {
                if ($function->returnsRef()) {
                    return;
                }
                if ($this->dim_depth > 0) {
                    $return_type = $function->getUnionType();
                    if ($return_type->isEmpty()) {
                        return;
                    }
                    if ($return_type->hasPossiblyObjectTypes()) {
                        // PhanTypeArraySuspicious covers that, though
                        return;
                    }
                }
            }
            if (isset($function)) {
                $this->emitIssue(
                    Issue::TypeInvalidCallExpressionAssignment,
                    $node->lineno,
                    ASTReverter::toShortString($this->assignment_node->children['var'] ?? $node),
                    $function->getUnionType()
                );
            }
        } catch (CodeBaseException $_) {
            // ignore it.
        }
    }

    /**
     * The following is an example of how this would happen.
     *
     * ```php
     * class A{
     *     function &f() {
     *         $x = [ 24 ]; return $x;
     *     }
     * }
     * A::f()[1] = 42;
     * ```
     *
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     *
     * @throws UnanalyzableException for first-class callable conversion
     */
    public function visitStaticCall(Node $node): Context
    {
        return $this->visitMethodCall($node);
    }

    /**
     * This happens for code like the following
     * ```
     * list($a) = [1, 2, 3];
     * ```
     *
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     */
    public function visitArray(Node $node): Context
    {
        $this->checkValidArrayDestructuring($node);
        if ($this->right_type->hasTopLevelArrayShapeTypeInstances()) {
            $this->analyzeShapedArrayAssignment($node);
        } else {
            // common case
            $this->analyzeGenericArrayAssignment($node);
        }
        return $this->context;
    }

    private function checkValidArrayDestructuring(Node $node): void
    {
        if (!$node->children) {
            $this->emitIssue(
                Issue::SyntaxEmptyListArrayDestructuring,
                $node->lineno
            );
            return;
        }
        $bitmask = 0;
        foreach ($node->children as $c) {
            // When $c is null, it's the same as an array entry without a key for purposes of warning.
            $bitmask |= (isset($c->children['key']) ? 1 : 2);
            if ($bitmask === 3) {
                $this->emitIssue(
                    Issue::SyntaxMixedKeyNoKeyArrayDestructuring,
                    $c->lineno ?? $node->lineno,
                    ASTReverter::toShortString($node)
                );
                return;
            }
        }
    }

    /**
     * Analyzes code such as list($a) = [1, 2, 3];
     * @see self::visitArray()
     */
    private function analyzeShapedArrayAssignment(Node $node): void
    {
        // Figure out the type of elements in the list
        $fallback_element_type = null;
        /** @suppress PhanAccessMethodInternal */
        $get_fallback_element_type = function () use (&$fallback_element_type): UnionType {
            return $fallback_element_type ?? ($fallback_element_type = (
                $this->right_type->genericArrayElementTypes(false, $this->code_base)
                                 ->withRealTypeSet(UnionType::computeRealElementTypesForDestructuringAccess($this->right_type->getRealTypeSet(), $this->code_base))));
        };

        $expect_string_keys_lineno = false;
        $expect_int_keys_lineno = false;

        $key_set = [];

        foreach ($node->children ?? [] as $child_node) {
            // Some times folks like to pass a null to
            // a list to throw the element away. I'm not
            // here to judge.
            if (!($child_node instanceof Node)) {
                // Track the element that was thrown away.
                $key_set[] = true;
                continue;
            }

            if ($child_node->kind !== ast\AST_ARRAY_ELEM) {
                $this->emitIssue(
                    Issue::InvalidNode,
                    $child_node->lineno,
                    "Spread operator is not supported in assignments"
                );
                continue;
            }
            // Get the key and value nodes for each
            // array element we're assigning to
            // TODO: Check key types are valid?
            $key_node = $child_node->children['key'];

            if ($key_node === null) {
                $key_set[] = true;
                \end($key_set);
                $key_value = \key($key_set);

                $expect_int_keys_lineno = $child_node->lineno;  // list($x, $y) = ... is equivalent to list(0 => $x, 1 => $y) = ...
            } else {
                if ($key_node instanceof Node) {
                    $key_value = (new ContextNode($this->code_base, $this->context, $key_node))->getEquivalentPHPScalarValue();
                } else {
                    $key_value = $key_node;
                }
                if (\is_scalar($key_value)) {
                    $key_set[$key_value] = true;
                    if (\is_int($key_value)) {
                        $expect_int_keys_lineno = $child_node->lineno;
                    } elseif (\is_string($key_value)) {
                        $expect_string_keys_lineno = $child_node->lineno;
                    }
                } else {
                    $key_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $key_node);
                    $key_type_enum = GenericArrayType::keyTypeFromUnionTypeValues($key_type);
                    // TODO: Warn about types that can't cast to int|string
                    if ($key_type_enum === GenericArrayType::KEY_INT) {
                        $expect_int_keys_lineno = $child_node->lineno;
                    } elseif ($key_type_enum === GenericArrayType::KEY_STRING) {
                        $expect_string_keys_lineno = $child_node->lineno;
                    }
                }
            }

            if (\is_scalar($key_value)) {
                $element_type = UnionTypeVisitor::resolveArrayShapeElementTypesForOffset($this->right_type, $key_value, false, $this->code_base);
                if ($element_type === null) {
                    $element_type = $get_fallback_element_type();
                } elseif ($element_type === false) {
                    $this->emitIssue(
                        Issue::TypeInvalidDimOffsetArrayDestructuring,
                        $child_node->lineno,
                        StringUtil::jsonEncode($key_value),
                        ASTReverter::toShortString($child_node),
                        (string)$this->right_type
                    );
                    $element_type = $get_fallback_element_type();
                } else {
                    if ($element_type->hasRealTypeSet()) {
                        $element_type = self::withComputedRealUnionType($element_type, $this->right_type, function (UnionType $new_right_type) use ($key_value): UnionType {
                            return UnionTypeVisitor::resolveArrayShapeElementTypesForOffset($new_right_type, $key_value, false, $this->code_base) ?: UnionType::empty();
                        });
                    }
                }
            } else {
                $element_type = $get_fallback_element_type();
            }

            $this->analyzeValueNodeOfShapedArray($element_type, $child_node->children['value']);
        }

        if (!Config::getValue('scalar_array_key_cast')) {
            $this->checkMismatchArrayDestructuringKey($expect_int_keys_lineno, $expect_string_keys_lineno);
        }
    }

    /**
     * Utility function to compute accurate real union types
     *
     * TODO: Move this into a common class such as UnionType?
     * @param Closure(UnionType):UnionType $recompute_inferred_type
     */
    private static function withComputedRealUnionType(UnionType $inferred_type, UnionType $source_type, Closure $recompute_inferred_type): UnionType
    {
        if (!$inferred_type->hasRealTypeSet()) {
            return $inferred_type;
        }
        if ($source_type->getRealTypeSet() === $source_type->getTypeSet()) {
            return $inferred_type;
        }
        $real_inferred_type = $recompute_inferred_type($inferred_type->getRealUnionType());
        return $inferred_type->withRealTypeSet($real_inferred_type->getTypeSet());
    }

    /**
     * @param Node|string|int|float $value_node
     */
    private function analyzeValueNodeOfShapedArray(
        UnionType $element_type,
        $value_node
    ): void {
        if (!$value_node instanceof Node) {
            return;
        }
        $kind = $value_node->kind;
        if ($kind === \ast\AST_REF) {
            $value_node = $value_node->children['expr'];
            if (!$value_node instanceof Node) {
                return;
            }
            // TODO: Infer that this is creating or copying a reference [&$a] = [&$b]
        }
        if ($kind === \ast\AST_VAR) {
            $variable = Variable::fromNodeInContext(
                $value_node,
                $this->context,
                $this->code_base,
                false
            );

            // Set the element type on each element of
            // the list
            $this->analyzeSetUnionType($variable, $element_type, $value_node);

            // Note that we're not creating a new scope, just
            // adding variables to the existing scope
            $this->context->addScopeVariable($variable);
        } elseif ($kind === \ast\AST_PROP) {
            try {
                $property = (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $value_node
                ))->getProperty(false, true);

                // Set the element type on each element of
                // the list
                $this->analyzeSetUnionType($property, $element_type, $value_node);
            } catch (UnanalyzableException | NodeException $_) {
                // Ignore it. There's nothing we can do.
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
                return;
            }
        } else {
            $this->context = (new AssignmentVisitor(
                $this->code_base,
                $this->context,
                $value_node,
                $element_type,
                0
            ))->__invoke($value_node);
        }
    }  // TODO: Warn if $value_node is not a node. NativeSyntaxCheckPlugin already does this.

    /**
     * Set the element's union type.
     * This should be used for warning about assignments such as `$leftHandSide = $str`, but not `is_string($var)`,
     * when typed properties could be used.
     *
     * @param Node|string|int|float|null $node
     */
    private function analyzeSetUnionType(
        TypedElementInterface $element,
        UnionType $element_type,
        $node
    ): void {
        // Let the caller warn about possibly undefined offsets, e.g. ['field' => $value] = ...
        // TODO: Convert real types to nullable?
        $element_type = $element_type->withIsPossiblyUndefined(false);
        $element->setUnionType($element_type);
        if ($element instanceof PassByReferenceVariable) {
            $assign_node = new Node(ast\AST_ASSIGN, 0, ['expr' => $node], $node->lineno ?? $this->assignment_node->lineno);
            self::analyzeSetUnionTypePassByRef($this->code_base, $this->context, $element, $element_type, $assign_node);
        }
    }

    /**
     * Set the element's union type.
     * This should be used for warning about assignments such as `$leftHandSide = $str`, but not `is_string($var)`,
     * when typed properties could be used.
     *
     * Static version of analyzeSetUnionType
     *
     * @param Node|string|int|float $node
     */
    public static function analyzeSetUnionTypeInContext(
        CodeBase $code_base,
        Context $context,
        TypedElementInterface $element,
        UnionType $element_type,
        $node
    ): void {
        $element->setUnionType($element_type);
        if ($element instanceof PassByReferenceVariable) {
            self::analyzeSetUnionTypePassByRef(
                $code_base,
                $context,
                $element,
                $element_type,
                new Node(ast\AST_ASSIGN, 0, ['expr' => $node], $node->lineno ?? $context->getLineNumberStart())
            );
        }
    }

    /**
     * Set the reference element's union type.
     * This should be used for warning about assignments such as `$leftHandSideRef = $str`, but not `is_string($varRef)`,
     * when typed properties could be used.
     *
     * @param Node|string|int|float $node the assignment expression
     */
    private static function analyzeSetUnionTypePassByRef(
        CodeBase $code_base,
        Context $context,
        PassByReferenceVariable $reference_element,
        UnionType $new_type,
        $node
    ): void {
        $element = $reference_element->getElement();
        while ($element instanceof PassByReferenceVariable) {
            $reference_element = $element;
            $element = $element->getElement();
        }
        if ($element instanceof Property) {
            $real_union_type = $element->getRealUnionType();
            if (!$real_union_type->isEmpty() && !$new_type->getRealUnionType()->canCastToDeclaredType($code_base, $context, $real_union_type)) {
                $reference_context = $reference_element->getContextOfCreatedReference();
                if ($reference_context) {
                    // Here, we emit the issue at the place where the reference was created,
                    // since that's the code that can be changed or where issues should be suppressed.
                    Issue::maybeEmit(
                        $code_base,
                        $reference_context,
                        Issue::TypeMismatchPropertyRealByRef,
                        $reference_context->getLineNumberStart(),
                        isset($node->children['expr']) ? ASTReverter::toShortString($node->children['expr']) : '(unknown)',
                        $new_type,
                        $element->getRepresentationForIssue(),
                        $real_union_type,
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart()
                    );
                }
                return;
            }
            if (!$new_type->canCastToUnionType($element->getPHPDocUnionType(), $code_base)) {
                $reference_context = $reference_element->getContextOfCreatedReference();
                if ($reference_context) {
                    Issue::maybeEmit(
                        $code_base,
                        $reference_context,
                        Issue::TypeMismatchPropertyByRef,
                        $reference_context->getLineNumberStart(),
                        isset($node->children['expr']) ? ASTReverter::toShortString($node->children['expr']) : '(unknown)',
                        $new_type,
                        $element->getRepresentationForIssue(),
                        $element->getPHPDocUnionType(),
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart()
                    );
                }
            }
        }
    }

    /**
     * Analyzes code such as list($a) = function_returning_array();
     * @param Node $node the ast\AST_ARRAY node on the left hand side of the assignment
     * @see self::visitArray()
     */
    private function analyzeGenericArrayAssignment(Node $node): void
    {
        // Figure out the type of elements in the list
        $right_type = $this->right_type;
        if ($right_type->isEmpty()) {
            $element_type = UnionType::empty();
        } else {
            $array_access_types = $right_type->asArrayOrArrayAccessSubTypes($this->code_base);
            if ($array_access_types->isEmpty()) {
                $this->emitIssue(
                    Issue::TypeInvalidExpressionArrayDestructuring,
                    $node->lineno,
                    $this->getAssignedExpressionString(),
                    $right_type,
                    'array|ArrayAccess'
                );
            }
            $element_type =
                $array_access_types->genericArrayElementTypes(false, $this->code_base)
                                   ->withRealTypeSet(UnionType::computeRealElementTypesForDestructuringAccess($right_type->getRealTypeSet(), $this->code_base));
            // @phan-suppress-previous-line PhanAccessMethodInternal
        }

        $expect_string_keys_lineno = false;
        $expect_int_keys_lineno = false;

        $scalar_array_key_cast = Config::getValue('scalar_array_key_cast');

        foreach ($node->children ?? [] as $child_node) {
            // Some times folks like to pass a null to
            // a list to throw the element away. I'm not
            // here to judge.
            if (!($child_node instanceof Node)) {
                continue;
            }
            if ($child_node->kind !== ast\AST_ARRAY_ELEM) {
                $this->emitIssue(
                    Issue::InvalidNode,
                    $child_node->lineno,
                    "Spread operator is not supported in assignments"
                );
                continue;
            }

            // Get the key and value nodes for each
            // array element we're assigning to
            // TODO: Check key types are valid?
            $key_node = $child_node->children['key'];
            if (!$scalar_array_key_cast) {
                if ($key_node === null) {
                    $expect_int_keys_lineno = $child_node->lineno;  // list($x, $y) = ... is equivalent to list(0 => $x, 1 => $y) = ...
                } else {
                    $key_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $key_node);
                    $key_type_enum = GenericArrayType::keyTypeFromUnionTypeValues($key_type);
                    // TODO: Warn about types that can't cast to int|string
                    if ($key_type_enum === GenericArrayType::KEY_INT) {
                        $expect_int_keys_lineno = $child_node->lineno;
                    } elseif ($key_type_enum === GenericArrayType::KEY_STRING) {
                        $expect_string_keys_lineno = $child_node->lineno;
                    }
                }
            }

            $value_node = $child_node->children['value'];
            if (!($value_node instanceof Node)) {
                // Skip non-nodes to avoid crash
                // TODO: Emit a new issue type for https://github.com/phan/phan/issues/1693
            } elseif ($value_node->kind === \ast\AST_VAR) {
                $variable = Variable::fromNodeInContext(
                    $value_node,
                    $this->context,
                    $this->code_base,
                    false
                );

                // Set the element type on each element of
                // the list
                $this->analyzeSetUnionType($variable, $element_type, $value_node);

                // Note that we're not creating a new scope, just
                // adding variables to the existing scope
                $this->context->addScopeVariable($variable);
            } elseif ($value_node->kind === \ast\AST_PROP) {
                try {
                    $property = (new ContextNode(
                        $this->code_base,
                        $this->context,
                        $value_node
                    ))->getProperty(false, true);

                    // Set the element type on each element of
                    // the list
                    $this->analyzeSetUnionType($property, $element_type, $value_node);
                } catch (UnanalyzableException | NodeException $_) {
                    // Ignore it. There's nothing we can do.
                } catch (IssueException $exception) {
                    Issue::maybeEmitInstance(
                        $this->code_base,
                        $this->context,
                        $exception->getIssueInstance()
                    );
                    continue;
                }
            } else {
                $this->context = (new AssignmentVisitor(
                    $this->code_base,
                    $this->context,
                    $value_node,
                    $element_type,
                    0
                ))->__invoke($value_node);
            }
        }

        $this->checkMismatchArrayDestructuringKey($expect_int_keys_lineno, $expect_string_keys_lineno);
    }

    /**
     * @param int|false $expect_int_keys_lineno
     * @param int|false $expect_string_keys_lineno
     */
    private function checkMismatchArrayDestructuringKey($expect_int_keys_lineno, $expect_string_keys_lineno): void
    {
        if ($expect_int_keys_lineno !== false || $expect_string_keys_lineno !== false) {
            $right_hand_key_type = GenericArrayType::keyTypeFromUnionTypeKeys($this->right_type);
            if ($expect_int_keys_lineno !== false && ($right_hand_key_type & GenericArrayType::KEY_INT) === 0) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    Issue::TypeMismatchArrayDestructuringKey,
                    $expect_int_keys_lineno,
                    'int',
                    'string'
                );
            } elseif ($expect_string_keys_lineno !== false && ($right_hand_key_type & GenericArrayType::KEY_STRING) === 0) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    Issue::TypeMismatchArrayDestructuringKey,
                    $expect_string_keys_lineno,
                    'string',
                    'int'
                );
            }
        }
    }

    /**
     * @param Node $node
     * A node to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     */
    public function visitDim(Node $node): Context
    {
        $expr_node = $node->children['expr'];
        if (!($expr_node instanceof Node)) {
            $this->emitIssue(
                Issue::InvalidWriteToTemporaryExpression,
                $node->lineno,
                ASTReverter::toShortString($node),
                Type::fromObject($expr_node)
            );
            return $this->context;
        }
        if ($expr_node->kind === \ast\AST_VAR) {
            $variable_name = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getVariableName();

            if (Variable::isHardcodedVariableInScopeWithName($variable_name, $this->context->isInGlobalScope())) {
                if ($variable_name === 'GLOBALS') {
                    return $this->analyzeSuperglobalDim($node, $variable_name);
                }
                if (!$this->context->getScope()->hasVariableWithName($variable_name)) {
                    $this->context->addScopeVariable(new Variable(
                        $this->context->withLineNumberStart($expr_node->lineno),
                        $variable_name,
                        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                        Variable::getUnionTypeOfHardcodedGlobalVariableWithName($variable_name),
                        0
                    ));
                }
            }
        }

        // TODO: Check if the unionType is valid for the []
        // For most types, it should be int|string, but SplObjectStorage and a few user-defined types will be exceptions.
        // Infer it from offsetSet?
        $dim_node = $node->children['dim'];
        if ($dim_node instanceof Node) {
            // TODO: Use ContextNode to infer dim_value
            $dim_type = UnionTypeVisitor::unionTypeFromNode(
                $this->code_base,
                $this->context,
                $dim_node
            );
            $dim_value = $dim_type->asSingleScalarValueOrNullOrSelf();
        } elseif (\is_scalar($dim_node)) {
            $dim_value = $dim_node;
            $dim_type = Type::fromObject($dim_node)->asRealUnionType();
        } else {
            // TODO: If the array shape has only one set of keys, then appending should add to that shape? Possibly not a common use case.
            $dim_type = null;
            $dim_value = null;
        }

        if ($dim_type !== null && !\is_object($dim_value)) {
            // TODO: This is probably why Phan has bugs with multi-dimensional assignment adding new union types instead of combining with existing ones.
            $right_type = ArrayShapeType::fromFieldTypes([
                $dim_value => $this->right_type,
            ], false)->asRealUnionType();
        } else {
            // Make the right type a generic (i.e. int -> int[])
            if ($dim_node !== null) {
                if ($dim_type !== null) {
                    $key_type_enum = GenericArrayType::keyTypeFromUnionTypeValues($dim_type);
                } else {
                    $key_type_enum = GenericArrayType::KEY_MIXED;
                }
                $right_inner_type = $this->right_type;
                if ($right_inner_type->isEmpty()) {
                    $right_type = GenericArrayType::fromElementType(MixedType::instance(false), false, $key_type_enum)->asRealUnionType();
                } else {
                    $right_type = $right_inner_type->asGenericArrayTypes($key_type_enum);
                }
            } else {
                $right_type = $this->right_type->asNonEmptyListTypes()->nonFalseyClone();
            }
            if (!$right_type->hasRealTypeSet()) {
                $right_type = $right_type->withRealTypeSet(UnionType::typeSetFromString('non-empty-array'));
            }
        }

        // Recurse into whatever we're []'ing
        $context = (new AssignmentVisitor(
            $this->code_base,
            $this->context,
            $this->assignment_node,
            $right_type,
            $this->dim_depth + 1,
            $dim_type
        ))->__invoke($expr_node);

        return $context;
    }

    /**
     * Analyze an assignment where $variable_name is a superglobal, and return the new context.
     * May create a new variable in $this->context.
     * TODO: Emit issues if the assignment is incompatible with the pre-existing type?
     */
    private function analyzeSuperglobalDim(Node $node, string $variable_name): Context
    {
        $dim = $node->children['dim'];
        if ('GLOBALS' === $variable_name) {
            if (!\is_string($dim)) {
                // You're not going to believe this, but I just
                // found a piece of code like $GLOBALS[mt_rand()].
                // Super weird, right?
                return $this->context;
            }

            if (Variable::isHardcodedVariableInScopeWithName($dim, $this->context->isInGlobalScope())) {
                // Don't override types of superglobals such as $_POST, $argv through $_GLOBALS['_POST'] = expr either. TODO: Warn.
                return $this->context;
            }

            $variable = new Variable(
                $this->context,
                $dim,
                $this->right_type,
                0
            );

            $this->context->addGlobalScopeVariable(
                $variable
            );
        }
        // TODO: Assignment sanity checks.
        return $this->context;
    }

    // TODO: visitNullsafeProp should not be possible on the left hand side? Emit Issue::InvalidNode

    /**
     * @param Node $node
     * A node to analyze as the target of an assignment.
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     */
    public function visitProp(Node $node): Context
    {
        // Get class list first, warn if the class list is invalid.
        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['expr']
            ))->getClassList(false, ContextNode::CLASS_LIST_ACCEPT_OBJECT, Issue::TypeExpectedObjectPropAccess);
        } catch (\Exception $_) {
            // If we can't figure out what kind of a class
            // this is, don't worry about it.
            //
            // Note that CodeBaseException is one possible exception due to invalid code created by the fallback parser, etc.
            return $this->context;
        }

        $property_name = $node->children['prop'];
        if ($property_name instanceof Node) {
            $property_name = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $property_name)->asSingleScalarValueOrNull();
        }

        // Things like $foo->$bar
        if (!\is_string($property_name)) {
            return $this->context;
        }
        $expr_node = $node->children['expr'];
        if ($expr_node instanceof Node &&
                $expr_node->kind === \ast\AST_VAR &&
                $expr_node->children['name'] === 'this') {
            $this->handleThisPropertyAssignmentInLocalScopeByName($node, $property_name);
        }

        if (Config::get_strict_object_checking()) {
            ContextNode::checkPossiblyUndeclaredInstanceProperty($this->code_base, $this->context, $node, $property_name);
        }

        $property = null;
        $class_with_property = null;
        $class_without_property = null;
        foreach ($class_list as $clazz) {
            if ($clazz->isPropertyImmutableFromContext($this->code_base, $this->context, $property_name)) {
                $this->emitTypeModifyImmutableObjectPropertyIssue($clazz, $property_name, $node);
                return $this->context;
            }
            // Check to see if this class has the property or
            // a setter
            if (!$clazz->hasPropertyWithName($this->code_base, $property_name)) {
                if (!$clazz->hasMethodWithName($this->code_base, '__set', true)) {
                    $class_without_property = $clazz;
                    continue;
                }
            }

            try {
                $property = $clazz->getPropertyByNameInContext(
                    $this->code_base,
                    $property_name,
                    $this->context,
                    false,
                    $node,
                    true
                );
                $class_with_property = $clazz;
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
                return $this->context;
            }
        }

        if ($property && $class_with_property) {
            if ($class_without_property && Config::get_strict_object_checking()) {
                $this->emitIssue(
                    Issue::PossiblyUndeclaredPropertyOfClass,
                    $node->lineno,
                    $property_name,
                    UnionTypeVisitor::unionTypeFromNode(
                        $this->code_base,
                        $this->context,
                        $node->children['expr'] ?? $node->children['class']
                    ),
                    $class_without_property->getFQSEN()
                );
            }
            try {
                return $this->analyzePropAssignment($class_with_property, $property, $node);
            } catch (RecursionDepthException $_) {
                return $this->context;
            }
        }

        // Check if it is a built in class with dynamic properties but (possibly) no __set, such as SimpleXMLElement or stdClass or V8Js
        $is_class_with_arbitrary_types = isset($class_list[0]) ? $class_list[0]->hasDynamicProperties($this->code_base) : false;

        if ($is_class_with_arbitrary_types || Config::getValue('allow_missing_properties')) {
            try {
                // Create the property
                $property = (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $node
                ))->getOrCreateProperty($property_name, false);

                $this->addTypesToProperty($property, $node);
            } catch (\Exception $_) {
                // swallow it
            }
        } elseif (\count($class_list) > 0) {
            foreach ($class_list as $clazz) {
                if ($clazz->hasDynamicProperties($this->code_base)) {
                    return $this->context;
                }
            }
            $first_class = $class_list[0];
            $this->emitIssueWithSuggestion(
                Issue::UndeclaredProperty,
                $node->lineno ?? 0,
                ["{$first_class->getFQSEN()}->$property_name"],
                IssueFixSuggester::suggestSimilarProperty(
                    $this->code_base,
                    $this->context,
                    $first_class,
                    $property_name,
                    false
                )
            );
        } else {
            // If we hit this part, we couldn't figure out
            // the class, so we ignore the issue
        }

        return $this->context;
    }

    /**
     * This analyzes an assignment to an instance or static property.
     *
     * @param Node $node the left hand side of the assignment
     */
    private function analyzePropAssignment(Clazz $clazz, Property $property, Node $node): Context
    {
        $code_base = $this->code_base;
        if ($property->isReadOnly()) {
            $this->analyzeAssignmentToReadOnlyProperty($property, $node);
        }
        // TODO: Iterate over individual types, don't look at the whole type at once?

        // If we're assigning to an array element then we don't
        // know what the array structure of the parameter is
        // outside of the scope of this assignment, so we add to
        // its union type rather than replace it.
        $property_union_type = $property->getPHPDocUnionType()->withStaticResolvedInContext($this->context);
        $resolved_right_type = $this->right_type->withStaticResolvedInContext($this->context);
        if ($this->dim_depth > 0) {
            if ($resolved_right_type->canCastToExpandedUnionType(
                $property_union_type,
                $code_base
            )) {
                $this->addTypesToProperty($property, $node);
                if (Config::get_strict_property_checking() && $resolved_right_type->typeCount() > 1) {
                    $this->analyzePropertyAssignmentStrict($property, $resolved_right_type, $node);
                }
            } elseif ($property_union_type->hasArrayAccess($code_base)) {
                // Add any type if this is a subclass with array access.
                $this->addTypesToProperty($property, $node);
            } else {
                // Convert array shape types to generic arrays to reduce false positive PhanTypeMismatchProperty instances.

                // TODO: If the codebase explicitly sets a phpdoc array shape type on a property assignment,
                // then preserve the array shape type.
                $new_types = $this->typeCheckDimAssignment($property_union_type, $node)
                                  ->withFlattenedArrayShapeOrLiteralTypeInstances()
                                  ->withStaticResolvedInContext($this->context);

                // TODO: More precise than canCastToExpandedUnionType
                if (!$new_types->canCastToExpandedUnionType(
                    $property_union_type,
                    $code_base
                )) {
                    // echo "Emitting warning for $new_types\n";
                    // TODO: Don't emit if array shape type is compatible with the original value of $property_union_type
                    $this->emitTypeMismatchPropertyIssue(
                        $node,
                        $property,
                        $resolved_right_type,
                        $new_types,
                        $property_union_type
                    );
                } else {
                    if (Config::get_strict_property_checking() && $resolved_right_type->typeCount() > 1) {
                        $this->analyzePropertyAssignmentStrict($property, $resolved_right_type, $node);
                    }
                    $this->right_type = $new_types;
                    $this->addTypesToProperty($property, $node);
                }
            }
            return $this->context;
        } elseif ($clazz->isPHPInternal() && $clazz->getFQSEN() !== FullyQualifiedClassName::getStdClassFQSEN()) {
            // We don't want to modify the types of internal classes such as \ast\Node even if they are compatible
            // This would result in unpredictable results, and types which are more specific than they really are.
            // stdClass is an exception to this, for issues such as https://github.com/phan/phan/pull/700
            return $this->context;
        } else {
            // This is a regular assignment, not an assignment to an offset
            if (!$resolved_right_type->canCastToUnionType(
                $property_union_type->asExpandedTypes($code_base),
                $code_base
            )
                && !($resolved_right_type->hasTypeInBoolFamily() && $property_union_type->hasTypeInBoolFamily())
                && !$clazz->hasDynamicProperties($code_base)
                && !$property->isDynamicProperty()
            ) {
                if ($resolved_right_type->nonNullableClone()->canCastToUnionType($property_union_type->asExpandedTypes($code_base), $code_base) &&
                        !$resolved_right_type->isType(NullType::instance(false))) {
                    if ($this->shouldSuppressIssue(Issue::TypeMismatchProperty, $node->lineno)) {
                        return $this->context;
                    }
                    $this->emitIssue(
                        Issue::PossiblyNullTypeMismatchProperty,
                        $node->lineno,
                        $this->getAssignedExpressionString(),
                        (string)$this->right_type->withUnionType($resolved_right_type),
                        $property->getRepresentationForIssue(),
                        (string)$property_union_type,
                        'null'
                    );
                } else {
                    // echo "Emitting warning for {$resolved_right_type->asExpandedTypes($code_base)} to {$property_union_type->asExpandedTypes($code_base)}\n";
                    $this->emitTypeMismatchPropertyIssue($node, $property, $resolved_right_type, $this->right_type->withUnionType($resolved_right_type), $property_union_type);
                }
                return $this->context;
            }

            if (Config::get_strict_property_checking() && $this->right_type->typeCount() > 1) {
                $this->analyzePropertyAssignmentStrict($property, $this->right_type, $node);
            }
        }

        // After having checked it, add this type to it
        $this->addTypesToProperty($property, $node);

        return $this->context;
    }

    /**
     * @param UnionType $resolved_right_type the type of the expression to use when checking for real type mismatches
     * @param UnionType $warn_type the type to use in issue messages
     */
    private function emitTypeMismatchPropertyIssue(
        Node $node,
        Property $property,
        UnionType $resolved_right_type,
        UnionType $warn_type,
        UnionType $property_union_type
    ): void {
        if ($this->context->hasSuppressIssue($this->code_base, Issue::TypeMismatchPropertyReal)) {
            return;
        }
        if (self::isRealMismatch($this->code_base, $property->getRealUnionType(), $resolved_right_type)) {
            $this->emitIssue(
                Issue::TypeMismatchPropertyReal,
                $node->lineno,
                $this->getAssignedExpressionString(),
                $warn_type,
                PostOrderAnalysisVisitor::toDetailsForRealTypeMismatch($warn_type),
                $property->getRepresentationForIssue(),
                $property_union_type,
                PostOrderAnalysisVisitor::toDetailsForRealTypeMismatch($property_union_type)
            );
            return;
        }
        if ($this->context->hasSuppressIssue($this->code_base, Issue::TypeMismatchPropertyProbablyReal)) {
            return;
        }
        if ($resolved_right_type->hasRealTypeSet() &&
            !$resolved_right_type->getRealUnionType()->canCastToDeclaredType($this->code_base, $this->context, $property_union_type)) {
            $this->emitIssue(
                Issue::TypeMismatchPropertyProbablyReal,
                $node->lineno,
                $this->getAssignedExpressionString(),
                $warn_type,
                PostOrderAnalysisVisitor::toDetailsForRealTypeMismatch($warn_type),
                $property->getRepresentationForIssue(),
                $property_union_type,
                PostOrderAnalysisVisitor::toDetailsForRealTypeMismatch($property_union_type)
            );
            return;
        }
        $this->emitIssue(
            Issue::TypeMismatchProperty,
            $node->lineno,
            $this->getAssignedExpressionString(),
            $warn_type,
            $property->getRepresentationForIssue(),
            $property_union_type
        );
    }

    private function getAssignedExpressionString(): string
    {
        $expr = $this->assignment_node->children['expr'] ?? null;
        if ($expr === null) {
            return '(unknown)';
        }
        $str = ASTReverter::toShortString($expr);
        if ($this->dim_depth > 0) {
            return "($str as a field)";
        }
        return $str;
    }

    /**
     * Returns true if Phan should emit a more severe issue type for real type mismatch
     */
    private static function isRealMismatch(CodeBase $code_base, UnionType $real_property_type, UnionType $real_actual_type): bool
    {
        if ($real_property_type->isEmpty()) {
            return false;
        }
        return !$real_actual_type->isStrictSubtypeOf($code_base, $real_property_type);
    }

    /**
     * Modifies $this->context (if needed) to track the assignment to a property of $this within a function-like.
     * This handles conditional branches.
     * @param string $prop_name
     * TODO: If $this->right_type is the empty union type and the property is declared, assume the phpdoc/real types instead of the empty union type.
     */
    private function handleThisPropertyAssignmentInLocalScopeByName(Node $node, string $prop_name): void
    {
        if ($this->dim_depth === 0) {
            $new_type = $this->right_type;
        } else {
            // Copied from visitVar
            $old_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $node);
            $right_type = $this->typeCheckDimAssignment($old_type, $node);
            $old_type = $old_type->nonNullableClone();
            if ($old_type->isEmpty()) {
                $old_type = ArrayType::instance(false)->asPHPDocUnionType();
            }

            if ($this->dim_depth > 1) {
                $new_type = $this->computeTypeOfMultiDimensionalAssignment($old_type, $right_type);
            } elseif ($old_type->hasTopLevelNonArrayShapeTypeInstances() || $right_type->hasTopLevelNonArrayShapeTypeInstances() || $right_type->isEmpty()) {
                $new_type = $old_type->withUnionType($right_type);
            } else {
                $new_type = ArrayType::combineArrayTypesOverriding($right_type, $old_type, true);
            }
        }
        $this->context = $this->context->withThisPropertySetToTypeByName($prop_name, $new_type);
    }

    private function analyzeAssignmentToReadOnlyProperty(Property $property, Node $node): void
    {
        $class_fqsen = $property->getClassFQSEN();
        $context = $property->getContext();

        $is_from_phpdoc = $property->isFromPHPDoc();
        if (!$is_from_phpdoc && $this->context->isInFunctionLikeScope()) {
            $method = $this->context->getFunctionLikeInScope($this->code_base);
            if ($method instanceof Method && strcasecmp($method->getName(), '__construct') === 0) {
                $class_type = $class_fqsen->asType();
                if ($property->getClassFQSEN()->asType()->isSubtypeOf($class_type, $this->code_base)) {
                    // This is a constructor setting its own properties or a base class's properties.
                    // TODO: Could support private methods
                    return;
                }
            }
        }
        $this->emitIssue(
            $is_from_phpdoc ? Issue::AccessReadOnlyMagicProperty : Issue::AccessReadOnlyProperty,
            $node->lineno,
            $property->asPropertyFQSENString(),
            $context->getFile(),
            $context->getLineNumberStart()
        );
    }

    private function analyzePropertyAssignmentStrict(Property $property, UnionType $assignment_type, Node $node): void
    {
        $type_set = $assignment_type->getTypeSet();
        if (\count($type_set) < 2) {
            throw new AssertionError('Expected to have at least two types when checking if types match in strict mode');
        }

        $property_union_type = $property->getUnionType();

        $mismatch_type_set = UnionType::empty();
        $mismatch_expanded_types = null;

        // For the strict
        foreach ($type_set as $type) {
            // Expand it to include all parent types up the chain
            $individual_type_expanded = $type->asExpandedTypes($this->code_base);

            // See if the argument can be cast to the
            // property
            if (!$individual_type_expanded->canCastToUnionType(
                $property_union_type,
                $this->code_base
            )) {
                $mismatch_type_set = $mismatch_type_set->withType($type);
                if ($mismatch_expanded_types === null) {
                    // Warn about the first type
                    $mismatch_expanded_types = $individual_type_expanded;
                }
            }
        }


        if ($mismatch_expanded_types === null) {
            // No mismatches
            return;
        }
        if ($this->shouldSuppressIssue(Issue::TypeMismatchPropertyReal, $node->lineno) ||
            $this->shouldSuppressIssue(Issue::TypeMismatchPropertyProbablyReal, $node->lineno) ||
            $this->shouldSuppressIssue(Issue::TypeMismatchProperty, $node->lineno)
        ) {
            // TypeMismatchProperty also suppresses PhanPossiblyNullTypeMismatchProperty, etc.
            return;
        }

        $this->emitIssue(
            self::getStrictPropertyMismatchIssueType($mismatch_type_set),
            $node->lineno,
            $this->getAssignedExpressionString(),
            (string)$this->right_type,
            $property->getRepresentationForIssue(),
            (string)$property_union_type,
            (string)$mismatch_expanded_types
        );
    }

    private static function getStrictPropertyMismatchIssueType(UnionType $union_type): string
    {
        if ($union_type->typeCount() === 1) {
            $type = $union_type->getTypeSet()[0];
            if ($type instanceof NullType) {
                return Issue::PossiblyNullTypeMismatchProperty;
            }
            if ($type instanceof FalseType) {
                return Issue::PossiblyFalseTypeMismatchProperty;
            }
        }
        return Issue::PartialTypeMismatchProperty;
    }

    /**
     * Based on AssignmentVisitor->addTypesToProperty
     * Used for analyzing reference parameters' possible effects on properties.
     * @internal the API will likely change
     */
    public static function addTypesToPropertyStandalone(
        CodeBase $code_base,
        Context $context,
        Property $property,
        UnionType $new_types
    ): void {
        $original_property_types = $property->getUnionType();
        if ($property->getRealUnionType()->isEmpty() && $property->getPHPDocUnionType()->isEmpty()) {
            $property->setUnionType(
                $new_types
                     ->eraseRealTypeSetRecursively()
                     ->withUnionType($property->getUnionType()->eraseRealTypeSetRecursively())
                     ->withStaticResolvedInContext($context)
                     ->withFlattenedArrayShapeOrLiteralTypeInstances()
            );
            return;
        }
        if ($original_property_types->isEmpty()) {
            // TODO: Be more precise?
            $property->setUnionType(
                $new_types
                     ->withStaticResolvedInContext($context)
                     ->withFlattenedArrayShapeOrLiteralTypeInstances()
                     ->withRealTypeSet($property->getRealUnionType()->getTypeSet())
            );
            return;
        }

        $has_literals = $original_property_types->hasLiterals();
        $new_types = $new_types->withStaticResolvedInContext($context)->withFlattenedArrayShapeTypeInstances();

        $updated_property_types = $original_property_types;
        foreach ($new_types->getTypeSet() as $new_type) {
            if ($new_type instanceof MixedType) {
                // Don't add MixedType to a non-empty property - It makes inferences on that property useless.
                continue;
            }

            // Only allow compatible types to be added to declared properties.
            // Allow anything to be added to dynamic properties.
            // TODO: Be more permissive about declared properties without phpdoc types.
            if (!$new_type->asPHPDocUnionType()->canCastToUnionType($original_property_types, $code_base) && !$property->isDynamicProperty()) {
                continue;
            }

            // Check for adding a specific array to as generic array as a workaround for #1783
            if (\get_class($new_type) === ArrayType::class && $original_property_types->hasGenericArray()) {
                continue;
            }
            if (!$has_literals) {
                $new_type = $new_type->asNonLiteralType();
            }
            $updated_property_types = $updated_property_types->withType($new_type);
        }

        // TODO: Add an option to check individual types, not just the whole union type?
        //       If that is implemented, verify that generic arrays will properly cast to regular arrays (public $x = [];)
        $property->setUnionType($updated_property_types->withRealTypeSet($property->getRealUnionType()->getTypeSet()));
    }

    /**
     * @param Property $property - The property which should have types added to it
     */
    private function addTypesToProperty(Property $property, Node $node): void
    {
        if ($property->getRealUnionType()->isEmpty() && $property->getPHPDocUnionType()->isEmpty()) {
            $property->setUnionType(
                $this->right_type
                     ->withUnionType($property->getUnionType())
                     ->withStaticResolvedInContext($this->context)
                     ->withFlattenedArrayShapeOrLiteralTypeInstances()
                     ->eraseRealTypeSetRecursively()
            );
            return;
        }
        $original_property_types = $property->getUnionType();
        if ($original_property_types->isEmpty()) {
            // TODO: Be more precise?
            $property->setUnionType(
                $this->right_type
                     ->withStaticResolvedInContext($this->context)
                     ->withFlattenedArrayShapeOrLiteralTypeInstances()
                     ->eraseRealTypeSetRecursively()
                     ->withRealTypeSet($property->getRealUnionType()->getTypeSet())
            );
            return;
        }

        if ($this->dim_depth > 0) {
            $new_types = $this->typeCheckDimAssignment($original_property_types, $node);
        } else {
            $new_types = $this->right_type;
        }
        $has_literals = $original_property_types->hasLiterals();
        $new_types = $new_types->withStaticResolvedInContext($this->context)->withFlattenedArrayShapeTypeInstances();

        $updated_property_types = $original_property_types;
        foreach ($new_types->getTypeSet() as $new_type) {
            if ($new_type instanceof MixedType) {
                // Don't add MixedType to a non-empty property - It makes inferences on that property useless.
                continue;
            }

            // Only allow compatible types to be added to declared properties.
            // Allow anything to be added to dynamic properties.
            // TODO: Be more permissive about declared properties without phpdoc types.
            if (!$new_type->asPHPDocUnionType()->canCastToUnionType($original_property_types, $this->code_base) && !$property->isDynamicProperty()) {
                continue;
            }

            // Check for adding a specific array to as generic array as a workaround for #1783
            if (\get_class($new_type) === ArrayType::class && $original_property_types->hasGenericArray()) {
                continue;
            }
            if (!$has_literals) {
                $new_type = $new_type->asNonLiteralType();
            }
            $updated_property_types = $updated_property_types->withType($new_type);
        }

        // TODO: Add an option to check individual types, not just the whole union type?
        //       If that is implemented, verify that generic arrays will properly cast to regular arrays (public $x = [];)
        $property->setUnionType($updated_property_types->withRealTypeSet($property->getRealUnionType()->getTypeSet()));
    }

    /**
     * @param Node $node
     * A node to analyze as the target of an assignment.
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     *
     * @see self::visitProp()
     */
    public function visitStaticProp(Node $node): Context
    {
        $property_name = $node->children['prop'];

        // Things like self::${$x}
        if (!\is_string($property_name)) {
            return $this->context;
        }

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['class']
            ))->getClassList(false, ContextNode::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME, Issue::TypeExpectedObjectStaticPropAccess);
        } catch (\Exception $_) {
            // If we can't figure out what kind of a class
            // this is, don't worry about it
            //
            // Note that CodeBaseException is one possible exception due to invalid code created by the fallback parser, etc.
            return $this->context;
        }

        foreach ($class_list as $clazz) {
            // Check to see if this class has the property
            if (!$clazz->hasPropertyWithName($this->code_base, $property_name)) {
                continue;
            }

            try {
                // Look for static properties with that $property_name
                $property = $clazz->getPropertyByNameInContext(
                    $this->code_base,
                    $property_name,
                    $this->context,
                    true,
                    null,
                    true
                );
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
                return $this->context;
            }

            try {
                return $this->analyzePropAssignment($clazz, $property, $node);
            } catch (RecursionDepthException $_) {
                return $this->context;
            }
        }

        if (\count($class_list) > 0) {
            $this->emitIssue(
                Issue::UndeclaredStaticProperty,
                $node->lineno ?? 0,
                $property_name,
                (string)$class_list[0]->getFQSEN()
            );
        } else {
            // If we hit this part, we couldn't figure out
            // the class, so we ignore the issue
        }

        return $this->context;
    }

    private function emitTypeModifyImmutableObjectPropertyIssue(Clazz $clazz, string $property_name, Node $node): void
    {
        if (!$clazz->isPHPInternal() && $clazz->hasPropertyWithName($this->code_base, $property_name)) {
            try {
                // Look for static properties with that $property_name
                $property = $clazz->getPropertyByNameInContext(
                    $this->code_base,
                    $property_name,
                    $this->context,
                    false, // is_static
                    null,
                    true
                );
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
                return;
            }
            $property_context = $property->getContext();
        } else {
            // TODO: Should emit a different issue when property doesn't exist.
            // TypeModifyUndeclaredPropertyOfImmutableClass
            $property_context = $clazz->getContext();
        }
        $this->emitIssue(
            Issue::TypeModifyImmutableObjectProperty,
            $node->lineno,
            $clazz->getClasslikeType(),
            $clazz->getFQSEN(),
            $property_name,
            $property_context->getFile(),
            $property_context->getLineNumberStart()
        );
    }

    /**
     * @param Node $node
     * A node of type ast\AST_VAR to analyze as the target of an assignment
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     */
    public function visitVar(Node $node): Context
    {
        try {
            $variable_name = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getVariableName();
        } catch (IssueException $exception) {
            Issue::maybeEmitInstance(
                $this->code_base,
                $this->context,
                $exception->getIssueInstance()
            );
            return $this->context;
        }
        // Don't analyze variables when we can't determine their names.
        if ($variable_name === '') {
            return $this->context;
        }

        if ($this->context->getScope()->hasVariableWithName($variable_name)) {
            $variable = $this->context->getScope()->getVariableByName($variable_name);
        } else {
            $variable_type = Variable::getUnionTypeOfHardcodedVariableInScopeWithName(
                $variable_name,
                $this->context->isInGlobalScope()
            );
            if ($variable_type) {
                $variable = new Variable(
                    $this->context,
                    $variable_name,
                    $variable_type,
                    0
                );
            } else {
                $variable = null;
            }
        }
        // Check to see if the variable already exists
        if ($variable) {
            // We clone the variable so as to not disturb its previous types
            // as we replace it.
            $variable = clone($variable);

            // If we're assigning to an array element then we don't
            // know what the array structure of the parameter is
            // outside of the scope of this assignment, so we add to
            // its union type rather than replace it.
            if ($this->dim_depth > 0) {
                $old_variable_union_type = $variable->getUnionType();
                if ($this->dim_depth === 1 && $old_variable_union_type->getRealUnionType()->isExclusivelyArray()) {
                    // We're certain of the types of $values, but not of $values[0], so check that the depth is exactly 1.
                    // @phan-suppress-next-line PhanUndeclaredProperty used in unused variable detection - array access to an object might have a side effect
                    $node->phan_is_assignment_to_real_array = true;
                }
                $right_type = $this->typeCheckDimAssignment($old_variable_union_type, $node);
                if ($old_variable_union_type->isEmpty()) {
                    $old_variable_union_type = ArrayType::instance(false)->asPHPDocUnionType();
                }
                // Note: Trying to assign dim offsets to a scalar such as `$x = 2` does not modify the variable.
                $old_variable_union_type = $old_variable_union_type->nonNullableClone();
                // TODO: Make the behavior more precise for $x['a']['b'] = ...; when $x is an array shape.
                if ($this->dim_depth > 1) {
                    $new_union_type = $this->computeTypeOfMultiDimensionalAssignment($old_variable_union_type, $right_type);
                } elseif ($old_variable_union_type->isEmpty() || $old_variable_union_type->hasPossiblyObjectTypes() || $right_type->hasTopLevelNonArrayShapeTypeInstances() || $right_type->isEmpty()) {
                    $new_union_type = $old_variable_union_type->withUnionType(
                        $right_type
                    );
                    // echo "Combining array shape types $right_type $old_variable_union_type $new_union_type\n";
                } else {
                    $new_union_type = ArrayType::combineArrayTypesOverriding(
                        $right_type,
                        $old_variable_union_type,
                        true
                    );
                }
                // Note that after $x[anything] = anything, $x is guaranteed not to be the empty array.
                // TODO: Handle `$x = 'x'; $s[0] = '0';`
                $this->analyzeSetUnionType($variable, $new_union_type->nonFalseyClone(), $this->assignment_node->children['expr'] ?? null);
            } else {
                $this->analyzeSetUnionType($variable, $this->right_type, $this->assignment_node->children['expr'] ?? null);
            }

            $this->context->addScopeVariable(
                $variable
            );

            return $this->context;
        }

        // no such variable exists, check for invalid array Dim access
        if ($this->dim_depth > 0) {
            $this->emitIssue(
                Issue::UndeclaredVariableDim,
                $node->lineno ?? 0,
                $variable_name
            );
        }

        $variable = new Variable(
            $this->context,
            $variable_name,
            UnionType::empty(),
            0
        );
        if ($this->dim_depth > 0) {
            // Reduce false positives: If $variable did not already exist, assume it may already have other array fields
            // (e.g. in a loop, or in the global scope)
            // TODO: Don't if this isn't in a loop or the global scope.
            $variable->setUnionType($this->right_type->withType(ArrayType::instance(false)));
        } else {
            // Set that type on the variable
            $variable->setUnionType(
                $this->right_type
            );
            if ($this->assignment_node->kind === ast\AST_ASSIGN_REF) {
                $expr = $this->assignment_node->children['expr'];
                if ($expr instanceof Node && \in_array($expr->kind, [ast\AST_STATIC_PROP, ast\AST_PROP], true)) {
                    try {
                        $property = (new ContextNode(
                            $this->code_base,
                            $this->context,
                            $expr
                        ))->getProperty($expr->kind === ast\AST_STATIC_PROP);
                        $variable = new PassByReferenceVariable(
                            $variable,
                            $property,
                            $this->code_base,
                            $this->context
                        );
                    } catch (IssueException | NodeException $_) {
                        // Hopefully caught elsewhere
                    }
                }
            }
        }

        // Note that we're not creating a new scope, just
        // adding variables to the existing scope
        $this->context->addScopeVariable($variable);

        return $this->context;
    }

    private function computeTypeOfMultiDimensionalAssignment(UnionType $old_union_type, UnionType $right_type): UnionType
    {
        if ($this->dim_depth <= 1) {
            throw new AssertionError("Expected dim_depth > 1, got $this->dim_depth");
        }
        if (!$right_type->hasTopLevelArrayShapeTypeInstances() || !$old_union_type->hasTopLevelArrayShapeTypeInstances()) {
            return $old_union_type->withUnionType($right_type);
        }

        return UnionType::of(
            self::computeTypeSetOfMergedArrayShapeTypes($old_union_type->getTypeSet(), $right_type->getTypeSet(), $this->dim_depth, false),
            self::computeTypeSetOfMergedArrayShapeTypes($old_union_type->getRealTypeSet(), $right_type->getRealTypeSet(), $this->dim_depth, true)
        );
    }

    /**
     * @param list<Type> $old_type_set may contain ArrayShapeType instances
     * @param list<Type> $new_type_set may contain ArrayShapeType instances
     * @return list<Type> possibly containing duplicates.
     * TODO: Handle $this->dim_depth of more than 2
     */
    private static function computeTypeSetOfMergedArrayShapeTypes(array $old_type_set, array $new_type_set, int $dim_depth, bool $is_real): array
    {
        if ($is_real) {
            if (!$old_type_set || !$new_type_set) {
                return [];
            }
        }
        $result = [];
        $new_array_shape_types = [];
        foreach ($new_type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                $new_array_shape_types[] = $type;
            } else {
                $result[] = $type;
            }
        }
        if (!$new_array_shape_types) {
            return \array_merge($old_type_set, $new_type_set);
        }
        $old_array_shape_types = [];
        foreach ($old_type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                $old_array_shape_types[] = $type;
            } else {
                $result[] = $type;
            }
        }
        if (!$old_array_shape_types) {
            return \array_merge($old_type_set, $new_type_set);
        }
        // Postcondition: $old_array_shape_types and $new_array_shape_types are non-empty lists of ArrayShapeTypes
        $old_array_shape_type = ArrayShapeType::union($old_array_shape_types);
        $new_array_shape_type = ArrayShapeType::union($new_array_shape_types);
        $combined_fields = $old_array_shape_type->getFieldTypes();
        foreach ($new_array_shape_type->getFieldTypes() as $field => $field_type) {
            $old_field_type = $combined_fields[$field] ?? null;
            if ($old_field_type) {
                if ($dim_depth >= 3) {
                    $combined_fields[$field] = UnionType::of(self::computeTypeSetOfMergedArrayShapeTypes(
                        $old_field_type->getTypeSet(),
                        $field_type->getTypeSet(),
                        $dim_depth - 1,
                        true
                    ));
                } else {
                    $combined_fields[$field] = ArrayType::combineArrayTypesOverriding($field_type, $old_field_type, true);
                }
            } else {
                $combined_fields[$field] = $field_type;
            }
        }
        $result[] = ArrayShapeType::fromFieldTypes($combined_fields, false);
        return $result;
    }

    /**
     * @param UnionType $assign_type - The type which is being added to
     * @return UnionType - Usually the unmodified UnionType. Sometimes, the adjusted type, e.g. for string modification.
     */
    public function typeCheckDimAssignment(UnionType $assign_type, Node $node): UnionType
    {
        static $int_or_string_type = null;
        static $string_array_type = null;
        static $simple_xml_element_type = null;

        if ($int_or_string_type === null) {
            $int_or_string_type = UnionType::fromFullyQualifiedPHPDocString('int|string');
            $string_array_type = UnionType::fromFullyQualifiedPHPDocString('string[]');
            $simple_xml_element_type =
                Type::fromNamespaceAndName('\\', 'SimpleXMLElement', false);
        }
        $dim_type = $this->dim_type;
        $right_type = $this->right_type;

        // Sanity check: Don't add list<T> to a property that isn't list<T>
        // unless it has 1 or more array types and all are list<T>
        $right_type = self::normalizeListTypesInDimAssignment($assign_type, $right_type);

        if ($assign_type->isEmpty() || ($assign_type->hasGenericArray() && !$assign_type->hasArrayAccess($this->code_base))) {
            // For empty union types or 'array', expect the provided dimension to be able to cast to int|string
            if ($dim_type && !$dim_type->isEmpty() && !$dim_type->canCastToUnionType($int_or_string_type, $this->code_base)) {
                $this->emitIssue(
                    Issue::TypeMismatchDimAssignment,
                    $node->lineno,
                    (string)$assign_type,
                    (string)$dim_type,
                    (string)$int_or_string_type
                );
            }
            return $right_type;
        }
        $assign_type_resolved = $assign_type->withStaticResolvedInContext($this->context);
        //echo "$assign_type_expanded : " . json_encode($assign_type_expanded->hasArrayLike()) . "\n";

        // TODO: Better heuristic to deal with false positives on ArrayAccess subclasses
        if ($assign_type_resolved->hasArrayAccess($this->code_base) && !$assign_type_resolved->hasGenericArray()) {
            return UnionType::empty();
        }

        if (!$assign_type_resolved->hasArrayLike($this->code_base)) {
            if ($assign_type->hasNonNullStringType()) {
                // Are we assigning to a variable/property of type 'string' (with no ArrayAccess or array types)?
                if (\is_null($dim_type)) {
                    $this->emitIssue(
                        Issue::TypeMismatchDimEmpty,
                        $node->lineno ?? 0,
                        (string)$assign_type,
                        'int'
                    );
                } elseif (!$dim_type->isEmpty() && !$dim_type->hasNonNullIntType()) {
                    $this->emitIssue(
                        Issue::TypeMismatchDimAssignment,
                        $node->lineno,
                        (string)$assign_type,
                        (string)$dim_type,
                        'int'
                    );
                } else {
                    if ($right_type->canCastToUnionType($string_array_type, $this->code_base)) {
                        // e.g. $a = 'aaa'; $a[0] = 'x';
                        // (Currently special casing this, not handling deeper dimensions)
                        return StringType::instance(false)->asPHPDocUnionType();
                    }
                }
            } elseif (!$assign_type->hasTypeMatchingCallback(static function (Type $type) use ($simple_xml_element_type): bool {
                return !$type->isNullableLabeled() && ($type instanceof MixedType || $type === $simple_xml_element_type);
            })) {
                // Imitate the check in UnionTypeVisitor, don't warn for mixed (but warn for `?mixed`), etc.
                $this->emitIssue(
                    Issue::TypeArraySuspicious,
                    $node->lineno,
                    ASTReverter::toShortString($node),
                    (string)$assign_type
                );
            }
        }
        return $right_type;
    }

    private static function normalizeListTypesInDimAssignment(UnionType $assign_type, UnionType $right_type): UnionType
    {
        // Offsets of $can_cast:
        // 0. lazily computed: True if list types should be kept as-is.
        // 1. lazily computed: Should this cast from a regular array to an associative array?
        $can_cast = [];
        /**
         * @param list<Type> $type_set
         * @return list<Type> with top level list converted to non-empty-array. May contain duplicates.
         */
        $map_type_set = static function (array $type_set) use ($assign_type, &$can_cast): array {
            foreach ($type_set as $i => $type) {
                if ($type instanceof ListType) {
                    $result = ($can_cast[0] = ($can_cast[0] ?? $assign_type->hasTypeMatchingCallback(static function (Type $other_type): bool {
                        if (!$other_type instanceof ArrayType) {
                            return false;
                        }
                        if ($other_type instanceof ListType) {
                            return true;
                        }
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        if ($other_type instanceof ArrayShapeType && $other_type->canCastToList()) {
                            return true;
                        }
                        return false;
                    })));
                    if ($result) {
                        continue;
                    }
                    $type_set[$i] = NonEmptyGenericArrayType::fromElementType($type->genericArrayElementType(), $type->isNullable(), $type->getKeyType());
                } elseif ($type instanceof GenericArrayType) {
                    $result = ($can_cast[1] = ($can_cast[1] ?? $assign_type->hasTypeMatchingCallback(static function (Type $other_type): bool {
                        if (!$other_type instanceof ArrayType) {
                            return false;
                        }
                        if ($other_type instanceof AssociativeArrayType) {
                            return true;
                        }
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        if ($other_type instanceof ArrayShapeType && $other_type->canCastToList()) {
                            return true;
                        }
                        return false;
                    })));
                    if (!$result) {
                        continue;
                    }
                    $type_set[$i] = NonEmptyAssociativeArrayType::fromElementType($type->genericArrayElementType(), $type->isNullable(), $type->getKeyType());
                }
            }
            return $type_set;
        };
        $new_type_set = $map_type_set($right_type->getTypeSet());
        $new_real_type_set = $map_type_set($right_type->getRealTypeSet());
        if (\count($can_cast) === 0) {
            return $right_type;
        }
        return UnionType::of($new_type_set, $new_real_type_set);
        // echo "Converting $right_type to $assign_type: $result\n";
    }

    /**
     * @param Node $node
     * A node to analyze as the target of an assignment of type AST_REF (found only in foreach)
     *
     * @return Context
     * A new or an unchanged context resulting from
     * analyzing the node
     */
    public function visitRef(Node $node): Context
    {
        // Note: AST_REF is only ever generated in AST_FOREACH, so this should be fine.
        $var = $node->children['var'];
        if ($var instanceof Node) {
            return $this->__invoke($var);
        }
        $this->emitIssue(
            Issue::Unanalyzable,
            $node->lineno
        );
        return $this->context;
    }
}
