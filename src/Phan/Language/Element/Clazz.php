<?php declare(strict_types=1);
namespace Phan\Language\Element;

use \Phan\CodeBase;
use \Phan\Exception\CodeBaseException;
use \Phan\Exception\IssueException;
use \Phan\Issue;
use \Phan\Language\Context;
use \Phan\Language\Element\Constant;
use \Phan\Language\Element\Method;
use \Phan\Language\Element\Property;
use \Phan\Language\FQSEN;
use \Phan\Language\FQSEN\FullyQualifiedClassName;
use \Phan\Language\FQSEN\FullyQualifiedMethodName;
use \Phan\Language\FQSEN\FullyQualifiedPropertyName;
use \Phan\Language\Type;
use \Phan\Language\UnionType;

class Clazz extends TypedStructuralElement implements Addressable
{
    use AddressableImplementation;
    use \Phan\Memoize;

    /**
     * @var \Phan\Language\FQSEN
     */
    private $parent_class_fqsen = null;

    /**
     * @var \Phan\Language\FQSEN[]
     * A possibly empty list of interfaces implemented
     * by this class
     */
    private $interface_fqsen_list = [];

    /**
     * @var \Phan\Language\FQSEN[]
     * A possibly empty list of traits used by this class
     */
    private $trait_fqsen_list = [];

    /**
     * @var bool
     * True if this class's constructor calls it's
     * parent constructor.
     */
    private $is_parent_constructor_called = true;

    /**
     * @param Context $context
     * The context in which the structural element lives
     *
     * @param string $name,
     * The name of the typed structural element
     *
     * @param UnionType $type,
     * A '|' delimited set of types satisfyped by this
     * typed structural element.
     *
     * @param int $flags,
     * The flags property contains node specific flags. It is
     * always defined, but for most nodes it is always zero.
     * ast\kind_uses_flags() can be used to determine whether
     * a certain kind has a meaningful flags value.
     *
     * @param FullyQualifiedClassName|null $parent_class_fqsen
     * @param FullyQualifiedClassName[]|null $interface_fqsen_list
     * @param FullyQualifiedClassName[]|null $trait_fqsen_list
     */
    public function __construct(
        Context $context,
        string $name,
        UnionType $type,
        int $flags,
        FullyQualifiedClassName $parent_class_fqsen = null,
        array $interface_fqsen_list = [],
        array $trait_fqsen_list = []
    ) {
        // Add variable '$this' to the scope
        $context = $context->withScopeVariable(new Variable(
            $context,
            'this',
            $type,
            0
        ));

        parent::__construct(
            $context,
            $name,
            $type,
            $flags
        );

        $this->parent_class_fqsen = $parent_class_fqsen;
        $this->interface_fqsen_list = $interface_fqsen_list;
        $this->trait_fqsen_list = $trait_fqsen_list;
    }

    /**
     * @param CodeBase $code_base
     * A reference to the entire code base in which this
     * context exists
     *
     * @param string $class_name
     * The name of a builtin class to build a new Class structural
     * element from.
     *
     * @return Clazz
     * A Class structural element representing the given named
     * builtin.
     */
    public static function fromClassName(
        CodeBase $code_base,
        string $class_name
    ) : Clazz {
        return self::fromReflectionClass(
            $code_base,
            new \ReflectionClass($class_name)
        );
    }

