<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use ast;
use ast\Node;
use Closure;
use LogicException;
use Phan\Analysis\AbstractMethodAnalyzer;
use Phan\Analysis\ClassConstantTypesAnalyzer;
use Phan\Analysis\ClassInheritanceAnalyzer;
use Phan\Analysis\CompositionAnalyzer;
use Phan\Analysis\DuplicateClassAnalyzer;
use Phan\Analysis\ParentConstructorCalledAnalyzer;
use Phan\Analysis\PostOrderAnalysisVisitor;
use Phan\Analysis\PropertyTypesAnalyzer;
use Phan\AST\ASTReverter;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Exception\IssueException;
use Phan\Exception\RecursionDepthException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Comment\Property as CommentProperty;
use Phan\Language\ElementContext;
use Phan\Language\FileRef;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\Scope\ClassScope;
use Phan\Language\Scope\GlobalScope;
use Phan\Language\Type;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TemplateType;
use Phan\Language\UnionType;
use Phan\Library\None;
use Phan\Library\Option;
use Phan\Library\Some;
use Phan\Memoize;
use Phan\Plugin\ConfigPluginSet;
use Phan\Suggestion;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

use function array_key_exists;
use function array_merge;
use function array_values;
use function count;
use function in_array;
use function is_int;
use function is_string;
use function strtolower;

/**
 * Clazz represents the information Phan knows about a class, trait, or interface,
 * the state of Phan populating that information (hydration),
 * and methods to access that information.
 *
 * @see CodeBase for the data structures used for looking up classes or elements of classes (properties, methods, constants, etc)
 *
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @phan-file-suppress PhanPluginNoCommentOnPublicMethod TODO: Add comments
 * @property FullyQualifiedClassName $fqsen
 */
class Clazz extends AddressableElement
{
    use Memoize;
    use ClosedScopeElement;
    use HasAttributesTrait;

    /**
     * @var Type|null
     * The type of the parent of this class if it extends
     * anything, else null.
     */
    private $parent_type = null;

    /**
     * @var int
     * The line number of the parent of this class if it extends
     * anything, else 0 if unknown/missing.
     */
    private $parent_type_lineno = 0;

    /**
     * @var list<FullyQualifiedClassName>
     * A possibly empty list of interfaces implemented
     * by this class
     */
    private $interface_fqsen_list = [];

    /**
     * @var list<int>
     * Line numbers for indices of interface_fqsen_list.
     */
    private $interface_fqsen_lineno = [];

    /**
     * @var list<FullyQualifiedClassName>
     * A possibly empty list of traits used by this class
     */
    private $trait_fqsen_list = [];

    /**
     * @var list<int>
     * Line numbers for indices of trait_fqsen_list
     */
    private $trait_fqsen_lineno = [];

    /**
     * @var array<string,TraitAdaptations>
     * Maps lowercase fqsen of a method to the trait names which are hidden
     * and the trait aliasing info
     */
    private $trait_adaptations_map = [];

    /**
     * @var bool - hydrate() will check for this to avoid prematurely hydrating while looking for values of class constants.
     */
    private $did_finish_parsing = true;

    /**
     * @var ?UnionType for Type->asExpandedTypes()
     *
     * TODO: This won't reverse in daemon mode?
     */
    private $additional_union_types = null;

    /**
     * An additional id to disambiguate classes on the same line
     * https://github.com/phan/phan/issues/1988
     */
    private $decl_id = 0;

    /**
     * @var Context
     */
    private $internal_context;

    /**
     * @var list<Type>
     */
    private $mixin_types = [];

    /**
     * @var array<mixed,string> maps value to the name of the case declaring that value
     * (for backed enums)
     */
    private $enum_case_map = [];

    /**
     * @var list<string> list of enum case names with values that could not be determined
     */
    private $enum_case_map_unknown = [];

    /**
     * @var list<string> list of enum case names
     * (for unit enums)
     */
    private $enum_case_list = [];

    /**
     * @var ?UnionType backing type of enum. Null if enum cases have no value.
     */
    private $enum_type = null;

    /**
     * @param Context $context
     * The context in which the structural element lives
     *
     * @param string $name
     * The name of the typed structural element
     *
     * @param UnionType $type
     * A '|' delimited set of types satisfied by this
     * typed structural element.
     *
     * @param int $flags
     * The flags property contains node specific flags. It is
     * always defined, but for most nodes it is always zero.
     * ast\kind_uses_flags() can be used to determine whether
     * a certain kind has a meaningful flags value.
     *
     * @param FullyQualifiedClassName $fqsen
     * A fully qualified name for this class
     *
     * @param Type|null $parent_type
     * @param list<FullyQualifiedClassName> $interface_fqsen_list
     * @param list<FullyQualifiedClassName> $trait_fqsen_list
     */
    public function __construct(
        Context $context,
        string $name,
        UnionType $type,
        int $flags,
        FullyQualifiedClassName $fqsen,
        ?Type $parent_type = null,
        array $interface_fqsen_list = [],
        array $trait_fqsen_list = []
    ) {
        parent::__construct(
            $context,
            $name,
            $type,
            $flags,
            $fqsen
        );

        $this->parent_type = $parent_type;
        $this->interface_fqsen_list = $interface_fqsen_list;
        $this->trait_fqsen_list = $trait_fqsen_list;

        $internal_scope = new ClassScope(
            $context->getScope(),
            $fqsen,
            $flags
        );
        $this->setInternalScope($internal_scope);
        $this->internal_context = $context->withScope($internal_scope);
    }

    private static function getASTFlagsForReflectionProperty(ReflectionProperty $prop): int
    {
        if ($prop->isPrivate()) {
            return \ast\flags\MODIFIER_PRIVATE;
        } elseif ($prop->isProtected()) {
            return \ast\flags\MODIFIER_PROTECTED;
        }
        return 0;
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
        ReflectionClass $class
    ): Clazz {
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
        // FIXME readonly flag
        if ($class->isAbstract()) {
            $flags |= \ast\flags\CLASS_ABSTRACT;
        }
        if (\PHP_VERSION_ID >= 80100) {
            // @phan-suppress-next-line PhanUndeclaredMethod this was added in 8.1
            if ($class->isEnum()) {
                $flags |= \ast\flags\CLASS_ENUM;
            }
        }

        $context = new Context();

        // ReflectionClass->getName() will include the namespace prefix (e.g. 'ast\Node'), unlike Phan\Language\Element\Clazz.
        $class_name = $class->getName();
        // @phan-suppress-next-line PhanThrowTypeAbsentForCall should be valid if extension is valid
        $class_fqsen = FullyQualifiedClassName::fromFullyQualifiedString($class_name);

        // Build a base class element
        $clazz = new Clazz(
            $context,
            $class_name,
            UnionType::fromFullyQualifiedRealString('\\' . $class_name),
            $flags,
            $class_fqsen
        );

        // If this class has a parent class, add it to the
        // class info
        if (($parent_class = $class->getParentClass())) {
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall should be valid if extension is valid
            $parent_class_fqsen = FullyQualifiedClassName::fromFullyQualifiedString(
                '\\' . $parent_class->getName()
            );

            $parent_type = $parent_class_fqsen->asType();

            $clazz->setParentType($parent_type);
        }

        if ($class_name === "Traversable") {
            // Make sure that canCastToExpandedUnionType() works as expected for Traversable and its subclasses
            $clazz->addAdditionalType(IterableType::instance(false));
        }

        $class_scope = new ClassScope(new GlobalScope(), $class_fqsen, $flags);

        // Note: If there are multiple calls to Clazz->addProperty(),
        // the UnionType from the first one will be used, subsequent calls to addProperty()
        // will have no effect.
        // As a result, we set the types from Phan's documented internal property types first,
        // preferring them over the default values (which may be null, etc.).
        foreach (UnionType::internalPropertyMapForClassName(
            $class_name
        ) as $property_name => $property_type_string) {
            // An asterisk indicates that the class supports
            // dynamic properties
            if ($property_name === '*') {
                $clazz->setHasDynamicProperties(true);
                continue;
            }

            $property_context = $context->withScope($class_scope);

            $property_type =
                UnionType::fromStringInContext(
                    $property_type_string,
                    new Context(),
                    Type::FROM_TYPE
                );

            $property_fqsen = FullyQualifiedPropertyName::make(
                $clazz->getFQSEN(),
                $property_name
            );

            if ($class->hasProperty($property_name)) {
                $reflection_property = $class->getProperty($property_name);
                $flags = self::getASTFlagsForReflectionProperty($reflection_property);
                $real_type = self::getRealTypeForReflectionProperty($reflection_property);
            } else {
                $flags = 0;
                $real_type = UnionType::empty();
            }

            $property = new Property(
                $property_context,
                $property_name,
                $property_type->withRealTypeSet($real_type->getTypeSet()),
                $flags,
                $property_fqsen,
                $real_type
            );
            // Record that Phan has known union types for this internal property,
            // so that analysis of assignments to the property can account for it.
            $property->setPHPDocUnionType($property_type);
            if (in_array(strtolower($class_name), ['backedenum', 'unitenum'], true)) {
                $property->setPhanFlags(Flags::IS_READ_ONLY | Flags::IS_ENUM_PROPERTY);
            }

            $clazz->addProperty($code_base, $property, None::instance());
        }

        if (in_array(strtolower($class_name), ['backedenum', 'unitenum'], true)) {
            $clazz->setPhanFlags($clazz->getPhanFlags() | Flags::IS_READ_ONLY);
        }
        // n.b.: public properties on internal classes don't get
        //       listed via reflection until they're set unless
        //       they have a default value. Therefore, we don't
        //       bother iterating over `$class->getProperties()`
        //       `$class->getStaticProperties()`.

        foreach ($class->getDefaultProperties() as $name => $default_value) {
            $property_context = $context->withScope($class_scope);

            $property_fqsen = FullyQualifiedPropertyName::make(
                $clazz->getFQSEN(),
                $name
            );

            if ($clazz->hasPropertyWithName($code_base, $name)) {
                continue;
            }
            if ($class->hasProperty($name)) {
                $reflection_property = $class->getProperty($name);
                $flags = self::getASTFlagsForReflectionProperty($reflection_property);
                $real_type = self::getRealTypeForReflectionProperty($reflection_property);
            } else {
                $flags = 0;
                $real_type = UnionType::empty();
            }
            $property = new Property(
                $property_context,
                $name,
                Type::fromObject($default_value)->asPHPDocUnionType()->withRealTypeSet($real_type->getTypeSet()),
                $flags,
                $property_fqsen,
                $real_type
            );

            $clazz->addProperty($code_base, $property, None::instance());
        }
        foreach ($class->getProperties() as $reflection_property) {
            // In PHP 7.4, it's possible for internal classes to have properties without defaults if they're uninitialized.
            $name = $reflection_property->name;
            if ($clazz->hasPropertyWithName($code_base, $name)) {
                continue;
            }
            $property_context = $context->withScope($class_scope);

            $property_fqsen = FullyQualifiedPropertyName::make(
                $clazz->getFQSEN(),
                $name
            );

            $real_type = self::getRealTypeForReflectionProperty($reflection_property);
            $property = new Property(
                $property_context,
                $name,
                $real_type->asRealUnionType(),
                self::getASTFlagsForReflectionProperty($reflection_property),
                $property_fqsen,
                $real_type
            );

            $clazz->addProperty($code_base, $property, None::instance());
        }

        foreach ($class->getInterfaceNames() as $name) {
            $clazz->addInterfaceClassFQSEN(
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall should be valid if extension is valid
                FullyQualifiedClassName::fromFullyQualifiedString(
                    '\\' . $name
                )
            );
        }

        foreach ($class->getTraitNames() as $name) {
            // TODO: optionally, support getTraitAliases()? This is low importance for internal PHP modules,
            // it would be uncommon to see traits in internal PHP modules.
            $clazz->addTraitFQSEN(
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall should be valid if extension is valid
                FullyQualifiedClassName::fromFullyQualifiedString(
                    '\\' . $name
                )
            );
        }

        foreach ($class->getConstants() as $name => $value) {
            $constant_fqsen = FullyQualifiedClassConstantName::make(
                $clazz->getFQSEN(),
                $name
            );

            $constant = new ClassConstant(
                $context,
                $name,
                Type::fromObject($value)->asRealUnionType(),  // TODO: These can vary based on OS/build flags
                0,
                $constant_fqsen
            );
            $constant->setNodeForValue($value);

            $clazz->addConstant($code_base, $constant);
        }

        foreach ($class->getMethods() as $reflection_method) {
            if ($reflection_method->getDeclaringClass()->name !== $class_name) {
                continue;
            }
            $method_context = $context->withScope($class_scope);

            $method_list =
                FunctionFactory::methodListFromReflectionClassAndMethod(
                    $method_context,
                    $class,
                    $reflection_method
                );

            foreach ($method_list as $method) {
                $clazz->addMethod($code_base, $method, None::instance());
            }
            if (\PHP_VERSION_ID < 80000 && $reflection_method->isConstructor()  && \strcasecmp($reflection_method->name, '__construct') !== 0) {
                // E.g. SoapFault::SoapFault and other soap classes before PHP 8.0
                $clazz->addMethod($code_base, Method::defaultConstructorForClass($clazz, $code_base), None::instance());
            }
        }

        return $clazz;
    }

    /**
     * @suppress PhanUndeclaredMethod properties only have types and reflection types in php 7.4+
     */
    private static function getRealTypeForReflectionProperty(ReflectionProperty $property): UnionType
    {
        if (\PHP_VERSION_ID >= 70400) {
            if ($property->hasType()) {
                return UnionType::fromReflectionType($property->getType());
            }
        }
        return UnionType::empty();
    }

    /**
     * @param Type $parent_type
     * The type of the parent (extended) class of this class.
     */
    public function setParentType(Type $parent_type, int $lineno = 0): void
    {
        if ($this->getInternalScope()->hasAnyTemplateType()) {
            // Get a reference to the local list of templated
            // types. We'll use this to map templated types on the
            // parent to locally templated types.
            $template_type_map =
                $this->getInternalScope()->getTemplateTypeMap();

            // Figure out if the given parent type contains any template
            // types.
            $contains_templated_type = false;
            foreach ($parent_type->getTemplateParameterTypeList() as $union_type) {
                foreach ($union_type->getTypeSet() as $type) {
                    if (isset($template_type_map[$type->getName()])) {
                        $contains_templated_type = true;
                        break 2;
                    }
                }
            }

            // If necessary, map the template parameter type list through the
            // local list of templated types.
            if ($contains_templated_type) {
                $parent_type = Type::fromType(
                    $parent_type,
                    \array_map(static function (UnionType $union_type) use ($template_type_map): UnionType {
                        return UnionType::of(
                            \array_map(static function (Type $type) use ($template_type_map): Type {
                                return $template_type_map[$type->getName()] ?? $type;
                            }, $union_type->getTypeSet()),
                            []
                        );
                    }, $parent_type->getTemplateParameterTypeList())
                );
            }
        }

        $this->parent_type = $parent_type;
        $this->parent_type_lineno = $lineno;

        // Add the parent to the union type of this class
        $this->addAdditionalType($parent_type);
    }

    /**
     * @return bool
     * True if this class has a parent class
     */
    public function hasParentType(): bool
    {
        return $this->parent_type !== null;
    }

