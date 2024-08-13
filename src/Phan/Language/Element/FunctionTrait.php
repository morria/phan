<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use AssertionError;
use ast;
use ast\Node;
use Closure;
use Phan\Analysis\ConditionVisitor;
use Phan\Analysis\FallbackMethodTypesVisitor;
use Phan\Analysis\NegatedConditionVisitor;
use Phan\Analysis\ParameterTypesAnalyzer;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\RecursionDepthException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Comment\Assertion;
use Phan\Language\FileRef;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\ClosureDeclarationParameter;
use Phan\Language\Type\ClosureDeclarationType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\FunctionLikeDeclarationType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\StaticOrSelfType;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\TrueType;
use Phan\Language\Type\VoidType;
use Phan\Language\UnionType;
use Phan\Plugin\ConfigPluginSet;
use Phan\PluginV3;

use function count;
use function end;
use function is_int;
use function spl_object_id;

/**
 * This contains functionality common to global functions, closures, and methods
 * @see FunctionInterface - Classes using this trait use that interface
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @phan-file-suppress PhanPluginNoCommentOnPublicMethod TODO: Add comments
 */
trait FunctionTrait
{
    use HasAttributesTrait;

    /**
     * @var Comment|null This is reused when quick mode is off.
     */
    protected $comment;

    /**
     * Did we initialize the inner scope of this method?
     * Deferred because hydrating parameter defaults requires having all class constants be known
     * @var bool This is set to true immediately after scope initialization is finished.
     */
    protected $is_inner_scope_initialized  = false;

    /**
     * @var ?int set by (at)phan-mandatory-param comments
     */
    protected $last_mandatory_phpdoc_param_offset  = null;

    /** @return int flags from \Phan\Language\Element\Flags */
    abstract public function getPhanFlags(): int;

    /** @return bool true if all of the bits in $bits are true in $this->getPhanFlags() */
    abstract public function getPhanFlagsHasState(int $bits): bool;

    abstract public function setPhanFlags(int $phan_flags): void;

    /**
     * @return string
     * The (not fully-qualified) name of this element.
     */
    abstract public function getName(): string;

    /**
     * @return FQSEN
     * The fully-qualified structural element name of this
     * structural element
     */
    abstract public function getFQSEN(): FQSEN;

    /**
     * @return string
     * A representation of this function, closure, or method,
     * for issue messages.
     */
    public function getRepresentationForIssue(bool $show_args = false): string
    {
        $args_repr = '';
        if ($show_args) {
            $parameter_list = $this->parameter_list;
            if ($parameter_list) {
                $is_internal = $this->isPHPInternal();
                $args_repr = \implode(', ', \array_map(static function (Parameter $parameter) use ($is_internal): string {
                    return $parameter->getShortRepresentationForIssue($is_internal);
                }, $parameter_list));
            }
        }
        return $this->getFQSEN()->__toString() . '(' . $args_repr . ')';
    }

    /**
     * @return string
     * The name of this structural element (without namespace/class),
     * or a string for FunctionLikeDeclarationType which lacks a real FQSEN
     */
    public function getNameForIssue(): string
    {
        return $this->getName() . '()';
    }

    /**
     * @var int
     * The number of required parameters for the method
     */
    private $number_of_required_parameters = 0;

    /**
     * @var int
     * The number of optional parameters for the method.
     * Note that this is set to a large number in methods using varargs or func_get_arg*()
     */
    private $number_of_optional_parameters = 0;

    /**
     * @var int
     * The number of required (real) parameters for the method declaration.
     * For internal methods, ignores Phan's annotations.
     */
    private $number_of_required_real_parameters = 0;

    /**
     * @var int
     * The number of optional (real) parameters for the method declaration.
     * For internal methods, ignores Phan's annotations.
     * For user-defined methods, ignores presence of func_get_arg*()
     */
    private $number_of_optional_real_parameters = 0;

    /**
     * @var bool|null
     * Does any parameter type possibly require recursive analysis if more specific types are provided?
     * Caches the return value for $this->needsRecursiveAnalysis()
     */
    private $needs_recursive_analysis = null;

    /**
     * @var list<Parameter>
     * The list of parameters for this method
     * This will change while the method is being analyzed when the config quick_mode is false.
     */
    private $parameter_list = [];

    /**
     * @var ?int
     * The hash of the types for the list of parameters for this function/method.
     */
    private $parameter_list_hash = null;

    /**
     * @var ?bool
     * Whether or not this function/method has any pass by reference parameters.
     */
    private $has_pass_by_reference_parameters = null;

    /**
     * @var array<int,int>
     * @phan-var associative-array<int,int>
     * If the types for a parameter list were checked,
     * this contains the recursion depth for a given integer hash (smaller is earlier in recursion)
     */
    private $checked_parameter_list_hashes = [];

    /**
     * @var list<Parameter>
     * The list of *real* (not from phpdoc) parameters for this method.
     * This does not change after initialization.
     */
    private $real_parameter_list = [];

    /**
     * @var array<string,UnionType>
     * The list of unmodified *phpdoc* parameter types for this method.
     * This does not change after initialization.
     */
    private $phpdoc_parameter_type_map = [];

    /**
     * @var list<string>
     * A list of parameter names that are output-only references
     */
    private $phpdoc_output_references = [];

    /**
     * @var ?UnionType
     * The unmodified *phpdoc* union type for this method.
     * Will be null without any (at)return statements.
     */
    private $phpdoc_return_type;

    /**
     * @var UnionType
     * The *real* (not from phpdoc) return type from this method.
     * This does not change after initialization.
     */
    private $real_return_type;

    /**
     * @var ?Closure(CodeBase, Context, FunctionInterface, list<Node|int|string|float>):UnionType
     */
    private $return_type_callback = null;

    /**
     * @var ?Closure(CodeBase, Context, FunctionInterface, list<Node|int|string|float>, ?Node):void
     */
    private $function_call_analyzer_callback = null;

    /**
     * @var array<int,true>
     */
    private $function_call_analyzer_callback_set = [];

    /**
     * @var FunctionLikeDeclarationType|null (Lazily generated representation of this as a closure type)
     */
    private $as_closure_declaration_type;

    /**
     * @var Type|null (Lazily generated representation of this as a generator type)
     */
    private $as_generator_template_type;

    /**
     * @var ?UnionType
     */
    private $original_return_type;

    /**
     * @return int
     * The number of optional real parameters on this function/method.
     * This may differ from getNumberOfOptionalParameters()
     * for internal modules lacking proper reflection info,
     * or if the installed module version's API changed from what Phan's stubs used,
     * or if a function/method uses variadics/func_get_arg*()
     *
     * @suppress PhanUnreferencedPublicMethod this is made available for plugins
     */
    public function getNumberOfOptionalRealParameters(): int
    {
        return $this->number_of_optional_real_parameters;
    }

    /**
     * @return int
     * The number of optional parameters on this method
     */
    public function getNumberOfOptionalParameters(): int
    {
        return $this->number_of_optional_parameters;
    }

    /**
     * The number of optional parameters
     */
    public function setNumberOfOptionalParameters(int $number): void
    {
        $this->number_of_optional_parameters = $number;
    }

    /**
     * @return int
     * The number of parameters in this function/method declaration.
     * Variadic parameters are counted only once.
     * TODO: Specially handle variadic parameters, either here or in ParameterTypesAnalyzer::analyzeOverrideRealSignature
     */
    public function getNumberOfRealParameters(): int
    {
        return $this->number_of_required_real_parameters +
               $this->number_of_optional_real_parameters;
    }

    /**
     * @return int
     * The maximum number of parameters to this function/method
     */
    public function getNumberOfParameters(): int
    {
        return $this->number_of_required_parameters +
               $this->number_of_optional_parameters;
    }

    /**
     * @return int
     * The number of required real parameters on this function/method.
     * This may differ for internal modules lacking proper reflection info,
     * or if the installed module version's API changed from what Phan's stubs used.
     */
    public function getNumberOfRequiredRealParameters(): int
    {
        return $this->number_of_required_real_parameters;
    }

    /**
     * @return int
     * The number of required parameters on this function/method
     */
    public function getNumberOfRequiredParameters(): int
    {
        return $this->number_of_required_parameters;
    }

