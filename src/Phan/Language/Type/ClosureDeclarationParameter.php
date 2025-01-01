<?php

declare(strict_types=1);

namespace Phan\Language\Type;

use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\Parameter;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;

/**
 * Not a type, but used by ClosureDeclarationType
 * @phan-pure
 */
final class ClosureDeclarationParameter
{
    /** @var UnionType the union type of the arguments expected for this variadic/non-variadic parameter */
    private $type;

    /** @var ?string the name this was declared with in a comment (for checking named arguments). */
    private $name;

    /** @var bool is this parameter variadic? */
    private $is_variadic;

    /** @var bool is this parameter pass-by-reference? */
    private $is_reference;

    /** @var bool is this parameter optional? */
    private $is_optional;

    public function __construct(UnionType $type, bool $is_variadic, bool $is_reference, bool $is_optional, ?string $name = null)
    {
        $this->type = $type;
        $this->name = $name;
        $this->is_variadic = $is_variadic;
        $this->is_reference = $is_reference;
        $this->is_optional = $is_optional || $is_variadic;
    }

    /**
     * Gets the non-variadic type of this parameter.
     * (i.e. the type of individual arguments the closure expects to be passed in by the caller)
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getNonVariadicUnionType(): UnionType
    {
        return $this->type;
    }

    /**
     * Is this variadic?
     */
    public function isVariadic(): bool
    {
        return $this->is_variadic;
    }

    /**
     * Is this passed by reference?
     */
    public function isPassByReference(): bool
    {
        return $this->is_reference;
    }

    /**
     * Is this an optional parameter
     * (i.e. do callers have to pass an argument for this parameter)
     */
    public function isOptional(): bool
    {
        return $this->is_optional;
    }

    // for debugging
    public function __toString(): string
    {
        $repr = $this->type->__toString();
        if (StringUtil::isNonZeroLengthString($this->name)) {
            $repr .= ' $' . $this->name;
        }
        if ($this->is_reference) {
            $repr .= '&';
        }
        if ($this->is_variadic) {
            $repr .= '...';
        }
        if ($this->is_optional && !$this->is_variadic) {
            $repr .= '=';
        }
        return $repr;
    }

    /**
     * Checks if this parameter can be used as an equivalent or more permissive form of the parameter $other.
     * This is used to check if closure/callable types can be cast to other closure/callable types.
     *
     * @see \Phan\Analysis\ParameterTypesAnalyzer::analyzeOverrideSignatureForOverriddenMethod() - Similar logic using LSP
     */
    public function canCastToParameterIgnoringVariadic(ClosureDeclarationParameter $other, CodeBase $code_base): bool
    {
        if ($this->is_reference !== $other->is_reference) {
            return false;
        }
        if (!$this->is_optional && $other->is_optional) {
            // We should have already checked this
            return false;
        }
        // TODO: stricter? (E.g. shouldn't allow int|string to cast to int)
        return $this->type->canCastToUnionType($other->type, $code_base);
    }

    /**
     * Checks if this parameter can be used as an equivalent or more permissive form of the parameter $other.
     * This is used to check if closure/callable types can be cast to other closure/callable types.
     *
     * This also allows templates to be used instead
     *
     * @see \Phan\Analysis\ParameterTypesAnalyzer::analyzeOverrideSignatureForOverriddenMethod() - Similar logic using LSP
     */
    public function canCastToParameterHandlingTemplatesIgnoringVariadic(ClosureDeclarationParameter $other, CodeBase $code_base): bool
    {
        if ($this->is_reference !== $other->is_reference) {
            return false;
        }
        if (!$this->is_optional && $other->is_optional) {
            // We should have already checked this
            return false;
        }
        // TODO: stricter? (E.g. shouldn't allow int|string to cast to int)
        return $this->type->canCastToUnionType($other->type, $code_base);
    }

    /**
     * Creates a ClosureDeclarationParameter with template types replaced with the corresponding union types.
     *
     * @param array<string,UnionType> $template_parameter_type_map
     */
    public function withTemplateParameterTypeMap(array $template_parameter_type_map): ClosureDeclarationParameter
    {
        $new_type = $this->type->withTemplateParameterTypeMap($template_parameter_type_map);
        if ($new_type === $this->type) {
            return $this;
        }

        return new self($new_type, $this->is_variadic, $this->is_reference, $this->is_optional, $this->name);
    }

    // TODO: Memoize?
    /**
     * Creates a parameter with the non-variadic version of the type
     * (i.e. with the type seen by callers for individual arguments)
     */
    public function asNonVariadicRegularParameter(int $i): Parameter
    {
        $flags = 0;
        // Skip variadic
        if ($this->is_reference) {
            $flags |= \ast\flags\PARAM_REF;
        }
        $result = Parameter::create(
            (new Context())->withFile('phpdoc'),
            "p$i",
            $this->type,
            $flags
        );
        if ($this->is_optional && !$this->is_variadic) {
            $result->setDefaultValueType($this->type);
        }
        return $result;
    }

    /**
     * Converts this to a regular parameter (e.g. as a placeholder for the ith parameter in a FunctionInterface)
     * (e.g. $p0, $p1, etc.)
     */
    public function asRegularParameter(int $i): Parameter
    {
        $flags = 0;
        if ($this->is_variadic) {
            $flags |= \ast\flags\PARAM_VARIADIC;
        }
        if ($this->is_reference) {
            $flags |= \ast\flags\PARAM_REF;
        }
        $name = $this->name;
        $result = Parameter::create(
            (new Context())->withFile('phpdoc'),
            StringUtil::isNonZeroLengthString($name) ? $name : "p$i",
            $this->type,
            $flags
        );
        if ($this->is_optional && !$this->is_variadic) {
            $result->setDefaultValueType(MixedType::instance(false)->asPHPDocUnionType());
        }
        return $result;
    }
}
