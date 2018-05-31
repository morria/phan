<?php declare(strict_types=1);
namespace Phan\Language\Type;

use Phan\CodeBase;
use Phan\Language\UnionType;
use Phan\Language\Type;
use Phan\Language\Type\ArrayType;

abstract class NativeType extends Type
{
    const NAME = '';

    /** @phan-override */
    const KEY_PREFIX = '!';

    /**
     * @param bool $is_nullable
     * If true, returns a nullable instance of this native type
     *
     * @return static
     */
    public static function instance(bool $is_nullable)
    {
        if ($is_nullable) {
            static $nullable_instance = null;

            if ($nullable_instance === null) {
                $nullable_instance = static::make('\\', static::NAME, [], true, Type::FROM_NODE);
            }

            return $nullable_instance;
        }

        static $instance = null;

        if ($instance === null) {
            $instance = static::make('\\', static::NAME, [], false, Type::FROM_NODE);
        }

        return $instance;
    }

    public function isNativeType() : bool
    {
        return true;
    }

    public function isSelfType() : bool
    {
        return false;
    }

    public function isArrayAccess() : bool
    {
        return false;
    }

    public function isTraversable() : bool
    {
        return false;
    }

    public function isGenerator() : bool
    {
        return false;
    }

    public function isObject() : bool
    {
        return false;
    }

    public function isObjectWithKnownFQSEN() : bool
    {
        return false;
    }

    public function isPossiblyObject() : bool
    {
        return false;
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableType(Type $type) : bool
    {
        // Anything can cast to mixed or ?mixed
        // Not much of a distinction in nullable mixed, except to emphasize in comments that it definitely can be null.
        // MixedType overrides the canCastTo*Type methods to always return true.
        if ($type instanceof MixedType) {
            return true;
        }

        if (!($type instanceof NativeType)
            || $this instanceof GenericArrayType
            || $type instanceof GenericArrayType
        ) {
            return parent::canCastToNonNullableType($type);
        }

        // Cast this to a native type
        \assert($type instanceof NativeType);

        // A nullable type cannot cast to a non-nullable type
        if ($this->getIsNullable() && !$type->getIsNullable()) {
            return false;
        }
        static $matrix;
        if ($matrix === null) {
            $matrix = self::initializeTypeCastingMatrix();
        }

        return $matrix[$this->getName()][$type->getName()]
            ?? parent::canCastToNonNullableType($type);
    }

    /**
     * @return array<string,array<string,bool>>
     */
    private static function initializeTypeCastingMatrix() : array
    {
        $generateRow = function (string ...$permittedCastTypeNames) {
            return [
                ArrayType::NAME    => in_array(ArrayType::NAME, $permittedCastTypeNames, true),
                IterableType::NAME => in_array(IterableType::NAME, $permittedCastTypeNames, true),
                BoolType::NAME     => in_array(BoolType::NAME, $permittedCastTypeNames, true),
                CallableType::NAME => in_array(CallableType::NAME, $permittedCastTypeNames, true),
                FalseType::NAME    => in_array(FalseType::NAME, $permittedCastTypeNames, true),
                FloatType::NAME    => in_array(FloatType::NAME, $permittedCastTypeNames, true),
                IntType::NAME      => in_array(IntType::NAME, $permittedCastTypeNames, true),
                MixedType::NAME    => true,
                NullType::NAME     => in_array(NullType::NAME, $permittedCastTypeNames, true),
                ObjectType::NAME   => in_array(ObjectType::NAME, $permittedCastTypeNames, true),
                ResourceType::NAME => in_array(ResourceType::NAME, $permittedCastTypeNames, true),
                ScalarRawType::NAME => in_array(ScalarRawType::NAME, $permittedCastTypeNames, true),
                StringType::NAME   => in_array(StringType::NAME, $permittedCastTypeNames, true),
                TrueType::NAME     => in_array(TrueType::NAME, $permittedCastTypeNames, true),
                VoidType::NAME     => in_array(VoidType::NAME, $permittedCastTypeNames, true),
            ];
        };

        // A matrix of allowable type conversions between
        // the various native types.
        // (Represented in a readable format, with only the true entries (omitting Mixed, which is always true))

        return [
            ArrayType::NAME    => $generateRow(ArrayType::NAME, IterableType::NAME, CallableType::NAME),
            BoolType::NAME     => $generateRow(BoolType::NAME, FalseType::NAME, TrueType::NAME, ScalarRawType::NAME),
            CallableType::NAME => $generateRow(CallableType::NAME),
            FalseType::NAME    => $generateRow(FalseType::NAME, BoolType::NAME, ScalarRawType::NAME),
            FloatType::NAME    => $generateRow(FloatType::NAME, ScalarRawType::NAME),
            IntType::NAME      => $generateRow(IntType::NAME, FloatType::NAME, ScalarRawType::NAME),
            IterableType::NAME => $generateRow(IterableType::NAME),
            MixedType::NAME    => $generateRow(MixedType::NAME),  // MixedType overrides the methods which would use this
            NullType::NAME     => $generateRow(NullType::NAME),
            ObjectType::NAME   => $generateRow(ObjectType::NAME),
            ResourceType::NAME => $generateRow(ResourceType::NAME),
            StringType::NAME   => $generateRow(StringType::NAME, CallableType::NAME, ScalarRawType::NAME),
            TrueType::NAME     => $generateRow(TrueType::NAME, BoolType::NAME, ScalarRawType::NAME),
            VoidType::NAME     => $generateRow(VoidType::NAME),
        ];
    }

    public function __toString() : string
    {
        // Native types can just use their
        // non-fully-qualified names
        $string = $this->name;

        if ($this->getIsNullable()) {
            $string = '?' . $string;
        }

        return $string;
    }

    public function asFQSENString() : string
    {
        return $this->name;
    }

    /**
     * @param CodeBase $code_base @phan-unused-param
     * The code base to use in order to find super classes, etc.
     *
     * @param int $recursion_depth @phan-unused-param
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Does nothing for Native Types, but GenericArrayType is an exception to that.
     * @override
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        return $this->asUnionType();
    }

    public function hasTemplateParameterTypes() : bool
    {
        return false;
    }

    /**
     * @return ?UnionType returns the iterable value's union type if this is a subtype of iterable, null otherwise.
     */
    public function iterableKeyUnionType(CodeBase $unused_code_base)
    {
        return null;
    }

    /**
     * @return ?UnionType returns the iterable value's union type if this is a subtype of iterable, null otherwise.
     */
    public function iterableValueUnionType(CodeBase $unused_code_base)
    {
        return null;
    }
}
\class_exists(ArrayType::class);
\class_exists(ScalarType::class);
