<?php

declare(strict_types=1);

namespace Phan\Language;

use ArrayObject;
use AssertionError;
use ast\Node;
use Closure;
use Exception;
use Phan\CodeBase;
use Phan\Exception\CodeBaseException;
use Phan\Issue;
use Phan\Language\Element\ClassElement;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Property;
use Phan\Language\Element\TypedElement;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionLikeName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedGlobalStructuralElement;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\Scope\GlobalScope;
use Phan\Language\Type\ArrayShapeType;
use Phan\Library\StringUtil;
use RuntimeException;

/**
 * An object representing the context in which any
 * structural element (such as a class or method) lives.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @phan-file-suppress PhanPluginNoCommentOnPublicMethod TODO: Add comments
 */
class Context extends FileRef
{
    /**
     * @var string
     * The namespace of the file.
     * To be consistent with what ScopeVisitor sets in visitNamespace(), this is '\\' for the root namespace as well.
     */
    private $namespace = '\\';

    /**
     * @var int
     * The id of the namespace
     */
    private $namespace_id = 0;

    /**
     * @var array<int,array<string,NamespaceMapEntry>>
     * @phan-var associative-array<int,array<string,NamespaceMapEntry>>
     * Maps [int flags => [string name/namespace => NamespaceMapEntry(fqsen, is_used)]]
     * Note that for \ast\USE_CONST (global constants), this is case-sensitive,
     * but the remaining types are case-insensitive (stored with lowercase name).
     */
    private $namespace_map = [];

    /**
     * @var array<int,array<string,NamespaceMapEntry>>
     * @phan-var associative-array<int,array<string,NamespaceMapEntry>>
     * Maps [int flags => [string name/namespace => NamespaceMapEntry(fqsen, is_used)]]
     *
     * (This is used in the analysis phase after the parse phase)
     * @see self::$namespace_map
     */
    private $parse_namespace_map = [];

    /**
     * @var ArrayObject<string,Type> shared among all clones of this context. Can include GenericMultiType.
     */
    private $type_alias_map;

    /**
     * @var int
     * strict_types setting for the file
     */
    protected $strict_types = 0;

    /**
     * @var list<Node>
     * A list of nodes of loop statements that have been entered in the current functionlike scope.
     */
    protected $loop_nodes = [];

    /**
     * @var Scope
     * The current scope in this context
     */
    private $scope;

    /**
     * @var array<mixed,mixed>
     * caches union types for a given node
     */
    private $cache = [];

    /**
     * Create a new context
     */
    public function __construct()
    {
        $this->scope = new GlobalScope();
        $this->type_alias_map = new ArrayObject();
    }

    /**
     * @param string $namespace
     * The namespace of the file
     *
     * @return Context
     * A clone of this context with the given value is returned
     */
    public function withNamespace(string $namespace): Context
    {
        $context = clone($this);
        $context->namespace = $namespace;
        $context->namespace_id += 1;  // Assumes namespaces are walked in order
        $context->namespace_map = [];

        if ($context->namespace_id > 1) {
            $context->type_alias_map = new ArrayObject();
        }
        return $context;
    }

    /**
     * @return string
     * The namespace of the file
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * @return int
     * The namespace id within the file (incrementing starting from 0)
     * Used because a file can have duplicate identical namespace declarations.
     */
    public function getNamespaceId(): int
    {
        return $this->namespace_id;
    }

    /**
     * @return bool
     * True if we have a mapped NS for the given named element
     */
    public function hasNamespaceMapFor(int $flags, string $name): bool
    {
        // Look for the mapping on the part before a
        // slash
        $name_parts = \explode('\\', $name, 2);
        if (\count($name_parts) > 1) {
            $name = $name_parts[0];
            // In php, namespaces, functions, and classes are case-insensitive.
            // However, constants are almost always case-insensitive.
            // The name we're looking for is a namespace(USE_NORMAL).
            // The suffix has type $flags
            $flags = \ast\flags\USE_NORMAL;
        }
        if ($flags !== \ast\flags\USE_CONST) {
            $name = \strtolower($name);
        }
        return isset($this->namespace_map[$flags][$name]);
    }