    /**
     * @return Option<Type>
     * If a parent type is defined, get Some<Type>, else None.
     */
    public function getParentTypeOption(): Option
    {
        if ($this->parent_type !== null) {
            return new Some($this->parent_type);
        }

        return None::instance();
    }

    /**
     * @return FullyQualifiedClassName
     * The parent class of this class if one exists
     *
     * @throws LogicException
     * An exception is thrown if this class has no parent
     */
    public function getParentClassFQSEN(): FullyQualifiedClassName
    {
        if (!$this->parent_type) {
            throw new LogicException("Class $this has no parent");
        }

        return FullyQualifiedClassName::fromType($this->parent_type);
    }

    /**
     * @return Clazz
     * The parent class of this class if defined
     *
     * @throws LogicException|RuntimeException
     * An exception is thrown if this class has no parent
     */
    public function getParentClass(CodeBase $code_base): Clazz
    {
        if (!$this->parent_type) {
            throw new LogicException("Class $this has no parent");
        }

        $parent_fqsen = FullyQualifiedClassName::fromType($this->parent_type);

        // invoking hasClassWithFQSEN also has the side effect of lazily loading the parent class definition.
        if (!$code_base->hasClassWithFQSEN($parent_fqsen)) {
            throw new RuntimeException("Failed to load parent Class $parent_fqsen of Class $this");
        }

        return $code_base->getClassByFQSEN(
            $parent_fqsen
        );
    }

    /**
     * @return Clazz
     * The parent class of this class if defined (does not trigger class hydration of the parent class, unlike getParentClass)
     *
     * @throws LogicException|RuntimeException
     * An exception is thrown if this class has no parent
     */
    private function getParentClassWithoutHydrating(CodeBase $code_base): Clazz
    {
        if (!$this->parent_type) {
            throw new LogicException("Class $this has no parent");
        }

        $parent_fqsen = FullyQualifiedClassName::fromType($this->parent_type);

        // invoking hasClassWithFQSEN also has the side effect of lazily loading the parent class definition.
        if (!$code_base->hasClassWithFQSEN($parent_fqsen)) {
            throw new RuntimeException("Failed to load parent Class $parent_fqsen of Class $this");
        }

        return $code_base->getClassByFQSENWithoutHydrating(
            $parent_fqsen
        );
    }

    /**
     * @param list<Type> $mixin_types
     */
    public function setMixinTypes(array $mixin_types): void
    {
        $this->mixin_types = $mixin_types;
    }

