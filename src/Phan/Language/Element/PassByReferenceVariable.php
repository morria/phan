<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use Phan\Analysis\AssignmentVisitor;
use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\UnionType;

/**
 * This class wraps a parameter and an element and proxies
 * calls to the element but keeps the name of the parameter
 * allowing us to pass an element into a method as a
 * pass-by-reference parameter so that its value can be
 * updated when re-analyzing the method.
 */
class PassByReferenceVariable extends Variable
{
    use ElementProxyTrait;

    /**
     * @var Variable the parameter which accepts references
     */
    private $parameter;

    /**
     * @var ?CodeBase set to a CodeBase if $element is a Property, for type checking
     */
    private $code_base;

    /**
     * @var ?Context set to a Context if $element is a Property, for emitting issues
     */
    private $context_of_created_reference;

    /**
     * @param TypedElement|UnaddressableTypedElement $element
     * NOTE: Non-null $code_base will be mandatory for $element Property in a future Phan release
     * NOTE: Non-null $context will be mandatory for $element Property in a future Phan release
     */
    public function __construct(
        Variable $parameter,
        $element,
        ?CodeBase $code_base = null,
        ?Context $context_of_created_reference = null
    ) {
        $this->parameter = $parameter;
        $this->element = $element;
        $this->type = $element->getNonVariadicUnionType();
        if ($element instanceof Property) {
            $this->code_base = $code_base;
            $this->context_of_created_reference = $context_of_created_reference;
            if ($code_base && $context_of_created_reference) {
                self::checkCanMutateProperty($code_base, $context_of_created_reference, $element);
            }
        }
    }

    /**
     * This detects uses of `pass_by_ref($enum_case->immutableProperty)` or `$a = &$enum_case->name;`
     *
     * TODO: This approach in general does not detect getting array offsets of immutable properties?
     */
    private static function checkCanMutateProperty(CodeBase $code_base, Context $context, Property $property): void
    {
        $class_fqsen = $property->getRealDefiningFQSEN()->getFullyQualifiedClassName();
        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
            return;
        }
        $class = $code_base->getClassByFQSEN($class_fqsen);
        if (!$class->isImmutableAtRuntime()) {
            return;
        }
        Issue::maybeEmit(
            $code_base,
            $context,
            Issue::TypeModifyImmutableObjectProperty,
            $context->getLineNumberStart(),
            $class->getClasslikeType(),
            $class_fqsen,
            $property->getName(),
            $property->getContext()->getFile(),
            $property->getContext()->getLineNumberStart()
        );
    }

    public function getName(): string
    {
        return $this->parameter->getName();
    }

    /**
     * Variables can't be variadic. This is the same as getUnionType for
     * variables, but not necessarily for subclasses. Method will return
     * the element type (such as `DateTime`) for variadic parameters.
     */
    public function getNonVariadicUnionType(): UnionType
    {
        return $this->type;
    }

    public function getUnionType(): UnionType
    {
        return $this->type;
    }

    /**
     * @suppress PhanAccessMethodInternal
     */
    public function setUnionType(UnionType $type): void
    {
        $this->type = $type;
        if ($this->element instanceof Property && $this->code_base) {
            // TODO: Also warn about incompatible types
            AssignmentVisitor::addTypesToPropertyStandalone(
                $this->code_base,
                $this->element->getContext(),
                $this->element,
                $type
            );
            return;
        }
        $new_element_type = UnionType::merge([
            $type->eraseRealTypeSetRecursively(),
            $this->element->getUnionType()
        ]);
        $this->element->setUnionType($new_element_type);
    }

    /**
     * Returns the context where this reference was created.
     * This is currently only available for references to properties.
     */
    public function getContextOfCreatedReference(): ?Context
    {
        return $this->context_of_created_reference;
    }

    /**
     * Is the variable/property this is referring to part of a PHP module?
     * (only possible for properties)
     * @suppress PhanUnreferencedPublicMethod this may be called by plugins or Phan in the future
     */
    public function isPHPInternal(): bool
    {
        return $this->element instanceof AddressableElement && $this->element->isPHPInternal();
    }

    /**
     * Get the parameter that this PassByReferenceVariable was passed into.
     * @suppress PhanUnreferencedPublicMethod this may be called by plugins
     */
    public function getParameter(): Variable
    {
        return $this->parameter;
    }
}