    /**
     * @return FullyQualifiedGlobalStructuralElement
     * The namespace mapped name for the given flags and name
     */
    public function getNamespaceMapFor(
        int $flags,
        string $name
    ): FullyQualifiedGlobalStructuralElement {

        // Look for the mapping on the part before a
        // slash
        $name_parts = \explode('\\', $name, 2);
        if (\count($name_parts) > 1) {
            $name = $name_parts[0];
            $suffix = $name_parts[1];
            // In php, namespaces, functions, and classes are case-insensitive.
            // However, constants are almost always case-insensitive.
            // The name we're looking for is a namespace(USE_NORMAL).
            // The suffix has type $flags
            $map_flags = \ast\flags\USE_NORMAL;
        } else {
            $suffix = '';
            $map_flags = $flags;
        }
        if ($map_flags !== \ast\flags\USE_CONST) {
            $name_key = \strtolower($name);
        } else {
            $name_key = $name;
        }

        $namespace_map_entry = $this->namespace_map[$map_flags][$name_key] ?? null;

        if (!$namespace_map_entry) {
            throw new AssertionError("No namespace defined for name '$name_key'");
        }
        $fqsen = $namespace_map_entry->fqsen;
        $namespace_map_entry->is_used = true;

        // Create something of the corresponding type (which may or may not be within a suffix)
        if (!StringUtil::isNonZeroLengthString($suffix)) {
            return $fqsen;
        }

        switch ($flags) {
            case \ast\flags\USE_NORMAL:
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall This and the suffix should have already been validated
                return FullyQualifiedClassName::fromFullyQualifiedString(
                    $fqsen->__toString() . '\\' . $suffix
                );
            case \ast\flags\USE_FUNCTION:
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall
                return FullyQualifiedFunctionName::fromFullyQualifiedString(
                    $fqsen->__toString() . '\\' . $suffix
                );
            case \ast\flags\USE_CONST:
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall
                return FullyQualifiedGlobalConstantName::fromFullyQualifiedString(
                    $fqsen->__toString() . '\\' . $suffix
                );
        }

        throw new AssertionError("Unknown flag $flags");
    }

    /**
     * @return Context
     * This context with the given value is returned
     *
     * TODO: Make code_base mandatory in a subsequent release
     */
    public function withNamespaceMap(
        int $flags,
        string $alias,
        FullyQualifiedGlobalStructuralElement $target,
        int $lineno,
        ?CodeBase $code_base = null
    ): Context {
        $original_alias = $alias;
        if ($flags !== \ast\flags\USE_CONST) {
            $alias = \strtolower($alias);
        } else {
            $last_part_index = \strrpos($alias, '\\');
            if ($last_part_index !== false) {
                // Convert the namespace to lowercase, but not the constant name.
                $alias = \strtolower(\substr($alias, 0, $last_part_index + 1)) . \substr($alias, $last_part_index + 1);
            }
        }
        // we may have imported this namespace map from the parse phase, making the target already exist
        // TODO: Warn if namespace_map already exists? Then again, `php -l` already does.
        $parse_entry = $this->parse_namespace_map[$flags][$alias] ?? null;
        if ($parse_entry !== null) {
            // We add entries to namespace_map only after encountering them
            // This is because statements can appear before 'use Foo\Bar;' (and those don't use the 'use' statement.)
            $this->namespace_map[$flags][$alias] = $parse_entry;
            return $this;
        }
        if (isset($this->namespace_map[$flags][$alias])) {
            if ($code_base) {
                $this->warnDuplicateUse($code_base, $target, $lineno, $flags, $alias);
            }
        }
        $this->namespace_map[$flags][$alias] = new NamespaceMapEntry($target, $original_alias, $lineno);
        return $this;
    }

    private function warnDuplicateUse(CodeBase $code_base, FullyQualifiedGlobalStructuralElement $target, int $lineno, int $flags, string $alias): void
    {
        switch ($flags) {
            case \ast\flags\USE_FUNCTION:
                $issue = Issue::DuplicateUseFunction;
                break;
            case \ast\flags\USE_CONST:
                $issue = Issue::DuplicateUseConstant;
                break;
            default:
                $issue = Issue::DuplicateUseNormal;
                break;
        }

        Issue::maybeEmit(
            $code_base,
            $this,
            $issue,
            $lineno,
            $target,
            $alias
        );
    }

    public function addTypeAlias(string $alias_name, Type $type): bool
    {
        if ($this->type_alias_map->offsetExists($alias_name)) {
            return false;
        }
        $this->type_alias_map->offsetSet($alias_name, $type);
        return true;
    }

