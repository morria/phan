<?php

declare(strict_types=1);

namespace Phan\Language\Type;

use AssertionError;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\FQSEN;
use Phan\Language\Type;

/**
 * Phan's representation of `Closure` and of closures associated with a given function-like's FQSEN
 * @see ClosureDeclarationType for representations created from PHPDoc `Closure(MyClass):MyOtherClass`.
 * @phan-pure
 */
final class ClosureType extends Type
{
    use NativeTypeTrait;

    /** Not an override */
    public const NAME = 'Closure';

    /**
     * @var FQSEN|null the FQSEN of the function-like from which this ClosureType was derived
     */
    private $fqsen;

    /**
     * @var ?FunctionInterface (Kept to check for type compatibility)
     * NOTE: We may use class FQSENs (for __invoke) or Method FQSENs if the closure was created via reflection
     */
    private $func;

    /**
     * Same as instance(), but guaranteed not to have memoized state.
     * @suppress PhanTypeMismatchReturn
     */
    private static function closureInstance(): ClosureType
    {
        static $instance = null;
        if ($instance === null) {
            $instance = self::make('\\', self::NAME, [], false, self::FROM_NODE);
        }
        return $instance;
    }

    /**
     * Create an instance of Closure for the FQSEN of the passed in function/closure/method $func with FQSEN $fqsen
     * @suppress PhanAccessReadOnlyProperty this is acting on a clone
     */
    public static function instanceWithClosureFQSEN(FQSEN $fqsen, ?FunctionInterface $func = null): ClosureType
    {
        static $original_instance = null;
        if ($original_instance === null) {
            $original_instance = self::closureInstance();
        }
        // Use an instance with no memoized or lazily initialized results.
        // Avoids picking up changes to ClosureType::instance(false) in the case that a result depends on asFQSEN()
        $instance = clone($original_instance);
        $instance->fqsen = $fqsen;
        $instance->func = $func;
        $instance->memoizeFlushAll();
        return $instance;
    }

    /**
     * @suppress PhanAccessReadOnlyProperty
     */
    public function __clone()
    {
        if ($this->fqsen !== null) {
            throw new AssertionError('should only clone null fqsen');
        }
        $this->singleton_union_type = null;
        $this->singleton_real_union_type = null;
        // same as new static($this->namespace, $this->name, $this->template_parameter_type_list, $this->is_nullable);
    }

    /**
     * Is this a closure which points to a known FQSEN
     * (in internal or parsed function-likes, classes, methods, closures, etc.
     */
    public function hasKnownFQSEN(): bool
    {
        return $this->fqsen !== null;
    }

    /**
     * Override asFQSEN to return the closure's FQSEN
     */
    public function asFQSEN(): FQSEN
    {
        return $this->fqsen ?? parent::asFQSEN();
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableType(Type $type, CodeBase $code_base): bool
    {
        if (!$type->isPossiblyObject()) {
            return false;
        }
        if ($type->isCallable($code_base)) {
            if ($type instanceof FunctionLikeDeclarationType) {
                // Check if the function declaration is known and available. It's not available for the generic \Closure.
                if ($this->func) {
                    return $this->func->asFunctionLikeDeclarationType()->canCastToNonNullableFunctionLikeDeclarationType($type, $code_base);
                }
            }
            return true;
        }

        return parent::canCastToNonNullableType($type, $code_base);
    }

    protected function canCastToNonNullableTypeWithoutConfig(Type $type, CodeBase $code_base): bool
    {
        if (!$type->isPossiblyObject()) {
            return false;
        }
        if ($type->isCallable($code_base)) {
            if ($type instanceof FunctionLikeDeclarationType) {
                // Check if the function declaration is known and available. It's not available for the generic \Closure.
                if ($this->func) {
                    return $this->func->asFunctionLikeDeclarationType()->canCastToNonNullableFunctionLikeDeclarationType($type, $code_base);
                }
            }
            return true;
        }

        return parent::canCastToNonNullableTypeWithoutConfig($type, $code_base);
    }

    /**
     * @return bool
     * True if this Type can be cast to the given Type
     * cleanly
     */
    protected function canCastToNonNullableTypeHandlingTemplates(Type $type, CodeBase $code_base): bool
    {
        if (!$type->isPossiblyObject()) {
            return false;
        }
        if ($type->isCallable($code_base)) {
            if ($type instanceof FunctionLikeDeclarationType) {
                // Check if the function declaration is known and available. It's not available for the generic \Closure.
                if ($this->func) {
                    return $this->func->asFunctionLikeDeclarationType()->canCastToNonNullableFunctionLikeDeclarationType($type, $code_base);
                }
            }
            return true;
        }

        return parent::canCastToNonNullableTypeHandlingTemplates($type, $code_base);
    }

    /**
     * @return bool
     * True if this type is a callable or a Closure.
     * @unused-param $code_base
     */
    public function isCallable(CodeBase $code_base): bool
    {
        return true;
    }

    public function __toString(): string
    {
        if ($this->func) {
            $result = $this->func->asFunctionLikeDeclarationType()->__toString();
        } else {
            $result = '\Closure';
        }

        return $this->is_nullable ? "?$result" : $result;
    }

    /**
     * Returns true if this contains a type that is definitely non-callable
     * e.g. returns true for false, array, int
     *      returns false for callable, array, object, iterable, T, etc.
     * @unused-param $code_base
     */
    public function isDefiniteNonCallableType(CodeBase $code_base): bool
    {
        return false;
    }

    /**
     * Gets the function-like this type was created from.
     *
     * TODO: Uses of this may keep outdated data in language server mode.
     * @param CodeBase $code_base @unused-param
     * @param Context $context @unused-param
     * @unused-param $warn
     */
    public function asFunctionInterfaceOrNull(CodeBase $code_base, Context $context, bool $warn = true): ?FunctionInterface
    {
        return $this->func;
    }

    /**
     * @param CodeBase $code_base @unused-param
     * @param Context $context @unused-param
     */
    public function canCastToDeclaredType(CodeBase $code_base, Context $context, Type $other): bool
    {
        if (!$other->isPossiblyObject()) {
            return false;
        }
        if ($other->hasObjectWithKnownFQSEN()) {
            // Probably overkill to check for intersection types for closure
            return $other->anyTypePartsMatchCallback(static function (Type $part): bool {
                return $part instanceof FunctionLikeDeclarationType || $part instanceof ClosureType || $part->asFQSEN()->__toString() === '\Closure';
            });
        }
        return parent::canCastToDeclaredType($code_base, $context, $other);
    }

    public function isSubtypeOf(Type $type, CodeBase $code_base): bool
    {
        if (!$type->isPossiblyObject()) {
            return false;
        }
        if ($type->isDefiniteNonCallableType($code_base)) {
            return false;
        }
        if ($type instanceof FunctionLikeDeclarationType) {
            return false;
        }
        return parent::isSubtypeOf($type, $code_base);
    }
}
