<?php declare(strict_types=1);
namespace Phan\Language\Type;

class IterableType extends NativeType
{
    /** @phan-override */
    const NAME = 'iterable';

    public function isIterable() : bool
    {
        return true;
    }

    public function isPrintableScalar() : bool
    {
        return false;
    }

    public function isPossiblyObject() : bool
    {
        return true;  // can be Traversable, which is an object
    }
}
// Trigger autoloader for subclass before make() can get called.
\class_exists(GenericIterableType::class);
\class_exists(ArrayType::class);