    /**
     * @param CodeBase $code_base
     * A reference to the entire code base in which this
     * context exists
     *
     * @param ReflectionClass $class
     * A reflection class representing a builtin class.
     *
     * @return Clazz
     * A Class structural element representing the given named
     * builtin.
     */
    public static function fromReflectionClass(
        CodeBase $code_base,
        \ReflectionClass $class
    ) : Clazz {
        // Build a set of flags based on the constitution
        // of the built-in class
        $flags = 0;
        if ($class->isFinal()) {
            $flags = \ast\flags\CLASS_FINAL;
        } elseif ($class->isInterface()) {
            $flags = \ast\flags\CLASS_INTERFACE;
        } elseif ($class->isTrait()) {
            $flags = \ast\flags\CLASS_TRAIT;
        }
        if ($class->isAbstract()) {
            $flags |= \ast\flags\CLASS_ABSTRACT;
        }

        $context = new Context();

        // Build a base class element
        $clazz = new Clazz(
            $context,
            $class->getName(),
            UnionType::fromStringInContext($class->getName(), $context),
            $flags
        );

        // If this class has a parent class, add it to the
        // class info
        if (($parent_class = $class->getParentClass())) {
            $parent_class_fqsen =
                FullyQualifiedClassName::fromFullyQualifiedString(
                    '\\' . $parent_class->getName()
                );

            $clazz->setParentClassFQSEN($parent_class_fqsen);
        }

        foreach ($class->getDefaultProperties() as $name => $value) {
            // TODO: whats going on here?
            $reflection_property =
                new \ReflectionProperty($class->getName(), $name);

            $property = new Property(
                $context->withClassFQSEN($clazz->getFQSEN()),
                $name,
                Type::fromObject($value)->asUnionType(),
                0
            );

            $clazz->addProperty($code_base, $property);
        }

        foreach ($class->getInterfaceNames() as $name) {
            $clazz->addInterfaceClassFQSEN(
                FullyQualifiedClassName::fromFullyQualifiedString(
                    '\\' . $name
                )
            );
        }

        foreach ($class->getTraitNames() as $name) {
            $clazz->addTraitFQSEN(
                FullyQualifiedClassName::fromFullyQualifiedString(
                    '\\' . $name
                )
            );
        }

        foreach ($class->getConstants() as $name => $value) {
            $clazz->addConstant(
                $code_base,
                new Constant(
                    $context,
                    $name,
                    Type::fromObject($value)->asUnionType(),
                    0
                )
            );
        }

        foreach ($class->getMethods() as $reflection_method) {
            $method_list =
                Method::methodListFromReflectionClassAndMethod(
                    $context->withClassFQSEN($clazz->getFQSEN()),
                    $code_base,
                    $class,
                    $reflection_method
                );

            foreach ($method_list as $method) {
                $clazz->addMethod($code_base, $method);
            }
        }

        return $clazz;
    }

    /**
     * @param FQSEN $fqsen
     * The parent class to associate with this class
     *
     * @return null
     */
    public function setParentClassFQSEN(FullyQualifiedClassName $fqsen)
    {
        $this->parent_class_fqsen = $fqsen;

        // Add the parent to the union type of this
        // class
        $this->getUnionType()->addUnionType(
            UnionType::fromFullyQualifiedString((string)$fqsen)
        );
    }

    /**
     * @return bool
     * True if this class has a parent class
     */
    public function hasParentClassFQSEN() : bool
    {
        return !empty($this->parent_class_fqsen);
    }

    /**
     * @return FQSEN
     * The parent class of this class if one exists
     */
    public function getParentClassFQSEN() : FullyQualifiedClassName
    {
        return $this->parent_class_fqsen;
    }

    /**
     * @param FQSEN $fqsen
     * Add the given FQSEN to the list of implemented
     * interfaces for this class
     *
     * @return null
     */
    public function addInterfaceClassFQSEN(FQSEN $fqsen)
    {
        $this->interface_fqsen_list[] = $fqsen;

        // Add the interface to the union type of this
        // class
        $this->getUnionType()->addUnionType(
            UnionType::fromFullyQualifiedString((string)$fqsen)
        );
    }

    /**
     * @return FQSEN[]
     * Get the list of interfaces implemented by this class
     */
    public function getInterfaceFQSENList() : array
    {
        return $this->interface_fqsen_list;
    }

    /**
     * @return void
     */
    public function addProperty(
        CodeBase $code_base,
        Property $property
    ) {
        if (!$this->hasPropertyWithName(
            $code_base,
            $property->getName()
        )) {
            $code_base->addPropertyInScope(
                $property,
                $this->getFQSEN()
            );
        }
    }

    /**
     * @return bool
     */
    public function hasPropertyWithName(
        CodeBase $code_base,
        string $name
    ) : bool {
        return $code_base->hasProperty(
            $this->getFQSEN(),
            $name
        );
    }

    /**
     * @return Property[]
     * The list of properties defined on this class
     */
    public function getPropertyList(
        CodeBase $code_base
    ) {
        return $code_base->getPropertyMapForScope(
            $this->getFQSEN()
        );
    }

