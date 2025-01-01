<?php

declare(strict_types=1);

namespace Phan\AST;

use AssertionError;
use ast;
use ast\Node;
use Error;
use Exception;
use Phan\Analysis\ConditionVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Exception\EmptyFQSENException;
use Phan\Exception\FQSENException;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Exception\RecursionDepthException;
use Phan\Exception\UnanalyzableException;
use Phan\Exception\UnanalyzableMagicPropertyException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\ClassConstant;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\GlobalConstant;
use Phan\Language\Element\Method;
use Phan\Language\Element\Parameter;
use Phan\Language\Element\Property;
use Phan\Language\Element\TraitAdaptations;
use Phan\Language\Element\TraitAliasSource;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\Type;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\StringType;
use Phan\Language\UnionType;
use Phan\Library\FileCache;
use Phan\Library\None;

use function implode;
use function is_object;
use function is_string;
use function strcasecmp;
use function strpos;
use function strtolower;

/**
 * Methods for an AST node in context
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
class ContextNode
{

    /** @var CodeBase The code base within which we're operating */
    private $code_base;

    /** @var Context The context in which we are requesting information about the Node $this->node */
    private $context;

    /** @var Node|string|float|int|null the node which we're requesting information about. */
    private $node;

    /**
     * @param CodeBase $code_base The code base within which we're operating
     * @param Context $context The context in which we are requesting information about the Node.
     * @param ?(Node|string|float|int) $node the node which we're requesting information about.
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        $node
    ) {
        $this->code_base = $code_base;
        $this->context = $context;
        $this->node = $node;
    }

    /**
     * Get a list of fully qualified names from a node
     *
     * @return list<string>
     * @throws FQSENException if the node has invalid names
     * @suppress PhanUnreferencedPublicMethod this used to be used
     */
    public function getQualifiedNameList(): array
    {
        if (!($this->node instanceof Node)) {
            return [];
        }

        $union_type = UnionType::empty();
        foreach ($this->node->children as $name_node) {
            $union_type = $union_type->withUnionType(UnionTypeVisitor::unionTypeFromClassNode(
                $this->code_base,
                $this->context,
                $name_node
            ));
        }
        return \array_map('strval', $union_type->getTypeSet());
    }

    /**
     * Get a fully qualified name from a node
     * @throws FQSENException if the node is invalid
     * @internal TODO: Stop using this
     */
    public function getQualifiedName(): string
    {
        return UnionTypeVisitor::unionTypeFromClassNode(
            $this->code_base,
            $this->context,
            $this->node
        )->__toString();
    }

    /**
     * Gets the list of possible FQSENs for a trait.
     * NOTE: does not validate that it is really used on a trait
     * @return list<FullyQualifiedClassName>
     * @throws FQSENException
     */
    public function getTraitFQSENList(): array
    {
        if (!($this->node instanceof Node)) {
            return [];
        }

        /**
         * @param Node|int|string|float|null $name_node
         * @throws FQSENException
         */
        $result = [];
        foreach ($this->node->children as $name_node) {
            $trait_fqsen = (new ContextNode(
                $this->code_base,
                $this->context,
                $name_node
            ))->getTraitFQSEN([]);
            if ($trait_fqsen) {
                // Should never be null but check anyway
                // TODO warn
                $result[] = $trait_fqsen;
            }
        }
        return $result;
    }

    /**
     * Gets the FQSEN for a trait.
     * NOTE: does not validate that it is really used on a trait
     * @param array<string,TraitAdaptations> $adaptations_map
     * @return ?FullyQualifiedClassName (If this returns null, the caller is responsible for emitting an issue or falling back)
     * @throws FQSENException hopefully impossible
     */
    public function getTraitFQSEN(array $adaptations_map): ?FullyQualifiedClassName
    {
        // TODO: In a subsequent PR, try to make trait analysis work when $adaptations_map has multiple possible traits.
        $trait_fqsen_string = $this->getQualifiedName();
        if ($trait_fqsen_string === '') {
            if (\count($adaptations_map) === 1) {
                // @phan-suppress-next-line PhanPossiblyNonClassMethodCall
                return \reset($adaptations_map)->getTraitFQSEN();
            } else {
                return null;
            }
        }
        return FullyQualifiedClassName::fromStringInContext(
            $trait_fqsen_string,
            $this->context
        );
    }

    /**
     * Get a list of traits adaptations from a node of kind ast\AST_TRAIT_ADAPTATIONS
     * (with fully qualified names and `as`/`instead` info)
     *
     * @param list<FullyQualifiedClassName> $trait_fqsen_list TODO: use this for sanity check
     *
     * @return array<string,TraitAdaptations> maps the lowercase trait fqsen to the corresponding adaptations.
     *
     * @throws UnanalyzableException (should be caught and emitted as an issue)
     */
    public function getTraitAdaptationsMap(array $trait_fqsen_list): array
    {
        if (!($this->node instanceof Node)) {
            return [];
        }

        // NOTE: This fetches fully qualified names more than needed,
        // but this isn't optimized, since traits aren't frequently used in classes.

        $adaptations_map = [];
        foreach ($trait_fqsen_list as $trait_fqsen) {
            $adaptations_map[\strtolower($trait_fqsen->__toString())] = new TraitAdaptations($trait_fqsen);
        }

        foreach ($this->node->children as $adaptation_node) {
            if (!$adaptation_node instanceof Node) {
                throw new AssertionError('Expected adaptation_node to be Node');
            }
            if ($adaptation_node->kind === ast\AST_TRAIT_ALIAS) {
                $this->handleTraitAlias($adaptations_map, $adaptation_node);
            } elseif ($adaptation_node->kind === ast\AST_TRAIT_PRECEDENCE) {
                $this->handleTraitPrecedence($adaptations_map, $adaptation_node);
            } else {
                throw new AssertionError("Unknown adaptation node kind " . $adaptation_node->kind);
            }
        }
        return $adaptations_map;
    }

    /**
     * Handles a node of kind ast\AST_TRAIT_ALIAS, modifying the corresponding TraitAdaptations instance
     * @param array<string,TraitAdaptations> $adaptations_map
     * @param Node $adaptation_node
     */
    private function handleTraitAlias(array $adaptations_map, Node $adaptation_node): void
    {
        $trait_method_node = $adaptation_node->children['method'];
        if (!$trait_method_node instanceof Node) {
            throw new AssertionError("Expected node for trait alias");
        }
        $trait_original_class_name_node = $trait_method_node->children['class'];
        $trait_original_method_name = $trait_method_node->children['method'];
        $trait_new_method_name = $adaptation_node->children['alias'] ?? $trait_original_method_name;
        if (!\is_string($trait_original_method_name)) {
            $this->emitIssue(
                Issue::InvalidTraitUse,
                $trait_original_class_name_node->lineno ?? 0,
                "Expected original method name of a trait use to be a string"
            );
            return;
        }
        if (!\is_string($trait_new_method_name)) {
            $this->emitIssue(
                Issue::InvalidTraitUse,
                $trait_original_class_name_node->lineno ?? 0,
                "Expected new method name of a trait use to be a string"
            );
            return;
        }
        try {
            $trait_fqsen = (new ContextNode(
                $this->code_base,
                $this->context,
                $trait_original_class_name_node
            ))->getTraitFQSEN($adaptations_map);
        } catch (FQSENException $e) {
            $this->emitIssue(
                Issue::InvalidTraitUse,
                $trait_original_class_name_node->lineno ?? 0,
                $e->getMessage()
            );
            return;
        }
        if ($trait_fqsen === null) {
            // TODO: try to analyze this rare special case instead of giving up in a subsequent PR?
            // E.g. `use A, B{foo as bar}` is valid PHP, but hard to analyze.
            $this->emitIssue(
                Issue::AmbiguousTraitAliasSource,
                $trait_method_node->lineno ?? 0,
                $trait_new_method_name,
                $trait_original_method_name,
                '[' . implode(', ', \array_map(static function (TraitAdaptations $t): string {
                    return (string) $t->getTraitFQSEN();
                }, $adaptations_map)) . ']'
            );
            return;
        }

        $fqsen_key = \strtolower($trait_fqsen->__toString());

        $adaptations_info = $adaptations_map[$fqsen_key] ?? null;
        if ($adaptations_info === null) {
            // This will probably correspond to a PHP fatal error, but keep going anyway.
            $this->emitIssue(
                Issue::RequiredTraitNotAdded,
                $trait_original_class_name_node->lineno ?? 0,
                $trait_fqsen->__toString()
            );
            return;
        }
        // TODO: Could check for duplicate alias method occurrences, but `php -l` would do that for you in some cases
        $adaptations_info->alias_methods[$trait_new_method_name] = new TraitAliasSource($trait_original_method_name, $adaptation_node->lineno ?? 0, $adaptation_node->flags ?? 0);
        // Handle `use MyTrait { myMethod as private; }` by skipping the original method.
        // TODO: Do this a cleaner way.
        if (strcasecmp($trait_new_method_name, $trait_original_method_name) === 0) {
            $adaptations_info->hidden_methods[\strtolower($trait_original_method_name)] = true;
        }
    }

    /**
     * @param string|int|float|bool|Type|UnionType|FQSEN ...$parameters
     * Template parameters for the issue's error message.
     * If these are objects, they should define __toString()
     */
    private function emitIssue(
        string $issue_type,
        int $lineno,
        ...$parameters
    ): void {
        Issue::maybeEmit(
            $this->code_base,
            $this->context,
            $issue_type,
            $lineno,
            ...$parameters
        );
    }

    /**
     * Handles a node of kind ast\AST_TRAIT_PRECEDENCE, modifying the corresponding TraitAdaptations instance
     * @param array<string,TraitAdaptations> $adaptations_map
     * @param Node $adaptation_node
     * @throws UnanalyzableException (should be caught and emitted as an issue)
     */
    private function handleTraitPrecedence(array $adaptations_map, Node $adaptation_node): void
    {
        // TODO: Should also verify that the original method exists, in a future PR?
        $trait_method_node = $adaptation_node->children['method'];
        if (!$trait_method_node instanceof Node) {
            throw new AssertionError("Expected node for trait use");
        }
        // $trait_chosen_class_name_node = $trait_method_node->children['class'];
        $trait_chosen_method_name = $trait_method_node->children['method'];
        $trait_chosen_class_name_node = $trait_method_node->children['class'];
        if (!is_string($trait_chosen_method_name)) {
            $this->emitIssue(
                Issue::InvalidTraitUse,
                $trait_method_node->lineno ?? 0,
                "Expected the insteadof method's name to be a string"
            );
            return;
        }

        try {
            $trait_chosen_fqsen = (new ContextNode(
                $this->code_base,
                $this->context,
                $trait_chosen_class_name_node
            ))->getTraitFQSEN($adaptations_map);
        } catch (FQSENException $e) {
            $this->emitIssue(
                Issue::InvalidTraitUse,
                $trait_method_node->lineno ?? 0,
                $e->getMessage()
            );
            return;
        }


        if (!$trait_chosen_fqsen) {
            throw new UnanalyzableException(
                $trait_chosen_class_name_node,
                "This shouldn't happen. Could not determine trait fqsen for trait with higher precedence for method $trait_chosen_method_name"
            );
        }

        if (($adaptations_map[\strtolower($trait_chosen_fqsen->__toString())] ?? null) === null) {
            // This will probably correspond to a PHP fatal error, but keep going anyway.
            $this->emitIssue(
                Issue::RequiredTraitNotAdded,
                $trait_chosen_class_name_node->lineno ?? 0,
                $trait_chosen_fqsen->__toString()
            );
        }

        // This is the class which will have the method hidden
        foreach ($adaptation_node->children['insteadof']->children as $trait_insteadof_class_name) {
            if (!$trait_insteadof_class_name instanceof Node) {
                // Fallback parser issue?
                continue;
            }
            try {
                $trait_insteadof_fqsen = (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $trait_insteadof_class_name
                ))->getTraitFQSEN($adaptations_map);
            } catch (\Exception $_) {
                $trait_insteadof_fqsen = null;
            }
            if (!$trait_insteadof_fqsen) {
                throw new UnanalyzableException(
                    $trait_insteadof_class_name,
                    "This shouldn't happen. Could not determine trait fqsen for trait with lower precedence for method $trait_chosen_method_name"
                );
            }

            $fqsen_key = \strtolower($trait_insteadof_fqsen->__toString());

            $adaptations_info = $adaptations_map[$fqsen_key] ?? null;
            if ($adaptations_info === null) {
                // TODO: Make this into an issue type
                $this->emitIssue(
                    Issue::RequiredTraitNotAdded,
                    $trait_insteadof_class_name->lineno ?? 0,
                    $trait_insteadof_fqsen->__toString()
                );
                continue;
            }
            $adaptations_info->hidden_methods[strtolower($trait_chosen_method_name)] = true;
        }
    }

    /**
     * @return string
     * A variable name associated with the given node
     *
     * TODO: Deprecate this and use more precise ways to locate the desired element
     * TODO: Distinguish between the empty string and the lack of a name
     */
    public function getVariableName(): string
    {
        if (!($this->node instanceof Node)) {
            return (string)$this->node;
        }

        $node = $this->node;

        while (($node instanceof Node)
            && ($node->kind !== ast\AST_VAR)
            && ($node->kind !== ast\AST_STATIC)
            && ($node->kind !== ast\AST_MAGIC_CONST)
        ) {
            $node = \reset($node->children);
        }

        if (!($node instanceof Node)) {
            return (string)$node;
        }

        $name_node = $node->children['name'] ?? '';
        if ($name_node === '') {
            return '';
        }

        if ($name_node instanceof Node) {
            // This is nonsense. Give up, but check if it's a type other than int/string.
            // (e.g. to catch typos such as $$this->foo = bar;)
            try {
                $name_node_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $name_node, true);
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
                return '';
            }
            static $int_or_string_type;
            if ($int_or_string_type === null) {
                $int_or_string_type = UnionType::fromFullyQualifiedPHPDocString('int|string|null');
            }
            if (!$name_node_type->canCastToUnionType($int_or_string_type, $this->code_base)) {
                $this->emitIssue(Issue::TypeSuspiciousIndirectVariable, $name_node->lineno ?? 0, (string)$name_node_type);
            }

            // return empty string on failure.
            return (string)$name_node_type->asSingleScalarValueOrNull();
        }

        return (string)$name_node;
    }

    // Constants for getClassList() API
    public const CLASS_LIST_ACCEPT_ANY = 0;
    public const CLASS_LIST_ACCEPT_OBJECT = 1;
    public const CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME = 2;

    /**
     * @return array{0:UnionType,1:Clazz[]}
     * @throws CodeBaseException if $ignore_missing_classes == false
     */
    public function getClassListInner(bool $ignore_missing_classes): array
    {
        $node = $this->node;
        if (!($node instanceof Node)) {
            if (\is_string($node)) {
                return [LiteralStringType::instanceForValue($node, false)->asRealUnionType(), []];
            }
            return [UnionType::empty(), []];
        }
        $context = $this->context;
        $node_id = \spl_object_id($node);

        $cached_result = $context->getCachedClassListOfNode($node_id);
        if ($cached_result) {
            // About 25% of requests are cache hits
            return $cached_result;
        }
        $code_base = $this->code_base;
        try {
            $union_type = UnionTypeVisitor::unionTypeFromClassNode(
                $code_base,
                $context,
                $node
            );
        } catch (FQSENException $e) {
            $this->emitIssue(
                $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                $this->node->lineno ?? $context->getLineNumberStart(),
                $e->getFQSEN()
            );
            $union_type = UnionType::empty();
        }
        if ($union_type->isEmpty()) {
            $result = [$union_type, []];
            $context->setCachedClassListOfNode($node_id, $result);
            return $result;
        }

        $class_list = [];
        try {
            foreach ($union_type->asClassList(
                $code_base,
                $context
            ) as $clazz) {
                $class_list[] = $clazz;
            }
            $result = [$union_type, $class_list];
            $context->setCachedClassListOfNode($node_id, $result);
            return $result;
        } catch (CodeBaseException $e) {
            if ($ignore_missing_classes) {
                // swallow it
                // TODO: Is it appropriate to return class_list
                return [$union_type, $class_list];
            }

            throw $e;
        }
    }
    /**
     * @param bool $ignore_missing_classes
     * If set to true, missing classes will be ignored and
     * exceptions will be inhibited
     *
     * @param int $expected_type_categories
     * If set to CLASS_LIST_ACCEPT_ANY, this will not warn.
     * If set to CLASS_LIST_ACCEPT_OBJECT, this will warn if the inferred type is exclusively non-object types. This will not add classes based on LiteralStringType
     * If set to CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME, this will warn if the inferred type is exclusively non-object and non-string types.
     *
     * @param ?string $custom_issue_type
     * If this exists, emit the given issue type (passing in the class's union type as format arg) instead of the default issue type.
     * The issue type passed in must have exactly one template string parameter (e.g. {CLASS}, {TYPE})
     *
     * @return list<Clazz>
     * A list of classes representing the non-native types
     * associated with the given node
     *
     * @throws CodeBaseException
     * An exception is thrown if a non-native type does not have
     * an associated class
     *
     * @throws IssueException
     * An exception is thrown if fetching the requested class name
     * would trigger an issue (e.g. Issue::ContextNotObject)
     */
    public function getClassList(
        bool $ignore_missing_classes = false,
        int $expected_type_categories = self::CLASS_LIST_ACCEPT_ANY,
        ?string $custom_issue_type = null,
        bool $warn_if_wrong_type = true
    ): array {
        [$union_type, $class_list] = $this->getClassListInner($ignore_missing_classes);
        if ($union_type->isEmpty()) {
            return [];
        }

        if (\count($class_list) === 0) {
            if (!$union_type->hasTypeMatchingCallback(function (Type $type) use ($expected_type_categories): bool {
                if ($this->node instanceof Node) {
                    if ($this->node->kind === ast\AST_NAME) {
                        return $type->isObjectWithKnownFQSEN();
                    }
                    if ($this->node->kind === ast\AST_TYPE) {
                        return $this->node->flags !== ast\flags\TYPE_STATIC;
                    }
                }
                return $type->isObject() || ($type instanceof MixedType) || ($expected_type_categories !== self::CLASS_LIST_ACCEPT_OBJECT && $type instanceof StringType);
            })) {
                if ($warn_if_wrong_type) {
                    if ($custom_issue_type === Issue::TypeExpectedObjectPropAccess) {
                        if ($union_type->isType(NullType::instance(false))) {
                            $custom_issue_type = Issue::TypeExpectedObjectPropAccessButGotNull;
                        }
                    }
                    $this->emitIssue(
                        $custom_issue_type ?? ($expected_type_categories !== self::CLASS_LIST_ACCEPT_OBJECT ? Issue::TypeExpectedObjectOrClassName : Issue::TypeExpectedObject),
                        $this->node->lineno ?? $this->context->getLineNumberStart(),
                        ASTReverter::toShortString($this->node),
                        (string)$union_type->asNonLiteralType()
                    );
                }
            } elseif ($expected_type_categories !== self::CLASS_LIST_ACCEPT_OBJECT) {
                foreach ($union_type->getTypeSet() as $type) {
                    if ($type instanceof LiteralStringType) {
                        $type_value = $type->getValue();
                        try {
                            $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($type_value);
                            if ($this->code_base->hasClassWithFQSEN($fqsen)) {
                                $class_list[] = $this->code_base->getClassByFQSEN($fqsen);
                            } elseif ($warn_if_wrong_type) {
                                $this->emitIssue(
                                    Issue::UndeclaredClass,
                                    $this->node->lineno ?? $this->context->getLineNumberStart(),
                                    (string)$fqsen
                                );
                            }
                        } catch (FQSENException $e) {
                            if ($warn_if_wrong_type) {
                                $this->emitIssue(
                                    $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                                    $this->node->lineno ?? $this->context->getLineNumberStart(),
                                    $e->getFQSEN()
                                );
                            }
                        }
                    }
                }
            }
        }

        return $class_list;
    }

    /**
     * Returns only the first method found that could possibly correspond to this callsite.
     *
     * @param Node|string $method_name
     * Either then name of the method or a node that
     * produces the name of the method.
     *
     * @param bool $is_static
     * Set to true if this is a static method call
     *
     * @param bool $is_direct @phan-mandatory-param
     * Set to true if this is directly invoking the method (guaranteed not to be special syntax)
     *
     * @param bool $is_new_expression
     * Set to true if this is (new (expr)())
     *
     * @return Method
     * A method with the given name on the class referenced
     * from the given node
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * method
     *
     * @throws IssueException
     */
    public function getMethod(
        $method_name,
        bool $is_static,
        bool $is_direct = false,
        bool $is_new_expression = false
    ): Method {
        return $this->getMethodListInternal(
            $method_name,
            $is_static,
            $is_direct,
            $is_new_expression,
            true
        )[0];
    }

    /**
     * Returns the full list of methods that could possibly correspond to this callsite.
     *
     * @param Node|string $method_name
     * Either then name of the method or a node that
     * produces the name of the method.
     *
     * @param bool $is_static
     * Set to true if this is a static method call
     *
     * @param bool $is_direct @phan-mandatory-param
     * Set to true if this is directly invoking the method (guaranteed not to be special syntax)
     *
     * @param bool $is_new_expression
     * Set to true if this is (new (expr)())
     *
     * @return list<Method>
     * A list of methods with the given name on the classes referenced
     * from the given node
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * method
     *
     * @throws IssueException
     */
    public function getMethodList(
        $method_name,
        bool $is_static,
        bool $is_direct = false,
        bool $is_new_expression = false
    ): array {
        return $this->getMethodListInternal(
            $method_name,
            $is_static,
            $is_direct,
            $is_new_expression,
            false
        );
    }

    /**
     * @param Node|string $method_name
     * Either then name of the method or a node that
     * produces the name of the method.
     *
     * @param bool $is_static
     * Set to true if this is a static method call
     *
     * @param bool $is_direct
     * Set to true if this is directly invoking the method (guaranteed not to be special syntax)
     *
     * @param bool $is_new_expression
     * Set to true if this is (new (expr)())
     *
     * @param bool $should_return_first_match
     * Set to true to short circuit and return only the first method found.
     *
     * @return list<Method>
     * A list of methods with the given name on the classes referenced
     * from the given node
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * method
     *
     * @throws IssueException
     */
    private function getMethodListInternal(
        $method_name,
        bool $is_static,
        bool $is_direct,
        bool $is_new_expression,
        bool $should_return_first_match
    ): array {

        if ($method_name instanceof Node) {
            $method_name_type = UnionTypeVisitor::unionTypeFromNode(
                $this->code_base,
                $this->context,
                $method_name
            );
            $methods = [];
            foreach ($method_name_type->getTypeSet() as $type) {
                if ($type instanceof LiteralStringType) {
                    $these_methods = [];
                    try {
                        // TODO: Warn about nullable?
                        $these_methods = $this->getMethodListInternal(
                            $type->getValue(),
                            $is_static,
                            $is_direct,
                            $is_new_expression,
                            $should_return_first_match
                        );
                    } catch (Exception $e) {
                        if ($should_return_first_match) {
                            throw $e;
                        }
                        // When returning the full method list, don't let an exception handling
                        // some elements of the list prevent returning the rest. Swallow the
                        // exception.
                    }

                    if ($should_return_first_match) {
                        return $these_methods;
                    } else {
                        $methods = array_merge($methods, $these_methods);
                    }
                }
            }

            if (!$methods) {
                // The method_name turned out to be a variable.
                // There isn't much we can do to figure out what
                // it's referring to.
                throw new NodeException(
                    $method_name,
                    "Unexpected method node"
                );
            } else {
                return $methods;
            }
        }

        if (!\is_string($method_name)) {
            throw new AssertionError("Method name must be a string. Found non-string in context.");
        }

        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$node must be a node');
        }

        try {
            // Fetch the list of valid classes, and warn about any undefined classes.
            // (We have more specific issue types such as PhanNonClassMethodCall below, don't emit PhanTypeExpected*)
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['expr']
                    ?? $node->children['class']
            ))->getClassList(
                false,
                $is_static || $is_new_expression ? self::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME : self::CLASS_LIST_ACCEPT_OBJECT,
                null,
                $is_new_expression  // emit warnings about the class if this is for `new $className`
            );
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassMethod)(
                    $this->context->getFile(),
                    $node->lineno,
                    [$method_name, (string)$exception_fqsen],
                    ($exception_fqsen instanceof FullyQualifiedClassName
                        ? IssueFixSuggester::suggestSimilarClassForMethod($this->code_base, $this->context, $exception_fqsen, $method_name, $is_static)
                        : null)
                )
            );
        }

        // If there were no classes on the left-type, figure
        // out what we were trying to call the method on
        // and send out an error.
        if (\count($class_list) === 0) {
            try {
                $union_type = UnionTypeVisitor::unionTypeFromClassNode(
                    $this->code_base,
                    $this->context,
                    $node->children['expr']
                        ?? $node->children['class']
                );
            } catch (FQSENException $e) {
                throw new IssueException(
                    Issue::fromType($e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike)(
                        $this->context->getFile(),
                        $node->lineno,
                        [$e->getFQSEN()]
                    )
                );
            }

            if ($union_type->isDefinitelyUndefined()
                || (!$union_type->isEmpty()
                    && $union_type->isNativeType()
                    && !$union_type->hasTypeMatchingCallback(static function (Type $type): bool {
                        return !$type->isNullableLabeled() && ($type instanceof MixedType || $type instanceof ObjectType);
                    })
                    // reject `$stringVar->method()` but not `$stringVar::method()` and not (`new $stringVar()`
                    && !(($is_static || $is_new_expression) && $union_type->hasNonNullStringType())
                    && !(
                        Config::get_null_casts_as_any_type()
                        && $union_type->hasType(NullType::instance(false))
                ))
            ) {
                throw new IssueException(
                    Issue::fromType(Issue::NonClassMethodCall)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ $method_name, (string)$union_type ]
                    )
                );
            }

            throw new NodeException(
                $node,
                "Can't figure out method call for $method_name"
            );
        }
        $class_without_method = null;
        $methods = [];
        $call_methods = [];
        $last_call_method = null;

        // Hunt to see if any of them have the method we're
        // looking for
        foreach ($class_list as $class) {
            if ($class->hasMethodWithName($this->code_base, $method_name, $is_direct)) {
                if ($should_return_first_match && $methods) {
                    // TODO: Could favor the most generic subclass in a union type
                    continue;
                }
                $method = $class->getMethodByName($this->code_base, $method_name);
                if ($method->hasTemplateType()) {
                    try {
                        $method = $method->resolveTemplateType(
                            $this->code_base,
                            UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $node->children['expr'] ?? $node->children['class'])
                        );
                    } catch (RecursionDepthException $_) {
                    }
                }
                $methods[] = $method;
            } elseif (!$is_static && $class->allowsCallingUndeclaredInstanceMethod($this->code_base)) {
                $last_call_method = $class->getCallMethod($this->code_base);
                $call_methods[] = $last_call_method;
            } elseif ($is_static && $class->allowsCallingUndeclaredStaticMethod($this->code_base)) {
                $last_call_method = $class->getCallStaticMethod($this->code_base);
                $call_methods[] = $last_call_method;
            } else {
                $class_without_method = $class->getFQSEN();
            }
        }

        // I don't understand what the isFakeConstructor clause is for, but I want to preserve the pre-existing
        // behavior when $should_return_first_match is true.
        if ($should_return_first_match && (!$methods || ($is_direct && $methods[0]->isFakeConstructor()))) {
            if ($last_call_method) {
                $methods[] = $last_call_method;
            } else {
                $methods = [];
            }
        } else {
            $methods = array_merge($methods, $call_methods);
        }
        if ($methods) {
            if (
                $class_without_method && Config::get_strict_method_checking() &&
                !$this->isDefinitelyPossiblyUndeclaredMethod($node, $method_name, $is_direct)
            ) {
                $this->emitIssue(
                    Issue::PossiblyUndeclaredMethod,
                    $node->lineno,
                    $method_name,
                    implode('|', \array_map(static function (Clazz $class): string {
                        return $class->getFQSEN()->__toString();
                    }, $class_list)),
                    $class_without_method
                );
            }
            return $methods;
        }

        $first_class = $class_list[0];

        // Figure out an FQSEN for the method we couldn't find
        $method_fqsen = FullyQualifiedMethodName::make(
            $first_class->getFQSEN(),
            $method_name
        );

        if ($is_static) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredStaticMethod)(
                    $this->context->getFile(),
                    $node->lineno,
                    [ (string)$method_fqsen ],
                    IssueFixSuggester::suggestSimilarMethod($this->code_base, $this->context, $first_class, $method_name, $is_static)
                )
            );
        }

        throw new IssueException(
            Issue::fromType(Issue::UndeclaredMethod)(
                $this->context->getFile(),
                $node->lineno,
                [ (string)$method_fqsen ],
                IssueFixSuggester::suggestSimilarMethod($this->code_base, $this->context, $first_class, $method_name, $is_static)
            )
        );
    }

    /**
     * @throws IssueException
     */
    private function isDefinitelyPossiblyUndeclaredMethod(Node $node, string $method_name, bool $is_direct): bool
    {
        try {
            $union_type = UnionTypeVisitor::unionTypeFromClassNode(
                $this->code_base,
                $this->context,
                $node->children['expr']
                    ?? $node->children['class']
            );
        } catch (FQSENException $e) {
            throw new IssueException(
                Issue::fromType($e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike)(
                    $this->context->getFile(),
                    $node->lineno,
                    [$e->getFQSEN()]
                )
            );
        }
        // Typically, this should only return false for intersection types that include a mix of types that have and don't have the method.
        foreach ($union_type->getTypeSet() as $type) {
            if (!$type->hasObjectWithKnownFQSEN()) {
                continue;
            }
            foreach ($type->asPHPDocUnionType()->asClassList($this->code_base, $this->context) as $class) {
                if ($class->hasMethodWithName($this->code_base, $method_name, $is_direct)) {
                    continue 2;
                }
            }
            // Part of the union type includes a type or intersection type that does not have that method.
            return false;
        }
        return true;
    }

    /**
     * Yields a list of FunctionInterface objects for the 'expr' of an AST_CALL.
     * @return iterable<mixed, FunctionInterface>
     */
    public function getFunctionFromNode(bool $return_placeholder_for_undefined = false): iterable
    {
        $expression = $this->node;
        if (!($expression instanceof Node)) {
            if (!\is_string($expression)) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    Issue::TypeInvalidCallable,
                    $this->context->getLineNumberStart(),
                    (string)$expression
                );
            }
            // TODO: this might need to account for 'myFunction'()
            return [];
        }
        if ($expression->kind === ast\AST_NAME) {
            $name = $expression->children['name'];
            try {
                if (!\is_string($name)) {
                    // Impossible?
                    Issue::maybeEmit(
                        $this->code_base,
                        $this->context,
                        Issue::TypeInvalidCallable,
                        $this->context->getLineNumberStart(),
                        '(unexpectedly unknown name)'
                    );
                    return [];
                }
                return [
                    $this->getFunction($name, false, $return_placeholder_for_undefined),
                ];
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $this->code_base,
                    $this->context,
                    $exception->getIssueInstance()
                );
            } catch (FQSENException $exception) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    $exception instanceof EmptyFQSENException ? Issue::EmptyFQSENInCallable : Issue::InvalidFQSENInCallable,
                    $expression->lineno,
                    $exception->getFQSEN()
                );
            }
            return [];
        }
        // The least common case: A dynamic function call such as $x(), (self::$x)(), etc.
        return $this->getFunctionLikeFromDynamicExpression();
    }

    /**
     * Yields a list of FunctionInterface objects for the 'expr' of an AST_CALL.
     * Precondition: expr->kind !== ast\AST_NAME
     *
     * @return \Generator<void, FunctionInterface, void, void>
     */
    private function getFunctionLikeFromDynamicExpression(): \Generator
    {
        $code_base = $this->code_base;
        $context = $this->context;
        $expression = $this->node;
        $union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $expression)->withStaticResolvedInContext($context);
        if ($union_type->isEmpty()) {
            return;
        }

        $has_type = false;
        foreach ($union_type->getTypeSet() as $type) {
            $func = $type->asFunctionInterfaceOrNull($code_base, $context);
            if ($func) {
                yield $func;
                $has_type = true;
            }
        }
        if (!$has_type) {
            if (!$union_type->hasPossiblyCallableType($code_base)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::TypeInvalidCallable,
                    $expression->lineno ?? $context->getLineNumberStart(),
                    $union_type
                );
                return;
            }
        }
        if (Config::get_strict_method_checking() && $union_type->containsDefiniteNonCallableType($code_base)) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::TypePossiblyInvalidCallable,
                $expression->lineno ?? $context->getLineNumberStart(),
                $union_type
            );
        }
    }

    /**
     * @throws IssueException for PhanUndeclaredFunction to be caught and reported by the caller
     */
    private function returnStubOrThrowUndeclaredFunctionIssueException(
        FullyQualifiedFunctionName $function_fqsen,
        bool $suggest_in_global_namespace,
        ?FullyQualifiedFunctionName $namespaced_function_fqsen = null,
        bool $return_placeholder_for_undefined = false
    ): Func {
        if ($return_placeholder_for_undefined) {
            $functions = $this->code_base->getPlaceholdersForUndeclaredFunction($function_fqsen);
            Issue::maybeEmitWithParameters(
                $this->code_base,
                $this->context,
                Issue::UndeclaredFunction,
                $this->node->lineno ?? $this->context->getLineNumberStart(),
                [ "$function_fqsen()" ],
                IssueFixSuggester::suggestSimilarGlobalFunction($this->code_base, $this->context, $namespaced_function_fqsen ?? $function_fqsen, $suggest_in_global_namespace)
            );
            if ($functions) {
                return $functions[0];
            }
        }
        throw new IssueException(
            Issue::fromType(Issue::UndeclaredFunction)(
                $this->context->getFile(),
                $this->node->lineno ?? $this->context->getLineNumberStart(),
                [ "$function_fqsen()" ],
                IssueFixSuggester::suggestSimilarGlobalFunction($this->code_base, $this->context, $namespaced_function_fqsen ?? $function_fqsen, $suggest_in_global_namespace)
            )
        );
    }

    /**
     * @param string $function_name
     * The name of the function we'd like to look up
     *
     * @param bool $is_function_declaration
     * This must be set to true if we're getting a function
     * that is being declared and false if we're getting a
     * function being called.
     *
     * @param bool $return_placeholder_for_undefined
     * When this is true, Phan will create a placeholder
     * for undefined functions so that argument counts and
     * types can be checked.
     *
     * @return FunctionInterface
     * A method with the given name in the given context
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * function
     *
     * @throws FQSENException
     * An exception is thrown if the FQSEN being requested
     * was determined but was invalid/empty
     */
    public function getFunction(
        string $function_name,
        bool $is_function_declaration = false,
        bool $return_placeholder_for_undefined = false
    ): FunctionInterface {

        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }
        $code_base = $this->code_base;
        $context = $this->context;
        $namespace = $context->getNamespace();
        $flags = $node->flags;
        // TODO: support namespace aliases for functions
        if ($is_function_declaration) {
            $function_fqsen = FullyQualifiedFunctionName::make($namespace, $function_name);
            if ($code_base->hasFunctionWithFQSEN($function_fqsen)) {
                return $code_base->getFunctionByFQSEN($function_fqsen);
            }
        } elseif (($flags & ast\flags\NAME_RELATIVE) !== 0) {
            // For relative functions (e.g. namespace\foo())
            $function_fqsen = FullyQualifiedFunctionName::make($namespace, $function_name);
            if (!$code_base->hasFunctionWithFQSEN($function_fqsen)) {
                return $this->returnStubOrThrowUndeclaredFunctionIssueException($function_fqsen, false, null, $return_placeholder_for_undefined);
            }
            return $code_base->getFunctionByFQSEN($function_fqsen);
        } else {
            if (($flags & ast\flags\NAME_NOT_FQ) !== 0) {
                if ($context->hasNamespaceMapFor(\ast\flags\USE_FUNCTION, $function_name)) {
                    // If we already have `use function function_name;`
                    $function_fqsen = $context->getNamespaceMapFor(\ast\flags\USE_FUNCTION, $function_name);
                    if (!($function_fqsen instanceof FullyQualifiedFunctionName)) {
                        throw new AssertionError("Expected to fetch a fully qualified function name for this namespace use");
                    }

                    // Make sure the method we're calling actually exists
                    if (!$code_base->hasFunctionWithFQSEN($function_fqsen)) {
                        // The FQSEN from 'use MyNS\function_name;' was the only possible fqsen for that function.
                        return $this->returnStubOrThrowUndeclaredFunctionIssueException($function_fqsen, false, null, $return_placeholder_for_undefined);
                    }

                    return $code_base->getFunctionByFQSEN($function_fqsen);
                }
                // For relative and non-fully qualified functions (e.g. namespace\foo(), foo())
                $function_fqsen = FullyQualifiedFunctionName::make($namespace, $function_name);

                if ($code_base->hasFunctionWithFQSEN($function_fqsen)) {
                    return $code_base->getFunctionByFQSEN($function_fqsen);
                }
                if ($namespace === '' || \strpos($function_name, '\\') !== false) {
                    return $this->returnStubOrThrowUndeclaredFunctionIssueException($function_fqsen, false, null, $return_placeholder_for_undefined);
                }
                // If it doesn't exist in the local namespace, try it
                // in the global namespace
            }
            $function_fqsen =
                FullyQualifiedFunctionName::make(
                    '',
                    $function_name
                );
        }

        // Make sure the method we're calling actually exists
        if (!$code_base->hasFunctionWithFQSEN($function_fqsen)) {
            $not_fully_qualified = (bool)($flags & ast\flags\NAME_NOT_FQ);
            return $this->returnStubOrThrowUndeclaredFunctionIssueException(
                $function_fqsen,
                $not_fully_qualified,
                $not_fully_qualified ? FullyQualifiedFunctionName::make($namespace, $function_name) : $function_fqsen,
                $return_placeholder_for_undefined
            );
        }

        return $code_base->getFunctionByFQSEN($function_fqsen);
    }

    /**
     * @return Variable
     * A variable in scope.
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An IssueException is thrown if the variable doesn't
     * exist
     */
    public function getVariable(): Variable
    {
        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        // Get the name of the variable
        $variable_name = $this->getVariableName();

        if ($variable_name === '') {
            throw new NodeException(
                $node,
                "Variable name not found"
            );
        }

        // Check to see if the variable exists in this scope
        if (!$this->context->getScope()->hasVariableWithName($variable_name)) {
            if (Variable::isHardcodedVariableInScopeWithName($variable_name, $this->context->isInGlobalScope())) {
                // We return a clone of the global or superglobal variable
                // that can't be used to influence the type of that superglobal in other files.
                return new Variable(
                    $this->context,
                    $variable_name,
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                    Variable::getUnionTypeOfHardcodedGlobalVariableWithName($variable_name),
                    0
                );
            }

            throw new IssueException(
                Issue::fromType(Variable::chooseIssueForUndeclaredVariable($this->context, $variable_name))(
                    $this->context->getFile(),
                    $node->lineno,
                    [ $variable_name ],
                    IssueFixSuggester::suggestVariableTypoFix($this->code_base, $this->context, $variable_name)
                )
            );
        }

        return $this->context->getScope()->getVariableByName(
            $variable_name
        );
    }

    /**
     * @return Variable
     * A variable in scope
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An IssueException is thrown if the variable doesn't
     * exist
     */
    public function getVariableStrict(): Variable
    {
        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        if ($node->kind === ast\AST_VAR) {
            $variable_name = $node->children['name'];

            if (!is_string($variable_name)) {
                throw new NodeException(
                    $node,
                    "Variable name not found"
                );
            }

            // Check to see if the variable exists in this scope
            $scope = $this->context->getScope();
            if (!$scope->hasVariableWithName($variable_name)) {
                if (Variable::isHardcodedVariableInScopeWithName($variable_name, $this->context->isInGlobalScope())) {
                    // We return a clone of the global or superglobal variable
                    // that can't be used to influence the type of that superglobal in other files.
                    return new Variable(
                        $this->context,
                        $variable_name,
                        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                        Variable::getUnionTypeOfHardcodedGlobalVariableWithName($variable_name),
                        0
                    );
                }

                throw new IssueException(
                    Issue::fromType(Variable::chooseIssueForUndeclaredVariable($this->context, $variable_name))(
                        $this->context->getFile(),
                        $node->lineno,
                        [ $variable_name ],
                        IssueFixSuggester::suggestVariableTypoFix($this->code_base, $this->context, $variable_name)
                    )
                );
            }

            return $scope->getVariableByName(
                $variable_name
            );
        }
        throw new NodeException($node, 'Not a variable node');
    }

    /**
     * @return Variable
     * A variable in scope or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @unused
     * @suppress PhanUnreferencedPublicMethod
     * @see self::getOrCreateVariableForReferenceParameter() - That is probably what you want instead.
     */
    public function getOrCreateVariable(): Variable
    {
        try {
            return $this->getVariable();
        } catch (IssueException $_) {
            // Swallow it
        }

        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        // Create a new variable
        $variable = Variable::fromNodeInContext(
            $node,
            $this->context,
            $this->code_base,
            false
        );

        $this->context->addScopeVariable($variable);

        return $variable;
    }

    /**
     * @param Parameter $parameter the parameter types inferred from combination of real and union type
     *
     * @param ?Parameter $real_parameter the real parameter type from the type signature
     *
     * @return Variable
     * A variable in scope for the argument to that reference parameter, or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     */
    public function getOrCreateVariableForReferenceParameter(Parameter $parameter, ?Parameter $real_parameter): Variable
    {
        // Return the original variable if it existed
        try {
            $variable = $this->getVariable();
            $union_type = $variable->getUnionType();
            if ($union_type->isPossiblyUndefined()) {
                $variable->setUnionType($union_type->convertUndefinedToNullable());
            }
            return $variable;
        } catch (IssueException $_) {
            // Swallow exceptions fetching the variable
        }
        // Create a new variable, and set its union type to null if that wouldn't create false positives.

        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        // Create a new variable
        $variable = Variable::fromNodeInContext(
            $node,
            $this->context,
            $this->code_base,
            false
        );
        if ($parameter->getReferenceType() === Parameter::REFERENCE_READ_WRITE ||
            ($real_parameter && !$real_parameter->getNonVariadicUnionType()->containsNullableOrIsEmpty())) {
            static $null_type = null;
            if ($null_type === null) {
                $null_type = NullType::instance(false)->asPHPDocUnionType();
            }
            // If this is a variable that is both read and written,
            // then set the previously undefined variable type to null instead so we can type check it
            // (e.g. arguments to array_shift())
            //
            // Also, if this has a real type signature that would make PHP throw a TypeError when passed null, then set this to null so the type checker will emit a warning (#1344)
            //
            // (TODO: read/writeable is currently only possible to annotate for internal functions in FunctionSignatureMap.php),

            // TODO: How should this handle variadic references?
            $variable->setUnionType($null_type);
        }

        $this->context->addScopeVariable($variable);

        return $variable;
    }

    /**
     * Returns only the first property found that could possibly correspond to this callsite.
     *
     * @param bool $is_static
     * True if we're looking for a static property,
     * false if we're looking for an instance property.
     *
     * @return Property
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * class or if we don't have access to the property (its
     * private or protected)
     * or if the property is static and missing.
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     */
    public function getProperty(
        bool $is_static,
        bool $is_known_assignment = false
    ): Property {
        return $this->getPropertyListInternal($is_static, $is_known_assignment, true)[0];
    }

    /**
     * Returns the full list of properties that could possibly correspond to this callsite.
     *
     * @param bool $is_static
     * True if we're looking for a static property,
     * false if we're looking for an instance property.
     *
     * @return list<Property>
     * A list of properties with the given name on the classes referenced
     * from the given node.
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * class or if we don't have access to the property (its
     * private or protected)
     * or if the property is static and missing.
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     */
    public function getPropertyList(
        bool $is_static,
        bool $is_known_assignment = false
    ): array {
        return $this->getPropertyListInternal($is_static, $is_known_assignment, false);
    }

    /**
     * @param bool $is_static
     * True if we're looking for a static property,
     * false if we're looking for an instance property.
     *
     * @param bool $should_return_first_match
     * Set to true to short circuit and return only the first method found.
     *
     * @return list<Property>
     * A list of properties with the given name on the classes referenced
     * from the given node.
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * class or if we don't have access to the property (its
     * private or protected)
     * or if the property is static and missing.
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     */
    private function getPropertyListInternal(
        bool $is_static,
        bool $is_known_assignment,
        bool $should_return_first_match
    ): array {
        $node = $this->node;

        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        $property_name = $node->children['prop'];

        // Give up for things like C::$prop_name
        if (!\is_string($property_name)) {
            if ($property_name instanceof Node) {
                $property_name = UnionTypeVisitor::anyStringLiteralForNode($this->code_base, $this->context, $property_name);
            } else {
                $property_name = (string)$property_name;
            }
            if (!\is_string($property_name)) {
                $this->throwExceptionForInvalidPropertyName($node, $is_static);
            }
        }

        $class_fqsen = null;

        try {
            $expected_type_categories = $is_static ? self::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME : self::CLASS_LIST_ACCEPT_OBJECT;
            $expected_issue = $is_static ? Issue::TypeExpectedObjectStaticPropAccess : Issue::TypeExpectedObjectPropAccess;
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['expr'] ??
                    $node->children['class']
            ))->getClassList(false, $expected_type_categories, $expected_issue);
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            if ($exception_fqsen instanceof FullyQualifiedClassName) {
                throw new IssueException(
                    Issue::fromType($is_static ? Issue::UndeclaredClassStaticProperty : Issue::UndeclaredClassProperty)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ $property_name, $exception_fqsen ],
                        IssueFixSuggester::suggestSimilarClassForGenericFQSEN($this->code_base, $this->context, $exception_fqsen)
                    )
                );
            }
            // TODO: Is this ever used? The undeclared property issues should instead be caused by the hasPropertyWithFQSEN checks below.
            if ($is_static) {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredStaticProperty)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ $property_name, (string)$exception->getFQSEN() ]
                    )
                );
            } else {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredProperty)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ "{$exception->getFQSEN()}->$property_name" ]
                    )
                );
            }
        }

        $class_without_property = null;
        $property = null;
        $properties = [];
        foreach ($class_list as $class) {
            $class_fqsen = $class->getFQSEN();

            // Keep hunting if this class doesn't have the given
            // property
            if (!$class->hasPropertyWithName(
                $this->code_base,
                $property_name
            )) {
                // (if fetching an instance property)
                // If there's a getter on properties then all
                // bets are off. However, @phan-forbid-undeclared-magic-properties
                // will make this method analyze the code as if all properties were declared or had @property annotations.
                if (
                    !$is_static && $class->hasGetMethod($this->code_base) &&
                    !$class->getForbidUndeclaredMagicProperties($this->code_base) &&
                    $should_return_first_match
                ) {
                    // Regarding the `$should_return_first_match` clause: when returning the full property list,
                    // don't let an exception handling some elements of the list prevent returning the rest.
                    // Swallow the exception.
                    throw new UnanalyzableMagicPropertyException(
                        $node,
                        $class,
                        $property_name,
                        "Can't determine if property {$property_name} exists in class {$class->getFQSEN()} with __get defined"
                    );
                }

                $class_without_property = $class;
                continue;
            }
            if ($properties && $should_return_first_match) {
                continue;
            }

            try {
                $property = $class->getPropertyByNameInContext(
                    $this->code_base,
                    $property_name,
                    $this->context,
                    $is_static,
                    $node,
                    $is_known_assignment
                );
            } catch (Exception $e) {
                if ($should_return_first_match) {
                    throw $e;
                } else {
                    // When returning the full property list, don't let an exception handling
                    // some elements of the list prevent returning the rest. Swallow the
                    // exception.
                    continue;
                }
            }

            $properties[] = $property;

            if ($property->isDeprecated()) {
                $this->emitIssue(
                    Issue::DeprecatedProperty,
                    $node->lineno,
                    $property->getRepresentationForIssue(),
                    $property->getFileRef()->getFile(),
                    $property->getFileRef()->getLineNumberStart(),
                    $property->getDeprecationReason()
                );
            }

            if ($property->isNSInternal($this->code_base)
                && !$property->isNSInternalAccessFromContext(
                    $this->code_base,
                    $this->context
                )
            ) {
                $this->emitIssue(
                    Issue::AccessPropertyInternal,
                    $node->lineno,
                    $property->getRepresentationForIssue(),
                    $property->getElementNamespace() ?: '\\',
                    $property->getFileRef()->getFile(),
                    $property->getFileRef()->getLineNumberStart(),
                    $this->context->getNamespace() ?: '\\'
                );
            }
        }
        if (!$is_static && Config::get_strict_object_checking() &&
                !($node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF)) {
            self::checkPossiblyUndeclaredInstanceProperty($this->code_base, $this->context, $node, $property_name);
        }
        if ($properties) {
            if ($class_without_property && Config::get_strict_object_checking() &&
                    !($node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF)) {
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
            return $properties;
        }

        // Since we didn't find the property on any of the
        // possible classes, check for classes with dynamic
        // properties
        if (!$is_static) {
            foreach ($class_list as $class) {
                if (Config::getValue('allow_missing_properties')
                    || $class->hasDynamicProperties($this->code_base)
                ) {
                    $properties[] = $class->getPropertyByNameInContext(
                        $this->code_base,
                        $property_name,
                        $this->context,
                        $is_static,
                        $node,
                        $is_known_assignment
                    );
                    if ($should_return_first_match) {
                        break;
                    }
                }
            }
            if ($properties) {
                return $properties;
            }
        }

        // If the class isn't found, we'll get the message elsewhere
        if ($class_fqsen) {
            $suggestion = null;
            if (isset($class)) {
                $suggestion = IssueFixSuggester::suggestSimilarProperty($this->code_base, $this->context, $class, $property_name, $is_static);
            }

            if ($is_static) {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredStaticProperty)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ $property_name, (string)$class_fqsen ],
                        $suggestion
                    )
                );
            } else {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredProperty)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ "$class_fqsen->$property_name" ],
                        $suggestion
                    )
                );
            }
        }

        throw new NodeException(
            $node,
            "Cannot figure out property from {$this->context}"
        );
    }

    /**
     * Warn if the expression of an AST_PROP is possibly invalid for an instance property
     * (both for reading and for writing)
     */
    public static function checkPossiblyUndeclaredInstanceProperty(CodeBase $code_base, Context $context, Node $node, string $property_name): void
    {
        $union_type = UnionTypeVisitor::unionTypeFromNode(
            $code_base,
            $context,
            $node->children['expr']
        );
        $invalid = UnionType::empty();
        foreach ($union_type->getTypeSet() as $type) {
            if (!$type->isPossiblyObject()) {
                $invalid = $invalid->withType($type);
            } elseif ($type->isNullableLabeled()) {
                $invalid = $invalid->withType(NullType::instance(false));
            }
        }
        if (!$invalid->isEmpty()) {
            if ($node->flags & PhanAnnotationAdder::FLAG_IGNORE_NULLABLE) {
                $invalid = $invalid->nonNullableClone();
            }
            if (!$invalid->isEmpty()) {
                // XXX: Previously, this would only warn about null/nullable, not about scalars and arrays.
                // Probably to reduce false positives.
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::PossiblyUndeclaredProperty,
                    $node->lineno,
                    $property_name,
                    $union_type,
                    $invalid
                );
            }
        }
    }

    /**
     * @throws NodeException|IssueException
     * @return no-return
     */
    private function throwExceptionForInvalidPropertyName(Node $node, bool $is_static): void
    {
        $property_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $node->children['prop']);
        if ($property_type->canCastToUnionType(StringType::instance(false)->asPHPDocUnionType(), $this->code_base)) {
            // If we know it can be a string, throw a NodeException instead of a specific issue
            throw new NodeException(
                $node,
                "Cannot figure out property name"
            );
        }
        throw new IssueException(
            Issue::fromType($is_static ? Issue::TypeInvalidStaticPropertyName : Issue::TypeInvalidPropertyName)(
                $this->context->getFile(),
                $node->lineno,
                [$property_type]
            )
        );
    }

    /**
     * @return Property
     * A declared property or a newly created dynamic property.
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws UnanalyzableException
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws IssueException
     * An exception is thrown if $is_static, but the property doesn't exist.
     */
    public function getOrCreateProperty(
        string $property_name,
        bool $is_static
    ): Property {

        try {
            return $this->getProperty($is_static);
        } catch (IssueException $exception) {
            if ($is_static) {
                throw $exception;
            }
            // TODO: log types of IssueException that aren't for undeclared properties?
            // (in another PR)

            // For instance properties, ignore it,
            // because we'll create our own property
            // @phan-suppress-next-line PhanPluginDuplicateCatchStatementBody
        } catch (UnanalyzableException $exception) {
            if ($is_static) {
                throw $exception;
            }
            // For instance properties, ignore it,
            // because we'll create our own property
        }
        // Post-condition: $is_static is false, because otherwise an exception would have been thrown

        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        try {
            $expected_type_categories = /* $is_static ? self::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME : */ self::CLASS_LIST_ACCEPT_OBJECT;
            $expected_issue = /* $is_static ? Issue::TypeExpectedObjectStaticPropAccess : */ Issue::TypeExpectedObjectPropAccess;
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['expr'] ?? null
            ))->getClassList(false, $expected_type_categories, $expected_issue);
        } catch (CodeBaseException $exception) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassReference)(
                    $this->context->getFile(),
                    $node->lineno,
                    [ $exception->getFQSEN() ]
                )
            );
        }

        $class = \reset($class_list);

        if (!($class instanceof Clazz)) {
            // empty list
            throw new UnanalyzableException(
                $node,
                "Could not get class name from node"
            );
        }

        $flags = 0;
        if ($node->kind === ast\AST_STATIC_PROP) {
            $flags |= ast\flags\MODIFIER_STATIC;
        }

        $property_fqsen = FullyQualifiedPropertyName::make(
            $class->getFQSEN(),
            $property_name
        );

        // Otherwise, we'll create it
        $property = new Property(
            $this->context,
            $property_name,
            UnionType::empty(),
            $flags,
            $property_fqsen,
            UnionType::empty()
        );

        $class->addProperty($this->code_base, $property, None::instance());

        return $property;
    }

    /**
     * @return GlobalConstant
     * Get the (non-class) constant associated with this node
     * in this context
     *
     * @throws IssueException
     * should be emitted by the caller if caught.
     */
    public function getConst(): GlobalConstant
    {
        $node = $this->node;
        if (!$node instanceof Node) {
            throw new AssertionError('$node must be a node');
        }

        if ($node->kind !== ast\AST_CONST) {
            throw new AssertionError("Node must be of type ast\AST_CONST");
        }

        $constant_name = $node->children['name']->children['name'] ?? null;
        if (!\is_string($constant_name)) {
            throw new AssertionError("Can't determine constant name");
        }

        $code_base = $this->code_base;
        $context = $this->context;
        $flags = $node->children['name']->flags ?? 0;
        try {
            if (($flags & ast\flags\NAME_RELATIVE) !== 0) {
                $fqsen = FullyQualifiedGlobalConstantName::make($context->getNamespace(), $constant_name);
            } elseif (($flags & ast\flags\NAME_NOT_FQ) !== 0) {
                if ($context->hasNamespaceMapFor(\ast\flags\USE_CONST, $constant_name)) {
                    // If we already have `use const CONST_NAME;`
                    $fqsen = $context->getNamespaceMapFor(\ast\flags\USE_CONST, $constant_name);
                    if (!($fqsen instanceof FullyQualifiedGlobalConstantName)) {
                        throw new AssertionError("expected to fetch a fully qualified const name for this namespace use");
                    }

                    // the fqsen from 'use myns\const_name;' was the only possible fqsen for that const.
                } else {
                    $fqsen = FullyQualifiedGlobalConstantName::make(
                        $context->getNamespace(),
                        $constant_name
                    );

                    if (!$code_base->hasGlobalConstantWithFQSEN($fqsen)) {
                        if (\strpos($constant_name, '\\') !== false) {
                            $this->throwUndeclaredGlobalConstantIssueException($code_base, $context, $fqsen);
                        }
                        // @phan-suppress-next-line PhanAccessClassConstantInternal
                        $constant_exists_variable = $context->getScope()->getVariableByNameOrNull(ConditionVisitor::CONSTANT_EXISTS_PREFIX . \ltrim($fqsen->__toString(), '\\'));
                        if ($constant_exists_variable &&
                            !$constant_exists_variable->getUnionType()->isPossiblyUndefined() &&
                            $constant_exists_variable->getFileRef()->getFile() === $context->getFile()) {
                            return $this->createPlaceholderGlobalConstant($fqsen);
                        }
                        $fqsen = FullyQualifiedGlobalConstantName::fromFullyQualifiedString(
                            $constant_name
                        );
                    }
                }
            } else {
                // This is a fully qualified constant
                $fqsen = FullyQualifiedGlobalConstantName::fromFullyQualifiedString(
                    $constant_name
                );
            }
        } catch (FQSENException $e) {
            throw new AssertionError("Impossible FQSENException: " . $e->getMessage());
        }

        // Handle true/false/null, even for edge cases such as `use const null as somethingelse;`
        $fqsen_lower = \strtolower($fqsen->__toString());
        if (\in_array($fqsen_lower, ['\true', '\false', '\null'], true)) {
            return $code_base->getGlobalConstantByFQSEN(
                // @phan-suppress-next-line PhanThrowTypeMismatchForCall
                FullyQualifiedGlobalConstantName::fromFullyQualifiedString(
                    $fqsen_lower
                )
            );
        }

        // This is either a fully qualified constant,
        // or a relative constant for which nothing was found in the namespace

        if (!$code_base->hasGlobalConstantWithFQSEN($fqsen)) {
            // @phan-suppress-next-line PhanAccessClassConstantInternal
            $constant_exists_variable = $context->getScope()->getVariableByNameOrNull(ConditionVisitor::CONSTANT_EXISTS_PREFIX . \ltrim($fqsen->__toString(), '\\'));
            if ($constant_exists_variable && !$constant_exists_variable->getUnionType()->isPossiblyUndefined() && $constant_exists_variable->getFileRef()->getFile() === $context->getFile()) {
                return $this->createPlaceholderGlobalConstant($fqsen);
            }
            $this->throwUndeclaredGlobalConstantIssueException($code_base, $context, $fqsen);
        }

        $constant = $code_base->getGlobalConstantByFQSEN($fqsen);

        if ($constant->isNSInternal($code_base)
            && !$constant->isNSInternalAccessFromContext(
                $code_base,
                $context
            )
        ) {
            // TODO: Refactor and also check namespaced constants
            $this->emitIssue(
                Issue::AccessConstantInternal,
                $node->lineno,
                (string)$constant->getFQSEN(),
                $constant->getElementNamespace(),
                $constant->getFileRef()->getFile(),
                $constant->getFileRef()->getLineNumberStart(),
                $context->getNamespace()
            );
        }

        return $constant;
    }

    private function createPlaceholderGlobalConstant(
        FullyQualifiedGlobalConstantName $fqsen
    ): GlobalConstant {
        return new GlobalConstant(
            $this->context,
            $fqsen->getName(),
            // This can't be an object.
            UnionType::fromFullyQualifiedRealString('?array|?bool|?float|?int|?resource|?string'),
            0,
            $fqsen
        );
    }

    /**
     * @throws IssueException
     * @return never
     */
    private function throwUndeclaredGlobalConstantIssueException(CodeBase $code_base, Context $context, FullyQualifiedGlobalConstantName $fqsen): void
    {
        throw new IssueException(
            Issue::fromType(Issue::UndeclaredConstant)(
                $this->context->getFile(),
                $this->node->lineno ?? $context->getLineNumberStart(),
                [ $fqsen ],
                IssueFixSuggester::suggestSimilarGlobalConstant($code_base, $context, $fqsen)
            )
        );
    }

    /**
     * Returns only the first class constant found that could
     * possibly correspond to this callsite.
     *
     * @return ClassConstant
     * Get the first class constant associated with this node
     * in this context
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     *
     * @throws IssueException
     * An exception is thrown if an issue is found while getting
     * the list of possible classes.
     */
    public function getClassConst(): ClassConstant {
        return $this->getClassConstListInternal(true)[0];
    }

    /**
     * Returns the full list of class constants that could
     * possibly correspond to this callsite.
     *
     * @return list<ClassConstant>
     * Get the list of class constants associated with this node
     * in this context
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     *
     * @throws IssueException
     * An exception is thrown if an issue is found while getting
     * the list of possible classes.
     */
    public function getClassConstList(): array {
        return $this->getClassConstListInternal(false);
    }

    /**
     * @param bool $should_return_first_match
     * Set to true to short circuit and return only the first method found.
     *
     * @return list<ClassConstant>
     * Get the list of class constants associated with this node
     * in this context
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseException
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     *
     * @throws IssueException
     * An exception is thrown if an issue is found while getting
     * the list of possible classes.
     */
    public function getClassConstListInternal(bool $should_return_first_match): array
    {
        $node = $this->node;
        if (!($node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        $constant_name = $node->children['const'];
        if (!is_string($constant_name)) {
            throw new AssertionError('$constant_name must be a string');
        }
        if (!\strcasecmp($constant_name, 'class')) {
            $constant_name = 'class';
        }

        $class_fqsen = null;

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $node->children['class']
            ))->getClassList(false, self::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME);
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            if ($constant_name === 'class') {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredClassReference)(
                        $this->context->getFile(),
                        $node->lineno,
                        [(string)$exception_fqsen],
                        IssueFixSuggester::suggestSimilarClassForGenericFQSEN($this->code_base, $this->context, $exception_fqsen)
                    )
                );
            }
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassConstant)(
                    $this->context->getFile(),
                    $node->lineno,
                    [$constant_name, (string)$exception_fqsen],
                    IssueFixSuggester::suggestSimilarClassForGenericFQSEN($this->code_base, $this->context, $exception_fqsen)
                )
            );
        }

        $constants = [];
        foreach ($class_list as $class) {
            // Remember the last analyzed class for the next issue message
            $class_fqsen = $class->getFQSEN();

            // Check to see if the class has the constant
            if (!$class->hasConstantWithName(
                $this->code_base,
                $constant_name
            )) {
                continue;
            }

            $constant = $class->getConstantByNameInContext(
                $this->code_base,
                $constant_name,
                $this->context
            );
            $constants[] = $constant;

            if ($constant->isNSInternal($this->code_base)
                && !$constant->isNSInternalAccessFromContext(
                    $this->code_base,
                    $this->context
                )
            ) {
                $this->emitIssue(
                    Issue::AccessClassConstantInternal,
                    $node->lineno,
                    (string)$constant->getFQSEN(),
                    $constant->getFileRef()->getFile(),
                    $constant->getFileRef()->getLineNumberStart()
                );
            }
            if ($constant->isDeprecated()) {
                $this->emitIssue(
                    Issue::DeprecatedClassConstant,
                    $node->lineno,
                    (string)$constant->getFQSEN(),
                    $constant->getFileRef()->getFile(),
                    $constant->getFileRef()->getLineNumberStart(),
                    $constant->getDeprecationReason()
                );
            }

            if ($should_return_first_match) {
                return $constants;
            }
        }

        if ($constants) {
            return $constants;
        }

        // If no class is found, we'll emit the error elsewhere
        if ($class_fqsen) {
            $class_constant_fqsen = FullyQualifiedClassConstantName::make($class_fqsen, $constant_name);
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredConstantOfClass)(
                    $this->context->getFile(),
                    $node->lineno,
                    [ "$class_fqsen::$constant_name" ],
                    IssueFixSuggester::suggestSimilarClassConstant($this->code_base, $this->context, $class_constant_fqsen)
                )
            );
        }

        throw new NodeException(
            $node,
            "Can't figure out constant {$constant_name} in node"
        );
    }

    /**
     * @return string
     * A unique and stable name for an anonymous class
     */
    public function getUnqualifiedNameForAnonymousClass(): string
    {
        if (!($this->node instanceof Node)) {
            throw new AssertionError('$this->node must be a node');
        }

        if (!($this->node->flags & ast\flags\CLASS_ANONYMOUS)) {
            throw new AssertionError('Node must be an anonymous class node');
        }

        $class_name = 'anonymous_class_'
            . \substr(\md5(
                $this->context->getFile() . $this->context->getLineNumberStart()
            ), 0, 8);

        return $class_name;
    }

    /**
     * @throws CodeBaseException if the closure could not be found
     */
    public function getClosure(): Func
    {
        if (!$this->node instanceof Node) {
            throw new CodeBaseException(
                null,
                "Could not find closure - invalid node. This should not happen."
            );
        }
        $closure_fqsen =
            FullyQualifiedFunctionName::fromClosureInContext(
                $this->context,
                $this->node
            );

        if (!$this->code_base->hasFunctionWithFQSEN($closure_fqsen)) {
            throw new CodeBaseException(
                $closure_fqsen,
                "Could not find closure $closure_fqsen"
            );
        }

        return $this->code_base->getFunctionByFQSEN($closure_fqsen);
    }

    /**
     * Perform some backwards compatibility checks on a node.
     * This ignores union types, and can be run in the parse phase.
     * (It often should, because outside quick mode, it may be run multiple times per node)
     *
     * TODO: This is repetitive, move these checks into ParseVisitor?
     * @suppress PhanPossiblyUndeclaredProperty
     */
    public function analyzeBackwardCompatibility(): void
    {
        if (!Config::get_backward_compatibility_checks()) {
            return;
        }

        if (!($this->node instanceof Node) || !($this->node->children['expr'] ?? false)) {
            return;
        }

        $kind = $this->node->kind;
        if (\in_array($kind, [ast\AST_STATIC_CALL, ast\AST_METHOD_CALL, ast\AST_NULLSAFE_METHOD_CALL], true)) {
            return;
        }

        $llnode = $this->node;

        if ($kind !== ast\AST_DIM) {
            $expr = $this->node->children['expr'];
            if (!($expr instanceof Node)) {
                return;
            }

            if ($expr->kind !== ast\AST_DIM) {
                (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $expr
                ))->analyzeBackwardCompatibility();
                return;
            }

            $temp = $expr->children['expr'];
            $llnode = $expr;
        } else {
            $temp = $this->node->children['expr'];
        }

        // Strings can have DIMs, it turns out.
        if (!($temp instanceof Node)) {
            return;
        }

        if (!($temp->kind === ast\AST_PROP
            || $temp->kind === ast\AST_STATIC_PROP
        )) {
            return;
        }

        $lnode = $temp;
        while ($temp instanceof Node
            && ($temp->kind === ast\AST_PROP
            || $temp->kind === ast\AST_STATIC_PROP)
        ) {
            $llnode = $lnode;
            $lnode = $temp;

            // Lets just hope the 0th is the expression
            // we want
            $temp = \array_values($temp->children)[0];
        }

        if (!($temp instanceof Node)) {
            return;
        }

        // Foo::$bar['baz'](); is a problem
        // Foo::$bar['baz'] is not
        if ($lnode->kind === ast\AST_STATIC_PROP
            && $kind !== ast\AST_CALL
        ) {
            return;
        }

        // $this->$bar['baz']; is a problem
        // $this->bar['baz'] is not
        if ($lnode->kind === ast\AST_PROP
            && !($lnode->children['prop'] instanceof Node)
            && !($llnode->children['prop'] instanceof Node)
        ) {
            return;
        }

        if ((
                (
                    $lnode->children['prop'] instanceof Node
                    && $lnode->children['prop']->kind === ast\AST_VAR
                )
                ||
                (
                    ($lnode->children['class'] ?? null) instanceof Node
                    && (
                        $lnode->children['class']->kind === ast\AST_VAR
                        || $lnode->children['class']->kind === ast\AST_NAME
                    )
                )
                ||
                (
                    ($lnode->children['expr'] ?? null) instanceof Node
                    && (
                        $lnode->children['expr']->kind === ast\AST_VAR
                        || $lnode->children['expr']->kind === ast\AST_NAME
                    )
                )
            )
            &&
            (
                $temp->kind === ast\AST_VAR
                || $temp->kind === ast\AST_NAME
            )
        ) {
            $cache_entry = FileCache::getOrReadEntry($this->context->getFile());
            $line = $cache_entry->getLine($this->node->lineno) ?? '';
            unset($cache_entry);
            if (strpos($line, '}[') === false
                && strpos($line, ']}') === false
                && strpos($line, '>{') === false
            ) {
                Issue::maybeEmit(
                    $this->code_base,
                    $this->context,
                    Issue::CompatiblePHP7,
                    $this->node->lineno
                );
            }
        }
    }

    /**
     * @throws IssueException if the list of possible classes couldn't be determined.
     */
    public function resolveClassNameInContext(): ?FullyQualifiedClassName
    {
        // A function argument to resolve into an FQSEN
        $arg = $this->node;

        try {
            if (\is_string($arg)) {
                // Class_alias treats arguments as fully qualified strings.
                return FullyQualifiedClassName::fromFullyQualifiedString($arg);
            }
            if ($arg instanceof Node
                && $arg->kind === ast\AST_CLASS_NAME) {
                $class_type = UnionTypeVisitor::unionTypeFromClassNode(
                    $this->code_base,
                    $this->context,
                    $arg->children['class']
                );

                // If we find a class definition, then return it. There should be 0 or 1.
                // (Expressions such as 'int::class' are syntactically valid, but would have 0 results).
                foreach ($class_type->asClassFQSENList($this->context) as $class_fqsen) {
                    return $class_fqsen;
                }
            }

            $class_name = $this->getEquivalentPHPScalarValue();
            // TODO: Emit
            if (\is_string($class_name)) {
                return FullyQualifiedClassName::fromFullyQualifiedString($class_name);
            }
        } catch (FQSENException $e) {
            throw new IssueException(
                Issue::fromType($e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike)(
                    $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                    $this->node->lineno ?? $this->context->getLineNumberStart(),
                    [$e->getFQSEN()]
                )
            );
        }

        return null;
    }

    // Flags for getEquivalentPHPValue

    // Should this attempt to resolve arrays?
    public const RESOLVE_ARRAYS = (1 << 0);
    // Should this attempt to resolve array keys?
    public const RESOLVE_ARRAY_KEYS = (1 << 1);
    // Should this attempt to resolve array values?
    public const RESOLVE_ARRAY_VALUES = (1 << 2);
    // Should this attempt to resolve accesses to constants?
    public const RESOLVE_CONSTANTS = (1 << 3);
    // If resolving array keys fails, should this use a placeholder?
    public const RESOLVE_KEYS_USE_FALLBACK_PLACEHOLDER = (1 << 4);
    // Skip unknown keys
    public const RESOLVE_KEYS_SKIP_UNKNOWN_KEYS = (1 << 5);
    // Resolve unary and binary operations.
    public const RESOLVE_OPS = (1 << 6);
    // Resolve calls to is_int, is_null, isset/empty, etc.
    public const RESOLVE_TYPE_CHECKS = (1 << 6);
    // Resolve variables, but only if this was defined as a constant AST.
    // This currently only supports static variables.
    // When disabled, all variables will be resolved.
    public const RESOLVE_ONLY_CONSTANT_VARS = (1 << 7);
    // Don't use variables.
    public const RESOLVE_DONT_USE_VARS = (1 << 8);

    public const RESOLVE_DEFAULT =
        self::RESOLVE_ARRAYS |
        self::RESOLVE_ARRAY_KEYS |
        self::RESOLVE_ARRAY_VALUES |
        self::RESOLVE_CONSTANTS |
        self::RESOLVE_KEYS_USE_FALLBACK_PLACEHOLDER;

    public const RESOLVE_SCALAR_DEFAULT =
        self::RESOLVE_CONSTANTS |
        self::RESOLVE_KEYS_USE_FALLBACK_PLACEHOLDER;

    /**
     * @param int $flags - See self::RESOLVE_*
     * @return ?array<mixed,mixed> - returns an array if elements could be resolved.
     */
    private function getEquivalentPHPArrayElements(Node $node, int $flags): ?array
    {
        $elements = [];
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                self::warnAboutEmptyArrayElements($this->code_base, $this->context, $node);
                continue;
            }
            $key_node = ($flags & self::RESOLVE_ARRAY_KEYS) !== 0 ? ($child_node->children['key'] ?? null) : null;
            $value_node = $child_node->children['value'] ?? null;
            if (self::RESOLVE_ARRAY_VALUES) {
                $value_node = $this->getEquivalentPHPValueForNode($value_node, $flags);
            }
            // NOTE: this has some overlap with DuplicateKeyPlugin
            if ($key_node === null) {
                $elements[] = $value_node;
            } elseif (\is_scalar($key_node)) {
                $elements[$key_node] = $value_node;  // Check for float?
            } else {
                $key = $this->getEquivalentPHPValueForNode($key_node, $flags);
                if (\is_scalar($key)) {
                    $elements[$key] = $value_node;
                } else {
                    if (($flags & self::RESOLVE_KEYS_USE_FALLBACK_PLACEHOLDER) !== 0) {
                        $elements[] = $value_node;
                    } else {
                        // TODO: Alternate strategies?
                        return null;
                    }
                }
            }
        }
        return $elements;
    }

    /**
     * @param Node $node a node of kind AST_ARRAY
     * @suppress PhanUndeclaredProperty this adds a dynamic property
     */
    public static function warnAboutEmptyArrayElements(CodeBase $code_base, Context $context, Node $node): void
    {
        if (isset($node->didWarnAboutEmptyArrayElements)) {
            return;
        }
        $node->didWarnAboutEmptyArrayElements = true;

        $lineno = $node->lineno;
        foreach ($node->children as $child_node) {
            if (!$child_node instanceof Node) {
                // Emit the line number of the nearest Node before this empty element
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::SyntaxError,
                    $lineno,
                    "Cannot use empty array elements in arrays"
                );
                continue;
            }
            // Update the line number of the nearest Node
            $lineno = $child_node->lineno;
        }
    }
    /**
     * This converts an AST node in context to the value it represents.
     * This is useful for plugins, etc, and will gradually improve.
     *
     * @see self::getEquivalentPHPValue()
     *
     * @param ?(Node|array|resource|float|int|string) $node
     * @return Node|string[]|int[]|float[]|string|float|int|bool|resource|null -
     *         If this could be resolved and we're certain of the value, this gets a raw PHP value for $node.
     *         Otherwise, this returns $node.
     */
    public function getEquivalentPHPValueForNode($node, int $flags)
    {
        if (!($node instanceof Node)) {
            return $node;
        }
        $kind = $node->kind;
        switch ($kind) {
            case ast\AST_ARRAY:
                if (($flags & self::RESOLVE_ARRAYS) === 0) {
                    return $node;
                }
                $elements = $this->getEquivalentPHPArrayElements($node, $flags);
                if ($elements === null) {
                    // Attempted to resolve elements but failed at one or more elements.
                    return $node;
                }
                return $elements;
            case ast\AST_CONST:
                $name = $node->children['name']->children['name'] ?? null;
                if (\is_string($name)) {
                    switch (\strtolower($name)) {
                        case 'false':
                            return false;
                        case 'true':
                            return true;
                        case 'null':
                            return null;
                    }
                }
                if (($flags & self::RESOLVE_CONSTANTS) === 0) {
                    return $node;
                }
                try {
                    $constant = (new ContextNode($this->code_base, $this->context, $node))->getConst();
                } catch (Exception $_) {
                    // Is there a need to catch IssueException as well?
                    return $node;
                }
                // TODO: Recurse, but don't try to resolve constants again
                $new_node = $constant->getNodeForValue();
                if (is_object($new_node)) {
                    // Avoid infinite recursion, only resolve once
                    $new_node = (new ContextNode($this->code_base, $constant->getContext(), $new_node))->getEquivalentPHPValueForNode($new_node, $flags & ~(self::RESOLVE_CONSTANTS | self::RESOLVE_ONLY_CONSTANT_VARS | self::RESOLVE_DONT_USE_VARS));
                }
                return $new_node;
            case ast\AST_CLASS_CONST:
                if (($flags & self::RESOLVE_CONSTANTS) === 0) {
                    return $node;
                }
                try {
                    $constant = (new ContextNode($this->code_base, $this->context, $node))->getClassConst();
                } catch (\Exception $_) {
                    return $node;
                }
                // TODO: Recurse, but don't try to resolve constants again
                $new_node = $constant->getNodeForValue();
                if (is_object($new_node)) {
                    // Avoid infinite recursion, only resolve once
                    $new_node = (new ContextNode($this->code_base, $constant->getContext(), $new_node))->getEquivalentPHPValueForNode($new_node, $flags & ~self::RESOLVE_CONSTANTS);
                }
                return $new_node;
            case ast\AST_CLASS_NAME:
                try {
                    return UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $node, false)->asSingleScalarValueOrNull() ?? $node;
                } catch (\Exception $_) {
                    return $node;
                }
            case ast\AST_MAGIC_CONST:
                // TODO: Look into eliminating this
                return $this->getValueForMagicConstByNode($node);
            case ast\AST_BINARY_OP:
                if ($flags & self::RESOLVE_OPS) {
                    return $this->getValueForBinaryOp($node, $flags);
                }
                break;
            case ast\AST_UNARY_OP:
                if ($flags & self::RESOLVE_OPS) {
                    return $this->getValueForUnaryOp($node, $flags);
                }
                break;
            case ast\AST_EMPTY:
                if ($flags & self::RESOLVE_TYPE_CHECKS) {
                    return $this->getValueForEmptyCheck($node, $flags);
                }
                break;
            case ast\AST_ISSET:
                if ($flags & self::RESOLVE_TYPE_CHECKS) {
                    // fprintf(STDERR, "Computing isset for %s\n", \Phan\Debug::nodeToString($node));
                    return $this->getValueForIssetCheck($node, $flags);
                }
                break;
            case ast\AST_CALL:
                if ($flags & self::RESOLVE_TYPE_CHECKS) {
                    // fprintf(STDERR, "Computing isset for %s\n", \Phan\Debug::nodeToString($node));
                    return $this->getValueForCall($node, $flags);
                }
                break;
            case ast\AST_VAR:
                if ($flags & self::RESOLVE_ONLY_CONSTANT_VARS) {
                    if (!$this->isVarWithConstantDefinition($node)) {
                        return $node;
                    }
                    // fall through.
                }
                break;
            default:
                if ($flags & self::RESOLVE_ONLY_CONSTANT_VARS) {
                    // Don't resolve other node kinds not in this list.
                    return $node;
                }
                break;
        }
        $node_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node
        );
        $value = $node_type->asValueOrNullOrSelf();
        if (\is_object($value)) {
            return $node;
        }
        return $value;
    }

    /**
     * Check if this variable is one which Phan has inferred to be likely
     * to have a definition that was constant at this point in the codebase.
     * (This is a heuristic)
     */
    public function isVarWithConstantDefinition(Node $node): bool
    {
        if ($node->kind !== ast\AST_VAR) {
            return false;
        }
        $name = $node->children['name'];
        if (!is_string($name)) {
            return false;
        }
        $scope = $this->context->getScope();
        if ($scope->hasVariableWithName($name)) {
            return ($scope->getVariableByName($name)->getPhanFlags() & \Phan\Language\Element\Flags::IS_CONSTANT_DEFINITION) !== 0;
        }
        return false;
    }

    /**
     * @return Node|string[]|int[]|float[]|string|float|int|bool|null -
     *         If this could be resolved and we're certain of the value,
     *         this gets a raw PHP value for the binary operation represented by $node.
     *         Otherwise, this returns $node.
     */
    private function getValueForBinaryOp(Node $node, int $flags)
    {
        $left_value = $this->getEquivalentPHPValueForNode($node->children['left'], $flags);
        if ($left_value instanceof Node) {
            return $node;
        }
        $right_value = $this->getEquivalentPHPValueForNode($node->children['right'], $flags);
        if ($right_value instanceof Node) {
            return $node;
        }
        try {
            // @phan-suppress-next-line PhanPartialTypeMismatchArgument throwing an Error is caught
            return InferValue::computeBinaryOpResult($left_value, $right_value, $node->flags);
        } catch (Error $e) {
            self::handleErrorInOperation($node, $e);
            return $node;
        }
    }

    private function handleErrorInOperation(Node $node, Error $e): void
    {
        $this->emitIssue(
            Issue::TypeErrorInOperation,
            $node->lineno,
            ASTReverter::toShortString($node),
            $e->getMessage()
        );
    }

    /**
     * @return Node|string[]|int[]|float[]|string|float|int|bool|null -
     *         If this could be resolved and we're certain of the value,
     *         then this gets a raw PHP value for the unary operation represented by $node.
     *         Otherwise, this returns $node.
     */
    private function getValueForUnaryOp(Node $node, int $flags)
    {
        $operand_value = $this->getEquivalentPHPValueForNode($node->children['expr'], $flags);
        // fprintf(STDERR, "Computing unary op for %s : operand = %s\n", \Phan\Debug::nodeToString($node), json_encode($operand_value));
        if ($operand_value instanceof Node) {
            return $node;
        }

        try {
            // @phan-suppress-next-line PhanPartialTypeMismatchArgument throwing an Error is caught
            return InferValue::computeUnaryOpResult($operand_value, $node->flags);
        } catch (Error $e) {
            self::handleErrorInOperation($node, $e);
            return $node;
        }
    }

    /**
     * @param Node $node a node of kind AST_EMPTY
     * @return Node|bool
     *         If this could be resolved and we're certain of the value, this gets a raw PHP boolean for $node.
     *         Otherwise, this returns $node.
     */
    private function getValueForEmptyCheck(Node $node, int $flags)
    {
        $expr_value = $this->getEquivalentPHPValueForNode($node->children['expr'], $flags);
        if ($expr_value instanceof Node) {
            return $node;
        }

        return !$expr_value;
    }

    /**
     * @param Node $node a node of kind AST_ISSET
     * @return Node|bool
     *         If this could be resolved and we're certain of the result returned by isset,
     *         this gets a raw PHP boolean for $node.
     *         Otherwise, this returns $node.
     */
    private function getValueForIssetCheck(Node $node, int $flags)
    {
        $var_value = $this->getEquivalentPHPValueForNode($node->children['var'], $flags);
        if ($var_value instanceof Node) {
            return $node;
        }

        return $var_value !== null;
    }

    // Type checks that can act on a single argument
    private const TYPE_CHECK_SET = [
        'is_array' => true,
        'is_bool' => true,
        'is_callable' => true,
        'is_double' => true,
        'is_float' => true,
        'is_int' => true,
        'is_integer' => true,
        'is_iterable' => true,
        'is_long' => true,
        'is_null' => true,
        'is_numeric' => true,
        'is_object' => true,
        'is_real' => true,
        'is_resource' => true,
        'is_scalar' => true,
        'is_string' => true,
    ];

    /**
     * @param Node $node a node of kind AST_CALL
     * @return Node|bool
     *         If this could be resolved and we're certain of the return value of the call,
     *         this gets a raw result for $node (currently limited to booleans, e.g. is_string($var).
     *         Otherwise, this returns $node.
     */
    private function getValueForCall(Node $node, int $flags)
    {
        $arg_list = $node->children['args']->children;
        // arg_list[0] should always be set.
        if (\count($arg_list) !== 1 || !isset($arg_list[0])) {
            return $node;
        }
        $raw_function_name = ConditionVisitor::getFunctionName($node);
        if (!is_string($raw_function_name)) {
            return $node;
        }
        $raw_function_name = \strtolower($raw_function_name);
        if (!isset(self::TYPE_CHECK_SET[$raw_function_name])) {
            return $node;
        }
        $arg_value = $this->getEquivalentPHPValueForNode($arg_list[0], $flags);
        if ($arg_value instanceof Node) {
            return $node;
        }

        // Given some known function name and the resolved value of the argument to the function,
        // evaluate what the result is.
        // e.g. `is_null($someStaticValue)`
        return $raw_function_name($arg_value);
    }

    /**
     * @return array|string|int|float|bool|null|Node the value of the corresponding PHP constant,
     * or the original node if that could not be determined
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getValueForMagicConst()
    {
        $node = $this->node;
        if (!($node instanceof Node && $node->kind === ast\AST_MAGIC_CONST)) {
            throw new AssertionError(__METHOD__ . ' expected AST_MAGIC_CONST');
        }
        return $this->getValueForMagicConstByNode($node);
    }

    /**
     * @return array|string|int|float|bool|null|Node the value of the corresponding PHP magic constant (e.g. __FILE__),
     * or the original node if that could not be determined
     */
    public function getValueForMagicConstByNode(Node $node)
    {
        $result = (new UnionTypeVisitor($this->code_base, $this->context))->visitMagicConst($node)->asSingleScalarValueOrNullOrSelf();
        return is_object($result) ? $node : $result;
    }

    /**
     * This converts an AST node in context to the value it represents.
     * This is useful for plugins, etc, and will gradually improve.
     *
     * This does not create new object instances.
     *
     * @return Node|string[]|int[]|float[]|string|float|int|bool|resource|null -
     *   If this could be resolved and we're certain of the value, this gets an equivalent definition.
     *   Otherwise, this returns $node.
     */
    public function getEquivalentPHPValue(int $flags = self::RESOLVE_DEFAULT)
    {
        return $this->getEquivalentPHPValueForNode($this->node, $flags);
    }

    /**
     * @return Node|string[]|int[]|float[]|string|float|int|bool|resource|null -
     *   If this could be resolved and we're certain of the value, this gets an equivalent definition.
     *   Otherwise, this returns $node.
     */
    public function getEquivalentPHPValueForControlFlowAnalysis()
    {
        return $this->getEquivalentPHPValueForNode(
            $this->node,
            self::RESOLVE_ARRAYS |
            self::RESOLVE_ARRAY_KEYS |
            self::RESOLVE_ARRAY_VALUES |
            self::RESOLVE_OPS |
            self::RESOLVE_ONLY_CONSTANT_VARS |
            self::RESOLVE_TYPE_CHECKS
        );
    }

    /**
     * This converts an AST node (of any kind) in context to the value it represents.
     * This is useful for plugins, etc, and will gradually improve.
     *
     * This does not create new object instances.
     *
     * @return Node|string|float|int|bool|null|resource -
     *         If this could be resolved and we're certain of the value, this gets an equivalent definition.
     *         Otherwise, if this would be an array or an object, this returns $node.
     *
     * @suppress PhanPartialTypeMismatchReturn the flags prevent this from returning an array
     */
    public function getEquivalentPHPScalarValue()
    {
        return $this->getEquivalentPHPValueForNode($this->node, self::RESOLVE_SCALAR_DEFAULT);
    }
}
