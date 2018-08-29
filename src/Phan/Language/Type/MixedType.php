<?php declare(strict_types=1);
namespace Phan\Language\Type;

use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Type;
use Phan\Language\UnionType;

final class MixedType extends NativeType
{
    /** @phan-override */
    const NAME = 'mixed';

    // mixed or ?mixed can cast to/from anything.
    // For purposes of analysis, there's no difference between mixed and nullable mixed.
    public function canCastToType(Type $unused_type) : bool
    {
        return true;
    }

    /**
     * @param Type[] $target_type_set 1 or more types @phan-unused-param
     * @return bool
     * @override
     */
    public function canCastToAnyTypeInSet(array $target_type_set) : bool
    {
        return true;
    }

    // mixed or ?mixed can cast to/from anything.
    // For purposes of analysis, there's no difference between mixed and nullable mixed.
    protected function canCastToNonNullableType(Type $unused_type) : bool
    {
        return true;
    }

    public function isExclusivelyNarrowedFormOrEquivalentTo(
        UnionType $union_type,
        Context $unused_context,
        CodeBase $unused_code_base
    ) : bool {
        // Type casting rules allow mixed to cast to anything.
        // But we don't want `@param mixed $x` to take precedence over `int $x` in the signature.
        return $union_type->hasType($this);
    }

    /**
     * @param int $key_type @phan-unused-param
     * (TODO: maybe use $key_type in the future?)
     */
    public function asGenericArrayType(int $key_type) : Type
    {
        return ArrayType::instance(false);
    }

    public function isArrayOrArrayAccessSubType(CodeBase $unused_code_base) : bool
    {
        return true;
    }

    public function isPrintableScalar() : bool
    {
        return true;  // It's possible.
    }

    public function isValidNumericOperand() : bool
    {
        return true;
    }
}