    public function getTypeAlias(string $alias_name): ?Type
    {
        return $this->type_alias_map[$alias_name] ?? null;
    }

    /**
     * @param int $strict_types
     * The strict_type setting for the file
     *
     * @return Context
     * This context with the given value is returned
     */
    public function withStrictTypes(int $strict_types): Context
    {
        $this->strict_types = $strict_types;
        return $this;
    }

    /**
     * @return bool
     * Returns true if strict_types is set to 1 in this context.
     */
    public function isStrictTypes(): bool
    {
        return (1 === $this->strict_types);
    }

    /**
     * @return Scope
     * An object describing the contents of the current
     * scope.
     */
    public function getScope(): Scope
    {
        return $this->scope;
    }

    /**
     * Set the scope on the context
     */
    public function setScope(Scope $scope): void
    {
        $this->scope = $scope;
        // TODO: Less aggressive? ConditionVisitor creates a lot of scopes
        $this->cache = [];
    }

    /**
     * @return Context
     * A new context with the given scope
     * @phan-pure
     */
    public function withScope(Scope $scope): Context
    {
        $context = clone($this);
        $context->setScope($scope);
        return $context;
    }

    /**
     * @phan-pure
     */
    public function withEnterLoop(Node $node): Context
    {
        $context = clone($this);
        $context->loop_nodes[] = $node;
        return $context;
    }