    /**
     * Set the number of required parameters
     */
    public function setNumberOfRequiredParameters(int $number): void
    {
        $this->number_of_required_parameters = $number;
    }

    /**
     * @return bool
     * True if this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     */
    public function isReturnTypeUndefined(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_RETURN_TYPE_UNDEFINED);
    }

    /**
     * @return bool
     * True if any of the following are true:
     * 1) This method had no return type defined when it was defined
     * 2) The setting `allow_overriding_vague_return_types` is enabled and the method had a vague enough return
     *      type that Phan would add types to it (return type is inferred from the method signature
     *      itself and the docblock).
     * 3) The setting `override_return_types` is enabled and the method has no hardcoded or dependent return type.
     */
    public function isReturnTypeModifiable(): bool
    {
        if ($this->isReturnTypeUndefined()) {
            return true;
        }

        $has_hardcoded_return_type = (bool) ($this->getPhanFlags() & Flags::HARDCODED_RETURN_TYPE);

        if (Config::getValue('override_return_types')) {
            if ($has_hardcoded_return_type || $this->hasDependentReturnType()) {
                // If the return type is hardcoded or we have a plugin that's inferring the return
                // type based on method params, assume that those will do a better job of
                // determining what the return type should actually be.
                return false;
            } else {
                return true;
            }
        }

        if (!Config::getValue('allow_overriding_vague_return_types')) {
            return false;
        }
        // Don't allow overriding method types if they have known overrides
        if ($this instanceof Method && $this->isOverriddenByAnother()) {
            return false;
        }
        if ($has_hardcoded_return_type) {
            return false;
        }
        $return_type = $this->getUnionType();
        // expect that $return_type has at least one type if isReturnTypeUndefined is false.
        foreach ($return_type->getTypeSet() as $type) {
            // Allow adding more specific types to ObjectType or MixedType.
            // TODO: Allow adding more specific types to Array
            if ($type instanceof ObjectType || $type instanceof MixedType) {
                return true;
            }
        }
        return false;
    }


    /**
     * @param bool $is_return_type_undefined
     * True if this method had no return type defined when it
     * was defined (either in the signature itself or in the
     * docblock).
     */
    public function setIsReturnTypeUndefined(
        bool $is_return_type_undefined
    ): void {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_RETURN_TYPE_UNDEFINED,
            $is_return_type_undefined
        ));
    }

    /**
     * @return bool
     * True if this method returns a value
     * (i.e. it has a return with an expression)
     */
    public function hasReturn(): bool
    {
        return $this->getPhanFlagsHasState(Flags::HAS_RETURN);
    }

    /**
     * @return bool
     * True if this method yields any value(i.e. it is a \Generator)
     */
    public function hasYield(): bool
    {
        return $this->getPhanFlagsHasState(Flags::HAS_YIELD);
    }

    /**
     * @return bool
     * True if this method has static variables
     */
    public function hasStaticVariable(): bool
    {
        return $this->getPhanFlagsHasState(Flags::HAS_STATIC_VARIABLE);
    }

    /**
     * @param bool $has_return
     * Set to true to mark this method as having a
     * return value
     */
    public function setHasReturn(bool $has_return): void
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::HAS_RETURN,
            $has_return
        ));
    }

    /**
     * @param bool $has_yield
     * Set to true to mark this method as having a
     * yield value
     */
    public function setHasYield(bool $has_yield): void
    {
        // TODO: In a future release of php-ast, this information will be part of the function node's flags.
        // (PHP 7.1+ only, not supported in PHP 7.0)
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::HAS_YIELD,
            $has_yield
        ));
    }

    /**
     * @param bool $has_static_variable
     * Set to true to mark this method as having a
     * static variable
     */
    public function setHasStaticVariable(bool $has_static_variable): void
    {
        // TODO: In a future release of php-ast, this information will be part of the function node's flags.
        // (PHP 7.1+ only, not supported in PHP 7.0)
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::HAS_STATIC_VARIABLE,
            $has_static_variable
        ));
    }

    /**
     * @return list<Parameter>
     * A list of parameters on the method
     *
     * @suppress PhanPluginCanUseReturnType
     * FIXME: Figure out why adding `: array` causes failures elsewhere (combination with interface?)
     */
    public function getParameterList()
    {
        return $this->parameter_list;
    }

    /**
     * @return bool - Does any parameter type possibly require recursive analysis if more specific types are provided?
     *
     * If this returns true, there is at least one parameter and at least one of those can be overridden with a more specific type.
     */
    public function needsRecursiveAnalysis(): bool
    {
        return $this->needs_recursive_analysis ?? ($this->needs_recursive_analysis = $this->computeNeedsRecursiveAnalysis());
    }

    private function computeNeedsRecursiveAnalysis(): bool
    {
        if (!$this->getNode()) {
            // E.g. this can be the case for magic methods, internal methods, stubs, etc.
            return false;
        }

        foreach ($this->parameter_list as $parameter) {
            if ($parameter->getNonVariadicUnionType()->shouldBeReplacedBySpecificTypes()) {
                return true;
            }
            if ($parameter->isPassByReference() && $parameter->getReferenceType() !== Flags::IS_WRITE_REFERENCE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Gets the $ith parameter for the **caller**.
     * In the case of variadic arguments, an infinite number of parameters exist.
     * (The callee would see variadic arguments(T ...$args) as a single variable of type T[],
     * while the caller sees a place expecting an expression of type T.
     *
     * @param int $i - offset of the parameter.
     * @return Parameter|null The parameter type that the **caller** observes.
     */
    public function getParameterForCaller(int $i): ?Parameter
    {
        $list = $this->parameter_list;
        if (count($list) === 0) {
            return null;
        }
        $parameter = $list[$i] ?? null;
        if ($parameter) {
            return $parameter->asNonVariadic();
        }
        $last_parameter = $list[count($list) - 1];
        if ($last_parameter->isVariadic()) {
            return $last_parameter->asNonVariadic();
        }
        return null;
    }

    /**
     * Gets the $ith parameter for the **caller** (with real types).
     * In the case of variadic arguments, an infinite number of parameters exist.
     * (The callee would see variadic arguments(T ...$args) as a single variable of type T[],
     * while the caller sees a place expecting an expression of type T.
     *
     * @param int $i - offset of the parameter.
     * @return Parameter|null The real parameter type (from php signature) that the **caller** observes.
     */
    public function getRealParameterForCaller(int $i): ?Parameter
    {
        $list = $this->real_parameter_list;
        if (count($list) === 0) {
            return null;
        }
        $parameter = $list[$i] ?? null;
        if ($parameter) {
            return $parameter->asNonVariadic();
        }
        $last_parameter = $list[count($list) - 1];
        if ($last_parameter->isVariadic()) {
            return $last_parameter->asNonVariadic();
        }
        return null;
    }

    /**
     * @param list<Parameter> $parameter_list
     * A list of parameters to set on this method
     * (When quick_mode is false, this is also called to temporarily
     * override parameter types, etc.)
     * @internal
     */
    public function setParameterList(array $parameter_list): void
    {
        $this->parameter_list = $parameter_list;
        if (\is_null($this->parameter_list_hash)) {
            $this->initParameterListInfo();
        }
    }

    /**
     * Called to lazily initialize properties of $this derived from $this->parameter_list
     */
    private function initParameterListInfo(): void
    {
        $parameter_list = $this->parameter_list;
        $this->parameter_list_hash = self::computeParameterListHash($parameter_list);
        $has_pass_by_reference_parameters = false;
        foreach ($parameter_list as $param) {
            if ($param->isPassByReference()) {
                $has_pass_by_reference_parameters = true;
                break;
            }
        }
        $this->has_pass_by_reference_parameters = $has_pass_by_reference_parameters;
    }

    /**
     * Called to generate a hash of a given parameter list, to avoid calling this on the same parameter list twice.
     *
     * @param list<Parameter> $parameter_list
     *
     * @return int 32-bit or 64-bit hash. Not likely to collide unless there are around 2^16 possible union types on 32-bit, or around 2^32 on 64-bit.
     *    (Collisions aren't a concern; The memory/runtime would probably be a bigger issue than collisions in non-quick mode.)
     */
    private static function computeParameterListHash(array $parameter_list): int
    {
        // Choosing a small value to fit inside of a packed array.
        if (\count($parameter_list) === 0) {
            return 0;
        }
        if (Config::get_quick_mode()) {
            return 0;
        }
        $param_repr = '';
        foreach ($parameter_list as $param) {
            $param_repr .= $param->getUnionType()->__toString() . ',';
        }
        $raw_bytes = \md5($param_repr, true);
        return \unpack(\PHP_INT_SIZE === 8 ? 'q' : 'l', $raw_bytes)[1];
    }

    /**
     * @return list<Parameter> $parameter_list
     * A list of parameters (not from phpdoc) that were set on this method. The parameters will be cloned.
     *
     * @suppress PhanPluginCanUseReturnType
     * FIXME: Figure out why adding `: array` causes failures elsewhere (combination with interface?)
     */
    public function getRealParameterList()
    {
        // Excessive cloning, to ensure that this stays immutable.
        return \array_map(static function (Parameter $param): Parameter {
            return clone($param);
        }, $this->real_parameter_list);
    }

    /**
     * @param list<Parameter> $parameter_list
     * A list of parameters (not from phpdoc) to set on this method. The parameters will be cloned.
     */
    public function setRealParameterList(array $parameter_list): void
    {
        $this->real_parameter_list = \array_map(static function (Parameter $param): Parameter {
            return clone($param);
        }, $parameter_list);

        $required_count = self::computeNumberOfRequiredParametersForList($parameter_list);
        $optional_count = \count($parameter_list) - $required_count;
        $this->number_of_required_real_parameters = $required_count;
        $this->number_of_optional_real_parameters = $optional_count;
    }

    /**
     * @internal - moves real parameter defaults to the inferred phpdoc parameters
     */
    public function inheritRealParameterDefaults(): void
    {
        foreach ($this->real_parameter_list as $i => $real_parameter) {
            $parameter = $this->parameter_list[$i] ?? null;
            if (!$parameter || $parameter->isVariadic() || $real_parameter->isVariadic()) {
                // No more parameters
                // TODO: Properly inherit variadic real types
                return;
            }
            $real_type = $real_parameter->getUnionType();
            if (!$real_type->isEmpty()) {
                $parameter->setUnionType($parameter->getUnionType()->withRealTypeSet($real_type->getTypeSet()));
            }
            if (!$real_parameter->hasDefaultValue()) {
                continue;
            }

            if (!$parameter->isOptional() || $parameter->isVariadic()) {
                continue;
            }
            $parameter->copyDefaultValueFrom($real_parameter);
        }
    }

    /**
     * @param list<Parameter> $parameter_list
     */
    protected static function computeNumberOfRequiredParametersForList(array $parameter_list): int
    {
        for ($i = \count($parameter_list) - 1; $i >= 0; $i--) {
            $parameter = $parameter_list[$i];
            if (!$parameter->isOptional()) {
                return $i + 1;
            }
        }
        return 0;
    }

    /**
     * @param UnionType $union_type
     * The real (non-phpdoc) return type of this method in its given context.
     */
    public function setRealReturnType(UnionType $union_type): void
    {
        // TODO: was `self` properly resolved already? What about in subclasses?
        $this->real_return_type = $union_type;
    }

    /**
     * @return UnionType
     * The type of this method in its given context.
     */
    public function getRealReturnType(): UnionType
    {
        if (!$this->real_return_type) {
            // Incomplete patch for https://github.com/phan/phan/issues/670
            return UnionType::empty();
            // throw new \Error(sprintf("Failed to get real return type in %s method %s", (string)$this->getClassFQSEN(), (string)$this));
        }
        // Clone the union type, to be certain it will remain immutable.
        return $this->real_return_type;
    }

    /**
     * @param Parameter $parameter
     * A parameter to append to the parameter list
     * @internal
     */
    public function appendParameter(Parameter $parameter): void
    {
        $this->parameter_list[] = $parameter;
    }

    /**
     * @return void
     *
     * Call this before calling appendParameter, if parameters were already added.
     * @internal
     */
    public function clearParameterList(): void
    {
        $this->parameter_list = [];
        $this->parameter_list_hash = null;
    }

    /**
     * Adds types from comments to the params of a user-defined function or method.
     * Also adds the types from defaults, and emits warnings for certain violations.
     *
     * Conceptually, Func and Method should have defaults/comments analyzed in the same way.
     *
     * This does nothing if $function is for an internal method.
     *
     * @param Context $context
     * The context in which the node appears
     *
     * @param CodeBase $code_base
     *
     * @param FunctionInterface $function - A Func or Method to add params to the local scope of.
     *
     * @param Comment $comment - processed doc comment of $node, with params
     */
    public static function addParamsToScopeOfFunctionOrMethod(
        Context $context,
        CodeBase $code_base,
        FunctionInterface $function,
        Comment $comment
    ): void {
        if ($function->isPHPInternal()) {
            return;
        }
        $parameter_offset = 0;
        $function_parameter_list = $function->getParameterList();
        $real_parameter_name_map = [];
        if ($function->getPhanFlags() & Flags::NO_NAMED_ARGUMENTS) {
            foreach ($function_parameter_list as $parameter) {
                $parameter->setHasNoNamedArguments();
            }
        }
        foreach ($function_parameter_list as $parameter) {
            $real_parameter_name_map[$parameter->getName()] = $parameter;
            self::addParamToScopeOfFunctionOrMethod(
                $context,
                $code_base,
                $function,
                $comment,
                $parameter_offset,
                $parameter
            );
            ++$parameter_offset;
        }

        $valid_comment_parameter_type_map = [];
        foreach ($comment->getParameterMap() as $comment_parameter_name => $comment_parameter) {
            if (!\array_key_exists($comment_parameter_name, $real_parameter_name_map)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    count($real_parameter_name_map) > 0 ? Issue::CommentParamWithoutRealParam : Issue::CommentParamOnEmptyParamList,
                    $comment_parameter->getLineno(),
                    $comment_parameter_name,
                    (string)$function
                );
                continue;
            }
            // Record phpdoc types to check if they are narrower than real types, later.
            // Only keep non-empty types.
            $comment_parameter_type = $comment_parameter->getUnionType();
            if (!$comment_parameter_type->isEmpty()) {
                $valid_comment_parameter_type_map[$comment_parameter_name] = $comment_parameter_type;
            }
            if ($comment_parameter->isIgnoredReference()) {
                $real_parameter_name_map[$comment_parameter_name]->setIsIgnoredReference();
            } elseif ($comment_parameter->isOutputReference()) {
                $real_parameter_name_map[$comment_parameter_name]->setIsOutputReference();
            }
        }
        foreach ($comment->getVariableList() as $comment_variable) {
            if (\array_key_exists($comment_variable->getName(), $real_parameter_name_map)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::CommentVarInsteadOfParam,
                    $comment_variable->getLineno(),
                    $comment_variable->getName(),
                    (string)$function
                );
                continue;
            }
        }
        $function->setPHPDocParameterTypeMap($valid_comment_parameter_type_map);
        if ($function instanceof Method) {
            $function->checkForTemplateTypes();
        }
        // Special, for libraries which use this for to document variadic param lists.
    }

    /**
     * Internally used.
     */
    public static function addParamToScopeOfFunctionOrMethod(
        Context $context,
        CodeBase $code_base,
        FunctionInterface $function,
        Comment $comment,
        int $parameter_offset,
        Parameter $parameter
    ): void {
        if ($function->isPHPInternal()) {
            return;
        }
        $real_type_set = $parameter->getNonVariadicUnionType()->getRealTypeSet();
        $parameter_name = $parameter->getName();
        if ($comment->hasParameterWithNameOrOffset(
            $parameter_name,
            $parameter_offset
        )) {
            $comment_param = $comment->getParameterWithNameOrOffset(
                $parameter_name,
                $parameter_offset
            );
            if ($comment_param->isMandatoryInPHPDoc()) {
                $function->recordHasMandatoryPHPDocParamAtOffset($parameter_offset);
            }
        } else {
            $comment_param = null;
        }
        if ($parameter->getNonVariadicUnionType()->isEmpty()) {
            // If there is no type specified in PHP, check
            // for a docComment with @param declarations. We
            // assume order in the docComment matches the
            // parameter order in the code
            if ($comment_param) {
                $comment_param_type = $comment_param->getUnionType();
                if ($parameter->isVariadic() !== $comment_param->isVariadic()) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        $parameter->isVariadic() ? Issue::TypeMismatchVariadicParam : Issue::TypeMismatchVariadicComment,
                        $comment_param->getLineno(),
                        $comment_param->__toString(),
                        $parameter->__toString()
                    );
                }

                // if ($parameter->isCloneOfVariadic()) { throw new \Error("Impossible\n"); }
                $parameter->addUnionType($comment_param_type);
            }
        }

        // If there's a default value on the parameter, check to
        // see if the type of the default is cool with the
        // specified type.
        if ($parameter->hasDefaultValue()) {
            $default_type = $parameter->getDefaultValueType();
            $default_is_null = $default_type->isType(NullType::instance(false));
            // If the default type isn't null and can't cast
            // to the parameter's declared type, emit an
            // issue.
            if (!$default_is_null) {
                if (!$default_type->canCastToUnionType(
                    $parameter->getUnionType(),
                    $code_base
                )) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::TypeMismatchDefault,
                        $function->getFileRef()->getLineNumberStart(),
                        (string)$parameter->getUnionType(),
                        $parameter_name,
                        (string)$default_type
                    );
                }
            }

            // If there are no types on the parameter, the
            // default shouldn't be treated as the one
            // and only allowable type.
            $was_empty = $parameter->getUnionType()->isEmpty();

            // If we have no other type info about a parameter,
            // just because it has a default value of null
            // doesn't mean that is its type. Any type can default
            // to null
            if ($default_is_null) {
                if ($was_empty) {
                    $parameter->addUnionType(MixedType::instance(true)->asPHPDocUnionType());
                }
                // The parameter constructor or above check for wasEmpty already took care of null default case
            } else {
                $default_type = $default_type->withFlattenedArrayShapeOrLiteralTypeInstances()->withRealTypeSet($parameter->getNonVariadicUnionType()->getRealTypeSet());
                if ($was_empty) {
                    $parameter->addUnionType(self::inferNormalizedTypesOfDefault($default_type));
                    if (!Config::getValue('guess_unknown_parameter_type_using_default')) {
                        $parameter->addUnionType(MixedType::instance(false)->asPHPDocUnionType());
                    }
                } else {
                    // Don't add both `int` and `?int` to the same set.
                    foreach ($default_type->getTypeSet() as $default_type_part) {
                        if (!$parameter->getNonvariadicUnionType()->hasType($default_type_part->withIsNullable(true))) {
                            // if ($parameter->isCloneOfVariadic()) { throw new \Error("Impossible\n"); }
                            $parameter->addType($default_type_part);
                        }
                    }
                }
            }
        }
        // Keep the real type set of the parameter to make redundant condition detection more accurate.
        $new_parameter_type = $parameter->getNonVariadicUnionType()->withRealTypeSet($real_type_set);
        if ($real_type_set) {
            $new_parameter_type = $new_parameter_type->asNormalizedTypes();
        }
        $parameter->setUnionType($new_parameter_type);
    }

    private static function inferNormalizedTypesOfDefault(UnionType $default_type): UnionType
    {
        $type_set = $default_type->getTypeSet();
        if (\count($type_set) === 0) {
            return $default_type;
        }
        $normalized_default_type = UnionType::empty();
        foreach ($type_set as $type) {
            if ($type instanceof FalseType || $type instanceof NullType) {
                return MixedType::instance(false)->asPHPDocUnionType();
            } elseif ($type instanceof GenericArrayType) {
                // Ideally should be the **only** type.
                $normalized_default_type = $normalized_default_type->withType(ArrayType::instance(false));
            } elseif ($type instanceof TrueType) {
                // e.g. for `function myFn($x = true) { }, $x is probably of type bool, but we're less sure about the type of $x from `$x = false`
                $normalized_default_type = $normalized_default_type->withType(BoolType::instance(false));
            } else {
                $normalized_default_type = $normalized_default_type->withType($type);
            }
        }
        return $normalized_default_type;
    }

    /**
     * @param array<string,UnionType> $parameter_map maps a subset of param names to the unmodified phpdoc parameter types. This may differ from real parameter types.
     */
    public function setPHPDocParameterTypeMap(array $parameter_map): void
    {
        $this->phpdoc_parameter_type_map = $parameter_map;
    }

    /**
     * Records the fact that $parameter_name is an output-only reference.
     * @param string $parameter_name
     * @suppress PhanUnreferencedPublicMethod
     * @unused - TODO: Clean up this and phpdoc_output_references
     */
    public function recordOutputReferenceParamName(string $parameter_name): void
    {
        $this->phpdoc_output_references[] = $parameter_name;
    }

    /**
     * @return list<string> list of output references. Usually empty.
     */
    public function getOutputReferenceParamNames(): array
    {
        return $this->phpdoc_output_references;
    }

    /**
     * @return array<string,UnionType> maps a subset of param names to the unmodified phpdoc parameter types.
     */
    public function getPHPDocParameterTypeMap(): array
    {
        return $this->phpdoc_parameter_type_map;
    }

    /**
     * @param ?UnionType $type the raw phpdoc union type
     */
    public function setPHPDocReturnType(?UnionType $type): void
    {
        $this->phpdoc_return_type = $type;
    }

    /**
     * @return ?UnionType the raw phpdoc union type
     * @suppress PhanUnreferencedPublicMethod this is made available for plugins or future features.
     */
    public function getPHPDocReturnType(): ?UnionType
    {
        return $this->phpdoc_return_type;
    }

    /**
     * Returns true if the param list has an instance of PassByReferenceVariable
     * If it does, the method has to be analyzed even if the same parameter types were analyzed already
     * @param list<Variable> $parameter_list
     */
    private function hasPassByReferenceVariable(array $parameter_list): bool
    {
        // Common case: function doesn't have any references in parameter list
        if ($this->has_pass_by_reference_parameters === false) {
            return false;
        }
        foreach ($parameter_list as $param) {
            if ($param instanceof PassByReferenceVariable) {
                return true;
            }
        }
        return false;
    }

    /**
     * analyzeWithNewParams is called only when the quick_mode config is false.
     * The new types are inferred based on the caller's types.
     * As an optimization, this refrains from re-analyzing the method/function it has already been analyzed for those param types
     * (With an equal or larger remaining recursion depth)
     *
     * @param list<Parameter> $parameter_list
     */
    public function analyzeWithNewParams(Context $context, CodeBase $code_base, array $parameter_list): Context
    {
        $hash = $this->computeParameterListHash($parameter_list);
        $has_pass_by_reference_variable = null;
        // Nothing to do, except if PassByReferenceVariable was used
        if ($hash === $this->parameter_list_hash) {
            if (!$this->hasPassByReferenceVariable($parameter_list)) {
                // Have to analyze pass by reference variables anyway
                return $context;
            }
            $has_pass_by_reference_variable = true;
        }
        // Check if we've already analyzed this method with those given types,
        // with as much or even more depth left in the recursion.
        // (getRecursionDepth() increases as the program recurses downward)
        $old_recursion_depth_for_hash = $this->checked_parameter_list_hashes[$hash] ?? null;
        $new_recursion_depth_for_hash = $this->getRecursionDepth();
        if ($old_recursion_depth_for_hash !== null) {
            if ($new_recursion_depth_for_hash >= $old_recursion_depth_for_hash) {
                if (!($has_pass_by_reference_variable ?? $this->hasPassByReferenceVariable($parameter_list))) {
                    return $context;
                }
                // Have to analyze pass by reference variables anyway
                $new_recursion_depth_for_hash = $old_recursion_depth_for_hash;
            }
        }
        // Record the fact that it has already been analyzed,
        // along with the depth of recursion so far.
        $this->checked_parameter_list_hashes[$hash] = $new_recursion_depth_for_hash;
        return $this->analyze($context, $code_base);
    }

    /**
     * Analyze this with original parameter types or types from arguments.
     */
    abstract public function analyze(Context $context, CodeBase $code_base): Context;

    /** @return int the current depth of recursive non-quick analysis. */
    abstract public function getRecursionDepth(): int;

    /** @return Node|null the node of this function-like's declaration, if any exist and were kept for recursive non-quick analysis. */
    abstract public function getNode(): ?Node;

    /** @return Context location and scope where this was declared. */
    abstract public function getContext(): Context;

    /** @return FileRef location where this was declared. */
    abstract public function getFileRef(): FileRef;

    /**
     * Returns true if the return type depends on the argument, and a plugin makes Phan aware of that.
     */
    public function hasDependentReturnType(): bool
    {
        return $this->return_type_callback !== null;
    }

    /**
     * Returns a union type based on $args_node and $context
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param list<Node|int|string|float> $args
     */
    public function getDependentReturnType(CodeBase $code_base, Context $context, array $args): UnionType
    {
        // @phan-suppress-next-line PhanTypeMismatchArgument, PhanTypePossiblyInvalidCallable - Callers should check hasDependentReturnType
        $result = ($this->return_type_callback)($code_base, $context, $this, $args);
        if (!$result->hasRealTypeSet()) {
            $real_return_type = $this->getRealReturnType();
            if (!$real_return_type->isEmpty()) {
                return $result->withRealTypeSet($real_return_type->getTypeSet());
            }
        }
        return $result;
    }

    public function setDependentReturnTypeClosure(Closure $closure): void
    {
        $this->return_type_callback = $closure;
    }

    /**
     * Returns true if this function or method has additional analysis logic for invocations (From internal and user defined plugins)
     */
    public function hasFunctionCallAnalyzer(): bool
    {
        return $this->function_call_analyzer_callback !== null;
    }

    /**
     * Perform additional analysis logic for invocations (From internal and user defined plugins)
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param list<Node|int|string|float> $args
     * @param ?Node $node - the node causing the call. This may be dynamic, e.g. call_user_func_array. This will be required in Phan 3.
     */
    public function analyzeFunctionCall(CodeBase $code_base, Context $context, array $args, ?Node $node = null): void
    {
        // @phan-suppress-next-line PhanTypePossiblyInvalidCallable, PhanTypeMismatchArgument - Callers should check hasFunctionCallAnalyzer
        ($this->function_call_analyzer_callback)($code_base, $context, $this, $args, $node);
    }

    /**
     * Make additional analysis logic of this function/method use $closure
     * If callers need to invoke multiple closures, they should pass in a closure to invoke multiple closures or use addFunctionCallAnalyzer.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function setFunctionCallAnalyzer(Closure $closure): void
    {
        $closure_id = spl_object_id($closure);
        $this->function_call_analyzer_callback_set = [
            $closure_id => true
        ];
        $this->function_call_analyzer_callback = $closure;
    }

    /**
     * Make additional analysis logic of this function/method use $closure in addition to any other closures.
     * @param ?PluginV3 $plugin @phan-mandatory-param
     */
    public function addFunctionCallAnalyzer(Closure $closure, ?PluginV3 $plugin = null): void
    {
        $closure_id = spl_object_id($plugin ?? $closure);
        if (isset($this->function_call_analyzer_callback_set[$closure_id])) {
            return;
        }
        $this->function_call_analyzer_callback_set[$closure_id] = true;
        $old_closure = $this->function_call_analyzer_callback;
        if ($old_closure) {
            $closure = ConfigPluginSet::mergeAnalyzeFunctionCallClosures($old_closure, $closure);
        }
        $this->function_call_analyzer_callback = $closure;
    }

    /**
     * @return ?Comment - Not set for internal functions/methods
     */
    public function getComment(): ?Comment
    {
        return $this->comment;
    }

    public function setComment(Comment $comment): void
    {
        $this->comment = $comment;
    }

    public function getOwnThrowsUnionType(): UnionType
    {
        $comment = $this->comment;
        return $comment ? $comment->getThrowsUnionType() : UnionType::empty();
    }

    public function getFullThrowsUnionType(): UnionType
    {
        return $this->getOwnThrowsUnionType();
    }

    /**
     * Initialize the inner scope of this method with variables created from the parameters.
     *
     * Deferred until the parse phase because getting the UnionType of parameter defaults requires having all class constants be known.
     */
    public function ensureScopeInitialized(CodeBase $code_base): void
    {
        if ($this->is_inner_scope_initialized) {
            return;
        }
        $this->is_inner_scope_initialized = true;
        $comment = $this->comment;
        // $comment can be null for magic methods from `@method`
        if ($comment !== null) {
            if (!($this instanceof FunctionInterface)) {
                throw new AssertionError('Expected any class using FunctionTrait to implement FunctionInterface');
            }
            self::addParamsToScopeOfFunctionOrMethod($this->getContext(), $code_base, $this, $comment);
        }
    }

    /**
     * Memoize the result of $fn(), saving the result
     * with key $key.
     *
     * @template T
     *
     * @param string $key
     * The key to use for storing the result of the
     * computation.
     *
     * @param Closure():T $fn
     * A function to compute only once for the given
     * $key.
     *
     * @return T
     * The result of the given computation is returned
     */
    abstract protected function memoize(string $key, Closure $fn);

    abstract protected function memoizeFlushAll(): void;

    /** @return UnionType union type this function-like's declared return type (from PHPDoc, signatures, etc.)  */
    abstract public function getUnionType(): UnionType;

    abstract public function setUnionType(UnionType $type): void;

    /**
     * Creates a callback that can restore this element to the state it had before parsing.
     * @internal - Used by daemon mode
     * @return Closure
     * @suppress PhanTypeMismatchDeclaredReturnNullable overriding phpdoc type deliberately so that this works in php 7.1
     */
    public function createRestoreCallback(): ?Closure
    {
        $clone_this = clone($this);
        foreach ($clone_this->parameter_list as $i => $parameter) {
            $clone_this->parameter_list[$i] = clone($parameter);
        }
        foreach ($clone_this->real_parameter_list as $i => $parameter) {
            $clone_this->real_parameter_list[$i] = clone($parameter);
        }
        $union_type = $this->getUnionType();

        return function () use ($clone_this, $union_type): void {
            $this->memoizeFlushAll();
            // @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach this is intentionally iterating over private properties of the clone.
            foreach ($clone_this as $key => $value) {
                $this->{$key} = $value;
            }
            $this->setUnionType($union_type);
        };
    }

    /**
     * Clone the parameter list, so that modifying the parameters on the first call won't modify the others.
     * TODO: If they're immutable, they can be shared without cloning with less worry.
     * @internal
     */
    public function cloneParameterList(): void
    {
        $this->setParameterList(
            \array_map(
                static function (Parameter $parameter): Parameter {
                    return clone($parameter);
                },
                $this->parameter_list
            )
        );
    }

    /**
     * Returns a FunctionLikeDeclarationType based on phpdoc+real types.
     * The return value is used for type casting rule checking.
     */
    public function asFunctionLikeDeclarationType(): FunctionLikeDeclarationType
    {
        return $this->as_closure_declaration_type ?? ($this->as_closure_declaration_type = $this->createFunctionLikeDeclarationType());
    }

    /**
     * Does this function-like return a reference?
     */
    abstract public function returnsRef(): bool;

    private function createFunctionLikeDeclarationType(): FunctionLikeDeclarationType
    {
        $params = \array_map(static function (Parameter $parameter): ClosureDeclarationParameter {
            return $parameter->asClosureDeclarationParameter();
        }, $this->parameter_list);

        $return_type = $this->getUnionType();
        if ($return_type->isEmpty()) {
            $return_type = MixedType::instance(false)->asPHPDocUnionType();
        }
        return new ClosureDeclarationType(
            $this->getFileRef(),
            $params,
            $return_type,
            $this->returnsRef(),
            false
        );
    }

    /**
     * @return array<mixed,string> in the same format as FunctionSignatureMap.php
     * @throws \InvalidArgumentException if this function has invalid parameters for generating a stub (e.g. param names, types, etc.)
     */
    public function toFunctionSignatureArray(): array
    {
        $return_type = $this->getUnionType();
        $stub = [$return_type->__toString()];
        '@phan-var array<mixed,string> $stub';  // TODO: Should not warn about PhanTypeMismatchDimFetch in isset below
        foreach ($this->parameter_list as $parameter) {
            $name = $parameter->getName();
            if ($name === '' || isset($stub[$name])) {
                throw new \InvalidArgumentException("Invalid name '$name' for {$this->getFQSEN()}");
            }
            if ($parameter->isOptional()) {
                $name .= '=';
            }
            $type_string = $parameter->getUnionType()->__toString();
            if ($parameter->isVariadic()) {
                $name = '...' . $name;
            }
            if ($parameter->isPassByReference()) {
                $name = '&' . $name;
            }
            $stub[$name] = $type_string;
        }
        return $stub;
    }

    /**
     * Precondition: This function is a generator type
     * Converts Generator|T[] to Generator<T>
     * Converts Generator|array<int,stdClass> to Generator<int,stdClass>, etc.
     */
    public function getReturnTypeAsGeneratorTemplateType(): Type
    {
        return $this->as_generator_template_type ?? ($this->as_generator_template_type = $this->getUnionType()->asGeneratorTemplateType());
    }

    /**
     * @var bool have the return types (both real and PHPDoc) of this method been analyzed and combined yet?
     */
    protected $did_analyze_return_types = false;

    /**
     * Check this method's return types (phpdoc and real) to make sure they're valid,
     * and infer a return type from the combination of the signature and phpdoc return types.
     */
    public function analyzeReturnTypes(CodeBase $code_base): void
    {
        if ($this->did_analyze_return_types) {
            return;
        }
        $this->did_analyze_return_types = true;
        try {
            $this->analyzeReturnTypesInner($code_base);
        } catch (RecursionDepthException $_) {
        }
    }

    /**
     * Is this internal?
     */
    abstract public function isPHPInternal(): bool;

    /**
     * Returns this function's union type without resolving `static` in the function declaration's context.
     */
    abstract public function getUnionTypeWithUnmodifiedStatic(): UnionType;

    private function analyzeReturnTypesInner(CodeBase $code_base): void
    {
        if ($this->isPHPInternal()) {
            // nothing to do, no known Node
            return;
        }
        $return_type = $this->getUnionTypeWithUnmodifiedStatic();
        $real_return_type = $this->getRealReturnType();
        $phpdoc_return_type = $this->phpdoc_return_type;
        $context = $this->getContext();
        // TODO: use method->getPHPDocReturnType() and getRealReturnType() to check compatibility, like analyzeParameterTypesDocblockSignaturesMatch

        // Look at each parameter to make sure their types
        // are valid

        // Look at each type in the function's return union type
        foreach ($return_type->withFlattenedArrayShapeOrLiteralTypeInstances()->getTypeSet() as $outer_type) {
            foreach ($outer_type->getReferencedClasses() as $type) {
                // If its a reference to self, its OK
                if ($this instanceof Method && $type instanceof StaticOrSelfType) {
                    continue;
                }

                if ($type instanceof TemplateType) {
                    if ($this instanceof Method) {
                        if ($this->isStatic() && !$this->declaresTemplateTypeInComment($type)) {
                            Issue::maybeEmit(
                                $code_base,
                                $context,
                                Issue::TemplateTypeStaticMethod,
                                $this->getFileRef()->getLineNumberStart(),
                                (string)$this->getFQSEN()
                            );
                        }
                    }
                    continue;
                }
                // Make sure the class exists
                $type_fqsen = FullyQualifiedClassName::fromType($type);
                if (!$code_base->hasClassWithFQSEN($type_fqsen)) {
                    Issue::maybeEmitWithParameters(
                        $code_base,
                        $this->getContext(),
                        Issue::UndeclaredTypeReturnType,
                        $this->getFileRef()->getLineNumberStart(),
                        [$this->getNameForIssue(), (string)$outer_type],
                        IssueFixSuggester::suggestSimilarClass($code_base, $this->getContext(), $type_fqsen, null, 'Did you mean', IssueFixSuggester::CLASS_SUGGEST_CLASSES_AND_TYPES_AND_VOID)
                    );
                } elseif ($code_base->hasClassWithFQSEN($type_fqsen->withAlternateId(1))) {
                    UnionType::emitRedefinedClassReferenceWarning(
                        $code_base,
                        $this->getContext(),
                        $type_fqsen
                    );
                }
            }
        }
        if (Config::getValue('check_docblock_signature_return_type_match') && !$real_return_type->isEmpty() && ($phpdoc_return_type instanceof UnionType) && !$phpdoc_return_type->isEmpty()) {
            $resolved_real_return_type = $real_return_type->withStaticResolvedInContext($context);
            foreach ($phpdoc_return_type->getTypeSet() as $phpdoc_type) {
                $is_exclusively_narrowed = $phpdoc_type->isExclusivelyNarrowedFormOrEquivalentTo(
                    $resolved_real_return_type,
                    $context,
                    $code_base
                );
                // Make sure that the commented type is a narrowed
                // or equivalent form of the syntax-level declared
                // return type.
                if (!$is_exclusively_narrowed) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::TypeMismatchDeclaredReturn,
                        // @phan-suppress-next-line PhanAccessMethodInternal, PhanPartialTypeMismatchArgument TODO: Support inferring this is FunctionInterface
                        ParameterTypesAnalyzer::guessCommentReturnLineNumber($this) ?? $context->getLineNumberStart(),
                        $this->getName(),
                        $phpdoc_type->__toString(),
                        $real_return_type->__toString()
                    );
                }
                if ($is_exclusively_narrowed && Config::getValue('prefer_narrowed_phpdoc_return_type') && !$phpdoc_return_type->isNeverType()) {
                    $normalized_phpdoc_return_type = ParameterTypesAnalyzer::normalizeNarrowedParamType($phpdoc_return_type, $real_return_type);
                    if ($normalized_phpdoc_return_type) {
                        // TODO: How does this currently work when there are multiple types in the union type that are compatible?
                        $real_type_set = $real_return_type->getTypeSet();
                        $new_return_type = $normalized_phpdoc_return_type->withRealTypeSet($real_type_set);
                        if ($real_type_set) {
                            $new_return_type = $new_return_type->asNormalizedTypes();
                        }
                        $this->setUnionType($new_return_type);
                    } else {
                        // This check isn't urgent to fix, and is specific to nullable casting rules,
                        // so use a different issue type.
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            Issue::TypeMismatchDeclaredReturnNullable,
                            // @phan-suppress-next-line PhanAccessMethodInternal, PhanPartialTypeMismatchArgument TODO: Support inferring this is FunctionInterface
                            ParameterTypesAnalyzer::guessCommentReturnLineNumber($this) ?? $context->getLineNumberStart(),
                            $this->getName(),
                            $phpdoc_type->__toString(),
                            $real_return_type->__toString()
                        );
                    }
                }
            }
        }
        if ($return_type->isEmpty()) {
            if ($this->hasReturn()) {
                if ($this instanceof Method) {
                    $union_type = $this->getUnionTypeOfMagicIfKnown();
                    if ($union_type) {
                        $this->setUnionType($union_type);
                    }
                }
            } else {
                if ($this instanceof Func || ($this instanceof Method && ($this->isPrivate() || $this->isEffectivelyFinal() || $this->isMagicAndVoid() || $this->getClass($code_base)->isFinal()))) {
                    $this->setUnionType(VoidType::instance(false)->asPHPDocUnionType());
                }
            }
        }
        foreach ($real_return_type->getUniqueFlattenedTypeSet() as $type) {
            if (!$type->isObjectWithKnownFQSEN()) {
                continue;
            }
            $type_fqsen = FullyQualifiedClassName::fromType($type);
            if (!$code_base->hasClassWithFQSEN($type_fqsen)) {
                // We should have already warned
                continue;
            }
            $class = $code_base->getClassByFQSEN($type_fqsen);
            if ($class->isTrait()) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::TypeInvalidTraitReturn,
                    $this->getFileRef()->getLineNumberStart(),
                    $this->getNameForIssue(),
                    $type_fqsen->__toString()
                );
            }
        }
        if ($this->comment) {
            // Add plugins **after** the phpdoc and real comment types were merged.
            // Plugins affecting return types (for template in (at)return)
            $template_type_list = $this->comment->getTemplateTypeList();
            if ($template_type_list) {
                $this->addClosureForDependentTemplateType($code_base, $context, $template_type_list);
            }
        }
    }

    /**
     * Does this function/method declare an (at)template type for this type?
     */
    public function declaresTemplateTypeInComment(TemplateType $template_type): bool
    {
        if ($this->comment) {
            // Template types are identical if they have the same name. See TemplateType::instanceForId.
            return \in_array($template_type->withIsNullable(false), $this->comment->getTemplateTypeList(), true);
        }
        return false;
    }

    private function isTemplateTypeUsed(TemplateType $type): bool
    {
        if ($this->getUnionType()->usesTemplateType($type)) {
            // used in `@return`
            return true;
        }

        if ($this->comment) {
            foreach ($this->comment->getParamAssertionMap() as $assertion) {
                // @phan-suppress-next-line PhanAccessPropertyInternal
                if ($assertion->union_type->usesTemplateType($type)) {
                    // used in `@phan-assert`
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param TemplateType[] $template_type_list
     */
    private function addClosureForDependentTemplateType(CodeBase $code_base, Context $context, array $template_type_list): void
    {
        if ($this->hasDependentReturnType()) {
            // We already added this or this conflicts with a plugin.
            return;
        }
        if (!$template_type_list) {
            // Shouldn't happen
            return;
        }
        $parameter_extractor_map = [];
        $has_all_templates = true;
        foreach ($template_type_list as $template_type) {
            if (!$this->isTemplateTypeUsed($template_type)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::TemplateTypeNotUsedInFunctionReturn,
                    $context->getLineNumberStart(),
                    $template_type,
                    $this->getNameForIssue()
                );
                $has_all_templates = false;
                continue;
            }
            $parameter_extractor = $this->getTemplateTypeExtractorClosure($code_base, $template_type);
            if (!$parameter_extractor) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::TemplateTypeNotDeclaredInFunctionParams,
                    $context->getLineNumberStart(),
                    $template_type,
                    $this->getNameForIssue()
                );
                $has_all_templates = false;
                continue;
            }
            $parameter_extractor_map[$template_type->getName()] = $parameter_extractor;
        }
        if (!$has_all_templates) {
            return;
        }
        /**
         * Resolve the template types based on the parameters passed to the function
         * @param list<Node|mixed> $args
         */
        $analyzer = static function (CodeBase $code_base, Context $context, FunctionInterface $function, array $args) use ($parameter_extractor_map): UnionType {
            $args_types = \array_map(
                /**
                 * @param mixed $node
                 */
                static function ($node) use ($code_base, $context): UnionType {
                    return UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node);
                },
                $args
            );
            $template_type_map = [];
            foreach ($parameter_extractor_map as $name => $closure) {
                $template_type_map[$name] = $closure($args_types, $context);
            }
            return $function->getUnionType()->withTemplateParameterTypeMap($template_type_map);
        };
        $this->setDependentReturnTypeClosure($analyzer);
    }

    /**
     * @param TemplateType $template_type the template type that this function is looking for references to in parameters
     *
     * @return ?Closure(list<Node|string|int|float|UnionType>, Context):UnionType
     */
    public function getTemplateTypeExtractorClosure(CodeBase $code_base, TemplateType $template_type, ?int $skip_index = null): ?Closure
    {
        $closure = null;
        foreach ($this->parameter_list as $i => $parameter) {
            if ($i === $skip_index) {
                continue;
            }
            $closure_for_type = $parameter->getUnionType()->getTemplateTypeExtractorClosure($code_base, $template_type);
            if (!$closure_for_type) {
                continue;
            }
            $closure = TemplateType::combineParameterClosures(
                $closure,
                /**
                 * @param list<Node|UnionType|mixed> $arguments
                 */
                static function (array $arguments, Context $context) use ($code_base, $i, $closure_for_type, $parameter): UnionType {
                    $arg_value = $arguments[$i] ?? ($parameter->hasDefaultValue() ? $parameter->getDefaultValueLiteralType() : null);
                    if ($arg_value !== null) {
                        if ($arg_value instanceof UnionType) {
                            // This helper method has two callers - one passes in an array of union types, another passes in the raw nodes.
                            $arg_type = $arg_value;
                        } else {
                            $arg_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $arg_value);
                        }
                        return $closure_for_type($arg_type, $context);
                    }
                    return UnionType::empty();
                }
            );
        }
        return $closure;
    }

    /**
     * Returns the index of the parameter with name $name.
     */
    public function getParamIndexForName(string $name): ?int
    {
        foreach ($this->parameter_list as $i => $param) {
            if ($param->getName() === $name) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Adds a plugin that will ensure postconditions in the comments take effect.
     * This adds closures the same way getAnalyzeFunctionCallClosures in a plugin would.
     *
     * @param array<string, Assertion> $param_assertion_map
     * @param ?Closure(CodeBase, Context, FunctionInterface, list<Node|string|float|int>):void $closure the previous closures to combine param assertions with
     * @return ?Closure(CodeBase, Context, FunctionInterface, list<Node|string|float|int>):void
     * @internal
     */
    private function getPluginForParamAssertionMap(CodeBase $code_base, array $param_assertion_map, ?Closure $closure): ?Closure
    {
        foreach ($param_assertion_map as $param_name => $assertion) {
            $i = $this->getParamIndexForName($param_name);
            if ($i === null) {
                Issue::maybeEmit(
                    $code_base,
                    $this->getContext(),
                    Issue::CommentParamAssertionWithoutRealParam,
                    $this->getContext()->getLineNumberStart(),
                    $param_name,
                    $this->getNameForIssue()
                );
                continue;
            }
            $new_closure = $this->createClosureForAssertion($code_base, $assertion, $i);
            if ($new_closure) {
                // @phan-suppress-next-line PhanTypeMismatchArgument
                $closure = ConfigPluginSet::mergeAnalyzeFunctionCallClosures($new_closure, $closure);
            }
        }
        return $closure;
    }

    /**
     * @param int $i the index of the parameter which $assertion acts upon.
     * @return ?Closure(CodeBase, Context, FunctionInterface, array, ?Node):void a closure to update the scope with the side effect of the condition
     * @suppress PhanAccessPropertyInternal
     * @internal
     */
    public function createClosureForAssertion(CodeBase $code_base, Assertion $assertion, int $i): ?Closure
    {
        $union_type = $assertion->union_type;
        if ($union_type->hasTemplateTypeRecursive()) {
            $union_type_extractor = $this->makeAssertionUnionTypeExtractor($code_base, $union_type, $i);
            if (!$union_type_extractor) {
                return null;
            }
        } else {
            /**
             * @param list<Node|mixed> $unused_args
             */
            $union_type_extractor = static function (CodeBase $unused_code_base, Context $unused_context, array $unused_args) use ($union_type): UnionType {
                return $union_type;
            };
        }
        return self::createClosureForUnionTypeExtractorAndAssertionType($union_type_extractor, $assertion->assertion_type, $i);
    }

    /**
     * @internal
     * @suppress PhanAccessClassConstantInternal
     * @param Closure(CodeBase, Context, array):UnionType $union_type_extractor
     * @return ?Closure(CodeBase, Context, FunctionInterface, array, ?Node):void a closure to update the scope with the side effect of the condition given the new type from $union_type_extractor
     */
    public static function createClosureForUnionTypeExtractorAndAssertionType(Closure $union_type_extractor, int $assertion_type, int $i): ?Closure
    {
        switch ($assertion_type) {
            case Assertion::IS_OF_TYPE:
                /**
                 * @param list<Node|mixed> $args
                 */
                return static function (CodeBase $code_base, Context $context, FunctionInterface $unused_function, array $args, ?Node $unused_node) use ($i, $union_type_extractor): void {
                    $arg = $args[$i] ?? null;
                    if (!($arg instanceof Node)) {
                        return;
                    }
                    $union_type = $union_type_extractor($code_base, $context, $args);
                    $new_context = ConditionVisitor::updateToHaveType($code_base, $context, $arg, $union_type);
                    // NOTE: This is hackish. This modifies the passed in context's scope.
                    $context->setScope($new_context->getScope());
                };
            case Assertion::IS_NOT_OF_TYPE:
                /**
                 * @param list<Node|mixed> $args
                 */
                return static function (CodeBase $code_base, Context $context, FunctionInterface $unused_function, array $args, ?Node $unused_node) use ($i, $union_type_extractor): void {
                    $arg = $args[$i] ?? null;
                    if (!($arg instanceof Node)) {
                        return;
                    }
                    $union_type = $union_type_extractor($code_base, $context, $args);
                    $new_context = ConditionVisitor::updateToNotHaveType($code_base, $context, $arg, $union_type);
                    // NOTE: This is hackish. This modifies the passed in context's scope.
                    $context->setScope($new_context->getScope());
                };
            case Assertion::IS_TRUE:
                /**
                 * @param list<Node|mixed> $args
                 */
                return static function (CodeBase $code_base, Context $context, FunctionInterface $unused_function, array $args, ?Node $unused_node) use ($i): void {
                    $arg = $args[$i] ?? null;
                    if (!($arg instanceof Node)) {
                        return;
                    }
                    $new_context = (new ConditionVisitor($code_base, $context))->__invoke($arg);
                    // NOTE: This is hackish. This modifies the passed in context's scope.
                    $context->setScope($new_context->getScope());
                };
            case Assertion::IS_FALSE:
                /**
                 * @param list<Node|mixed> $args
                 */
                return static function (CodeBase $code_base, Context $context, FunctionInterface $unused_function, array $args, ?Node $unused_node) use ($i): void {
                    $arg = $args[$i] ?? null;
                    if (!($arg instanceof Node)) {
                        return;
                    }
                    $new_context = (new NegatedConditionVisitor($code_base, $context))->__invoke($arg);
                    // NOTE: This is hackish. This modifies the passed in context's scope.
                    $context->setScope($new_context->getScope());
                };
        }
        // TODO: Support and test combining these closures
        return null;
    }

    /**
     * Creates a closure that can extract real types from template types used in (at)phan-assert.
     *
     * @return ?Closure(CodeBase, Context, array):UnionType
     */
    private function makeAssertionUnionTypeExtractor(CodeBase $code_base, UnionType $type, int $asserted_param_index): ?Closure
    {
        if (!$this->comment) {
            return null;
        }
        $parameter_extractor_map = [];
        foreach ($this->comment->getTemplateTypeList() as $template_type) {
            if (!$type->usesTemplateType($template_type)) {
                continue;
            }
            $param_closure = $this->getTemplateTypeExtractorClosure($code_base, $template_type, $asserted_param_index);
            if (!$param_closure) {
                // TODO: Warn
                return null;
            }
            $parameter_extractor_map[$template_type->getName()] = $param_closure;
        }
        if (!$parameter_extractor_map) {
            return null;
        }
        /**
         * @param list<Node|mixed> $args
         */
        return static function (CodeBase $unused_code_base, Context $context, array $args) use ($type, $parameter_extractor_map): UnionType {
            $template_type_map = [];
            foreach ($parameter_extractor_map as $template_type_name => $closure) {
                $template_type_map[$template_type_name] = $closure($args, $context);
            }
            return $type->withTemplateParameterTypeMap($template_type_map);
        };
    }

    /**
     * Create any plugins that exist due to doc comment annotations.
     * Must be called after adding this FunctionInterface to the $code_base, so that issues can be emitted if needed.
     * @return ?Closure(CodeBase, Context, FunctionInterface, list<Node|string|int|float>):UnionType
     * @internal
     */
    public function getCommentParamAssertionClosure(CodeBase $code_base): ?Closure
    {
        if (!\is_object($this->comment)) {
            return null;
        }
        $last_mandatory_phpdoc_param_offset = $this->last_mandatory_phpdoc_param_offset;
        $closure = null;
        if (is_int($last_mandatory_phpdoc_param_offset)) {
            $param = $this->parameter_list[$last_mandatory_phpdoc_param_offset] ?? null;

            if ($param) {
                /**
                 * @param list<Node|int|string|float> $array
                 */
                $closure = static function (CodeBase $code_base, Context $context, FunctionInterface $function, array $array) use ($param, $last_mandatory_phpdoc_param_offset): void {
                    if (count($array) > $last_mandatory_phpdoc_param_offset) {
                        return;
                    }
                    $last_arg = end($array);
                    if ($last_arg instanceof Node && $last_arg->kind === ast\AST_UNPACK) {
                        return;
                    }
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::ParamTooFewInPHPDoc,
                        $context->getLineNumberStart(),
                        count($array),
                        $function->getRepresentationForIssue(true),
                        $last_mandatory_phpdoc_param_offset + 1,
                        $param->getName(),
                        $function->getContext()->getFile(),
                        $function->getContext()->getLineNumberStart()
                    );
                };
            }
        }
        $param_assertion_map = $this->comment->getParamAssertionMap();
        if ($param_assertion_map) {
            return $this->getPluginForParamAssertionMap($code_base, $param_assertion_map, $closure);
        }
        return $closure;
    }

    /**
     * Returns stub text for the phpdoc parameters that can be used in markdown
     */
    public function getParameterStubText(): string
    {
        return \implode(', ', \array_map(function (Parameter $parameter): string {
            return $parameter->toStubString($this->isPHPInternal());
        }, $this->parameter_list));
    }

    /**
     * Returns stub text for the real parameters that can be used in `tool/make_stubs`
     */
    public function getRealParameterStubText(): string
    {
        return \implode(', ', \array_map(function (Parameter $parameter): string {
            return $parameter->toStubString($this->isPHPInternal());
        }, $this->getRealParameterList()));
    }

    /**
     * Mark this function or method as read-only
     */
    public function setIsPure(): void
    {
        $this->setPhanFlags($this->getPhanFlags() | Flags::IS_SIDE_EFFECT_FREE);
    }

    /**
     * Check if this function or method is marked as pure (having no visible side effects)
     */
    public function isPure(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_SIDE_EFFECT_FREE);
    }

    /**
     * Mark this function or method as having a tentative return type
     */
    public function setHasTentativeReturnType(): void
    {
        $this->setPhanFlags($this->getPhanFlags() | Flags::HAS_TENTATIVE_RETURN_TYPE);
    }

    /**
     * Check if this function has a tentative return type
     */
    public function hasTentativeReturnType(): bool
    {
        return $this->getPhanFlagsHasState(Flags::HAS_TENTATIVE_RETURN_TYPE);
    }

    /**
     * @return array<mixed, UnionType> very conservatively maps variable names to union types they can have.
     * Entries are omitted if there are possible assignments that aren't known.
     *
     * This is useful as a fallback for determining missing types when analyzing the first iterations of loops.
     *
     * Other approaches, such as analyzing loops multiple times, are possible, but not implemented.
     */
    public function getVariableTypeFallbackMap(CodeBase $code_base): array
    {
        return $this->memoize(__METHOD__, /** @return array<mixed, UnionType> */ function () use ($code_base): array {
            // @phan-suppress-next-line PhanTypeMismatchArgument no way to indicate $this always implements FunctionInterface
            return FallbackMethodTypesVisitor::inferTypes($code_base, $this);
        });
    }

    /**
     * Gets the original union type of this function/method.
     *
     * This is populated the first time it is called.
     *
     * NOTE: Phan also infers union types from the overridden methods.
     * This doesn't attempt to account for that.
     */
    public function getOriginalReturnType(): UnionType
    {
        return $this->original_return_type ?? ($this->original_return_type = $this->getUnionType());
    }

    /**
     * Sets the original union type of this function/method to the current union type.
     *
     * This is populated the first time it is called.
     */
    public function setOriginalReturnType(): void
    {
        $this->original_return_type = $this->getUnionType();
    }

    public function recordHasMandatoryPHPDocParamAtOffset(int $parameter_offset): void
    {
        if (isset($this->last_mandatory_phpdoc_param_offset) && $parameter_offset <= $this->last_mandatory_phpdoc_param_offset) {
            return;
        }
        $this->last_mandatory_phpdoc_param_offset = $parameter_offset;
    }

    /**
     * Returns whether this function is marked with no-named-arguments
     */
    public function hasNoNamedArguments(): bool
    {
        return $this->getPhanFlagsHasState(Flags::NO_NAMED_ARGUMENTS);
    }

    /**
     * Marks this function as having (at)no-named-arguments
     */
    public function setHasNoNamedArguments(): void
    {
        $this->setPhanFlags($this->getPhanFlags() | Flags::NO_NAMED_ARGUMENTS);
        foreach ($this->parameter_list as $parameter) {
            $parameter->setHasNoNamedArguments();
        }
    }
}
