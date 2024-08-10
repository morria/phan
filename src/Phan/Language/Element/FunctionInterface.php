<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use ast\Node;
use Closure;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\Scope\ClosedScope;
use Phan\Language\Type;
use Phan\Language\Type\FunctionLikeDeclarationType;
use Phan\Language\Type\TemplateType;
use Phan\Language\UnionType;
use Phan\PluginV3;

/**
 * Interface defining the behavior of both Methods and Functions
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
interface FunctionInterface extends AddressableElementInterface
{
    /**
     * An easy workaround to mark a function-like as accepting an infinite number of optional parameters
     * TODO: Distinguish between __call and __callStatic invoked manually and via magic (See uses of this constant)
     */
    public const INFINITE_PARAMETERS = 999999;

    /**
     * @return FullyQualifiedMethodName|FullyQualifiedFunctionName
     * The fully-qualified structural element name of this
     * structural element
     */
    public function getFQSEN();

    /**
     * @return string
     * The fully-qualified structural element name and args of this function-like.
     */
    public function getRepresentationForIssue(bool $show_args = false): string;

    /**
     * @return string
     * The name of this structural element (without namespace/class),
     * or a string for FunctionLikeDeclarationType (or a closure) which lacks a real FQSEN
     */
    public function getNameForIssue(): string;

    /**
     * Sets the scope within this function-like element's body,
     * for tracking variables within the function-like.
     */
    public function setInternalScope(ClosedScope $internal_scope): void;

    /**
     * @return ClosedScope
     * The internal scope of this closed scope element
     */
    public function getInternalScope(): ClosedScope;

    /**
     * @return int
     * The number of optional parameters on this method
     */
    public function getNumberOfOptionalParameters(): int;

    /**
     * The number of optional parameters
     */
    public function setNumberOfOptionalParameters(int $number): void;

    /**
     * @return int
     * The number of optional real parameters on this function/method.
     * This may differ from getNumberOfOptionalParameters()
     * for internal modules lacking proper reflection info,
     * or if the installed module version's API changed from what Phan's stubs used,
     * or if a function/method uses variadics/func_get_arg*()
     */
    public function getNumberOfOptionalRealParameters(): int;

    /**
     * @return int
     * The maximum number of parameters to this method
     */
    public function getNumberOfParameters(): int;

    /**
     * @return int
     * The number of required parameters on this method
     */
    public function getNumberOfRequiredParameters(): int;

    /**
     * The number of required parameters
     */
    public function setNumberOfRequiredParameters(int $number): void;

    /**
     * @return int
     * The number of required real parameters on this function/method.
     * This may differ for internal modules lacking proper reflection info,
     * or if the installed module version's API changed from what Phan's stubs used.
     */
    public function getNumberOfRequiredRealParameters(): int;

    /**
     * @return bool
     * True if this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     */
    public function isReturnTypeUndefined(): bool;

    /**
     * Sets whether this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     *
     * @param bool $is_return_type_undefined
     * True if it was undefined
     */
    public function setIsReturnTypeUndefined(
        bool $is_return_type_undefined
    ): void;

    /**
     * @return bool
     * True if this method returns a value
     */
    public function hasReturn(): bool;

    /**
     * @param bool $has_return
     * Set to true to mark this method as having a
     * return value (Only through `return`)
     */
    public function setHasReturn(bool $has_return): void;

    /**
     * @param bool $has_yield
     * Set to true to mark this method as having a
     * yield statement (Only through `yield`)
     * This implies that it has a return value of \Generator.
     * (or a parent interface or type)
     */
    public function setHasYield(bool $has_yield): void;

    /**
     * @return bool
     * True if this method yields any value(i.e. it is a \Generator)
     */
    public function hasYield(): bool;

    /**
     * @param bool $has_static_variable
     * Set to true to mark this method as having a
     * static variable.
     */
    public function setHasStaticVariable(bool $has_static_variable): void;

    /**
     * @return bool
     * True if this method has any static variables
     */
    public function hasStaticVariable(): bool;


    /**
     * @return list<Parameter>
     * A list of parameters on the method
     */
    public function getParameterList();

    /**
     * @param list<Parameter> $parameter_list
     * A list of parameters to set on this method
     * (When quick_mode is false, this is also called to temporarily
     * override parameter types, etc.)
     * @internal
     */
    public function setParameterList(array $parameter_list): void;

    /**
     * @internal - moves real parameter defaults to the inferred phpdoc parameters
     */
    public function inheritRealParameterDefaults(): void;

    /**
     * Gets the $ith parameter for the **caller**.
     * In the case of variadic arguments, an infinite number of parameters exist.
     * (The callee would see variadic arguments(T ...$args) as a single variable of type T[],
     * while the caller sees a place expecting an expression of type T.
     *
     * @param int $i - offset of the parameter.
     * @return Parameter|null The parameter type that the **caller** observes.
     */
    public function getParameterForCaller(int $i): ?Parameter;

    /**
     * Gets the $ith real parameter for the **caller**.
     * In the case of variadic arguments, an infinite number of parameters exist.
     * (The callee would see variadic arguments(T ...$args) as a single variable of type T[],
     * while the caller sees a place expecting an expression of type T.
     *
     * @param int $i - offset of the parameter.
     * @return Parameter|null The parameter type that the **caller** observes.
     */
    public function getRealParameterForCaller(int $i): ?Parameter;

    /**
     * @param Parameter $parameter
     * A parameter to append to the parameter list
     */
    public function appendParameter(Parameter $parameter): void;

    /**
     * @return void
     *
     * Call this before calling appendParameter, if parameters were already added.
     */
    public function clearParameterList(): void;

    /**
     * Records the fact that $parameter_name is an output-only reference.
     * @param string $parameter_name
     */
    public function recordOutputReferenceParamName(string $parameter_name): void;

    /**
     * @return list<string> list of output references (annotated with (at)phan-output-reference. Usually empty.
     */
    public function getOutputReferenceParamNames(): array;

    /**
     * @return \Generator<static>
     * The set of all alternates to this function
     */
    public function alternateGenerator(CodeBase $code_base): \Generator;

    /**
     * @param CodeBase $code_base
     * The code base in which this element exists.
     *
     * @return bool
     * True if this is marked as an element internal to
     * its namespace
     */
    public function isNSInternal(CodeBase $code_base): bool;

    /**
     * @param CodeBase $code_base
     * The code base in which this element exists.
     *
     * @return bool
     * True if this element is internal to the namespace
     */
    public function isNSInternalAccessFromContext(
        CodeBase $code_base,
        Context $context
    ): bool;

    /**
     * @return Context
     * Analyze the node associated with this object
     * in the given context
     */
    public function analyze(Context $context, CodeBase $code_base): Context;

    /**
     * @return Context
     * Analyze the node associated with this object
     * in the given context.
     * This function's parameter list may or may not have been modified.
     * @param list<Parameter> $parameter_list
     */
    public function analyzeWithNewParams(Context $context, CodeBase $code_base, array $parameter_list): Context;

    /**
     * @return string the namespace in which this function interface was declared.
     *
     * Used for checking (at)internal annotations, etc.
     */
    public function getElementNamespace(): string;

    /**
     * @return UnionType
     * The type of this method in its given context.
     */
    public function getRealReturnType(): UnionType;

    /**
     * @return list<Parameter>
     * A list of parameters on the method, with types from the method signature.
     */
    public function getRealParameterList();

    /**
     * @param array<string,UnionType> $parameter_map maps a subset of param names to the unmodified phpdoc parameter types.
     * Will differ from real parameter types (ideally narrower)
     */
    public function setPHPDocParameterTypeMap(array $parameter_map): void;

    /**
     * @return array<string,UnionType> maps a subset of param names to the unmodified phpdoc parameter types.
     */
    public function getPHPDocParameterTypeMap(): array;

    /**
     * @param ?UnionType $union_type the raw phpdoc union type
     */
    public function setPHPDocReturnType(?UnionType $union_type): void;

    /**
     * @return ?UnionType the raw phpdoc union type
     */
    public function getPHPDocReturnType(): ?UnionType;

    /**
     * @return bool
     * True if this function or method returns a reference
     */
    public function returnsRef(): bool;

    /**
     * Returns true if the return type depends on the argument, and a plugin makes Phan aware of that.
     */
    public function hasDependentReturnType(): bool;

    /**
     * Returns a union type based on $args_node and $context
     * @param CodeBase $code_base
     * @param Context $context
     * @param list<Node|int|string|float> $args
     */
    public function getDependentReturnType(CodeBase $code_base, Context $context, array $args): UnionType;

    /**
     * Make calculation of the return type of this function/method use $closure
     */
    public function setDependentReturnTypeClosure(Closure $closure): void;

    /**
     * Returns true if this function or method has additional analysis logic for invocations (From internal and user defined plugins)
     * @see getDependentReturnType
     */
    public function hasFunctionCallAnalyzer(): bool;

    /**
     * Perform additional analysis logic for invocations (From internal and user defined plugins)
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param list<Node|int|string|float> $args
     * @param ?Node $node - the node causing the call. This may be dynamic, e.g. call_user_func_array. This will be required in Phan 3.
     */
    public function analyzeFunctionCall(CodeBase $code_base, Context $context, array $args, ?Node $node = null): void;

    /**
     * Make additional analysis logic of this function/method use $closure
     * If callers need to invoke multiple closures, they should pass in a closure to invoke multiple closures with addFunctionCallAnalyzer and the plugin that created the closure.
     * @suppress PhanUnreferencedPublicMethod
     * @deprecated
     */
    public function setFunctionCallAnalyzer(Closure $closure): void;

    /**
     * If callers need to invoke multiple closures, they should pass in a closure to invoke multiple closures.
     * @param ?PluginV3 $plugin @phan-mandatory-param
     */
    public function addFunctionCallAnalyzer(Closure $closure, ?PluginV3 $plugin = null): void;

    /**
     * Initialize the inner scope of this method with variables created from the parameters.
     *
     * Deferred until the parse phase because getting the UnionType of parameter defaults requires having all class constants be known.
     */
    public function ensureScopeInitialized(CodeBase $code_base): void;

    /** Get the node with the declaration of this function/method, if it exists and if Phan is running in non-quick mode */
    public function getNode(): ?Node;

    /**
     * @return ?Comment - Not set for internal functions/methods
     */
    public function getComment(): ?Comment;

    /**
     * @return string - a suffix for an issue message indicating the cause for deprecation. This string is empty if the cause is unknown.
     */
    public function getDeprecationReason(): string;

    /**
     * Set the comment data for this function/method
     */
    public function setComment(Comment $comment): void;

    /**
     * Mark this function or method as pure (having no visible side effects)
     */
    public function setIsPure(): void;

    /**
     * Check if this function or method is marked as pure (having no visible side effects)
     */
    public function isPure(): bool;

    /**
     * Mark this function or method as having a tentative return type
     */
    public function setHasTentativeReturnType(): void;

    /**
     * Check if this function has a tentative return type
     */
    public function hasTentativeReturnType(): bool;

    /**
     * @return UnionType of 0 or more types from (at)throws annotations on this function-like. This only includes
     * types that are specifically mentioned in the function-like phpdoc (and not, for instance, types inherited from
     * an ancestor method).
     */
    public function getOwnThrowsUnionType(): UnionType;

    /**
     * @return UnionType of 0 or more types from (at)throws annotations on this function-like. This might include types
     * that are not specifically mentioned in the function-like phpdoc (for instance, types inherited from an
     * ancestor method).
     */
    public function getFullThrowsUnionType(): UnionType;

    /**
     * @return bool
     * True if this is a magic phpdoc method (declared via (at)method on class declaration phpdoc)
     * Always false for global functions(Func).
     */
    public function isFromPHPDoc(): bool;

    /**
     * Clone the parameter list, so that modifying the parameters on the first call won't modify the others.
     * TODO: If parameters were changed to be immutable, they can be shared without cloning with less worry.
     * @internal
     */
    public function cloneParameterList(): void;

    /**
     * @return bool - Does any parameter type possibly require recursive analysis if more specific types are provided?
     *
     * If this returns true, there is at least one parameter and at least one of those can be overridden with a more specific type.
     */
    public function needsRecursiveAnalysis(): bool;

    /**
     * Returns a FunctionLikeDeclarationType based on phpdoc+real types.
     * The return value is used for type casting rule checking.
     */
    public function asFunctionLikeDeclarationType(): FunctionLikeDeclarationType;

    /**
     * @return array<mixed,string> in the same format as FunctionSignatureMap.php
     */
    public function toFunctionSignatureArray(): array;

    /**
     * Precondition: This function is a generator type
     * Converts Generator|T[] to Generator<T>
     * Converts Generator|array<int,stdClass> to Generator<int,stdClass>, etc.
     */
    public function getReturnTypeAsGeneratorTemplateType(): Type;

    /**
     * Returns this function's union type without resolving `static` in the function declaration's context.
     */
    public function getUnionTypeWithUnmodifiedStatic(): UnionType;

    /**
     * Check this method's return types (phpdoc and real) to make sure they're valid,
     * and infer a return type from the combination of the signature and phpdoc return types.
     */
    public function analyzeReturnTypes(CodeBase $code_base): void;

    /**
     * Does this function/method declare an (at)template type for this type?
     */
    public function declaresTemplateTypeInComment(TemplateType $template_type): bool;

    /**
     * Create any plugins that exist due to doc comment annotations.
     * Must be called after adding this FunctionInterface to the $code_base, so that issues can be emitted if needed.
     * @return ?Closure(CodeBase, Context, FunctionInterface, list<Node|string|int|float>):UnionType
     * @internal
     */
    public function getCommentParamAssertionClosure(CodeBase $code_base): ?Closure;

    /**
     * @return array<mixed, UnionType> very conservatively maps variable names to union types they can have.
     * Entries are omitted if there are possible assignments that aren't known.
     *
     * This is useful as a fallback for determining missing types when analyzing the first iterations of loops.
     *
     * Other approaches, such as analyzing loops multiple times, are possible, but not implemented.
     */
    public function getVariableTypeFallbackMap(CodeBase $code_base): array;

    /**
     * Gets the original union type of this function/method.
     *
     * This is populated the first time it is called.
     */
    public function getOriginalReturnType(): UnionType;

    /**
     * Record the existence of a parameter with an `(at)phan-mandatory-param` comment at $offset
     */
    public function recordHasMandatoryPHPDocParamAtOffset(int $parameter_offset): void;

    /**
     * Set the attributes associated with this function-like
     * @param list<Attribute> $attribute_list
     */
    public function setAttributeList(array $attribute_list): void;

    /**
     * Get the attributes associated with this function-like
     * @return list<Attribute>
     */
    public function getAttributeList(): array;

    /**
     * Get the phan flags associated with this function-like
     */
    public function getPhanFlags(): int;

    /**
     * Check if this function was implemented with (at)no-named-arguments
     */
    public function hasNoNamedArguments(): bool;
}