    /**
     * @param string $name
     * The name of the property
     *
     * @param Context $context
     * The context of the caller requesting the property
     *
     * @return Property
     * A property with the given name
     *
     * @throws IssueException
     * An exception may be thrown if the caller does not
     * have access to the given property from the given
     * context
     */
    public function getPropertyByNameInContext(
        CodeBase $code_base,
        string $name,
        Context $context
    ) : Property {

        // Check to see if we have the property
        if (!$code_base->hasProperty($this->getFQSEN(), $name)) {
            // If we don't have the property but do have a
            // __get method, then we can create the property
            if ($this->hasMethodWithName($code_base, '__get')) {
                $property = new Property(
                    $context,
                    $name,
                    new UnionType(),
                    0
                );

                $property->setFQSEN(
                    FullyQualifiedPropertyName::make(
                        $this->getFQSEN(),
                        $name
                    )
                );

                $this->addProperty($code_base, $property);
            } else {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredProperty)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [ "{$this->getFQSEN()}::\$$name}" ]
                    )
                );
            }
        }

        $property = $code_base->getProperty(
            $this->getFQSEN(),
            $name
        );

        // If we're getting the property from outside of this
        // class and the property isn't public and we don't
        // have a getter or setter, emit an access error
        if ((!$context->hasClassFQSEN() || $context->getClassFQSEN() != $this->getFQSEN())
            && !$property->isPublic()
            && !$this->hasMethodWithName($code_base, '__get')
            && !$this->hasMethodWithName($code_base, '__set')
        ) {
            if ($property->isPrivate()) {
                throw new IssueException(
                    Issue::fromType(Issue::AccessPropertyPrivate)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [ "{$this->getFQSEN()}::\${$property->getName()}" ]
                    )
                );
            }
            if ($property->isProtected()) {
                throw new IssueException(
                    Issue::fromType(Issue::AccessPropertyProtected)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [ "{$this->getFQSEN()}::\${$property->getName()}" ]
                    )
                );
            }
        }

        return $property;
    }

    /**
     * @return Property[]
     * The list of properties on this class
     */
    public function getPropertyMap(CodeBase $code_base) : array
    {
        return $code_base->getPropertyMapForScope(
            $this->getFQSEN()
        );
    }

    /**
     * Add a class constant
     *
     * @return null;
     */
    public function addConstant(
        CodeBase $code_base,
        Constant $constant
    ) {
        $code_base->addConstantInScope(
            $constant,
            $this->getFQSEN()
        );
    }

    /**
     * @return bool
     * True if a constant with the given name is defined
     * on this class.
     */
    public function hasConstantWithName(
        CodeBase $code_base,
        string $name
    ) : bool {
        return $code_base->hasConstant(
            $this->getFQSEN(),
            $name
        );
    }

    /**
     * @return Constant
     * The class constant with the given name.
     */
    public function getConstantWithName(
        CodeBase $code_base,
        string $name
    ) : Constant {
        return $code_base->getConstant(
            $this->getFQSEN(),
            $name
        );
    }

    /**
     * @return Constant[]
     * The constants associated with this class
     */
    public function getConstantMap(CodeBase $code_base) : array
    {
        return $code_base->getConstantMapForScope(
            $this->getFQSEN()
        );
    }

    /**
     * @return null
     */
    public function addMethod(
        CodeBase $code_base,
        Method $method
    ) {
        $method_fqsen = FullyQualifiedMethodName::make(
            $this->getFQSEN(),
            $method->getName()
        );

        // Don't overwrite overridden methods with
        // parent methods
        if ($code_base->hasMethod($method_fqsen)) {
            return;
        }

        $code_base->addMethodInScope(
            $method,
            $this->getFQSEN()
        );
    }

    /**
     * @return bool
     * True if this class has a method with the given name
     */
    public function hasMethodWithName(
        CodeBase $code_base,
        string $name
    ) : bool {
        // All classes have a constructor even if it hasn't
        // been declared yet
        if ('__construct' === strtolower($name)) {
            return true;
        }

        $method_fqsen = FullyQualifiedMethodName::make(
            $this->getFQSEN(),
            $name
        );

        return $code_base->hasMethod($method_fqsen);
    }

    /**
     * @return Method
     * The method with the given name
     */
    public function getMethodByNameInContext(
        CodeBase $code_base,
        string $name,
        Context $context
    ) : Method {

        $method_fqsen = FullyQualifiedMethodName::make(
            $this->getFQSEN(),
            $name
        );

        if (!$code_base->hasMethod($method_fqsen)) {
            if ('__construct' === $name) {
                // Create a default constructor if its requested
                // but doesn't exist yet
                $default_constructor =
                    Method::defaultConstructorForClassInContext(
                        $this,
                        $this->getContext()->withClassFQSEN(
                            $this->getFQSEN()
                        )
                    );

                $this->addMethod($code_base, $default_constructor);

                return $default_constructor;
            }

            throw new CodeBaseException(
                $method_fqsen,
                "Method with name $name does not exist for class {$this->getFQSEN()}."
            );
        }

        return $code_base->getMethod($method_fqsen);
    }

    /**
     * @return Method[]
     * A list of methods on this class
     */
    public function getMethodMap(CodeBase $code_base) : array
    {
        return $code_base->getMethodMapForScope(
            $this->getFQSEN()
        );
    }

    /**
     * @return null
     */
    public function addTraitFQSEN(FQSEN $fqsen)
    {
        $this->trait_fqsen_list[] = $fqsen;

        // Add the trait to the union type of this class
        $this->getUnionType()->addUnionType(
            UnionType::fromFullyQualifiedString((string)$fqsen)
        );
    }

    /**
     * @return FQSEN[]
     * A list of FQSEN's for included traits
     */
    public function getTraitFQSENList() : array
    {
        return $this->trait_fqsen_list;
    }

    /**
     * @return bool
     * True if this class calls its parent constructor
     */
    public function getIsParentConstructorCalled() : bool
    {
        return $this->is_parent_constructor_called;
    }

    /**
     * @return null
     */
    public function setIsParentConstructorCalled(bool $is_parent_constructor_called)
    {
        $this->is_parent_constructor_called =
            $is_parent_constructor_called;
    }

    /**
     * @return bool
     * True if this is a final class
     */
    public function isFinal() : bool
    {
        return (bool)(
            $this->getFlags() & \ast\flags\CLASS_FINAL
        );
    }

    /**
     * @return bool
     * True if this is an abstract class
     */
    public function isAbstract() : bool
    {
        return (bool)(
            $this->getFlags() & \ast\flags\CLASS_ABSTRACT
        );
    }

    /**
     * @return bool
     * True if this is an interface
     */
    public function isInterface() : bool
    {
        return (bool)(
            $this->getFlags() & \ast\flags\CLASS_INTERFACE
        );
    }

    /**
     * @return bool
     * True if this class is a trait
     */
    public function isTrait() : bool
    {
        return (bool)(
            $this->getFlags() & \ast\flags\CLASS_TRAIT
        );
    }

    /**
     * @return FullyQualifiedClassName
     */
    public function getFQSEN() : FQSEN
    {
        // Allow overrides
        if ($this->fqsen) {
            return $this->fqsen;
        }

        return FullyQualifiedClassName::fromStringInContext(
            $this->getName(),
            $this->getContext()
        );
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return FullyQualifiedClassName[]
     */
    public function getNonParentAncestorFQSENList(CodeBase $code_base)
    {
        return array_merge(
            $this->getTraitFQSENList(),
            $this->getInterfaceFQSENList()
        );
    }

    /**
     * @return FullyQualifiedClassName[]
     */
    public function getAncestorFQSENList(CodeBase $code_base)
    {
        $ancestor_list = $this->getNonParentAncestorFQSENList($code_base);

        if ($this->hasParentClassFQSEN()) {
            $ancestor_list[] = $this->getParentClassFQSEN();
        }

        return $ancestor_list;
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return Clazz[]
     */
    public function getAncestorClazzList(CodeBase $code_base)
    {
        $ancestor_class_list = [];
        foreach ($this->getAncestorFQSENList($code_base) as $fqsen) {
            if ($code_base->hasClassWithFQSEN($fqsen)) {
                $ancestor_class_list[] =
                    $code_base->getClassByFQSEN($fqsen);
            }
        }
        return $ancestor_class_list;
    }


    /**
     * Determine if this method overrides another method
     *
     * @return void
     */
    public function analyzeMethodOverrides(CodeBase $code_base)
    {
        return $this->memoize(__METHOD__, function () use ($code_base) {

            // Get the list of ancestors of this class
            $ancestor_class_list = $this->getAncestorClazzList(
                $code_base
            );

            // For each method, check to see if its overriding
            // something. We're relying here on having copied
            // methods to subclasses.
            foreach ($this->getMethodMap($code_base) as $name => $method) {
                $is_override = array_reduce(
                    $ancestor_class_list,
                    function (bool $carry, Clazz $class) use ($code_base, $name) {
                        return ($carry || $class->hasMethodWithName(
                            $code_base,
                            $name
                        ));
                    },
                    false
                );

                // Tell the method if its overriding something or not
                $method->setIsOverride($is_override);
            }
        });
    }

    /**
     * Add properties, constants and methods from all
     * ancestors (parents, traits, ...) to this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return null
     */
    public function importAncestorClasses(CodeBase $code_base)
    {
        $this->memoize(__METHOD__, function () use ($code_base) {
            foreach ($this->getNonParentAncestorFQSENList($code_base) as $fqsen) {
                if (!$code_base->hasClassWithFQSEN($fqsen)) {
                    continue;
                }

                $this->importAncestorClass(
                    $code_base,
                    $code_base->getClassByFQSEN($fqsen)
                );
            }

            // Copy information from the parent(s)
            $this->importParentClass($code_base);
        });
    }

    /*
     * Add properties, constants and methods from the
     * parent of this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return null
     */
    public function importParentClass(CodeBase $code_base)
    {
        $this->memoize(__METHOD__, function () use ($code_base) {
            if (!$this->hasParentClassFQSEN()) {
                return;
            }

            // Let the parent class finder worry about this
            if (!$code_base->hasClassWithFQSEN(
                $this->getParentClassFQSEN()
            )) {
                return;
            }

            assert(
                $code_base->hasClassWithFQSEN($this->getParentClassFQSEN()),
                "Clazz {$this->getParentClassFQSEN()} should already have been proven to exist from {$this->getContext()}"
            );

            // Get the parent class
            $parent = $code_base->getClassByFQSEN(
                $this->getParentClassFQSEN()
            );

            // Tell the parent to import its own parents first
            $parent->importAncestorClasses($code_base);

            // Import elements from the parent
            $this->importAncestorClass(
                $code_base,
                $parent
            );
        });
    }

    /**
     * Add properties, constants and methods from the given
     * class to this.
     *
     * @param Clazz $superclazz
     * A class to import from
     *
     * @return null
     */
    public function importAncestorClass(
        CodeBase $code_base,
        Clazz $superclazz
    ) {
        $this->memoize(
            (string)$superclazz->getFQSEN(),
            function () use ($code_base, $superclazz) {

                // Copy properties
                foreach ($superclazz->getPropertyMap($code_base) as $property) {
                    $this->addProperty($code_base, $property);
                }

                // Copy constants
                foreach ($superclazz->getConstantMap($code_base) as $constant) {
                    $this->addConstant($code_base, $constant);
                }

                // Copy methods
                foreach ($superclazz->getMethodMap($code_base) as $method) {
                    $this->addMethod($code_base, $method);
                }
            }
        );
    }

    /**
     * @return int
     * The number of references to this typed structural element
     */
    public function getReferenceCount(
        CodeBase $code_base
    ) : int {
        $count = parent::getReferenceCount($code_base);

        // A function that maps a list of elements to the
        // total reference count for all elements
        $list_count = function (array $list) use ($code_base) {
            return array_reduce($list, function (
                int $count,
                TypedStructuralElement $element
            ) use ($code_base) {
                return (
                    $count
                    + $element->getReferenceCount($code_base)
                );
            }, 0);
        };

        // Sum up counts for all dependent elements
        $count += $list_count($this->getPropertyList($code_base));
        $count += $list_count($this->getMethodMap($code_base));
        $count += $list_count($this->getConstantMap($code_base));

        return $count;
    }

    /**
     * @return string
     * A string describing this class
     */
    public function __toString() : string
    {
        $string = '';

        if ($this->isFinal()) {
            $string .= 'final ';
        }

        if ($this->isAbstract()) {
            $string .= 'abstract ';
        }

        if ($this->isInterface()) {
            $string .= 'Interface ';
        } elseif ($this->isTrait()) {
            $string .= 'Trait ';
        } else {
            $string .= 'Class ';
        }

        $string .= (string)$this->getFQSEN()->getCanonicalFQSEN();

        return $string;
    }
}