    /**
     * Is this a subclass of $other?
     *
     * This only checks parent classes.
     * It should not be used for traits or interfaces.
     *
     * This returns false if $this === $other
     *
     * @deprecated This may lead to infinite recursion when analyzing invalid code. asExpandedTypes should be used instead.
     * @suppress PhanUnreferencedPublicMethod
     * @suppress PhanDeprecatedFunction
     */
    public function isSubclassOf(CodeBase $code_base, Clazz $other): bool
    {
        if (!$this->hasParentType()) {
            return false;
        }

        if (!$code_base->hasClassWithFQSEN(
            $this->getParentClassFQSEN()
        )) {
            // Let this emit an issue elsewhere for the
            // parent not existing
            return false;
        }

        // Get the parent class
        $parent = $this->getParentClass($code_base);

        if ($parent === $other) {
            return true;
        }

        return $parent->isSubclassOf($code_base, $other);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return int
     * This class's depth in the class hierarchy
     */
    public function getHierarchyDepth(CodeBase $code_base): int
    {
        if (!$this->hasParentType()) {
            return 0;
        }

        if (!$code_base->hasClassWithFQSEN(
            $this->getParentClassFQSEN()
        )) {
            // Let this emit an issue elsewhere for the
            // parent not existing
            return 0;
        }

        // Get the parent class
        $parent = $this->getParentClass($code_base);

        // Prevent infinite loops
        if ($parent === $this) {
            return 0;
        }

        return (1 + $parent->getHierarchyDepth($code_base));
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return FullyQualifiedClassName
     * The FQSEN of the root class on this class's hierarchy
     */
    public function getHierarchyRootFQSEN(
        CodeBase $code_base
    ): FullyQualifiedClassName {
        $visited = [];
        for ($current = $this; $current->hasParentType(); $current = $parent) {
            $fqsen = $current->getFQSEN();

            if (!$code_base->hasClassWithFQSEN(
                $current->getParentClassFQSEN()
            )) {
                // Let this emit an issue elsewhere for the
                // parent not existing
                return $fqsen;
            }

            // Get the parent class
            $parent = $current->getParentClass($code_base);
            $visited[$fqsen->__toString()] = true;

            // Prevent infinite loops
            if (\array_key_exists($parent->getFQSEN()->__toString(), $visited)) {
                return $fqsen;
            }
        }
        return $current->getFQSEN();
    }

    /**
     * Add the given FQSEN to the list of implemented
     * interfaces for this class.
     *
     * @param FullyQualifiedClassName $fqsen
     */
    public function addInterfaceClassFQSEN(FullyQualifiedClassName $fqsen, int $lineno = 0): void
    {
        $this->interface_fqsen_lineno[count($this->interface_fqsen_list)] = $lineno;
        $this->interface_fqsen_list[] = $fqsen;

        // Add the interface to the union type of this
        // class
        $this->addAdditionalType($fqsen->asType());
    }

    /**
     * Get the list of interfaces implemented by this class
     * @return list<FullyQualifiedClassName>
     */
    public function getInterfaceFQSENList(): array
    {
        return $this->interface_fqsen_list;
    }

    /**
     * Add a property to this class
     *
     * @param CodeBase $code_base
     * A reference to the code base in which the ancestor exists
     *
     * @param Property $property
     * The property to copy onto this class
     *
     * @param Option<Type> $type_option
     * A possibly defined type used to define template
     * parameter types when importing the property
     *
     * @param bool $from_trait
     */
    public function addProperty(
        CodeBase $code_base,
        Property $property,
        Option $type_option,
        bool $from_trait = false
    ): void {
        // Ignore properties we already have
        // TODO: warn about private properties in subclass overriding ancestor private property.
        $property_name = $property->getName();
        if ($this->hasPropertyWithName($code_base, $property_name)) {
            // TODO: Check if trait properties would be inherited first.
            // TODO: Figure out semantics and use $from_trait?
            self::checkPropertyCompatibility(
                $code_base,
                $property,
                $this->getPropertyByName($code_base, $property_name)
            );
            return;
        }

        $property_fqsen = FullyQualifiedPropertyName::make(
            $this->fqsen,
            $property_name
        );

        // TODO: defer template properties until the analysis phase? They might not be parsed or resolved yet.
        $original_property_fqsen = $property->getFQSEN();
        if ($original_property_fqsen !== $property_fqsen) {
            $property = clone($property);
            $property->setFQSEN($property_fqsen);
            if ($property->hasStaticInUnionType()) {
                $property->inheritStaticUnionType($original_property_fqsen->getFullyQualifiedClassName(), $this->fqsen);
            }

            // Private properties of traits are accessible from the class that used that trait
            // (as well as from within the trait itself).
            // Also, for inheritance purposes, treat protected properties the same way.
            if ($from_trait) {
                $property->setDefiningFQSEN($property_fqsen);
            }

            try {
                // If we have a parent type defined, map the property's
                // type through it
                if ($type_option->isDefined()
                    && !$property->hasUnresolvedFutureUnionType()
                    && $property->getUnionType()->hasTemplateType()
                ) {
                    $property->setUnionType(
                        $property->getUnionType()->withTemplateParameterTypeMap(
                            $type_option->get()->getTemplateParameterTypeMap(
                                $code_base
                            )
                        )
                    );
                }
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $code_base,
                    $property->getContext(),
                    $exception->getIssueInstance()
                );
            }
        }

        $code_base->addProperty($property);
    }

    private static function checkPropertyCompatibility(
        CodeBase $code_base,
        Property $inherited_property,
        Property $overriding_property
    ): void {
        $overriding_property->setIsOverride(true);
        if ($inherited_property->isFromPHPDoc() || $inherited_property->isDynamicProperty() ||
            $overriding_property->isFromPHPDoc() || $overriding_property->isDynamicProperty()) {
            return;
        }

        if ($inherited_property->isStrictlyMoreVisibleThan($overriding_property)) {
            if ($inherited_property->isPHPInternal()) {
                if (!$overriding_property->checkHasSuppressIssueAndIncrementCount(Issue::PropertyAccessSignatureMismatchInternal)) {
                    Issue::maybeEmit(
                        $code_base,
                        new ElementContext($overriding_property),
                        Issue::PropertyAccessSignatureMismatchInternal,
                        $overriding_property->getFileRef()->getLineNumberStart(),
                        $overriding_property->asVisibilityAndFQSENString(),
                        $inherited_property->asVisibilityAndFQSENString()
                    );
                }
            } else {
                if (!$overriding_property->checkHasSuppressIssueAndIncrementCount(Issue::PropertyAccessSignatureMismatchInternal)) {
                    Issue::maybeEmit(
                        $code_base,
                        new ElementContext($overriding_property),
                        Issue::PropertyAccessSignatureMismatch,
                        $overriding_property->getFileRef()->getLineNumberStart(),
                        $overriding_property,
                        $inherited_property,
                        $inherited_property->getFileRef()->getFile(),
                        $inherited_property->getFileRef()->getLineNumberStart()
                    );
                }
            }
        }
        // original_property is the one that the class is using.
        // We added $property after that (so it likely in a base class, or a trait's property added after this property was added)
        if ($overriding_property->isStatic() != $inherited_property->isStatic()) {
            Issue::maybeEmit(
                $code_base,
                new ElementContext($overriding_property),
                $overriding_property->isStatic() ? Issue::AccessNonStaticToStaticProperty : Issue::AccessStaticToNonStaticProperty,
                $overriding_property->getFileRef()->getLineNumberStart(),
                $inherited_property->asPropertyFQSENString(),
                $overriding_property->asPropertyFQSENString()
            );
        }
    }

    /**
     * @param array<string,CommentProperty> $magic_property_map mapping from property name to property
     * @param CodeBase $code_base
     * @return bool whether or not we defined the magic property map
     */
    public function setMagicPropertyMap(
        array $magic_property_map,
        CodeBase $code_base
    ): bool {
        if (count($magic_property_map) === 0) {
            return true;  // Vacuously true.
        }
        $class_fqsen = $this->fqsen;
        $context = $this->internal_context;
        foreach ($magic_property_map as $comment_parameter) {
            // $phan_flags can be used to indicate if something is property-read or property-write
            $phan_flags = $comment_parameter->getFlags();
            $property_name = $comment_parameter->getName();
            $property_fqsen = FullyQualifiedPropertyName::make(
                $class_fqsen,
                $property_name
            );
            $original_union_type = $comment_parameter->getUnionType();
            $union_type = $original_union_type->withStaticResolvedInContext($context);
            $property = new Property(
                (clone $context)->withLineNumberStart($comment_parameter->getLine()),
                $property_name,
                $union_type,
                0,
                $property_fqsen,
                UnionType::empty()
            );
            $property->setPHPDocUnionType($union_type);
            if ($original_union_type !== $union_type) {
                $phan_flags |= Flags::HAS_STATIC_UNION_TYPE;
            }
            $property->setPhanFlags($phan_flags | Flags::IS_FROM_PHPDOC);

            $this->addProperty($code_base, $property, None::instance());
        }
        return true;
    }

    /**
     * @param array<string,\Phan\Language\Element\Comment\Method> $magic_method_map mapping from method name to this.
     * @param CodeBase $code_base
     * @return bool whether or not we defined the magic method map
     */
    public function setMagicMethodMap(
        array $magic_method_map,
        CodeBase $code_base
    ): bool {
        if (count($magic_method_map) === 0) {
            return true;  // Vacuously true.
        }
        $class_fqsen = $this->fqsen;
        $context = $this->internal_context;
        $is_pure = $this->isPure();
        foreach ($magic_method_map as $comment_method) {
            // $flags is the same as the flags for `public` and non-internal?
            // Or \ast\flags\MODIFIER_PUBLIC.
            $flags = \ast\flags\MODIFIER_PUBLIC;
            if ($comment_method->isStatic()) {
                $flags |= \ast\flags\MODIFIER_STATIC;
            }
            $method_name = $comment_method->getName();
            if ($this->hasMethodWithName($code_base, $method_name, true)) {
                // No point, and this would hurt inference accuracy.
                continue;
            }
            $method_fqsen = FullyQualifiedMethodName::make(
                $class_fqsen,
                $method_name
            );
            $method_context = (clone $context)->withLineNumberStart($comment_method->getLine());
            $real_parameter_list = \array_map(static function (\Phan\Language\Element\Comment\Parameter $parameter) use ($method_context): Parameter {
                return $parameter->asRealParameter($method_context);
            }, $comment_method->getParameterList());
            $method = new Method(
                $method_context,
                $method_name,
                $comment_method->getUnionType(),
                $flags,
                $method_fqsen,
                $real_parameter_list
            );

            $method->setRealParameterList($real_parameter_list);
            $method->setNumberOfRequiredParameters($comment_method->getNumberOfRequiredParameters());
            $method->setNumberOfOptionalParameters($comment_method->getNumberOfOptionalParameters());
            $method->setIsFromPHPDoc(true);
            if ($is_pure && !$comment_method->isStatic()) {
                $method->setIsPure();
            }

            $this->addMethod($code_base, $method, None::instance());
        }
        return true;
    }

    public function hasPropertyWithName(
        CodeBase $code_base,
        string $name
    ): bool {
        return $code_base->hasPropertyWithFQSEN(
            FullyQualifiedPropertyName::make(
                $this->fqsen,
                $name
            )
        );
    }

    /**
     * Returns the property $name of this class.
     * @see self::hasPropertyWithName()
     */
    public function getPropertyByName(
        CodeBase $code_base,
        string $name
    ): Property {
        return $code_base->getPropertyByFQSEN(
            FullyQualifiedPropertyName::make(
                $this->fqsen,
                $name
            )
        );
    }

    /**
     * @param CodeBase $code_base
     * A reference to the entire code base in which the
     * property exists.
     *
     * @param string $name
     * The name of the property
     *
     * @param Context $context
     * The context of the caller requesting the property
     *
     * @return Property
     * A property with the given name.
     * Callers can check if the property is read-only when writing,
     * or write-only when reading.
     *
     * @throws IssueException
     * An exception may be thrown if the caller does not
     * have access to the given property from the given
     * context
     */
    public function getPropertyByNameInContext(
        CodeBase $code_base,
        string $name,
        Context $context,
        bool $is_static,
        ?Node $node = null,
        bool $is_known_assignment = false
    ): Property {

        // Get the FQSEN of the property we're looking for
        $property_fqsen = FullyQualifiedPropertyName::make(
            $this->fqsen,
            $name
        );

        $property = null;

        // Figure out if we have the property and
        // figure out if the property is accessible.
        $is_property_accessible = false;
        if ($code_base->hasPropertyWithFQSEN($property_fqsen)) {
            $property = $code_base->getPropertyByFQSEN(
                $property_fqsen
            );
            if ($is_static !== $property->isStatic()) {
                if ($is_static) {
                    throw new IssueException(
                        Issue::fromType(Issue::AccessPropertyNonStaticAsStatic)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [$property->asPropertyFQSENString()]
                        )
                    );
                } else {
                    throw new IssueException(
                        Issue::fromType(Issue::AccessPropertyStaticAsNonStatic)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [$property->asPropertyFQSENString()]
                        )
                    );
                }
            }

            $is_property_accessible = $property->isAccessibleFromClass(
                $code_base,
                $context->getClassFQSENOrNull()
            );
        }
        if ($is_static && $property) {
            $access_class_fqsen = $property->getFQSEN()->getFullyQualifiedClassName();
            if ($node instanceof Node && !PostOrderAnalysisVisitor::isStaticNameNode($node->children['class'] ?? null, true)) {
                if ($code_base->hasClassWithFQSEN($access_class_fqsen) && $code_base->getClassByFQSEN($access_class_fqsen)->isTrait()) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::CompatibleAccessPropertyOnTraitDefinition,
                        $context->getLineNumberStart(),
                        $property->getRepresentationForIssue()
                    );
                }
            }
            // If the property is from a trait, the (different) defining FQSEN is the FQSEN of the class using the FQSEN, not the trait.
            $defining_fqsen = $property->getDefiningFQSEN();

            if ($defining_fqsen !== $property_fqsen) {
                if ($code_base->hasPropertyWithFQSEN($defining_fqsen)) {
                    $property = $code_base->getPropertyByFQSEN($defining_fqsen);
                }
            }
        }

        // If the property exists and is accessible, return it
        if ($is_property_accessible) {
            // @phan-suppress-next-line PhanTypeMismatchReturnNullable is_property_accessible ensures that this is non-null
            return $property;
        }

        // Check to see if we can use a __get magic method
        // TODO: What about __set?
        if (!$is_static && $this->hasMethodWithName($code_base, '__get', true)) {
            $method = $this->getMethodByName($code_base, '__get');

            // Make sure the magic method is accessible
            if ($method->isPrivate()) {
                throw new IssueException(
                    Issue::fromType(Issue::AccessPropertyPrivate)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [
                            $property ? $property->asPropertyFQSENString() : $property_fqsen,
                            $method->getContext()->getFile(),
                            $method->getContext()->getLineNumberStart()
                        ]
                    )
                );
            } elseif ($method->isProtected()) {
                if (!self::isAccessToElementOfThis($node)) {
                    throw new IssueException(
                        Issue::fromType(Issue::AccessPropertyProtected)(
                            $context->getFile(),
                            $context->getLineNumberStart(),
                            [
                                $property ? $property->asPropertyFQSENString() : $property_fqsen,
                                $method->getContext()->getFile(),
                                $method->getContext()->getLineNumberStart()
                            ]
                        )
                    );
                }
            }

            $property = new Property(
                $context,
                $name,
                $method->getUnionType(),
                0,
                $property_fqsen,
                UnionType::empty()
            );
            $property->setIsDynamicProperty(true);

            $this->addProperty($code_base, $property, None::instance());

            return $property;
        } elseif ($property) {
            // If we have a property, but it's inaccessible, emit
            // an issue
            if ($property->isPrivate()) {
                throw new IssueException(
                    Issue::fromType(Issue::AccessPropertyPrivate)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [$property->asPropertyFQSENString(), $property->getContext()->getFile(), $property->getContext()->getLineNumberStart() ],
                        $this->suggestGettersOrSetters($code_base, $context, $property, $is_known_assignment)
                    )
                );
            }
            if ($property->isProtected()) {
                if (self::isAccessToElementOfThis($node)) {
                    return $property;
                }
                throw new IssueException(
                    Issue::fromType(Issue::AccessPropertyProtected)(
                        $context->getFile(),
                        $context->getLineNumberStart(),
                        [$property->asPropertyFQSENString(), $property->getContext()->getFile(), $property->getContext()->getLineNumberStart() ],
                        $this->suggestGettersOrSetters($code_base, $context, $property, $is_known_assignment)
                    )
                );
            }
        }

        // Check to see if missing properties are allowed
        // or we're working with a class with dynamic
        // properties such as stdClass.
        if (!$is_static && (Config::getValue('allow_missing_properties')
            || $this->hasDynamicProperties($code_base))
        ) {
            $property = new Property(
                $context,
                $name,
                UnionType::empty(),
                0,
                $property_fqsen,
                UnionType::empty()
            );
            $property->setIsDynamicProperty(true);

            $this->addProperty($code_base, $property, None::instance());

            return $property;
        }

        throw new IssueException(
            Issue::fromType(Issue::UndeclaredProperty)(
                $context->getFile(),
                $context->getLineNumberStart(),
                [$this->fqsen . ($is_static ? '::$' : '->') . $name],
                IssueFixSuggester::suggestSimilarProperty($code_base, $context, $this, $name, $is_static)
            )
        );
    }

    private function suggestGettersOrSetters(CodeBase $code_base, Context $context, Property $property, bool $is_known_assignment): ?Suggestion
    {
        if ($is_known_assignment) {
            return $this->suggestSetters($code_base, $context, $property);
        } else {
            return $this->suggestGetters($code_base, $context, $property);
        }
    }

    private function suggestSetters(CodeBase $code_base, Context $context, Property $property): ?Suggestion
    {
        $getters = $this->getSettersMap($code_base)[$property->getName()] ?? [];
        if (!$getters) {
            return null;
        }
        $suggestions = [];
        // @phan-suppress-next-line PhanAccessMethodInternal
        $class_fqsen_in_current_scope = IssueFixSuggester::maybeGetClassInCurrentScope($context);
        foreach ($getters as $method) {
            if ($method->isAccessibleFromClass($code_base, $class_fqsen_in_current_scope)) {
                $suggestions[] = $method->getRepresentationForIssue();
            }
        }
        if (!$suggestions) {
            return null;
        }
        \sort($suggestions, \SORT_STRING);
        return Suggestion::fromString('Did you mean ' . \implode(' or ', $suggestions));
    }

    /**
     * @return array<string,list<Method>> maps property names to setters for that property
     */
    private function getSettersMap(CodeBase $code_base): array
    {
        return $this->memoize(
            __METHOD__,
            /**
             * @return array<string,list<Method>> maps property names to setters for that property (both instance and static properties)
             */
            function () use ($code_base): array {
                if ($this->isPHPInternal()) {
                    return [];
                }
                $setters = [];
                foreach ($this->getMethodMap($code_base) as $method) {
                    if ($method->isStatic()) {
                        continue;
                    }
                    if ($method->getNumberOfParameters() === 0) {
                        continue;
                    }
                    $node = $method->getNode()->children['stmts'] ?? null;
                    if (!$node instanceof Node) {
                        continue;
                    }
                    $first_parameter = $method->getParameterList()[0] ?? null;
                    if (!$first_parameter) {
                        // func_get
                        continue;
                    }
                    $fetched_property_name = self::computeSetPropertyName($node, $first_parameter->getName());
                    if (is_string($fetched_property_name)) {
                        $setters[$fetched_property_name][] = $method;
                    }
                }
                return $setters;
            }
        );
    }

    private function suggestGetters(CodeBase $code_base, Context $context, Property $property): ?Suggestion
    {
        $getters = $this->getGettersMap($code_base)[$property->getName()] ?? [];
        if (!$getters) {
            return null;
        }
        $suggestions = [];
        // @phan-suppress-next-line PhanAccessMethodInternal
        $class_fqsen_in_current_scope = IssueFixSuggester::maybeGetClassInCurrentScope($context);
        foreach ($getters as $method) {
            if ($method->isAccessibleFromClass($code_base, $class_fqsen_in_current_scope)) {
                $suggestions[] = $method->getRepresentationForIssue();
            }
        }
        if (!$suggestions) {
            return null;
        }
        return Suggestion::fromString('Did you mean ' . \implode(' or ', $suggestions));
    }

    /**
     * @return array<string,list<Method>> maps property names to getters for that property
     */
    public function getGettersMap(CodeBase $code_base): array
    {
        if ($this->isInterface()) {
            return [];
        }
        return $this->memoize(
            __METHOD__,
            /**
             * @return array<string,list<Method>> maps property names to getters for that property (for instance properties)
             */
            function () use ($code_base): array {
                if ($this->isPHPInternal()) {
                    return [];
                }

                // Hydrate the class so that getters from ancestor classes will also be accessible
                $this->hydrate($code_base);
                $getters = [];
                foreach ($this->getMethodMap($code_base) as $method) {
                    if ($method->isStatic()) {
                        // TODO support static getters for static properties
                        continue;
                    }
                    $node = $method->getNode()->children['stmts'] ?? null;
                    if (!$node instanceof Node) {
                        continue;
                    }
                    $fetched_property_name = self::computeFetchedPropertyName($node);
                    if (is_string($fetched_property_name)) {
                        $getters[$fetched_property_name][] = $method;
                    }
                }
                return $getters;
            }
        );
    }

    private static function computeFetchedPropertyName(Node $node): ?string
    {
        if (count($node->children) !== 1) {
            return null;
        }
        $stmt = $node->children[0];
        if (!$stmt instanceof Node || $stmt->kind !== ast\AST_RETURN) {
            return null;
        }
        return self::getPropName($stmt->children['expr']);
    }

    /**
     * Returns the name of the instance property set to the parameter with name $expected_parameter_name, if this is a setter
     */
    private static function computeSetPropertyName(Node $node, string $expected_parameter_name): ?string
    {
        if (count($node->children) !== 1) {
            return null;
        }
        $stmt = $node->children[0];
        if (!$stmt instanceof Node || $stmt->kind !== ast\AST_ASSIGN) {
            return null;
        }
        $prop_name = self::getPropName($stmt->children['var']);
        if (!is_string($prop_name)) {
            return null;
        }
        $expr = $stmt->children['expr'];
        if (!$expr instanceof Node || $expr->kind !== ast\AST_VAR) {
            return null;
        }
        if ($expr->children['name'] === $expected_parameter_name) {
            return $prop_name;
        }
        return null;
    }

    /**
     * @param Node|string|int|float|null $node
     */
    private static function getPropName($node): ?string
    {
        if (!$node instanceof Node) {
            return null;
        }
        if ($node->kind !== ast\AST_PROP) {
            return null;
        }
        $obj = $node->children['expr'];
        if (!($obj instanceof Node && $obj->kind === ast\AST_VAR &&
                $obj->children['name'] === 'this')) {
            return null;
        }
        $prop = $node->children['prop'];
        return is_string($prop) ? $prop : null;
    }

    /**
     * Returns true if this is an access to a property or method of self/static/$this
     *
     * @param ?Node $node
     */
    public static function isAccessToElementOfThis(?Node $node): bool
    {
        if (!($node instanceof Node)) {
            return false;
        }
        $node = $node->children['expr'] ?? $node->children['class'];
        if (!($node instanceof Node)) {
            return false;
        }
        switch ($node->kind) {
            case ast\AST_VAR:
                $name = $node->children['name'];
                return is_string($name) && $name === 'this';
            case ast\AST_CONST:
                $name = $node->children['name']->children['name'] ?? null;
                return is_string($name) && \strcasecmp($name, 'static') === 0;
            default:
                return false;
        }
    }

    /**
     * @return array<string,Property>
     * The list of properties on this class
     */
    public function getPropertyMap(CodeBase $code_base): array
    {
        return $code_base->getPropertyMapByFullyQualifiedClassName(
            $this->fqsen
        );
    }

    /**
     * Inherit a class constant from an ancestor class
     */
    public function inheritConstant(
        CodeBase $code_base,
        ClassConstant $constant
    ): void {
        $constant_fqsen = FullyQualifiedClassConstantName::make(
            $this->fqsen,
            $constant->getName()
        );

        if ($code_base->hasClassConstantWithFQSEN($constant_fqsen)) {
            // If the constant with that name already exists, mark it as an override.
            $overriding_constant = $code_base->getClassConstantByFQSEN($constant_fqsen);
            $overriding_constant->setIsOverride(true);
            $this->checkConstantCompatibility(
                $code_base,
                $constant,
                $code_base->getClassConstantByFQSEN(
                    $constant_fqsen
                )
            );
            return;
        }
        // Warn if inheriting a class constant declared as @abstract without overriding it.
        // Optionally, could check if other interfaces declared class constants with the same value, but low priority.
        if ($constant->isPHPDocAbstract() && !$constant->isPrivate() && !$this->isAbstract() && $this->isClass()) {
            Issue::maybeEmit(
                $code_base,
                $this->getContext(),
                Issue::CommentAbstractOnInheritedConstant,
                $this->getContext()->getLineNumberStart(),
                $this->fqsen,
                $constant->getRealDefiningFQSEN(),
                $constant->getContext()->getFile(),
                $constant->getContext()->getLineNumberStart(),
                '@abstract'
            );
        }
        if ($constant->getClass($code_base)->isTrait()) {
            $constant = $constant->createUseAlias($this);
        } elseif ($constant->getFQSEN() !== $constant_fqsen) {
            // Update the FQSEN if it's not associated with this
            // class yet (always true)
            $constant = clone($constant);
            $constant->setFQSEN($constant_fqsen);
        }

        $code_base->addClassConstant($constant);
    }

    private function checkConstantCompatibility(
        CodeBase $code_base,
        ClassConstant $inherited_constant,
        ClassConstant $overriding_constant
    ): void {
        if ($inherited_constant->isFinal() && $inherited_constant->getRealDefiningFQSEN() !== $overriding_constant->getRealDefiningFQSEN()) {
            $issue_context = $overriding_constant->getFQSEN() === $overriding_constant->getRealDefiningFQSEN() ? $overriding_constant->getContext() : $this->getContext();
            Issue::maybeEmit(
                $code_base,
                $issue_context,
                Issue::AccessOverridesFinalConstant,
                $issue_context->getLineNumberStart(),
                $overriding_constant->getDefiningFQSEN(),
                $inherited_constant->getDefiningFQSEN(),
                $inherited_constant->getContext()->getFile(),
                $inherited_constant->getContext()->getLineNumberStart()
            );
        }
        // Traits don't have constants, thankfully, so the logic is simple.
        // FIXME now they do
        if ($inherited_constant->isStrictlyMoreVisibleThan($overriding_constant)) {
            if ($inherited_constant->isPHPInternal()) {
                if (!$overriding_constant->checkHasSuppressIssueAndIncrementCount(Issue::ConstantAccessSignatureMismatchInternal)) {
                    Issue::maybeEmit(
                        $code_base,
                        $overriding_constant->getContext(),
                        Issue::ConstantAccessSignatureMismatchInternal,
                        $overriding_constant->getFileRef()->getLineNumberStart(),
                        $overriding_constant->asVisibilityAndFQSENString(),
                        $inherited_constant->asVisibilityAndFQSENString()
                    );
                }
            } else {
                if (!$overriding_constant->checkHasSuppressIssueAndIncrementCount(Issue::ConstantAccessSignatureMismatchInternal)) {
                    Issue::maybeEmit(
                        $code_base,
                        $overriding_constant->getContext(),
                        Issue::ConstantAccessSignatureMismatch,
                        $overriding_constant->getFileRef()->getLineNumberStart(),
                        $overriding_constant->asVisibilityAndFQSENString(),
                        $inherited_constant->asVisibilityAndFQSENString(),
                        $inherited_constant->getFileRef()->getFile(),
                        $inherited_constant->getFileRef()->getLineNumberStart()
                    );
                }
            }
        }
    }

    /**
     * Add an enum case (this is a specialization of a class constant)
     */
    public function addEnumCase(CodeBase $code_base, EnumCase $enum_case): void
    {
        $this->addConstant($code_base, $enum_case);

        // TODO need to update minimum enum version to get enum's declared type
        $value = $enum_case->getNodeForValue();
        $name = $enum_case->getName();
        if (($value !== null) !== ($this->enum_type !== null)) {
            if ($this->enum_type) {
                Issue::maybeEmit(
                    $code_base,
                    $enum_case->getContext(),
                    Issue::SyntaxEnumCaseExpectedValue,
                    $enum_case->getContext()->getLineNumberStart(),
                    $name,
                    $this->enum_type
                );
            } else {
                Issue::maybeEmit(
                    $code_base,
                    $enum_case->getContext(),
                    Issue::SyntaxEnumCaseUnexpectedValue,
                    $enum_case->getContext()->getLineNumberStart(),
                    $name
                );
            }
        }
        if ($value !== null) {
            if ($value instanceof Node) {
                // TODO: Phan has a limit on how long of a string it will evaluate.
                // The default max_literal_string_type_length config settings will cause problems for case values longer than 200 bytes, which are hopefully rare
                $value = UnionTypeVisitor::unionTypeFromNode($code_base, $this->getContext(), $value)->asSingleScalarValueOrNullOrSelf();
            }
            if (is_int($value) || is_string($value)) {
                $enum_case->setEnumCaseValue($value);
                $old_name = $this->enum_case_map[$value] ?? null;
                if (is_string($old_name)) {
                    $old_enum_case_fqsen = FullyQualifiedClassConstantName::make($this->fqsen, $old_name);
                    $old_enum_case = $code_base->getClassConstantByFQSEN($old_enum_case_fqsen);
                    Issue::maybeEmit(
                        $code_base,
                        $enum_case->getContext(),
                        Issue::ReusedEnumCaseValue,
                        $enum_case->getContext()->getLineNumberStart(),
                        $name,
                        ASTReverter::toShortString($value),
                        $old_name,
                        $old_enum_case->getFileRef()->getFile(),
                        $old_enum_case->getFileRef()->getLineNumberStart()
                    );
                    return;
                }
                $this->enum_case_map[$value] = $name;
            } else {
                $this->enum_case_map_unknown[] = $name;
            }
            if ($this->enum_type !== null) {
                $actual_case_type = $value instanceof UnionType ? $value : Type::fromObject($value)->asPHPDocUnionType();
                if (!$actual_case_type->canStrictCastToUnionType($code_base, $this->enum_type)) {
                    Issue::maybeEmit(
                        $code_base,
                        $enum_case->getContext(),
                        Issue::TypeUnexpectedEnumCaseType,
                        $enum_case->getContext()->getLineNumberStart(),
                        $name,
                        $actual_case_type,
                        $this->enum_type
                    );
                }
            }
        } else {
            $this->enum_case_list[] = $name;
        }
    }

    /**
     * Add a class constant
     */
    public function addConstant(
        CodeBase $code_base,
        ClassConstant $constant
    ): void {
        $constant_fqsen = FullyQualifiedClassConstantName::make(
            $this->fqsen,
            $constant->getName()
        );

        // Update the FQSEN if it's not associated with this
        // class yet
        if ($constant->getFQSEN() !== $constant_fqsen) {
            $constant = clone($constant);
            $constant->setFQSEN($constant_fqsen);
        }

        $code_base->addClassConstant($constant);
    }

    /**
     * @return bool
     * True if a constant with the given name is defined
     * on this class.
     */
    public function hasConstantWithName(
        CodeBase $code_base,
        string $name
    ): bool {
        if ($code_base->hasClassConstantWithFQSEN(
            FullyQualifiedClassConstantName::make(
                $this->fqsen,
                $name
            )
        )) {
            return true;
        }
        if (!$this->hydrateConstantsIndicatingFirstTime($code_base)) {
            return false;
        }
        return $code_base->hasClassConstantWithFQSEN(
            FullyQualifiedClassConstantName::make(
                $this->fqsen,
                $name
            )
        );
    }

    /**
     * @param CodeBase $code_base
     * A reference to the entire code base in which the
     * property exists.
     *
     * @param string $name
     * The name of the class constant
     *
     * @param Context $context
     * The context of the caller requesting the property
     *
     * @return ClassConstant
     * A constant with the given name
     *
     * @throws IssueException
     * An exception may be thrown if the caller does not
     * have access to the given property from the given
     * context
     */
    public function getConstantByNameInContext(
        CodeBase $code_base,
        string $name,
        Context $context
    ): ClassConstant {

        $constant_fqsen = FullyQualifiedClassConstantName::make(
            $this->fqsen,
            $name
        );

        if (!$code_base->hasClassConstantWithFQSEN($constant_fqsen)) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredConstantOfClass)(
                    $context->getFile(),
                    $context->getLineNumberStart(),
                    [
                        $this->fqsen . '::' . $constant_fqsen
                    ],
                    IssueFixSuggester::suggestSimilarClassConstant($code_base, $context, $constant_fqsen)
                )
            );
        }

        $constant = $code_base->getClassConstantByFQSEN($constant_fqsen);

        if ($constant->isPublic() && !$this->isTrait()) {
            // Most constants are public and declared outside of traits, check that after checking if the declaring class was a trait
            return $constant;
        }

        $accessing_class = $context->getClassFQSENOrNull();
        if ($accessing_class && $constant->isAccessibleFromClass($code_base, $accessing_class)) {
            return $constant;
        }
        // Visibility checks for private/protected class constants:
        if ($this->isTrait()) {
            throw new IssueException(
                Issue::fromType(Issue::AccessClassConstantOfTraitDirectly)(
                    $context->getFile(),
                    $context->getLineNumberStart(),
                    [
                        $constant_fqsen,
                        $this->fqsen,
                        $constant->getContext()->getFile(),
                        $constant->getContext()->getLineNumberStart()
                    ]
                )
            );
        }

        if ($constant->isPrivate()) {
            // This is attempting to access a private constant from outside of the class
            throw new IssueException(
                Issue::fromType(Issue::AccessClassConstantPrivate)(
                    $context->getFile(),
                    $context->getLineNumberStart(),
                    [
                        $constant_fqsen,
                        $constant->getContext()->getFile(),
                        $constant->getContext()->getLineNumberStart()
                    ]
                )
            );
        }

        // We now know that $constant is a protected constant
        throw new IssueException(
            Issue::fromType(Issue::AccessClassConstantProtected)(
                $context->getFile(),
                $context->getLineNumberStart(),
                [
                    $constant_fqsen,
                    $constant->getContext()->getFile(),
                    $constant->getContext()->getLineNumberStart()
                ]
            )
        );
    }

    /**
     * @return array<string,ClassConstant>
     * The constants associated with this class
     */
    public function getConstantMap(CodeBase $code_base): array
    {
        return $code_base->getClassConstantMapByFullyQualifiedClassName(
            $this->fqsen
        );
    }

    /**
     * Add a method to this class
     *
     * @param CodeBase $code_base
     * A reference to the code base in which the ancestor exists
     *
     * @param Method $method
     * The method to copy onto this class
     *
     * @param Option<Type> $type_option
     * A possibly defined type used to define template
     * parameter types when importing the method
     */
    public function addMethod(
        CodeBase $code_base,
        Method $method,
        Option $type_option
    ): void {
        $method_fqsen = FullyQualifiedMethodName::make(
            $this->fqsen,
            $method->getName(),
            $method->getFQSEN()->getAlternateId()
        );

        $is_override = $code_base->hasMethodWithFQSEN($method_fqsen);
        // Don't overwrite overridden methods with
        // parent methods
        if ($is_override) {
            // Note that we're overriding something
            // (but only do this if it's abstract)
            // TODO: Consider all permutations of abstract and real methods on classes, interfaces, and traits.
            $existing_method =
                $code_base->getMethodByFQSEN($method_fqsen);
            if ($existing_method->getRealDefiningFQSEN() === $method->getRealDefiningFQSEN()) {
                return;
            }
            $existing_method_defining_fqsen = $existing_method->getDefiningFQSEN();
            // Note: For private/protected methods, the defining FQSEN is set to the FQSEN of the inheriting class.
            // So, when multiple traits are inherited, they may have identical defining FQSENs, but some may be abstract, and others may be implemented.
            if ($method->getDefiningFQSEN() === $existing_method_defining_fqsen) {
                if ($method->isAbstract() === $existing_method->isAbstract()) {
                    return;
                }
            }

            if ($existing_method->getRealDefiningFQSEN() === $method_fqsen || $method->isAbstract() || !$existing_method->isAbstract() || $existing_method->isNewConstructor()) {
                // TODO: What if both of these are abstract, and those get combined into an abstract class?
                //       Should phan check compatibility of the abstract methods it inherits?
                $existing_method->setIsOverride(true);
                // TODO: What happens for protected methods and traits with getDefiningFQSEN
                self::markMethodAsOverridden($code_base, $method->getDefiningFQSEN(), $method_fqsen);

                // Don't add the method since it was already added
                return;
            } elseif ($method->getRealDefiningFQSEN() === $method_fqsen) {
                $method->setIsOverride(true);
                // TODO: What happens for traits with getDefiningFQSEN
                self::markMethodAsOverridden($code_base, $existing_method->getDefiningFQSEN(), $method_fqsen);
            }
        }

        if ($method->getFQSEN() !== $method_fqsen) {
            $original_method = $method;
            $method = clone($method);
            $method->setFQSEN($method_fqsen);
            // When we inherit it from the ancestor class, it may be an override in the ancestor class,
            // but that doesn't imply it's an override in *this* class.
            $method->setIsOverride($is_override);
            $method->setIsOverriddenByAnother(false);

            // Clone the parameter list, so that modifying the parameters on the first call won't modify the others.
            $method->cloneParameterList();
            $method->ensureClonesReturnType($original_method);

            // If we have a parent type defined, map the method's
            // return type and parameter types through it
            if ($type_option->isDefined()) {
                // Map the method's return type
                if ($method->getUnionType()->hasTemplateType()) {
                    $method->setUnionType(
                        $method->getUnionType()->withTemplateParameterTypeMap(
                            $type_option->get()->getTemplateParameterTypeMap(
                                $code_base
                            )
                        )
                    );
                }

                // Map each method parameter
                $method->setParameterList(
                    \array_map(static function (Parameter $parameter) use ($type_option, $code_base): Parameter {

                        if (!$parameter->getUnionType()->hasTemplateType()) {
                            return $parameter;
                        }

                        $mapped_parameter = clone($parameter);

                        $mapped_parameter->setUnionType(
                            $mapped_parameter->getUnionType()->withTemplateParameterTypeMap(
                                $type_option->get()->getTemplateParameterTypeMap(
                                    $code_base
                                )
                            )
                        );

                        return $mapped_parameter;
                    }, $method->getParameterList())
                );
            }
        }
        if ($method->hasYield()) {
            // There's no phpdoc standard for template types of Generators at the moment.
            $new_type = UnionType::fromFullyQualifiedRealString('\\Generator');
            if (!$new_type->canCastToUnionType($method->getUnionType(), $code_base)) {
                $method->setUnionType($new_type);
            }
        }

        // Methods defined on interfaces are always abstract, but don't have that flag set.
        // NOTE: __construct is special for the following reasons:
        // 1. We automatically add __construct to class-like definitions (Not sure why it's done for interfaces)
        // 2. If it's abstract, then PHP would enforce that signatures are compatible
        if ($this->isInterface() && !$method->isNewConstructor()) {
            $method->setFlags(Flags::bitVectorWithState($method->getFlags(), \ast\flags\MODIFIER_ABSTRACT, true));
        }

        if ($this->hasNoNamedArguments()) {
            // Add another check for trait inheritance, method inheritance, etc.
            $method->setHasNoNamedArguments();
        }

        if ($is_override) {
            $method->setIsOverride(true);
        }

        $code_base->addMethod($method);
    }

    /**
     * @param bool $is_direct_invocation @phan-mandatory-param
     * @return bool
     * True if this class has a method with the given name
     */
    public function hasMethodWithName(
        CodeBase $code_base,
        string $name,
        bool $is_direct_invocation = false
    ): bool {
        // All classes have a constructor even if it hasn't
        // been declared yet
        if (!$is_direct_invocation && ('__construct' === \strtolower($name) && !$this->isTrait())) {
            return true;
        }

        $method_fqsen = FullyQualifiedMethodName::make(
            $this->fqsen,
            $name
        );

        if ($code_base->hasMethodWithFQSEN($method_fqsen)) {
            return true;
        }
        if (!$this->hydrateIndicatingFirstTime($code_base)) {
            return false;
        }
        return $code_base->hasMethodWithFQSEN($method_fqsen);
    }

    /**
     * @return Method
     * The method with the given name
     *
     * @throws CodeBaseException if the method (or a placeholder) could not be found (or created)
     */
    public function getMethodByName(
        CodeBase $code_base,
        string $name
    ): Method {
        $method_fqsen = FullyQualifiedMethodName::make(
            $this->fqsen,
            $name
        );

        if (!$code_base->hasMethodWithFQSEN($method_fqsen)) {
            if ('__construct' === $name) {
                // Create a default constructor if it's requested
                // but doesn't exist yet
                $default_constructor =
                    Method::defaultConstructorForClass(
                        $this,
                        $code_base
                    );

                $this->addMethod($code_base, $default_constructor, $this->getParentTypeOption());

                return $default_constructor;
            }

            throw new CodeBaseException(
                $method_fqsen,
                "Method with name $name does not exist for class {$this->fqsen}."
            );
        }

        return $code_base->getMethodByFQSEN($method_fqsen);
    }

    /**
     * @return array<string,Method>
     * A list of methods on this class
     */
    public function getMethodMap(CodeBase $code_base): array
    {
        return $code_base->getMethodMapByFullyQualifiedClassName(
            $this->fqsen
        );
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__call' method
     */
    public function hasCallMethod(CodeBase $code_base): bool
    {
        return $this->hasMethodWithName($code_base, '__call', true);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__call' method,
     * and (at)phan-forbid-undeclared-magic-methods doesn't exist on this class or ancestors
     */
    public function allowsCallingUndeclaredInstanceMethod(CodeBase $code_base): bool
    {
        return $this->hasCallMethod($code_base) &&
            !$this->getForbidUndeclaredMagicMethods($code_base);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return Method
     * The magic `__call` method
     */
    public function getCallMethod(CodeBase $code_base): Method
    {
        return self::makeCallMethodCloneForCaller($this->getMethodByName($code_base, '__call'));
    }

    private static function makeCallMethodCloneForCaller(Method $method): Method
    {
        $clone = new Method(
            $method->getContext(),
            $method->getName(),
            $method->getUnionType(),
            $method->getFlags(),
            $method->getFQSEN(),
            [
                new VariadicParameter($method->getContext(), 'args', UnionType::empty(), 0)
            ]
        );
        $clone->setPhanFlags($method->getPhanFlags());
        return $clone;
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__callStatic' method
     */
    public function hasCallStaticMethod(CodeBase $code_base): bool
    {
        return $this->hasMethodWithName($code_base, '__callStatic', true);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__callStatic' method,
     * and (at)phan-forbid-undeclared-magic-methods doesn't exist on this class or ancestors.
     */
    public function allowsCallingUndeclaredStaticMethod(CodeBase $code_base): bool
    {
        return $this->hasCallStaticMethod($code_base) &&
            !$this->getForbidUndeclaredMagicMethods($code_base);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return Method
     * The magic `__callStatic` method
     */
    public function getCallStaticMethod(CodeBase $code_base): Method
    {
        return self::makeCallMethodCloneForCaller($this->getMethodByName($code_base, '__callStatic'));
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__get' method
     */
    public function hasGetMethod(CodeBase $code_base): bool
    {
        return $this->hasMethodWithName($code_base, '__get', true);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__set' method
     */
    public function hasSetMethod(CodeBase $code_base): bool
    {
        return $this->hasMethodWithName($code_base, '__set', true);
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return bool
     * True if this class has a magic '__get' or '__set'
     * method
     * @suppress PhanUnreferencedPublicMethod
     */
    public function hasGetOrSetMethod(CodeBase $code_base): bool
    {
        return (
            $this->hasGetMethod($code_base)
            || $this->hasSetMethod($code_base)
        );
    }

    public function addTraitFQSEN(FullyQualifiedClassName $fqsen, int $lineno = 0): void
    {
        $this->trait_fqsen_lineno[count($this->trait_fqsen_list)] = $lineno;
        $this->trait_fqsen_list[] = $fqsen;

        // Add the trait to the union type of this class
        $this->addAdditionalType($fqsen->asType());
    }

    public function addTraitAdaptations(TraitAdaptations $trait_adaptations): void
    {
        $key = \strtolower($trait_adaptations->getTraitFQSEN()->__toString());
        $old_adaptations = $this->trait_adaptations_map[$key] ?? null;
        if ($old_adaptations) {
            $old_adaptations->alias_methods += $trait_adaptations->alias_methods;
            $old_adaptations->hidden_methods += $trait_adaptations->hidden_methods;
        } else {
            $this->trait_adaptations_map[$key] = $trait_adaptations;
        }
    }

    /**
     * @return list<FullyQualifiedClassName>
     * A list of FQSENs for included traits
     */
    public function getTraitFQSENList(): array
    {
        return $this->trait_fqsen_list;
    }

    /**
     * True if this class calls its parent constructor
     */
    public function isParentConstructorCalled(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_PARENT_CONSTRUCTOR_CALLED);
    }

    public function setIsParentConstructorCalled(
        bool $is_parent_constructor_called
    ): void {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_PARENT_CONSTRUCTOR_CALLED,
            $is_parent_constructor_called
        ));
    }

    /**
     * Check if this class or its ancestors forbids undeclared magic properties.
     */
    public function getForbidUndeclaredMagicProperties(CodeBase $code_base): bool
    {
        return $this->hasFlagsRecursive($code_base, Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES);
    }

    /**
     * Set whether undeclared magic properties are forbidden
     * (properties accessed through __get or __set, with no (at)property annotation on parent class)
     * @param bool $forbid_undeclared_dynamic_properties - set to true to forbid.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function setForbidUndeclaredMagicProperties(
        bool $forbid_undeclared_dynamic_properties
    ): void {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::CLASS_FORBID_UNDECLARED_MAGIC_PROPERTIES,
            $forbid_undeclared_dynamic_properties
        ));
    }

    /**
     * Check if this class or its ancestors forbids undeclared magic methods.
     */
    public function getForbidUndeclaredMagicMethods(CodeBase $code_base): bool
    {
        return $this->hasFlagsRecursive($code_base, Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS);
    }

    /**
     * Set whether undeclared magic methods are forbidden
     * (methods accessed through __call or __callStatic, with no (at)method annotation on class)
     * @param bool $forbid_undeclared_magic_methods - set to true to forbid.
     * @suppress PhanUnreferencedPublicMethod
     */
    public function setForbidUndeclaredMagicMethods(
        bool $forbid_undeclared_magic_methods
    ): void {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::CLASS_FORBID_UNDECLARED_MAGIC_METHODS,
            $forbid_undeclared_magic_methods
        ));
    }

    /**
     * Returns whether this class is `(at)immutable` in phpdoc
     *
     * This will warn if instance properties of instances of the class will not change after the object is constructed.
     * - Methods of (at)immutable classes may change external state (e.g. perform I/O, modify other objects)
     */
    public function isImmutable(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_READ_ONLY);
    }

    /**
     * Returns whether this class is `(at)pure`
     *
     * This will warn if instance properties of instances of the class will not change after the object is constructed.
     * - Methods of (at)immutable classes may change external state (e.g. perform I/O, modify other objects)
     */
    public function isPure(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_SIDE_EFFECT_FREE);
    }

    /**
     * @return bool
     * True if this class has dynamic properties. (e.g. stdClass)
     */
    public function hasDynamicProperties(CodeBase $code_base): bool
    {
        foreach($this->attribute_list as $attr) {
            if ((string)$attr === '\AllowDynamicProperties') {
                return true;
            }
        }
        return $this->hasFlagsRecursive($code_base, Flags::CLASS_HAS_DYNAMIC_PROPERTIES);
    }

    private function hasFlagsRecursive(CodeBase $code_base, int $flags): bool
    {
        $current = $this;
        $checked = [];
        while (true) {
            if ($current->getPhanFlagsHasState($flags)) {
                return true;
            }
            if (!$current->hasParentType() || !$code_base->hasClassWithFQSEN($current->getParentClassFQSEN())) {
                return false;
            }
            $checked[$current->getFQSEN()->__toString()] = true;
            $current = $current->getParentClass($code_base);
            if (\array_key_exists($current->getFQSEN()->__toString(), $checked)) {
                // Prevent infinite recursion.
                return false;
            }
        }
    }

    public function setHasDynamicProperties(
        bool $has_dynamic_properties
    ): void {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::CLASS_HAS_DYNAMIC_PROPERTIES,
            $has_dynamic_properties
        ));
    }

    /**
     * @return bool
     * True if this is a final class
     */
    public function isFinal(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_FINAL);
    }

    /**
     * @return bool
     * True if this is an abstract class
     */
    public function isAbstract(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_ABSTRACT);
    }

    /**
     * @return bool
     * True if this is a php 8.1 enum
     */
    public function isEnum(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_ENUM);
    }

    /**
     * @return bool
     * True if this is a php 8.2 readonly class
     */
    public function isReadonly(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_READONLY);
    }

    private const IMMUTABLE_CLASS_SET = [
        '\addressinfo' => true,
        '\closure' => true,
        '\curlhandle' => true,
        '\curlmultihandle' => true,
        '\curlsharehandle' => true,
        '\deflatecontext' => true,
        '\enchantbroker' => true,
        '\enchantdictionary' => true,
        '\fiber' => true,
        '\ftp\connection' => true,
        '\gdfont' => true,
        '\gdimage' => true,
        '\generator' => true,
        '\imap\connection' => true,
        '\inflatecontext' => true,
        '\ldap\connection' => true,
        '\ldap\resultentry' => true,
        '\ldap\result' => true,
        '\opensslasymmetrickey' => true,
        '\opensslcertificatesigningrequest' => true,
        '\opensslcertificate' => true,
        '\pgsql\connection' => true,
        '\pgsql\lob' => true,
        '\pgsql\result' => true,
        '\pspell\config' => true,
        '\pspell\dictionary' => true,
        '\shmop' => true,
        '\socket' => true,
        '\sysvmessagequeue' => true,
        '\sysvsemaphore' => true,
        '\sysvsharedmemory' => true,
        '\weakmap' => true,
        '\weakreference' => true,
        '\xmlparser' => true,
    ];

    private const READONLY_CLASS_FLAGS = ast\flags\CLASS_ENUM | ast\flags\CLASS_READONLY;

    /**
     * @return bool
     * True if this is an object with immutable properties such as an enum or closure
     * (for non-enums, this corresponds loosely to `ZEND_ACC_NO_DYNAMIC_PROPERTIES` in php-src or PECLs,
     * which is typically set on classes with no accessible properties at all)
     */
    public function isImmutableAtRuntime(): bool
    {
        if ($this->getFlags() & self::READONLY_CLASS_FLAGS) {
            return true;
        }
        return array_key_exists(strtolower($this->fqsen->__toString()), self::IMMUTABLE_CLASS_SET);
    }

    /**
     * Check if $prop_name of this class can't be mutated from the given Context $context.
     * @unused-param $context
     */
    public function isPropertyImmutableFromContext(CodeBase $code_base, Context $context, string $prop_name): bool
    {
        if (!$this->hasPropertyWithName($code_base, $prop_name)) {
            // Cannot add undeclared properties to a readonly class or enum or internal readonly class.
            return $this->isImmutableAtRuntime();
        }
        if ($this->isImmutableAtRuntime()) {
            if (!$this->isReadonly()) {
                // This is an enum or an internal class where properties can't be mutated at runtime.
                return true;
            }
        }
        $property = $this->getPropertyByName($code_base, $prop_name);
        if ($property->isReadOnlyReal()) {
            // A `readonly` property can only be mutated from within the declaration's own class scope.
            // (even public properties)
            return $context->getClassFQSENOrNull() !== $property->getDefiningClassFQSEN();
        }
        // Declared Properties of readonly classes can be initialized once, inside or outside of the class.
        //
        // TODO: Make this stricter, e.g. forbid writes if a property would be set in the constructor.
        // - For now, the existing logic for PhanAccessReadOnlyProperty on phpdoc `@readonly` or real `readonly`
        //   will continue to be used.
        //
        return false;
    }

    /**
     * @return bool
     * True if this is an interface
     */
    public function isInterface(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_INTERFACE);
    }

    /**
     * @return bool
     * True if this is a class (i.e. neither a trait nor an interface)
     *
     * This also returns true for classes - enums are a specialization of a class.
     */
    public function isClass(): bool
    {
        return ($this->getFlags() & (ast\flags\CLASS_INTERFACE | ast\flags\CLASS_TRAIT)) === 0;
    }

    /**
     * @return bool
     * True if this class is a trait
     */
    public function isTrait(): bool
    {
        return $this->getFlagsHasState(\ast\flags\CLASS_TRAIT);
    }

    /**
     * Returns a string representing which type of classlike type this is, for issue messages
     */
    public function getClasslikeType(): string
    {
        $flags = $this->getFlags();
        if ($flags & ast\flags\CLASS_TRAIT) {
            return 'trait';
        } elseif ($flags & ast\flags\CLASS_INTERFACE) {
            return 'interface';
        } elseif ($flags & ast\flags\CLASS_ENUM) {
            return 'enum';
        }
        return 'class';
    }

    /**
     * @return bool
     * True if this class is anonymous
     */
    public function isAnonymous(): bool
    {
        return ($this->getFlags() & \ast\flags\CLASS_ANONYMOUS) > 0;
    }

    /**
     * @return FullyQualifiedClassName
     * @suppress PhanTypeMismatchReturn (FQSEN on declaration)
     */
    public function getFQSEN()
    {
        return $this->fqsen;
    }

    /**
     * @return list<FullyQualifiedClassName>
     */
    public function getNonParentAncestorFQSENList(): array
    {
        return \array_merge(
            $this->interface_fqsen_list,
            $this->trait_fqsen_list
        );
    }

    /**
     * @return list<FullyQualifiedClassName>
     */
    public function getAncestorFQSENList(): array
    {
        $ancestor_list = $this->getNonParentAncestorFQSENList();

        if ($this->hasParentType()) {
            $ancestor_list[] = $this->getParentClassFQSEN();
        }

        return $ancestor_list;
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @param list<FullyQualifiedClassName> $fqsen_list
     * A list of class FQSENs to turn into a list of
     * Clazz objects
     *
     * @return list<Clazz>
     */
    private static function getClassListFromFQSENList(
        CodeBase $code_base,
        array $fqsen_list
    ): array {
        $class_list = [];
        foreach ($fqsen_list as $fqsen) {
            if ($code_base->hasClassWithFQSEN($fqsen)) {
                $class_list[] = $code_base->getClassByFQSEN($fqsen);
            }
        }
        return $class_list;
    }

    /**
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     *
     * @return list<Clazz>
     */
    public function getAncestorClassList(CodeBase $code_base): array
    {
        return self::getClassListFromFQSENList(
            $code_base,
            $this->getAncestorFQSENList()
        );
    }

    /**
     * Add class constants from all ancestors (parents, traits, ...)
     * to this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     */
    public function importConstantsFromAncestorClasses(CodeBase $code_base): void
    {
        if (!$this->isFirstExecution(__METHOD__)) {
            return;
        }

        foreach ($this->interface_fqsen_list as $fqsen) {
            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                continue;
            }

            $ancestor = $code_base->getClassByFQSENWithoutHydrating($fqsen);
            $this->importConstantsFromAncestorClass(
                $code_base,
                $ancestor
            );
        }

        foreach ($this->trait_fqsen_list as $fqsen) {
            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                continue;
            }

            $ancestor = $code_base->getClassByFQSENWithoutHydrating($fqsen);
            $this->importConstantsFromAncestorClass(
                $code_base,
                $ancestor
            );
        }

        // Copy information from the parent(s)
        $this->importConstantsFromParentClass($code_base);
    }

    /**
     * Add properties, constants and methods from all
     * ancestors (parents, traits, ...) to this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     */
    public function importAncestorClasses(CodeBase $code_base): void
    {
        if (!$this->isFirstExecution(__METHOD__)) {
            return;
        }
        $this->importConstantsFromAncestorClasses($code_base);

        foreach ($this->interface_fqsen_list as $i => $fqsen) {
            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                continue;
            }

            $ancestor = $code_base->getClassByFQSEN($fqsen);

            if (!$ancestor->isInterface()) {
                $this->emitWrongInheritanceCategoryWarning($code_base, $ancestor, 'Interface', $this->interface_fqsen_lineno[$i] ?? 0);
            }

            $this->importAncestorClass(
                $code_base,
                $ancestor,
                None::instance()
            );
        }

        foreach ($this->trait_fqsen_list as $i => $fqsen) {
            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                continue;
            }

            $ancestor = $code_base->getClassByFQSEN($fqsen);
            if (!$ancestor->isTrait()) {
                $this->emitWrongInheritanceCategoryWarning($code_base, $ancestor, 'Trait', $this->trait_fqsen_lineno[$i] ?? 0);
            }

            $this->importAncestorClass(
                $code_base,
                $ancestor,
                None::instance()
            );
        }

        // Copy information from the parent(s)
        $this->importParentClass($code_base);

        foreach ($this->mixin_types as $type) {
            $this->importMixin($code_base, $type);
        }
    }

    public function getLinenoOfAncestorReference(FullyQualifiedClassName $fqsen): int
    {
        $class_line = $this->getFileRef()->getLineNumberStart();
        foreach ($this->interface_fqsen_list as $i => $interface_fqsen) {
            if ($interface_fqsen === $fqsen) {
                return $this->interface_fqsen_lineno[$i] ?? $class_line;
            }
        }
        foreach ($this->trait_fqsen_list as $i => $trait_fqsen) {
            if ($trait_fqsen === $fqsen) {
                return $this->trait_fqsen_lineno[$i] ?? $class_line;
            }
        }
        return $class_line;
    }

    /**
     * Import all methods of the other type as magic methods.
     */
    private function importMixin(CodeBase $code_base, Type $type): void
    {
        $fqsen = FullyQualifiedClassName::fromType($type);
        if (!$code_base->hasClassWithFQSEN($fqsen) || $fqsen === $this->fqsen) {
            Issue::maybeEmit(
                $code_base,
                $this->internal_context,
                Issue::InvalidMixin,
                $this->internal_context->getLineNumberStart(),
                $type
            );
            return;
        }
        $class = $code_base->getClassByFQSEN($fqsen);
        foreach ($class->getMethodMap($code_base) as $name => $method) {
            if ($method->isMagic() || !$method->isPublic()) {
                // Skip __invoke, and private/protected methods
                continue;
            }
            if ($this->hasMethodWithName($code_base, $name, true)) {
                continue;
            }
            // Treat it as if all of the methods were added, with their real and phpdoc union types.
            $this->addMethod($code_base, $method->asPHPDocMethod($this), None::instance());
        }
        foreach ($class->getPropertyMap($code_base) as $name => $property) {
            if (!$property->isPublic() || $property->isStatic()) {
                // Skip private/protected/static properties. There's no __getStatic().
                continue;
            }
            if ($property->isDynamicProperty()) {
                continue;
            }
            if ($this->hasPropertyWithName($code_base, $name)) {
                continue;
            }
            // Treat it as if all of the properties were added, with their real and phpdoc union types.
            // TODO: Finalize behavior for edge cases such as `static` and templates in union types
            $new_property = clone($property);
            $new_property->setFQSEN(FullyQualifiedPropertyName::make($this->fqsen, $name));
            $new_property->setPhanFlags($new_property->getPhanFlags() | Flags::IS_FROM_PHPDOC);
            $this->addProperty($code_base, $new_property, None::instance());
        }
    }

    /**
     * Add constants from the parent of this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     */
    private function importConstantsFromParentClass(CodeBase $code_base): void
    {
        if (!$this->isFirstExecution(__METHOD__)) {
            return;
        }

        if (!$this->hasParentType()) {
            return;
        }

        if ($this->getParentClassFQSEN() === $this->fqsen) {
            return;
        }

        // Let the parent class finder worry about this
        if (!$code_base->hasClassWithFQSEN(
            $this->getParentClassFQSEN()
        )) {
            return;
        }

        // Get the parent class
        $parent = $this->getParentClassWithoutHydrating($code_base);

        // import constants from that class
        $this->importConstantsFromAncestorClass($code_base, $parent);
    }

    /**
     * Add properties, constants and methods from the
     * parent of this class
     *
     * @param CodeBase $code_base
     * The entire code base from which we'll find ancestor
     * details
     */
    private function importParentClass(CodeBase $code_base): void
    {
        if (!$this->isFirstExecution(__METHOD__)) {
            return;
        }

        if (!$this->hasParentType()) {
            return;
        }

        if ($this->getParentClassFQSEN() === $this->fqsen) {
            return;
        }

        // Let the parent class finder worry about this
        if (!$code_base->hasClassWithFQSEN(
            $this->getParentClassFQSEN()
        )) {
            return;
        }

        // Get the parent class
        $parent = $this->getParentClass($code_base);

        if (!$parent->isClass()) {
            $this->emitWrongInheritanceCategoryWarning($code_base, $parent, 'Class', $this->parent_type_lineno);
        }
        if ($parent->isFinal()) {
            $this->emitExtendsFinalClassWarning($code_base, $parent);
        }

        // Tell the parent to import its own parents first

        // Import elements from the parent
        $this->importAncestorClass(
            $code_base,
            $parent,
            $this->getParentTypeOption()
        );
    }

    private function emitWrongInheritanceCategoryWarning(
        CodeBase $code_base,
        Clazz $ancestor,
        string $expected_inheritance_category,
        int $lineno
    ): void {
        $context = $this->getContext();
        if ($ancestor->isPHPInternal()) {
            if (!$this->checkHasSuppressIssueAndIncrementCount(Issue::AccessWrongInheritanceCategoryInternal)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::AccessWrongInheritanceCategoryInternal,
                    $lineno ?: $context->getLineNumberStart(),
                    (string)$ancestor,
                    $expected_inheritance_category
                );
            }
        } else {
            if (!$this->checkHasSuppressIssueAndIncrementCount(Issue::AccessWrongInheritanceCategory)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::AccessWrongInheritanceCategory,
                    $lineno ?: $context->getLineNumberStart(),
                    (string)$ancestor,
                    $ancestor->getFileRef()->getFile(),
                    $ancestor->getFileRef()->getLineNumberStart(),
                    $expected_inheritance_category
                );
            }
        }
    }

    private function emitExtendsFinalClassWarning(
        CodeBase $code_base,
        Clazz $ancestor
    ): void {
        $context = $this->getContext();
        if ($ancestor->isPHPInternal()) {
            if (!$this->checkHasSuppressIssueAndIncrementCount(Issue::AccessExtendsFinalClassInternal)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::AccessExtendsFinalClassInternal,
                    $this->parent_type_lineno ?: $context->getLineNumberStart(),
                    (string)$ancestor->getFQSEN()
                );
            }
        } else {
            if (!$this->checkHasSuppressIssueAndIncrementCount(Issue::AccessExtendsFinalClass)) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::AccessExtendsFinalClass,
                    $this->parent_type_lineno ?: $context->getLineNumberStart(),
                    (string)$ancestor->getFQSEN(),
                    $ancestor->getFileRef()->getFile(),
                    $ancestor->getFileRef()->getLineNumberStart()
                );
            }
        }
    }

    /**
     * Add constants from the given class to this.
     *
     * @param CodeBase $code_base
     * A reference to the code base in which the ancestor exists
     *
     * @param Clazz $class
     * A class to import from
     */
    public function importConstantsFromAncestorClass(
        CodeBase $code_base,
        Clazz $class
    ): void {
        $key = \strtolower((string)$class->getFQSEN());
        if (!$this->isFirstExecution(
            __METHOD__ . ':' . $key
        )) {
            return;
        }

        $class->addReference($this->getContext());

        // Make sure that the class imports its parents' constants first
        // (And **only** the constants)
        $class->hydrateConstants($code_base);

        // Copy constants
        foreach ($class->getConstantMap($code_base) as $constant) {
            $this->inheritConstant($code_base, $constant);
        }
    }

    /**
     * @param FileRef $file_ref
     * A reference to a location in which this typed structural
     * element is referenced.
     * @override
     */
    public function addReference(FileRef $file_ref): void
    {
        if (Config::get_track_references()) {
            // Currently, we don't need to track references to PHP-internal methods/functions/constants
            // such as PHP_VERSION, strlen(), Closure::bind(), etc.
            // This may change in the future.
            if ($this->isPHPInternal()) {
                return;
            }
            if ($file_ref instanceof Context) {
                if ($file_ref->getClassFQSENOrNull() === $this->fqsen) {
                    // Don't count references declared within MyClass as references to MyClass for dead code detection
                    return;
                }
            }
            $this->reference_list[$file_ref->__toString()] = $file_ref;
        }
    }

    /**
     * Add properties, constants and methods from the given
     * class to this.
     *
     * @param CodeBase $code_base
     * A reference to the code base in which the ancestor exists
     *
     * @param Clazz $class
     * A class to import from
     *
     * @param Option<Type> $type_option
     * A possibly defined ancestor type used to define template
     * parameter types when importing ancestor properties and
     * methods
     */
    public function importAncestorClass(
        CodeBase $code_base,
        Clazz $class,
        Option $type_option
    ): void {
        $class_fqsen = $class->getFQSEN();
        $key = \strtolower($class_fqsen->__toString());
        if (!$this->isFirstExecution(
            __METHOD__ . ':' . $key
        )) {
            return;
        }
        $next_class_fqsen = $class_fqsen->withAlternateId($class_fqsen->getAlternateId() + 1);
        if (!$this->isPHPInternal() && $code_base->hasClassWithFQSEN($next_class_fqsen)) {
            $this->warnAboutAmbiguousInheritance($code_base, $class, $next_class_fqsen);
        }

        if ($attribute_list = $class->getAttributeList()) {
            foreach($attribute_list as $attr) {
                if ((string)$attr === "\AllowDynamicProperties") {
                    $new_attribute_list = $this->getAttributeList();
                    $new_attribute_list[] = $attr;
                    $this->setAttributeList($new_attribute_list);
                    break;
                }
            }
        }
        // Constants should have been imported earlier, but call it again just in case
        $this->importConstantsFromAncestorClass($code_base, $class);

        // Make sure that the class imports its parents first
        // NOTE: We already imported constants from $class in importConstantsFromAncestorClass
        $class->hydrate($code_base);
        $is_trait = $class->isTrait();
        $trait_adaptations = $is_trait ? ($this->trait_adaptations_map[$key] ?? null) : null;

        // Copy properties
        foreach ($class->getPropertyMap($code_base) as $property) {
            if ($property->isPHPDocAbstract() && !$property->isPrivate() &&
                $this->isClass() && !$this->isAbstract() && !$this->hasPropertyWithName($code_base, $property->getName())) {
                Issue::maybeEmit(
                    $code_base,
                    $this->getContext(),
                    Issue::CommentAbstractOnInheritedProperty,
                    $this->getContext()->getLineNumberStart(),
                    $this->fqsen,
                    $property->getRealDefiningFQSEN(),
                    $property->getContext()->getFile(),
                    $property->getContext()->getLineNumberStart(),
                    '@abstract'
                );
            }

            // TODO: check for conflicts in visibility and default values for traits.
            // TODO: Check for ancestor classes with the same private property?
            $this->addProperty(
                $code_base,
                $property,
                $type_option,
                $is_trait
            );
        }

        // Copy methods
        foreach ($class->getMethodMap($code_base) as $method) {
            if (!\is_null($trait_adaptations) && count($trait_adaptations->hidden_methods) > 0) {
                $method_name_key = \strtolower($method->getName());
                if (isset($trait_adaptations->hidden_methods[$method_name_key])) {
                    // TODO: Record that the method was hidden, and check later on that all method that were hidden were actually defined?
                    continue;
                }
            }
            // Workaround: For private methods, copy the method with a new defining class.
            // If you import a trait's private method, it becomes private **to the class which used the trait** in PHP code.
            // (But preserving the defining FQSEN is fine for this)
            if ($is_trait) {
                $method = $this->adaptInheritedMethodFromTrait($method);
            }
            $this->addMethod(
                $code_base,
                $method,
                $type_option
            );
        }

        if (!\is_null($trait_adaptations)) {
            $this->importTraitAdaptations($code_base, $class, $trait_adaptations, $type_option);
        }
    }

    private function adaptInheritedMethodFromTrait(Method $method): Method
    {
        $method_flags = $method->getFlags();
        if (Flags::bitVectorHasState($method_flags, \ast\flags\MODIFIER_PRIVATE)) {
            $method = $method->createUseAlias($this, $method->getName(), \ast\flags\MODIFIER_PRIVATE);
        } elseif (Flags::bitVectorHasState($method_flags, \ast\flags\MODIFIER_PROTECTED)) {
            $method = $method->createUseAlias($this, $method->getName(), \ast\flags\MODIFIER_PROTECTED);
        } else {
            $method = $method->createUseAlias($this, $method->getName(), \ast\flags\MODIFIER_PUBLIC);
        }
        $context = $this->getContext()->withScope($this->internal_scope);
        $method->setUnionType(
            $method->getUnionTypeWithUnmodifiedStatic()->withSelfResolvedInContext($context)
        );
        $method->setRealReturnType(
            $method->getRealReturnType()->withSelfResolvedInContext($context)
        );
        $parameter_list = $method->getParameterList();
        $changed = false;
        foreach ($parameter_list as $i => $parameter) {
            $old_type = $parameter->getNonVariadicUnionType();
            $type = $old_type->withSelfResolvedInContext($context);
            if ($type->hasStaticType()) {
                $type = $type->withType($this->fqsen->asType());
            }
            if ($old_type !== $type) {
                $changed = true;
                $parameter = clone($parameter);
                $parameter->setUnionType($type);
                $parameter_list[$i] = $parameter;
            }
        }
        if ($changed) {
            $method->setParameterList($parameter_list);
        }

        $real_parameter_list = $method->getRealParameterList();
        $changed = false;
        foreach ($real_parameter_list as $i => $parameter) {
            $old_type = $parameter->getNonVariadicUnionType();
            $type = $old_type->withSelfResolvedInContext($context);
            if ($type->hasStaticType()) {
                $type = $type->withType($this->fqsen->asType());
            }
            if ($old_type !== $type) {
                $changed = true;
                $parameter = clone($parameter);
                $parameter->setUnionType($type);
                $real_parameter_list[$i] = $parameter;
            }
        }
        if ($changed) {
            $method->setRealParameterList($parameter_list);
        }

        return $method;
    }

    /**
     * @param CodeBase $code_base
     * @param Clazz $class
     * @param TraitAdaptations $trait_adaptations
     * @param Option<Type> $type_option
     * A possibly defined ancestor type used to define template
     * parameter types when importing ancestor properties and
     * methods
     */
    private function importTraitAdaptations(
        CodeBase $code_base,
        Clazz $class,
        TraitAdaptations $trait_adaptations,
        Option $type_option
    ): void {
        foreach ($trait_adaptations->alias_methods ?? [] as $alias_method_name => $original_trait_alias_source) {
            $source_method_name = $original_trait_alias_source->getSourceMethodName();
            if ($class->hasMethodWithName($code_base, $source_method_name, true)) {
                $source_method = $class->getMethodByName($code_base, $source_method_name);
            } else {
                $source_method = null;
            }
            if (!$source_method || $source_method->isFromPHPDoc()) {
                Issue::maybeEmit(
                    $code_base,
                    $this->getContext(),
                    Issue::UndeclaredAliasedMethodOfTrait,
                    $original_trait_alias_source->getAliasLineno(),  // TODO: Track line number in TraitAdaptation
                    \sprintf('%s::%s', (string)$this->fqsen, $alias_method_name),
                    \sprintf('%s::%s', (string)$class->getFQSEN(), $source_method_name),
                    $class->getName()
                );
                continue;
            }
            $alias_method = $source_method->createUseAlias(
                $this,
                $alias_method_name,
                $original_trait_alias_source->getAliasVisibilityFlags()
            );
            $this->addMethod($code_base, $alias_method, $type_option);
        }
    }

    private function warnAboutAmbiguousInheritance(
        CodeBase $code_base,
        Clazz $inherited_class,
        FullyQualifiedClassName $alternate_class_fqsen
    ): void {
        $alternate_class = $code_base->getClassByFQSEN($alternate_class_fqsen);
        if ($inherited_class->isTrait()) {
            $issue_type = Issue::RedefinedUsedTrait;
        } elseif ($inherited_class->isInterface()) {
            $issue_type = Issue::RedefinedInheritedInterface;
        } else {
            $issue_type = Issue::RedefinedExtendedClass;
        }
        if ($this->checkHasSuppressIssueAndIncrementCount($issue_type)) {
            return;
        }
        $first_context = $inherited_class->getContext();
        $second_context = $alternate_class->getContext();

        Issue::maybeEmit(
            $code_base,
            $this->getContext(),
            $issue_type,
            $this->getContext()->getLineNumberStart(),
            $this->fqsen,
            $inherited_class->__toString(),
            $first_context->getFile(),
            $first_context->getLineNumberStart(),
            $second_context->getFile(),
            $second_context->getLineNumberStart()
        );
    }

    /**
     * @return int
     * The number of references to this typed structural element
     */
    public function getReferenceCount(
        CodeBase $code_base
    ): int {
        $count = parent::getReferenceCount($code_base);

        /**
         * A function that maps a list of elements to the
         * total reference count for all elements
         * @param array<string,AddressableElement> $list
         */
        $list_count = function (array $list): int {
            return \array_reduce($list, function (
                int $count,
                ClassElement $element
            ): int {
                foreach ($element->reference_list as $reference) {
                    if ($reference instanceof Context && $reference->getClassFQSENOrNull() === $this->fqsen) {
                        continue;
                    }
                    $count++;
                }
                return $count;
            }, 0);
        };

        // Sum up counts for all dependent elements
        $count += $list_count($this->getPropertyMap($code_base));
        $count += $list_count($this->getMethodMap($code_base));
        $count += $list_count($this->getConstantMap($code_base));

        return $count;
    }

    /**
     * @return bool
     * True if this class contains generic types
     */
    public function isGeneric(): bool
    {
        return $this->getInternalScope()->hasAnyTemplateType();
    }

    /**
     * @return array<string,TemplateType>
     * The set of all template types parameterizing this generic
     * class
     */
    public function getTemplateTypeMap(): array
    {
        return $this->getInternalScope()->getTemplateTypeMap();
    }

    /**
     * @return string
     * A string describing this class
     */
    public function __toString(): string
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
        } elseif ($this->isEnum()) {
            // Remove the 'final' qualifier.
            $string = 'Enum ';
        } else {
            $string .= 'Class ';
        }

        $string .= (string)$this->fqsen->getCanonicalFQSEN();

        return $string;
    }

    private function toStubSignature(CodeBase $code_base): string
    {
        $string = '';

        if ($this->isFinal()) {
            $string .= 'final ';
        }

        if ($this->isAbstract() && !$this->isInterface()) {
            $string .= 'abstract ';
        }

        if ($this->isInterface()) {
            $string .= 'interface ';
        } elseif ($this->isTrait()) {
            $string .= 'trait ';
        } elseif ($this->isEnum()) {
            // Remove the 'final' qualifier
            $string = 'enum ';
        } else {
            $string .= 'class ';
        }

        $string .= $this->fqsen->getName();

        $extend_types = [];
        $implements_types = [];
        $parent_implements_types = [];

        if ($this->parent_type) {
            $extend_types[] = FullyQualifiedClassName::fromType($this->parent_type);
            $parent_class = $this->getParentClass($code_base);
            $parent_implements_types = $parent_class->interface_fqsen_list;
        }

        if (count($this->interface_fqsen_list) > 0) {
            if ($this->isInterface()) {
                $extend_types = \array_merge($extend_types, $this->interface_fqsen_list);
            } else {
                $implements_types = $this->interface_fqsen_list;
                if (count($parent_implements_types) > 0) {
                    $implements_types = \array_diff($implements_types, $parent_implements_types);
                }
            }
        }
        if (count($extend_types) > 0) {
            $string .= ' extends ' . \implode(', ', $extend_types);
        }
        if (count($implements_types) > 0) {
            $string .= ' implements ' . \implode(', ', $implements_types);
        }
        return $string;
    }

    public function getMarkupDescription(): string
    {
        $fqsen = $this->fqsen;
        $string = '';
        $namespace = \ltrim($fqsen->getNamespace(), '\\');
        if ($namespace !== '') {
            // Render the namespace one line above the class
            $string .= "namespace $namespace;\n";
        }

        if ($this->isFinal()) {
            $string .= 'final ';
        }

        if ($this->isAbstract() && !$this->isInterface()) {
            $string .= 'abstract ';
        }

        if ($this->isInterface()) {
            $string .= 'interface ';
        } elseif ($this->isTrait()) {
            $string .= 'trait ';
        } else {
            $string .= 'class ';
        }

        if ($this->isAnonymous()) {
            $string .= 'anonymous_class';
        } else {
            $string .= $fqsen->getName();
        }
        return $string;
    }


    /**
     * @suppress PhanUnreferencedPublicMethod (toStubInfo is used by callers for more flexibility)
     */
    public function toStub(CodeBase $code_base): string
    {
        [$namespace, $string] = $this->toStubInfo($code_base);
        $namespace_text = $namespace === '' ? '' : "$namespace ";
        $string = \sprintf("namespace %s{\n%s}\n", $namespace_text, $string);
        return $string;
    }

    /** @return array{0:string,1:string} [string $namespace, string $text] */
    public function toStubInfo(CodeBase $code_base): array
    {
        $signature = $this->toStubSignature($code_base);

        $stub = '';
        if (self::shouldAddDescriptionsToStubs()) {
            $description = (string)MarkupDescription::extractDescriptionFromDocComment($this);
            $stub .= MarkupDescription::convertStringToDocComment($description);
        }
        $stub .= $signature;

        $stub .= " {";

        $constant_map = $this->getConstantMap($code_base);
        if (count($constant_map) > 0) {
            $stub .= "\n\n    // constants\n";
            $stub .= \implode("\n", \array_map(static function (ClassConstant $constant): string {
                return $constant->toStub();
            }, $constant_map));
        }

        $property_map = $this->getPropertyMap($code_base);
        if (count($property_map) > 0) {
            $stub .= "\n\n    // properties\n";

            $stub .= \implode("\n", \array_map(static function (Property $property): string {
                return $property->toStub();
            }, $property_map));
        }
        $reflection_class = new \ReflectionClass((string)$this->fqsen);
        $method_map = \array_filter($this->getMethodMap($code_base), static function (Method $method) use ($reflection_class): bool {
            if ($method->getFQSEN()->isAlternate()) {
                return false;
            }
            $reflection_method = $reflection_class->getMethod($method->getName());
            return $reflection_method->class === $reflection_class->name;
        });
        if (count($method_map) > 0) {
            $stub .= "\n\n    // methods\n";

            $is_interface = $this->isInterface();
            $stub .= \implode("\n", \array_map(static function (Method $method) use ($is_interface): string {
                return $method->toStub($is_interface);
            }, $method_map));
        }

        $stub .= "\n}\n\n";
        $namespace = \ltrim($this->fqsen->getNamespace(), '\\');
        return [$namespace, $stub];
    }

    protected function hydrateConstantsOnce(CodeBase $code_base): void
    {
        foreach ($this->getAncestorFQSENList() as $fqsen) {
            if ($code_base->hasClassWithFQSEN($fqsen)) {
                $code_base->getClassByFQSENWithoutHydrating(
                    $fqsen
                )->hydrateConstants($code_base);
            }
        }

        // Create the 'class' constant
        $class_constant_value = \ltrim($this->fqsen->__toString(), '\\');
        $class_constant = new ClassConstant(
            $this->getContext(),
            'class',
            LiteralStringType::instanceForValue(
                $class_constant_value,
                false
            )->asRealUnionType(),
            0,
            FullyQualifiedClassConstantName::make(
                $this->fqsen,
                'class'
            )
        );
        $class_constant->setNodeForValue($class_constant_value);
        $this->addConstant($code_base, $class_constant);

        // Add variable '$this' to the scope
        $this->getInternalScope()->addVariable(
            new Variable(
                $this->getContext(),
                'this',
                StaticType::instance(false)->asRealUnionType(),
                0
            )
        );

        // Fetch the constants declared within the class, to check if they have override annotations later.
        $original_declared_class_constants = $this->getConstantMap($code_base);

        // Load parent methods, properties, constants
        $this->importConstantsFromAncestorClasses($code_base);

        self::analyzeClassConstantOverrides($code_base, $original_declared_class_constants);
    }

    /**
     * This method must be called before analysis
     * begins.
     */
    protected function hydrateOnce(CodeBase $code_base): void
    {
        // Ensure that we hydrate constants before hydrating properties and methods
        $this->hydrateConstants($code_base);

        foreach ($this->getAncestorFQSENList() as $fqsen) {
            if ($code_base->hasClassWithFQSEN($fqsen)) {
                $code_base->getClassByFQSENWithoutHydrating(
                    $fqsen
                )->hydrate($code_base);
            }
        }

        if ($this->isEnum()) {
            $this->addEnumProperties($code_base);
        }

        // Fetch the properties declared within the class, to check if they have override annotations later.
        $original_declared_properties = $this->getPropertyMap($code_base);

        $this->importAncestorClasses($code_base);

        self::analyzePropertyOverrides($code_base, $original_declared_properties);

        // Make sure there are no abstract methods on non-abstract classes
        AbstractMethodAnalyzer::analyzeAbstractMethodsAreImplemented(
            $code_base,
            $this
        );
    }

    /**
     * @param ClassConstant[] $original_declared_class_constants
     */
    private static function analyzeClassConstantOverrides(CodeBase $code_base, array $original_declared_class_constants): void
    {
        foreach ($original_declared_class_constants as $constant) {
            if ($constant->isOverrideIntended() && !$constant->isOverride()) {
                if ($constant->checkHasSuppressIssueAndIncrementCount(Issue::CommentOverrideOnNonOverrideConstant)) {
                    continue;
                }
                $context = $constant->getContext();
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::CommentOverrideOnNonOverrideConstant,
                    $context->getLineNumberStart(),
                    (string)$constant->getFQSEN()
                );
            }
        }
    }

    /**
     * @param array<string, Property> $original_declared_properties
     */
    private static function analyzePropertyOverrides(CodeBase $code_base, array $original_declared_properties): void
    {
        foreach ($original_declared_properties as $property) {
            if ($property->isOverrideIntended() && !$property->isOverride()) {
                if ($property->checkHasSuppressIssueAndIncrementCount(Issue::CommentOverrideOnNonOverrideProperty)) {
                    continue;
                }
                $context = $property->getContext();
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::CommentOverrideOnNonOverrideProperty,
                    $context->getLineNumberStart(),
                    (string)$property->getFQSEN()
                );
            }
        }
    }

    /**
     * This method should be called after hydration
     * @throws RecursionDepthException for deep class hierarchies
     */
    final public function analyze(CodeBase $code_base): void
    {
        if ($this->isPHPInternal()) {
            return;
        }

        // Make sure the parent classes exist
        ClassInheritanceAnalyzer::analyzeClassInheritance(
            $code_base,
            $this
        );

        DuplicateClassAnalyzer::analyzeDuplicateClass(
            $code_base,
            $this
        );

        ParentConstructorCalledAnalyzer::analyzeParentConstructorCalled(
            $code_base,
            $this
        );

        PropertyTypesAnalyzer::analyzePropertyTypes(
            $code_base,
            $this
        );

        ClassConstantTypesAnalyzer::analyzeClassConstantTypes(
            $code_base,
            $this
        );

        // Analyze this class to make sure that we don't have conflicting
        // types between similar inherited methods.
        CompositionAnalyzer::analyzeComposition(
            $code_base,
            $this
        );

        $this->analyzeInheritedMethods($code_base);

        $this->analyzeAndUpdateEnum($code_base);

        // Let any configured plugins analyze the class
        ConfigPluginSet::instance()->analyzeClass(
            $code_base,
            $this
        );
    }

    private function analyzeInheritedMethods(CodeBase $code_base): void
    {
        if (!$this->isClass()) {
            return;
        }
        if ($this->isAbstract()) {
            return;
        }

        foreach ($this->getMethodMap($code_base) as $method) {
            if ($method->getRealDefiningFQSEN() === $method->getFQSEN()) {
                continue;
            }
            if ($method->isPHPDocAbstract() && !$method->isPrivate()) {
                Issue::maybeEmit(
                    $code_base,
                    $this->getContext(),
                    Issue::CommentAbstractOnInheritedMethod,
                    $this->getContext()->getLineNumberStart(),
                    $this->fqsen,
                    $method->getRealDefiningFQSEN(),
                    $method->getContext()->getFile(),
                    $method->getContext()->getLineNumberStart(),
                    '@abstract'
                );
            }
        }
    }

    public function setDidFinishParsing(bool $did_finish_parsing): void
    {
        $this->did_finish_parsing = $did_finish_parsing;
    }

    /**
     * @var bool have the class constants been hydrated
     * (must be done before hydrating properties and methods to avoid recursive dependencies)
     */
    protected $are_constants_hydrated;

    /**
     * This method must be called before analysis
     * begins. It hydrates constants, but not properties/methods.
     */
    protected function hydrateConstants(CodeBase $code_base): void
    {
        if (!$this->did_finish_parsing) {
            return;
        }
        if ($this->are_constants_hydrated) {  // Same as isFirstExecution(), inlined due to being called frequently.
            return;
        }
        if (!$code_base->shouldHydrateRequestedElements()) {
            return;
        }
        $this->are_constants_hydrated = true;

        $this->hydrateConstantsOnce($code_base);
    }

    /**
     * This method must be called before analysis begins.
     * This is identical to hydrateConstants(),
     * but returns true only if this is the first time the element was hydrated.
     * (i.e. true if there may be newly added constants)
     */
    public function hydrateConstantsIndicatingFirstTime(CodeBase $code_base): bool
    {
        if (!$this->did_finish_parsing) {  // Is **this** class fully parsed
            return false;
        }
        if ($this->are_constants_hydrated) {  // Same as isFirstExecution(), inlined due to being called frequently.
            return false;
        }
        if (!$code_base->shouldHydrateRequestedElements()) {
            return false;
        }
        $this->are_constants_hydrated = true;

        $this->hydrateConstantsOnce($code_base);
        return true;
    }

    /**
     * This method must be called before analysis
     * begins.
     * @override
     */
    public function hydrate(CodeBase $code_base): void
    {
        if (!$this->did_finish_parsing) {
            return;
        }
        if ($this->is_hydrated) {  // Same as isFirstExecution(), inlined due to being called frequently.
            return;
        }
        if (!$code_base->shouldHydrateRequestedElements()) {
            return;
        }
        $this->is_hydrated = true;

        $this->hydrateOnce($code_base);
    }

    /**
     * This method must be called before analysis begins.
     * This is identical to hydrate(), but returns true only if this is the first time the element was hydrated.
     * @internal
     */
    public function hydrateIndicatingFirstTime(CodeBase $code_base): bool
    {
        if (!$this->did_finish_parsing) {
            return false;
        }
        if ($this->is_hydrated) {  // Same as isFirstExecution(), inlined due to being called frequently.
            return false;
        }
        if (!$code_base->shouldHydrateRequestedElements()) {
            return false;
        }
        $this->is_hydrated = true;

        $this->hydrateOnce($code_base);
        return true;
    }

    /**
     * Used by daemon mode to restore an element to the state it had before parsing.
     */
    public function createRestoreCallback(): Closure
    {
        // NOTE: Properties, Methods, and closures are restored separately.
        $original_this = clone($this);
        $original_union_type = $this->getUnionType();

        return function () use ($original_union_type, $original_this): void {
            // @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach this is intentionally iterating over private properties of the clone.
            foreach ($original_this as $key => $value) {
                $this->{$key} = $value;
            }
            $this->setUnionType($original_union_type);
            $this->memoizeFlushAll();
        };
    }

    public function addAdditionalType(Type $type): void
    {
        $this->additional_union_types = ($this->additional_union_types ?? UnionType::empty())->withType($type);
    }

    public function getAdditionalTypes(): ?UnionType
    {
        return $this->additional_union_types;
    }

    /**
     * @param array<string,UnionType> $template_parameter_type_map
     */
    public function resolveParentTemplateType(array $template_parameter_type_map): UnionType
    {
        if (\count($template_parameter_type_map) === 0) {
            return UnionType::empty();
        }
        if ($this->parent_type === null) {
            return UnionType::empty();
        }
        if (!$this->parent_type->hasTemplateParameterTypes()) {
            return UnionType::empty();
        }
        $parent_template_parameter_type_list = $this->parent_type->getTemplateParameterTypeList();
        $changed = false;
        foreach ($parent_template_parameter_type_list as $i => $template_type) {
            $new_template_type = $template_type->withTemplateParameterTypeMap($template_parameter_type_map);
            if ($template_type === $new_template_type) {
                continue;
            }
            $parent_template_parameter_type_list[$i] = $new_template_type;
            $changed = true;
        }
        if (!$changed) {
            return UnionType::empty();
        }
        return Type::fromType($this->parent_type, $parent_template_parameter_type_list)->asPHPDocUnionType();
    }

    /**
     * @return array<string,Property>
     */
    public function getPropertyMapExcludingDynamicAndMagicProperties(CodeBase $code_base): array
    {
        return $this->memoize(__METHOD__, /** @return array<string,Property> */ function () use ($code_base): array {
            // TODO: This won't work if a class declares both a real property and a magic property of the same name.
            // Low priority because that is uncommon
            return \array_filter(
                $this->getPropertyMap($code_base),
                static function (Property $property): bool {
                    return !$property->isDynamicOrFromPHPDoc();
                }
            );
        });
    }

    public const CAN_ITERATE_STATUS_NO_PROPERTIES = 0;
    public const CAN_ITERATE_STATUS_NO_ACCESSIBLE_PROPERTIES = 1;
    public const CAN_ITERATE_STATUS_HAS_ACCESSIBLE_PROPERTIES = 2;

    /**
     * Returns an enum value (self::CAN_ITERATE_STATUS_*) indicating whether
     * analyzed code iterating over an instance of this class has potential bugs.
     * (and what type of bug it would be)
     */
    public function checkCanIterateFromContext(
        CodeBase $code_base,
        Context $context
    ): int {
        $accessing_class = $context->getClassFQSENOrNull();
        return $this->memoize(
            'can_iterate:' . (string)$accessing_class,
            function () use ($accessing_class, $code_base): int {
                $properties = $this->getPropertyMapExcludingDynamicAndMagicProperties($code_base);
                foreach ($properties as $property) {
                    if ($property->isAccessibleFromClass($code_base, $accessing_class)) {
                        return self::CAN_ITERATE_STATUS_HAS_ACCESSIBLE_PROPERTIES;
                    }
                }
                if (count($properties) > 0) {
                    return self::CAN_ITERATE_STATUS_NO_ACCESSIBLE_PROPERTIES;
                }
                return self::CAN_ITERATE_STATUS_NO_PROPERTIES;
            }
        );
    }

    /**
     * @return list<Closure(list<Node|string|int|float|UnionType>, Context):UnionType>
     */
    public function getGenericConstructorBuilder(CodeBase $code_base): array
    {
        return $this->memoize(
            'template_type_resolvers',
            /**
             * @return list<Closure(list<Node|string|int|float|UnionType>, Context):UnionType>
             */
            function () use ($code_base): array {
                // Get the constructor so that we can figure out what
                // template types we're going to be mapping
                $constructor_method =
                    $this->getMethodByName($code_base, '__construct');

                $template_type_resolvers = [];
                foreach ($this->getTemplateTypeMap() as $template_type) {
                    $template_type_resolver = $constructor_method->getTemplateTypeExtractorClosure(
                        $code_base,
                        $template_type
                    );
                    if (!$template_type_resolver) {
                        // PhanTemplateTypeNotDeclaredInFunctionParams can be suppressed both on the class and on __construct()
                        if (!$this->checkHasSuppressIssueAndIncrementCount(Issue::TemplateTypeNotDeclaredInFunctionParams)) {
                            $warn_context = $constructor_method->getDefiningClassFQSEN() === $this->fqsen ? $constructor_method->getContext() : $this->getContext();

                            Issue::maybeEmit(
                                $code_base,
                                $warn_context,
                                Issue::GenericConstructorTypes,
                                $warn_context->getLineNumberStart(),
                                $template_type,
                                $this->fqsen
                            );
                        }
                        /** @param list<\ast\Node|mixed> $unused_arg_list */
                        $template_type_resolver = static function (array $unused_arg_list, Context $unused_context): UnionType {
                            return MixedType::instance(false)->asPHPDocUnionType();
                        };
                    }
                    $template_type_resolvers[] = $template_type_resolver;
                }
                return $template_type_resolvers;
            }
        );
    }

    /**
     * Given the FQSEN of an ancestor class and an element definition,
     * return the overridden element's definition or null if this didn't override anything.
     *
     * TODO: Handle renamed elements from traits.
     *
     * @return ?ClassElement if non-null, this is of the same type as $element
     */
    public static function getAncestorElement(CodeBase $code_base, FullyQualifiedClassName $ancestor_fqsen, ClassElement $element): ?ClassElement
    {
        if (!$code_base->hasClassWithFQSEN($ancestor_fqsen)) {
            return null;
        }
        $ancestor_class = $code_base->getClassByFQSEN($ancestor_fqsen);
        $name = $element->getName();
        if ($element instanceof Method) {
            if (!$ancestor_class->hasMethodWithName($code_base, $name, true)) {
                return null;
            }
            return $ancestor_class->getMethodByName($code_base, $name);
        } elseif ($element instanceof ClassConstant) {
            if (!$ancestor_class->hasConstantWithName($code_base, $name)) {
                return null;
            }
            $constant_fqsen = FullyQualifiedClassConstantName::make(
                $ancestor_fqsen,
                $name
            );
            return $code_base->getClassConstantByFQSEN($constant_fqsen);
        } elseif ($element instanceof Property) {
            if (!$ancestor_class->hasPropertyWithName($code_base, $name)) {
                return null;
            }
            return $ancestor_class->getPropertyByName($code_base, $name);
        }
        return null;
    }

    private static function markMethodAsOverridden(
        CodeBase $code_base,
        FullyQualifiedMethodName $method_fqsen,
        FullyQualifiedMethodName $overriding_method_fqsen
    ): void {
        if (!$code_base->hasMethodWithFQSEN($method_fqsen)) {
            return;
        }
        $method = $code_base->getMethodByFQSEN($method_fqsen);
        /*
        if ($method->getName() === 'getBaz') {
            echo "Marking $method_fqsen as overridden by $overriding_method_fqsen\n";
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        }
        */
        $method->setIsOverriddenByAnother(true, $overriding_method_fqsen);
    }

    /**
     * Sets the declaration id of the node containing this user-defined class
     */
    public function setDeclId(int $id): void
    {
        $this->decl_id = $id;
    }

    /**
     * Gets the declaration id of the node containing this user-defined class.
     * Returns 0 for internal classes.
     */
    public function getDeclId(): int
    {
        return $this->decl_id;
    }

    /**
     * Returns a context with the internal scope of this class (including suppression info)
     * Equivalent to $clazz->getContext()->withScope($clazz->getInternalScope())
     *
     * TODO: Use this for more issues about class and class-like declarations.
     */
    public function getInternalContext(): Context
    {
        return $this->internal_context;
    }

    /**
     * Returns true if this is a class that can be used as an attribute
     * @suppress PhanUnreferencedPublicMethod
     */
    public function isAttribute(): bool
    {
        return $this->memoize(__METHOD__, function (): bool {
            // TODO: Fix for internal classes
            if (!$this->isClass()) {
                return false;
            }
            foreach ($this->attribute_list as $attribute) {
                $fqsen = $attribute->getFQSEN();
                if ($fqsen->getName() === 'Attribute' && $fqsen->getNamespace() === '\\') {
                    return true;
                }
            }
            if ($this->isPHPInternal()) {
                // Check this after checking if it's an internal stub
                $fqsen_string = $this->fqsen->__toString();
                if ($fqsen_string === '\Attribute') {
                    // Handle the most common case in php 8
                    return true;
                }
                if (\PHP_MAJOR_VERSION >= 8 && \class_exists($fqsen_string)) {
                    // @phan-suppress-next-line PhanUndeclaredMethod this is added in php 8.0
                    foreach ((new ReflectionClass($fqsen_string))->getAttributes() as $php_attribute) {
                        // @phan-suppress-next-line PhanPluginUnknownObjectMethodCall unable to infer type as a result of target_php_version being 7.2
                        if ($php_attribute->getName() === 'Attribute') {
                            return true;
                        }
                    }
                }
            }
            return false;
        });
    }

    /**
     * Returns the attribute flags associated with this attribute declaration.
     *
     * E.g. for `X` in `#[Attribute(Attribute::TARGET_FUNCTION)] class X {}`, returns `Attribute::TARGET_FUNCTION`.
     *
     * TODO: Support internal attributes using Reflection
     */
    public function getAttributeFlags(CodeBase $code_base): int
    {
        return $this->memoize(__METHOD__, function () use ($code_base): int {
            foreach ($this->attribute_list as $attribute) {
                $fqsen = $attribute->getFQSEN();
                if ($fqsen->getName() === 'Attribute' && $fqsen->getNamespace() === '\\') {
                    $args = $attribute->getArgs()->children ?? [];
                    if ($args) {
                        $value = UnionTypeVisitor::unionTypeFromNode($code_base, $this->getContext(), \reset($args))->asSingleScalarValueOrNullOrSelf();
                        if (\is_int($value)) {
                            return $value;
                        }
                    }
                    break;
                }
            }
            if ($this->isPHPInternal()) {
                // Check this after checking if it's an internal stub
                $fqsen_string = $this->fqsen->__toString();
                if ($fqsen_string === '\Attribute') {
                    // Handle the most common case in php 8
                    return Attribute::TARGET_CLASS;
                }
                if (\PHP_MAJOR_VERSION >= 8 && \class_exists($fqsen_string)) {
                    // @phan-suppress-next-line PhanUndeclaredMethod this is added in php 8.0
                    foreach ((new ReflectionClass($fqsen_string))->getAttributes() as $php_attribute) {
                        // @phan-suppress-next-line PhanPluginUnknownObjectMethodCall unable to infer type as a result of target_php_version being 7.2
                        if ($php_attribute->getName() === 'Attribute') {
                            // @phan-suppress-next-line PhanPluginUnknownObjectMethodCall unable to infer type as a result of target_php_version being 7.2
                            $arg = $php_attribute->getArguments()[0] ?? null;
                            if (is_int($arg)) {
                                return $arg;
                            }
                        }
                    }
                }
            }
            return Attribute::TARGET_ALL;
        });
    }

    private function analyzeAndUpdateEnum(CodeBase $code_base): void
    {
        if (!$this->isEnum()) {
            return;
        }

        // Inherit properties from traits and magic properties from interfaces
        $this->hydrate($code_base);

        $fqsen = $this->fqsen;
        foreach ($this->getPropertyMap($code_base) as $property) {
            if (($property->getPhanFlags() & Flags::IS_ENUM_PROPERTY)) {
                continue;
            }
            $fqsen_string = $property->getDefiningClassFQSEN()->__toString();
            if (in_array(strtolower($fqsen_string), ['\backedenum', '\unitenum'], true)) {
                continue;
            }
            $context = $property->getRealDefiningFQSEN()->getFullyQualifiedClassName() === $fqsen ? $property->getContext() : $this->getContext();
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::EnumCannotHaveProperties,
                $context->getLineNumberStart(),
                $this->fqsen,
                $property->getName(),
                $property->getContext()->getFile(),
                $property->getContext()->getLineNumberStart()
            );
        }
        $cases = array_merge(array_values($this->enum_case_map), $this->enum_case_map_unknown, $this->enum_case_list);
        if (!$cases) {
            foreach ($this->getMethodMap($code_base) as $method) {
                $method_name_lc = strtolower($method->getName());
                $context = $method->getRealDefiningFQSEN()->getFullyQualifiedClassName() === $fqsen ? $method->getContext() : $this->getContext();
                if (array_key_exists($method_name_lc, FullyQualifiedMethodName::MAGIC_METHOD_NAME_SET)) {
                    if (!in_array($method_name_lc, ['__call', '__callstatic', '__invoke'], true)) {
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            Issue::EnumForbiddenMagicMethod,
                            $context->getLineNumberStart(),
                            $this->fqsen,
                            $method->getName() . '()',
                            $method->getContext()->getFile(),
                            $method->getContext()->getLineNumberStart()
                        );
                    }
                }
                if ($method->isStatic()) {
                    continue;
                }
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::InstanceMethodWithNoEnumCases,
                    $context->getLineNumberStart(),
                    $this->fqsen,
                    $method->getName() . '()',
                    $method->getContext()->getFile(),
                    $method->getContext()->getLineNumberStart()
                );
            }
        }
    }

    public function setEnumType(UnionType $type): void
    {
        $this->enum_type = $type;
    }

    /**
     * Get the enum's type declaration. Currently, this can be the union type for int, string, or be null (cases have no value)
     * @suppress PhanUnreferencedPublicMethod provided for plugins
     */
    public function getEnumType(): ?UnionType
    {
        return $this->enum_type;
    }

    private function addEnumProperties(CodeBase $code_base): void
    {
        $string_type = StringType::instance(false)->asRealUnionType();
        $name_property = new Property(
            $this->getContext(),
            'name',
            $string_type,
            ast\flags\MODIFIER_PUBLIC,
            FullyQualifiedPropertyName::make($this->fqsen, 'name'),
            $string_type
        );
        $name_property->setPhanFlags(Flags::IS_READ_ONLY | Flags::IS_ENUM_PROPERTY);
        $this->addProperty($code_base, $name_property, None::instance());
        $value_type = $this->enum_type;
        if ($value_type instanceof UnionType) {
            $value_property = new Property(
                $this->getContext(),
                'value',
                $value_type,
                ast\flags\MODIFIER_PUBLIC,
                FullyQualifiedPropertyName::make($this->fqsen, 'name'),
                $value_type
            );
            $value_property->setPhanFlags(Flags::IS_READ_ONLY | Flags::IS_ENUM_PROPERTY);
            $this->addProperty($code_base, $value_property, None::instance());
        }
    }

    /**
     * Returns true if this class was marked as (at)no-named-arguments.
     * All methods added to this class will also be treated as having no-named-arguments.
     */
    public function hasNoNamedArguments(): bool
    {
        return $this->getPhanFlagsHasState(Flags::NO_NAMED_ARGUMENTS);
    }
}