    public function withExitLoop(Node $node): Context
    {
        $context = clone($this);

        while ($context->loop_nodes) {
            if (\array_pop($context->loop_nodes) === $node) {
                if (\count($context->loop_nodes) === 0) {
                    // @phan-suppress-next-line PhanUndeclaredProperty
                    foreach ($node->phan_deferred_checks ?? [] as $cb) {
                        $cb($context);
                    }
                }
                break;
            }
        }
        return $context;
    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    public function deferCheckToOutermostLoop(Closure $closure): void
    {
        $node = $this->loop_nodes[0] ?? null;
        if ($node) {
            if (!isset($node->phan_deferred_checks)) {
                $node->phan_deferred_checks = [];
            }
            $node->phan_deferred_checks[] = $closure;
        }
    }

    /**
     * Is this in a loop of the current function body (or global scope)?
     */
    public function isInLoop(): bool
    {
        return \count($this->loop_nodes) > 0;
    }

    /**
     * Fetches the innermost loop node.
     * @suppress PhanPossiblyFalseTypeReturn
     */
    public function getInnermostLoopNode(): Node
    {
        return \end($this->loop_nodes);
    }

    public function withoutLoops(): Context
    {
        $context = clone($this);
        $context->loop_nodes = [];
        return $context;
    }

    /**
     * @return Context
     *
     * A new context with the a clone of the current scope.
     * This is useful when using AssignmentVisitor for things that aren't actually assignment operations.
     * (AssignmentVisitor modifies the passed in scope variables in place)
     */
    public function withClonedScope(): Context
    {
        $context = clone($this);
        $context->scope = clone($context->scope);
        return $context;
    }

    /**
     * @param Variable $variable
     * A variable to add to the scope for the new
     * context
     *
     * @return Context
     * A new context based on this with a variable
     * as defined by the parameters in scope
     * @phan-pure
     */
    public function withScopeVariable(
        Variable $variable
    ): Context {
        return $this->withScope(
            $this->scope->withVariable($variable)
        );
    }

    /**
     * @param Variable $variable
     * A variable to add to the scope for the new
     * context
     */
    public function addGlobalScopeVariable(Variable $variable): void
    {
        $this->scope->addGlobalVariable($variable);
    }

    /**
     * Add a variable to this context's scope. Note that
     * this does not create a new context. You're actually
     * injecting the variable into the context. Use with
     * caution.
     *
     * @param Variable $variable
     * A variable to inject into this context
     */
    public function addScopeVariable(
        Variable $variable
    ): void {
        $this->scope->addVariable($variable);
    }

    /**
     * Unset a variable in this context's scope. Note that
     * this does not create a new context. You're actually
     * removing the variable from the context. Use with
     * caution.
     *
     * @param string $variable_name
     * The name of a variable to remove from the context.
     */
    public function unsetScopeVariable(
        string $variable_name
    ): void {
        $this->scope->unsetVariable($variable_name);
    }

    /**
     * Returns a string representing this Context for debugging
     * @suppress PhanUnreferencedPublicMethod kept around to make it easy to dump variables in a context
     */
    public function toDebugString(): string
    {
        $result = (string)$this;
        foreach ($this->scope->getVariableMap() as $variable) {
            $result .= "\n{$variable->getDebugRepresentation()}";
        }
        return $result;
    }

    /**
     * @return bool
     * True if this context is currently within a class
     * scope, else false.
     */
    public function isInClassScope(): bool
    {
        return $this->scope->isInClassScope();
    }

    /**
     * @return FullyQualifiedClassName
     * A fully-qualified structural element name describing
     * the current class in scope.
     */
    public function getClassFQSEN(): FullyQualifiedClassName
    {
        return $this->scope->getClassFQSEN();
    }

    public function getClassFQSENOrNull(): ?FullyQualifiedClassName
    {
        return $this->scope->getClassFQSENOrNull();
    }

    /**
     * @return bool
     * True if this context is currently within a property
     * scope, else false.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isInPropertyScope(): bool
    {
        return $this->scope->isInPropertyScope();
    }

    /**
     * @return FullyQualifiedPropertyName
     * A fully-qualified structural element name describing
     * the current property in scope.
     */
    public function getPropertyFQSEN(): FullyQualifiedPropertyName
    {
        return $this->scope->getPropertyFQSEN();
    }

    /**
     * @param CodeBase $code_base
     * The global code base holding all state
     *
     * @return Clazz
     * Get the class in this scope, or fail real hard
     *
     * @throws CodeBaseException
     * Thrown if we can't find the class in scope within the
     * given codebase.
     */
    public function getClassInScope(CodeBase $code_base): Clazz
    {
        if (!$this->scope->isInClassScope()) {
            throw new AssertionError("Must be in class scope to get class");
        }

        if (!$code_base->hasClassWithFQSEN($this->getClassFQSEN())) {
            throw new CodeBaseException(
                $this->getClassFQSEN(),
                "Cannot find class with FQSEN {$this->getClassFQSEN()} in context {$this}"
            );
        }

        return $code_base->getClassByFQSEN(
            $this->getClassFQSEN()
        );
    }

    /**
     * @param CodeBase $code_base
     * The global code base holding all state
     *
     * @return Property
     * Get the property in this scope, or fail real hard
     *
     * @throws CodeBaseException
     * Thrown if we can't find the property in scope within the
     * given codebase.
     */
    public function getPropertyInScope(CodeBase $code_base): Property
    {
        if (!$this->scope->isInPropertyScope()) {
            throw new AssertionError("Must be in property scope to get property");
        }

        $property_fqsen = $this->getPropertyFQSEN();
        if (!$code_base->hasPropertyWithFQSEN($property_fqsen)) {
            throw new CodeBaseException(
                $property_fqsen,
                "Cannot find class with FQSEN {$property_fqsen} in context {$this}"
            );
        }

        return $code_base->getPropertyByFQSEN(
            $property_fqsen
        );
    }

    /**
     * @return bool
     * True if this context is currently within a method,
     * function or closure scope.
     */
    public function isInFunctionLikeScope(): bool
    {
        return $this->scope->isInFunctionLikeScope();
    }

    /**
     * @return bool
     * True if this context is currently within a method.
     */
    public function isInMethodScope(): bool
    {
        return $this->scope->isInMethodLikeScope();
    }

    /**
     * @return FullyQualifiedFunctionLikeName|FullyQualifiedMethodName|FullyQualifiedFunctionName
     * A fully-qualified structural element name describing
     * the current function or method in scope.
     */
    public function getFunctionLikeFQSEN()
    {
        $scope = $this->scope;
        if (!$scope->isInFunctionLikeScope()) {
            throw new AssertionError("Must be in function-like scope to get function-like FQSEN");
        }
        return $scope->getFunctionLikeFQSEN();
    }

    /**
     * @param CodeBase $code_base
     * The global code base holding all state
     *
     * @return Element\Func|Element\Method
     * Get the method in this scope or fail real hard
     */
    public function getFunctionLikeInScope(
        CodeBase $code_base
    ): FunctionInterface {
        $fqsen = $this->getFunctionLikeFQSEN();

        if ($fqsen instanceof FullyQualifiedFunctionName) {
            if (!$code_base->hasFunctionWithFQSEN($fqsen)) {
                throw new RuntimeException("The function $fqsen does not exist, but Phan is in that function's scope");
            }
            return $code_base->getFunctionByFQSEN($fqsen);
        }

        if ($fqsen instanceof FullyQualifiedMethodName) {
            if (!$code_base->hasMethodWithFQSEN($fqsen)) {
                throw new RuntimeException("Method $fqsen does not exist");
            }
            return $code_base->getMethodByFQSEN($fqsen);
        }

        throw new AssertionError("FQSEN must be for a function or method");
    }

    /**
     * @return bool
     * True if we're within the scope of a class, method,
     * function or closure. False if we're in the global
     * scope
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isInElementScope(): bool
    {
        return $this->scope->isInElementScope();
    }

    /**
     * @return bool
     * True if we're in the global scope (not in a class,
     * method, function, closure).
     */
    public function isInGlobalScope(): bool
    {
        return !$this->scope->isInElementScope();
    }

    /**
     * @param CodeBase $code_base
     * The code base from which to retrieve the TypedElement
     *
     * @return TypedElement
     * The element whose scope we're in. If we're in the global
     * scope this method will go down in flames and take your
     * process with it.
     *
     * @throws CodeBaseException if this was called without first checking
     * if this context is in an element scope
     */
    public function getElementInScope(CodeBase $code_base): TypedElement
    {
        if ($this->scope->isInFunctionLikeScope()) {
            return $this->getFunctionLikeInScope($code_base);
        } elseif ($this->scope->isInPropertyScope()) {
            return $this->getPropertyInScope($code_base);
        } elseif ($this->scope->isInClassScope()) {
            return $this->getClassInScope($code_base);
        }

        throw new CodeBaseException(
            null,
            "Cannot get element in scope if we're in the global scope"
        );
    }

    /**
     * @param CodeBase $code_base
     * The code base from which to retrieve a possible TypedElement
     * that contains an issue suppression list
     *
     * @param string $issue_name
     * The name of the issue which is being checked for membership
     * in an issue suppression list.
     *
     * @return bool
     * True if issues with the given name are suppressed within
     * this context.
     */
    public function hasSuppressIssue(
        CodeBase $code_base,
        string $issue_name
    ): bool {
        if ($code_base->hasFileLevelSuppression($this->file, $issue_name)) {
            return true;
        }
        if (!$this->scope->isInElementScope()) {
            return false;
        }

        try {
            $element = $this->getElementInScope($code_base);
        } catch (Exception $_) {
            return false;
        }
        if ($element instanceof ClassElement) {
            $defining_fqsen = $element->getRealDefiningFQSEN();
            if ($defining_fqsen !== $element->getFQSEN()) {
                if ($defining_fqsen instanceof FullyQualifiedMethodName) {
                    $element = $code_base->getMethodByFQSEN($defining_fqsen);
                } elseif ($defining_fqsen instanceof FullyQualifiedPropertyName) {
                    $element = $code_base->getPropertyByFQSEN($defining_fqsen);
                } elseif ($defining_fqsen instanceof FullyQualifiedClassConstantName) {
                    $element = $code_base->getClassConstantByFQSEN($defining_fqsen);
                }
            }
        }
        $has_suppress_issue = $element->hasSuppressIssue($issue_name);

        // Increment the suppression use count
        if ($has_suppress_issue) {
            $element->incrementSuppressIssueCount($issue_name);
        }

        return $has_suppress_issue;
    }

    /**
     * $this->cache is reused for multiple types of caches
     * We xor the node ids with the following bits so that the values don't overlap.
     * (The node id is based on \spl_object_id(), which is the object ID number.
     *
     * (This caching scheme makes a reasonable assumption
     * that there are less than 1 billion Node objects on 32-bit systems,
     * (It'd run out of memory with more than 4 bytes needed per Node)
     * and less than (1 << 62) objects on 64-bit systems.)
     *
     * It also assumes that nodes won't be freed while this Context still exists
     *
     * 0x00(node_id) is used for getUnionTypeOfNodeIfCached(int $node_id, false)
     * 0x10(node_id) is used for getUnionTypeOfNodeIfCached(int $node_id, true)
     * 0x01(node_id) is used for getCachedClassListOfNode(int $node_id)
     */
    public const HIGH_BIT_1 = (1 << (\PHP_INT_SIZE * 8) - 1);
    public const HIGH_BIT_2 = (1 << (\PHP_INT_SIZE * 8) - 2);

    /**
     * @param int $node_id \spl_object_id($node)
     * @param bool $should_catch_issue_exception the value passed to UnionTypeVisitor
     * @suppress PhanPartialTypeMismatchReturn seen with --analyze-twice because $this->cache is reused
     */
    public function getUnionTypeOfNodeIfCached(int $node_id, bool $should_catch_issue_exception): ?UnionType
    {
        if ($should_catch_issue_exception) {
            return $this->cache[$node_id] ?? null;
        }
        return $this->cache[$node_id ^ self::HIGH_BIT_1] ?? null;
    }

    /**
     * TODO: This may be unsafe? Clear the cache after a function goes out of scope.
     *
     * A UnionType is only cached if there is no exception.
     *
     * @param int $node_id \spl_object_id($node)
     * @param UnionType $type the type to cache.
     * @param bool $should_catch_issue_exception the value passed to UnionTypeVisitor
     */
    public function setCachedUnionTypeOfNode(int $node_id, UnionType $type, bool $should_catch_issue_exception): void
    {
        if (!$should_catch_issue_exception) {
            $this->cache[$node_id ^ self::HIGH_BIT_1] = $type;
            // If we weren't suppressing exceptions and setCachedUnionTypeOfNode was called,
            // that would mean that there were no exceptions to catch.
            // So, that means the UnionType for should_catch_issue_exception = true will be the same
        }
        $this->cache[$node_id] = $type;
    }

    /**
     * @param int $node_id
     * @return ?array{0:UnionType,1:Clazz[]} $result
     * @suppress PhanPartialTypeMismatchReturn cache is mixed with other cache objects
     */
    public function getCachedClassListOfNode(int $node_id): ?array
    {
        return $this->cache[$node_id ^ self::HIGH_BIT_2] ?? null;
    }

    /**
     * TODO: This may be unsafe? Clear the cache after a function goes out of scope.
     * @param int $node_id \spl_object_id($node)
     * @param array{0:UnionType,1:Clazz[]} $result
     */
    public function setCachedClassListOfNode(int $node_id, array $result): void
    {
        $this->cache[$node_id ^ self::HIGH_BIT_2] = $result;
    }

    public function clearCachedUnionTypes(): void
    {
        $this->cache = [];
    }

    /**
     * Gets Phan's internal representation of all of the 'use elem;' statements in a namespace.
     * Use hasNamespaceMapFor and getNamespaceMapFor instead.
     *
     * @internal
     *
     * @return array<int,array<string,NamespaceMapEntry>> maps use kind flags to the entries.
     * @phan-return associative-array<int,array<string,NamespaceMapEntry>> maps use kind flags to the entries.
     */
    public function getNamespaceMap(): array
    {
        return $this->namespace_map;
    }

    /**
     * Warn about any unused \ast\AST_USE or \ast\AST_GROUP_USE nodes (`use Foo\Bar;`)
     * This should be called after analyzing the end of a namespace (And before analyzing the next namespace)
     *
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @internal
     */
    public function warnAboutUnusedUseElements(CodeBase $code_base): void
    {
        foreach ($this->namespace_map as $flags => $entries_for_flag) {
            foreach ($entries_for_flag as $namespace_map_entry) {
                if ($namespace_map_entry->is_used) {
                    continue;
                }
                switch ($flags) {
                    case \ast\flags\USE_NORMAL:
                    default:
                        $issue_type = Issue::UnreferencedUseNormal;
                        break;
                    case \ast\flags\USE_FUNCTION:
                        $issue_type = Issue::UnreferencedUseFunction;
                        break;
                    case \ast\flags\USE_CONST:
                        $issue_type = Issue::UnreferencedUseConstant;
                        break;
                }
                Issue::maybeEmit(
                    $code_base,
                    $this,
                    $issue_type,
                    $namespace_map_entry->lineno,
                    $namespace_map_entry->original_name,
                    (string)$namespace_map_entry->fqsen
                );
            }
        }
    }

    /**
     * @internal
     * @suppress PhanAccessMethodInternal
     */
    public function importNamespaceMapFromParsePhase(CodeBase $code_base): void
    {
        $this->parse_namespace_map = $code_base->getNamespaceMapFromParsePhase($this->file, $this->namespace, $this->namespace_id);
    }

    /**
     * Copy private properties of $other to this
     */
    final protected function copyPropertiesFrom(Context $other): void
    {
        $this->file = $other->file;
        $this->line_number_start = $other->line_number_start;
        $this->namespace = $other->namespace;
        $this->namespace_id = $other->namespace_id;
        $this->namespace_map = $other->namespace_map;
        $this->parse_namespace_map = $other->parse_namespace_map;
        $this->strict_types = $other->strict_types;
        $this->loop_nodes = $other->loop_nodes;
        $this->scope = $other->scope;
        $this->cache = $other->cache;
    }

    /**
     * This name is internally used by Phan to track the properties of $this similarly to the way array shapes are represented.
     */
    public const VAR_NAME_THIS_PROPERTIES = "phan\0\$this";

    /**
     * Analyzes the side effects of setting the type of $this->property to $type
     * @suppress PhanUnreferencedPublicMethod this might be used in the future
     */
    public function withThisPropertySetToType(Property $property, UnionType $type): Context
    {
        $old_union_type = $property->getUnionType();
        if ($this->scope->hasVariableWithName(self::VAR_NAME_THIS_PROPERTIES)) {
            $variable = clone($this->scope->getVariableByName(self::VAR_NAME_THIS_PROPERTIES));
            $old_type = $variable->getUnionType();
            $override_type = ArrayShapeType::fromFieldTypes([$property->getName() => $type], false);
            $override_type = self::addArrayShapeTypes($override_type, $old_type->getTypeSet());

            $variable->setUnionType($override_type->asPHPDocUnionType());
        } else {
            // There is nothing inferred about any type

            if ($old_union_type->isEqualTo($type)) {
                // And this new type is what we already inferred, so there's nothing to do
                return $this;
            }
            $override_type = ArrayShapeType::fromFieldTypes([$property->getName() => $type], false);
            $variable = new Variable(
                $this,
                self::VAR_NAME_THIS_PROPERTIES,
                $override_type->asPHPDocUnionType(),
                0
            );
        }
        return $this->withScopeVariable($variable);
    }

    /**
     * Analyzes the side effects of setting the type of $this->property_name to $type
     *
     * The caller should check if it is necessary to do this.
     */
    public function withThisPropertySetToTypeByName(string $property_name, UnionType $type): Context
    {
        if ($this->scope->hasVariableWithName(self::VAR_NAME_THIS_PROPERTIES)) {
            $variable = clone($this->scope->getVariableByName(self::VAR_NAME_THIS_PROPERTIES));
            $old_type = $variable->getUnionType();
            $override_type = ArrayShapeType::fromFieldTypes([$property_name => $type], false);
            $override_type = self::addArrayShapeTypes($override_type, $old_type->getTypeSet());

            $variable->setUnionType($override_type->asPHPDocUnionType());
        } else {
            // There is nothing inferred about any type

            $override_type = ArrayShapeType::fromFieldTypes([$property_name => $type], false);
            $variable = new Variable(
                $this,
                self::VAR_NAME_THIS_PROPERTIES,
                $override_type->asPHPDocUnionType(),
                0
            );
        }
        return $this->withScopeVariable($variable);
    }

    /**
     * @param list<Type> $type_set
     */
    private static function addArrayShapeTypes(ArrayShapeType $override_type, array $type_set): ArrayShapeType
    {
        if (!$type_set) {
            return $override_type;
        }
        $array_shape_type_set = [];
        foreach ($type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                $array_shape_type_set[] = $type;
            }
        }
        if ($array_shape_type_set) {
            // Add in all of the locally known types for other properties
            $override_type = ArrayShapeType::combineWithPrecedence($override_type, ArrayShapeType::union($array_shape_type_set));
        }
        return $override_type;
    }

    public function getThisPropertyIfOverridden(string $name): ?UnionType
    {
        if (!$this->scope->hasVariableWithName(self::VAR_NAME_THIS_PROPERTIES)) {
            return null;
        }
        $types = $this->scope->getVariableByName(self::VAR_NAME_THIS_PROPERTIES)->getUnionType();
        if ($types->isEmpty()) {
            return null;
        }

        $result = null;
        foreach ($types->getTypeSet() as $type) {
            if (!$type instanceof ArrayShapeType) {
                return null;
            }
            $extra = $type->getFieldTypes()[$name] ?? null;
            if (!$extra || ($extra->isPossiblyUndefined() && !$extra->isDefinitelyUndefined())) {
                return null;
            }
            if ($result) {
                '@phan-var UnionType $result';
                $result = $result->withUnionType($extra);
            } else {
                $result = $extra;
            }
        }
        return $result;
    }
}
