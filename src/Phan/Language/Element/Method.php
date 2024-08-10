<?php

declare(strict_types=1);

namespace Phan\Language\Element;

// Note: This file uses both class Phan\Language\Element\Flags and namespace ast\flags
use ast;
use ast\Node;
use Phan\Analysis\Analyzable;
use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\ElementContext;
use Phan\Language\FileRef;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\Scope\ClassScope;
use Phan\Language\Scope\FunctionLikeScope;
use Phan\Language\Scope\GlobalScope;
use Phan\Language\UnionType;
use Phan\Library\Set;
use Phan\Memoize;

/**
 * Phan's representation of a class's method.
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 * @method FullyQualifiedMethodName getDefiningFQSEN() @phan-suppress-current-line PhanParamSignaturePHPDocMismatchReturnType
 * @property FullyQualifiedMethodName $fqsen
 */
class Method extends ClassElement implements FunctionInterface
{
    use Analyzable;
    use Memoize;
    use FunctionTrait;
    use ClosedScopeElement;

    /**
     * @var ?FullyQualifiedMethodName If this was originally defined in a trait, this is the trait's defining fqsen.
     * This is tracked separately from getDefiningFQSEN() in order to not break access checks on protected/private methods.
     * Used for dead code detection.
     */
    private $real_defining_fqsen;

    /**
     * @var ?Method The defining method, if this method was inherited.
     *              This is only set if this is needed to recursively infer method types - do not use this.
     *
     *              This may become out of date in language server mode.
     */
    private $defining_method_for_type_fetching;

    /**
     * @var Set<FullyQualifiedMethodName> FQSENs of methods potentially overriding this one.
     */
    private $method_overrides;

    /**
     * @var UnionType|null
     */
    private $inherited_throws_union_type;

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
     * @param FullyQualifiedMethodName $fqsen
     * A fully qualified name for the element
     *
     * @param ?list<Parameter> $parameter_list
     * A list of parameters to set on this method
     */
    public function __construct(
        Context $context,
        string $name,
        UnionType $type,
        int $flags,
        FullyQualifiedMethodName $fqsen,
        $parameter_list
    ) {
        $internal_scope = new FunctionLikeScope(
            $context->getScope(),
            $fqsen
        );
        $context = $context->withScope($internal_scope);
        if ($type->hasTemplateType()) {
            $this->recordHasTemplateType();
        }
        parent::__construct(
            $context,
            FullyQualifiedMethodName::canonicalName($name),
            $type,
            $flags,
            $fqsen
        );

        // Presume that this is the original definition
        // of this method, and let it be overwritten
        // if it isn't.
        $this->setDefiningFQSEN($fqsen);
        $this->real_defining_fqsen = $fqsen;

        // Record the FQSEN of this method (With the current Clazz),
        // to prevent recursing from a method into itself in non-quick mode.
        $this->setInternalScope($internal_scope);

        if ($parameter_list !== null) {
            $this->setParameterList($parameter_list);
        }
        $this->checkForTemplateTypes();

        $this->method_overrides = new Set();
    }

    public function __clone()
    {
        $this->setInternalScope(clone($this->getInternalScope()));
    }

    /**
     * Sets hasTemplateType to true if it finds any template types in the parameters or methods
     */
    public function checkForTemplateTypes(): void
    {
        if ($this->getUnionType()->hasTemplateTypeRecursive()) {
            $this->recordHasTemplateType();
            return;
        }
        foreach ($this->parameter_list as $parameter) {
            if ($parameter->getUnionType()->hasTemplateTypeRecursive()) {
                $this->recordHasTemplateType();
                return;
            }
        }
    }

    /**
     * @return bool
     * True if this is a magic phpdoc method (declared via (at)method on class declaration phpdoc)
     */
    public function isFromPHPDoc(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_FROM_PHPDOC);
    }

    /**
     * Sets whether this is a magic phpdoc method (declared via (at)method on class declaration phpdoc)
     * @param bool $from_phpdoc - True if this is a magic phpdoc method
     */
    public function setIsFromPHPDoc(bool $from_phpdoc): void
    {
        $this->setPhanFlags(
            Flags::bitVectorWithState(
                $this->getPhanFlags(),
                Flags::IS_FROM_PHPDOC,
                $from_phpdoc
            )
        );
    }

    /**
     * Returns true if this element is overridden by at least one other element
     */
    public function isOverriddenByAnother(): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_OVERRIDDEN_BY_ANOTHER);
    }

    /**
     * Returns a (possibly incomplete) list of methods overriding this one.
     * @param CodeBase $code_base
     * @return list<Method>
     * @suppress PhanUnreferencedPublicMethod May be called by plugins (#4502)
     */
    public function getPossibleOverrides(CodeBase $code_base): array
    {
        $ret = [];
        foreach ($this->method_overrides as $fqsen) {
            // Consistency checks, mostly for language server mode.
            /** @var FullyQualifiedMethodName $fqsen */
            if (!$code_base->hasMethodWithFQSEN($fqsen)) {
                continue;
            }
            $method = $code_base->getMethodByFQSEN($fqsen);
            if ($method->getDefiningFQSEN() !== $fqsen) {
                continue;
            }
            $subclassExpanded = $method->getClassFQSEN()->asType()->asExpandedTypes($code_base);
            $thisClassType = $this->getClassFQSEN()->asType();
            if ($subclassExpanded->hasType($thisClassType)) {
                $ret[] = $method;
            }
        }
        return $ret;
    }

    /**
     * Sets whether this method is overridden by another method
     *
     * @param bool $is_overridden_by_another
     * True if this method is overridden by another method
     * @param FullyQualifiedMethodName|null $fqsen
     * FQSEN of the overriding method, if available and $is_overridden_by_another is true.
     */
    public function setIsOverriddenByAnother(bool $is_overridden_by_another, ?FullyQualifiedMethodName $fqsen = null): void
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_OVERRIDDEN_BY_ANOTHER,
            $is_overridden_by_another
        ));
        if ($is_overridden_by_another && $fqsen) {
            $this->method_overrides->attach($fqsen);
        }
    }

    /**
     * @return bool
     * True if this is an abstract method
     */
    public function isAbstract(): bool
    {
        return $this->getFlagsHasState(ast\flags\MODIFIER_ABSTRACT);
    }

    /**
     * @return bool
     * True if this is a final method
     */
    public function isFinal(): bool
    {
        return $this->getFlagsHasState(ast\flags\MODIFIER_FINAL);
    }

    /**
     * @return bool
     * True if this should be analyzed as if it is a final method
     */
    public function isEffectivelyFinal(): bool
    {
        if ($this->isFinal()) {
            return true;
        }
        return Config::getValue('assume_no_external_class_overrides')
            && !$this->isOverriddenByAnother()
            && !$this->isAbstract();
    }

    /**
     * @return bool
     * True if this method returns a reference
     */
    public function returnsRef(): bool
    {
        return $this->getFlagsHasState(ast\flags\FUNC_RETURNS_REF);
    }

    /**
     * Returns true if this is a magic method
     * (Names are all normalized in FullyQualifiedMethodName::make())
     */
    public function isMagic(): bool
    {
        return \array_key_exists($this->name, FullyQualifiedMethodName::MAGIC_METHOD_NAME_SET);
    }

    /**
     * Returns the return union type of this magic method, if known.
     */
    public function getUnionTypeOfMagicIfKnown(): ?UnionType
    {
        $type_string = FullyQualifiedMethodName::MAGIC_METHOD_TYPE_MAP[$this->name] ?? null;
        return $type_string ? UnionType::fromFullyQualifiedPHPDocString($type_string) : null;
    }

    /**
     * Returns true if this is a magic method which should have return type of void
     * (Names are all normalized in FullyQualifiedMethodName::make())
     */
    public function isMagicAndVoid(): bool
    {
        return \array_key_exists($this->name, FullyQualifiedMethodName::MAGIC_VOID_METHOD_NAME_SET);
    }

    /**
     * Returns true if this is the `__construct` method
     * (Does not return true for php4 constructors)
     */
    public function isNewConstructor(): bool
    {
        // NOTE: This is normalized to lowercase by canonicalName
        return $this->name === '__construct';
    }

    /**
     * Returns true if this is a placeholder for the `__construct` method that was never declared
     */
    public function isFakeConstructor(): bool
    {
        return ($this->getPhanFlags() & Flags::IS_FAKE_CONSTRUCTOR) !== 0;
    }

    /**
     * Returns true if this is the magic `__call` method
     */
    public function isMagicCall(): bool
    {
        // NOTE: This is normalized to lowercase by canonicalName
        return $this->name === '__call';
    }

    /**
     * Returns true if this is the magic `__callStatic` method
     */
    public function isMagicCallStatic(): bool
    {
        // NOTE: This is normalized to lowercase by canonicalName
        return $this->name === '__callStatic';
    }

    /**
     * @return Method
     * A default constructor for the given class
     */
    public static function defaultConstructorForClass(
        Clazz $clazz,
        CodeBase $code_base
    ): Method {
        if ($clazz->getFQSEN()->getNamespace() === '\\' && $clazz->hasMethodWithName($code_base, $clazz->getName(), true)) {
            $old_style_constructor = $clazz->getMethodByName($code_base, $clazz->getName());
        } else {
            $old_style_constructor = null;
        }

        $method_fqsen = FullyQualifiedMethodName::make(
            $clazz->getFQSEN(),
            '__construct'
        );

        $method = new Method(
            $old_style_constructor ? $old_style_constructor->getContext() : $clazz->getContext(),
            '__construct',
            $clazz->getUnionType(),
            0,
            $method_fqsen,
            $old_style_constructor ? $old_style_constructor->getParameterList() : null
        );

        if ($old_style_constructor) {
            $method->setRealParameterList($old_style_constructor->getRealParameterList());
            $method->setNumberOfRequiredParameters($old_style_constructor->getNumberOfRequiredParameters());
            $method->setNumberOfOptionalParameters($old_style_constructor->getNumberOfOptionalParameters());
            $method->setRealReturnType($old_style_constructor->getRealReturnType());
            $method->setUnionType($old_style_constructor->getUnionType());
        }

        $method->setPhanFlags($method->getPhanFlags() | Flags::IS_FAKE_CONSTRUCTOR);

        return $method;
    }

    /**
     * Convert this method to a method from phpdoc.
     * Used when importing methods with mixins.
     *
     * Precondition: This is not a magic method
     */
    public function asPHPDocMethod(Clazz $class): Method
    {
        $method = clone($this);
        $method->setFlags($method->getFlags() & (
            ast\flags\MODIFIER_PUBLIC |
            ast\flags\MODIFIER_PROTECTED |
            ast\flags\MODIFIER_PRIVATE |
            ast\flags\MODIFIER_STATIC
        ));  // clear MODIFIER_ABSTRACT and other flags
        $method->setPhanFlags(
            ($method->getPhanFlags() | Flags::IS_FROM_PHPDOC) & ~(Flags::IS_OVERRIDDEN_BY_ANOTHER | Flags::IS_OVERRIDE)
        );

        // TODO: Handle template. Possibly support @mixin Foo<stdClass, bool> and resolve methods.
        // $method->setPhanFlags(Flags::IS_FROM_PHPDOC);
        $method->clearNode();
        // Set the new FQSEN but keep the defining FQSEN
        $method->setFQSEN(FullyQualifiedMethodName::make($class->getFQSEN(), $method->getName()));
        return $method;
    }

    /**
     * @param Clazz $clazz - The class to treat as the defining class of the alias. (i.e. the inheriting class)
     * @param string $alias_method_name - The alias method name.
     * @param int $new_visibility_flags (0 if unchanged)
     * @return Method
     *
     * An alias from a trait use, which is treated as though it was defined in $clazz
     * E.g. if you import a trait's method as private/protected, it becomes private/protected **to the class which used the trait**
     *
     * The resulting alias doesn't inherit the Node of the method body, so aliases won't have a redundant analysis step.
     */
    public function createUseAlias(
        Clazz $clazz,
        string $alias_method_name,
        int $new_visibility_flags
    ): Method {

        $method_fqsen = FullyQualifiedMethodName::make(
            $clazz->getFQSEN(),
            $alias_method_name
        );

        $method = new Method(
            $this->getContext(),
            $alias_method_name,
            $this->getUnionTypeWithUnmodifiedStatic(),
            $this->getFlags(),
            $method_fqsen,
            $this->getParameterList()
        );
        $method->setPhanFlags($this->getPhanFlags() & ~(Flags::IS_OVERRIDE | Flags::IS_OVERRIDDEN_BY_ANOTHER));
        switch ($new_visibility_flags) {
            case ast\flags\MODIFIER_PUBLIC:
            case ast\flags\MODIFIER_PROTECTED:
            case ast\flags\MODIFIER_PRIVATE:
                // Replace the visibility with the new visibility.
                $method->setFlags(Flags::bitVectorWithState(
                    Flags::bitVectorWithState(
                        $method->getFlags(),
                        ast\flags\MODIFIER_PUBLIC | ast\flags\MODIFIER_PROTECTED | ast\flags\MODIFIER_PRIVATE,
                        false
                    ),
                    $new_visibility_flags,
                    true
                ));
                break;
            default:
                break;
        }

        $defining_fqsen = $this->getDefiningFQSEN();
        if ($method->isPublic()) {
            $method->setDefiningFQSEN($defining_fqsen);
        }
        $method->real_defining_fqsen = $defining_fqsen;

        $method->setRealParameterList($this->getRealParameterList());
        $method->setRealReturnType($this->getRealReturnType());
        $method->setNumberOfRequiredParameters($this->getNumberOfRequiredParameters());
        $method->setNumberOfOptionalParameters($this->getNumberOfOptionalParameters());
        // Copy the comment so that features such as templates will work
        $method->comment = $this->comment;

        return $method;
    }

    /**
     * These magic **instance** methods don't inherit pureness from the class in question
     */
    private const NON_PURE_METHOD_NAME_SET = [
        '__clone'       => true,
        '__construct'   => true,
        '__destruct'    => true,
        '__set'         => true,  // This could exist in a pure class to throw exceptions or do nothing
        '__unserialize' => true,
        '__unset'       => true,  // This could exist in a pure class to throw exceptions or do nothing
        '__wakeup'      => true,
    ];

    /**
     * @param Context $context
     * The context in which the node appears
     *
     * @param CodeBase $code_base
     *
     * @param Node $node
     * An AST node representing a method
     *
     * @param ?Clazz $class @phan-mandatory-param
     * This will be mandatory in a future Phan release.
     *
     * @return Method
     * A Method representing the AST node in the
     * given context
     */
    public static function fromNode(
        Context $context,
        CodeBase $code_base,
        Node $node,
        FullyQualifiedMethodName $fqsen,
        ?Clazz $class = null
    ): Method {

        // Create the skeleton method object from what
        // we know so far
        $method = new Method(
            $context,
            (string)$node->children['name'],
            UnionType::empty(),
            $node->flags,
            $fqsen,
            null
        );
        $doc_comment = $node->children['docComment'] ?? '';
        $method->setDocComment($doc_comment);

        // Parse the comment above the method to get
        // extra meta information about the method.
        $comment = Comment::fromStringInContext(
            $doc_comment,
            $code_base,
            $context,
            $node->lineno,
            Comment::ON_METHOD
        );

        // Defer adding params to the local scope for user functions. (FunctionTrait::addParamsToScopeOfFunctionOrMethod)
        // See PostOrderAnalysisVisitor->analyzeCallToMethod
        $method->setComment($comment);

        // Record @internal, @deprecated, and @phan-pure
        $method->setPhanFlags($method->getPhanFlags() | $comment->getPhanFlagsForMethod());

        $element_context = new ElementContext($method);
        // @var list<Parameter>
        // The list of parameters specified on the
        // method
        $parameter_list = Parameter::listFromNode(
            $element_context,
            $code_base,
            $node->children['params']
        );
        $method->setParameterList($parameter_list);
        $method->setAttributeList(Attribute::fromNodeForAttributeList(
            $code_base,
            $element_context,
            $node->children['attributes'] ?? null
        ));
        foreach ($parameter_list as $parameter) {
            if ($parameter->getUnionType()->hasTemplateTypeRecursive()) {
                $method->recordHasTemplateType();
                break;
            }
        }

        // Add each parameter to the scope of the function
        // NOTE: it's important to clone this,
        // because we don't want any assignments to modify the original Parameter
        foreach ($parameter_list as $parameter) {
            $method->getInternalScope()->addVariable(
                $parameter->cloneAsNonVariadic()
            );
        }
        foreach ($comment->getTemplateTypeList() as $template_type) {
            $method->getInternalScope()->addTemplateType($template_type);
        }

        if (!$method->isPHPInternal()) {
            // If the method is Analyzable, set the node so that
            // we can come back to it whenever we like and
            // rescan it
            $method->setNode($node);
        }

        // Keep an copy of the original parameter list, to check for fatal errors later on.
        $method->setRealParameterList($parameter_list);

        $required_parameter_count = self::computeNumberOfRequiredParametersForList($parameter_list);
        $method->setNumberOfRequiredParameters($required_parameter_count);

        $method->setNumberOfOptionalParameters(\count($parameter_list) - $required_parameter_count);

        // Check to see if the comment specifies that the
        // method is deprecated
        $method->setIsDeprecated($comment->isDeprecated());

        // Set whether or not the method is internal to
        // the namespace.
        $method->setIsNSInternal($comment->isNSInternal());

        // Set whether or not the comment indicates that the method is intended
        // to override another method.
        $method->setIsOverrideIntended($comment->isOverrideIntended());
        $method->setSuppressIssueSet($comment->getSuppressIssueSet());

        $class = $class ?? $context->getClassInScope($code_base);

        if ($method->isMagicCall() || $method->isMagicCallStatic()) {
            $method->setNumberOfOptionalParameters(FunctionInterface::INFINITE_PARAMETERS);
            $method->setNumberOfRequiredParameters(0);
        }

        if ($class->isPure() && !$method->isStatic() &&
                !\array_key_exists(\strtolower($method->getName()), self::NON_PURE_METHOD_NAME_SET)) {
            $method->setIsPure();
        }
        if ($class->hasNoNamedArguments()) {
            $method->setHasNoNamedArguments();
        }

        $is_trait = $class->isTrait();
        // Add the syntax-level return type to the method's union type
        // if it exists
        $return_type = $node->children['returnType'];
        if ($return_type instanceof Node) {
            // TODO: Avoid resolving this, but only in traits
            $return_union_type = (new UnionTypeVisitor($code_base, $context))->fromTypeInSignature(
                $return_type
            );
            $method->setUnionType($method->getUnionType()->withUnionType($return_union_type)->withRealTypeSet($return_union_type->getTypeSet()));
            // TODO: Replace 'self' with the real class when not in a trait
        } else {
            $return_union_type = UnionType::empty();
        }
        // TODO: Deprecate the setRealReturnType API due to properly tracking real return type?
        $method->setRealReturnType($return_union_type);

        // If available, add in the doc-block annotated return type
        // for the method.
        if ($comment->hasReturnUnionType()) {
            $comment_return_union_type = $comment->getReturnType();
            if (!$is_trait) {
                $comment_return_union_type = $comment_return_union_type->withSelfResolvedInContext($context);
            }
            $signature_union_type = $method->getUnionType();

            $new_type = self::computeNewTypeForComment($code_base, $context, $signature_union_type, $comment_return_union_type);
            $method->setUnionType($new_type);
            $method->setPHPDocReturnType($comment_return_union_type);
        }
        $element_context->freeElementReference();
        // Populate the original return type.
        $method->setOriginalReturnType();

        return $method;
    }

    private static function computeNewTypeForComment(CodeBase $code_base, Context $context, UnionType $signature_union_type, UnionType $comment_return_union_type): UnionType
    {
        $new_type = $comment_return_union_type;
        foreach ($comment_return_union_type->getTypeSet() as $type) {
            if (!$type->asPHPDocUnionType()->canAnyTypeStrictCastToUnionType($code_base, $signature_union_type)) {
                // Allow `@return static` to override a real type of MyClass.
                // php8 may add a real type of static.
                $resolved_type = $type->withStaticResolvedInContext($context);
                if ($resolved_type === $type || !$resolved_type->asPHPDocUnionType()->canAnyTypeStrictCastToUnionType($code_base, $signature_union_type)) {
                    $new_type = $new_type->withoutType($type);
                }
            }
        }

        if ($new_type !== $comment_return_union_type) {
            $new_type = $signature_union_type->withUnionType($new_type)->withRealTypeSet($signature_union_type->getRealTypeSet());
            if ($comment_return_union_type->hasRealTypeSet() && !$new_type->hasRealTypeSet()) {
                $new_type = $new_type->withRealTypeSet($comment_return_union_type->getRealTypeSet());
            }
            return $new_type;
        }
        if ($comment_return_union_type->hasRealTypeSet() && !$signature_union_type->hasRealTypeSet()) {
            return $comment_return_union_type;
        }
        return $comment_return_union_type->withRealTypeSet($signature_union_type->getRealTypeSet());
    }


    /**
     * Ensure that this clone will use the return type of the ancestor method
     */
    public function ensureClonesReturnType(Method $original_method): void
    {
        if ($this->defining_method_for_type_fetching) {
            return;
        }
        // Get the real ancestor of C::method() if C extends B and B extends A
        $original_method = $original_method->defining_method_for_type_fetching ?? $original_method;

        // Don't bother with methods that can't have types inferred recursively
        if ($original_method->isAbstract() || $original_method->isFromPHPDoc() || $original_method->isPHPInternal()) {
            return;
        }

        if (!$original_method->getUnionType()->isEmpty() || !$original_method->getRealReturnType()->isEmpty()) {
            // This heuristic is used as little as possible.
            // It will only use this fallback of directly using the (possibly modified)
            // parent's type if the parent method declaration had no phpdoc return type and no real return type (and nothing was guessed such as `void`).
            return;
        }
        $this->defining_method_for_type_fetching = $original_method;
    }

    public function setUnionType(UnionType $union_type): void
    {
        $this->defining_method_for_type_fetching = null;
        parent::setUnionType($union_type);
    }

    protected function getUnionTypeWithStatic(): UnionType
    {
        return parent::getUnionType();
    }

    /**
     * @return UnionType
     * The return type of this method in its given context.
     */
    public function getUnionType(): UnionType
    {
        if ($this->defining_method_for_type_fetching) {
            $union_type = $this->defining_method_for_type_fetching->getUnionTypeWithStatic();
        } else {
            $union_type = parent::getUnionType();
        }

        // If the type contains 'static', add this method's class
        // to the return type.
        $scope = new ClassScope(new GlobalScope(), $this->fqsen->getFullyQualifiedClassName(), 0);
        $new_union_type = $union_type->withStaticResolvedInContext((clone $this->getContext())->withScope($scope));
        if ($new_union_type !== $union_type) {
            $union_type = $union_type->withUnionType($new_union_type);
        }

        return $union_type;
    }

    public function getUnionTypeWithUnmodifiedStatic(): UnionType
    {
        return parent::getUnionType();
    }

    /** @suppress PhanTypeMismatchReturn */
    public function getFQSEN(): FullyQualifiedMethodName
    {
        return $this->fqsen;
    }

    /**
     * @return \Generator
     * @phan-return \Generator<Method>
     * The set of all alternates to this method
     */
    public function alternateGenerator(CodeBase $code_base): \Generator
    {
        // Workaround so that methods of generic classes will have the resolved template types
        yield $this;
        $fqsen = $this->fqsen;
        $alternate_id = $fqsen->getAlternateId() + 1;

        $fqsen = $fqsen->withAlternateId($alternate_id);

        while ($code_base->hasMethodWithFQSEN($fqsen)) {
            yield $code_base->getMethodByFQSEN($fqsen);
            $fqsen = $fqsen->withAlternateId(++$alternate_id);
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base with which to look for classes
     *
     * @return Method[]
     * 0 or more Methods that this Method is overriding
     * (Abstract methods are returned before concrete methods)
     */
    public function getOverriddenMethods(
        CodeBase $code_base
    ): array {
        // Get the class that defines this method
        $class = $this->getClass($code_base);

        // Get the list of ancestors of that class
        $ancestor_class_list = $class->getAncestorClassList(
            $code_base
        );

        $defining_fqsen = $this->getDefiningFQSEN();

        $method_list = [];
        $abstract_method_list = [];
        // Hunt for any ancestor classes that define a method with
        // the same name as this one.
        foreach ($ancestor_class_list as $ancestor_class) {
            // TODO: Handle edge cases in traits.
            // A trait may be earlier in $ancestor_class_list than the parent, but the parent may define abstract classes.
            // TODO: What about trait aliasing rules?
            if ($ancestor_class->hasMethodWithName($code_base, $this->name, true)) {
                $method = $ancestor_class->getMethodByName(
                    $code_base,
                    $this->name
                );
                if ($method->getDefiningFQSEN() === $defining_fqsen) {
                    // Skip it, this method **is** the one which defined this.
                    continue;
                }
                // We initialize the overridden method's scope to ensure that
                // analyzers are aware of the full param/return types of the overridden method.
                $method->ensureScopeInitialized($code_base);
                if ($method->isAbstract()) {
                    // TODO: check for trait conflicts, etc.
                    $abstract_method_list[] = $method;
                    continue;
                }
                $method_list[] = $method;
            }
        }
        // Return abstract methods before concrete methods, in order to best check method compatibility.
        $method_list = \array_merge($abstract_method_list, $method_list);
        // Give up on throwing exceptions if this method doesn't override anything.
        // Mixins and traits result in too many edge cases: https://github.com/phan/phan/issues/3796
        return $method_list;
    }

    /**
     * @return FullyQualifiedMethodName the FQSEN with the original definition (Even if this is private/protected and inherited from a trait). Used for dead code detection.
     *                                  Inheritance tests use getDefiningFQSEN() so that access checks won't break.
     */
    public function getRealDefiningFQSEN(): FullyQualifiedMethodName
    {
        return $this->real_defining_fqsen ?? $this->getDefiningFQSEN();
    }

    /**
     * @return string
     * A string representation of this method signature (preferring phpdoc types)
     */
    public function __toString(): string
    {
        $string = '';
        // TODO: should this representation and other representations include visibility?

        $string .= 'function ';
        if ($this->returnsRef()) {
            $string .= '&';
        }
        $string .= $this->name;

        $string .= '(' . \implode(', ', \array_map(function (Parameter $param): string {
            return $param->toStubString($this->isPHPInternal());
        }, $this->getParameterList())) . ')';

        $union_type = $this->getUnionTypeWithUnmodifiedStatic();
        if (!$union_type->isEmpty()) {
            $string .= ' : ' . (string)$union_type;
        }

        return $string;
    }

    /**
     * @return string
     * A string representation of this method signature
     * (Based on real types only, instead of phpdoc+real types)
     */
    public function toRealSignatureString(): string
    {
        $string = '';

        $string .= 'function ';
        if ($this->returnsRef()) {
            $string .= '&';
        }
        $string .= $this->name;

        $string .= '(' . \implode(', ', \array_map(function (Parameter $param): string {
            return $param->toStubString($this->isPHPInternal());
        }, $this->getRealParameterList())) . ')';

        if (!$this->getRealReturnType()->isEmpty()) {
            $string .= ' : ' . (string)$this->getRealReturnType();
        }

        return $string;
    }

    public function getMarkupDescription(): string
    {
        $string = '';
        // It's an error to have visibility or abstract in an interface's stub (e.g. JsonSerializable)
        if ($this->isPrivate()) {
            $string .= 'private ';
        } elseif ($this->isProtected()) {
            $string .= 'protected ';
        } else {
            $string .= 'public ';
        }

        if ($this->isAbstract()) {
            $string .= 'abstract ';
        }

        if ($this->isStatic()) {
            $string .= 'static ';
        }

        $string .= 'function ';
        if ($this->returnsRef()) {
            $string .= '&';
        }
        $string .= $this->name;

        $string .= '(' . $this->getParameterStubText() . ')';

        if ($this->isPHPInternal()) {
            $return_type = $this->getUnionType();
        } else {
            $return_type = $this->real_return_type;
        }
        if ($return_type && !$return_type->isEmpty()) {
            // Use PSR-12 style with no space before `:`
            $string .= ': ' . (string)$return_type;
        }

        return $string;
    }

    /**
     * Returns this method's visibility ('private', 'protected', or 'public')
     */
    public function getVisibilityName(): string
    {
        if ($this->isPrivate()) {
            return 'private';
        } elseif ($this->isProtected()) {
            return 'protected';
        } else {
            return 'public';
        }
    }

    /**
     * Returns a PHP stub that can be used in the output of `tool/make_stubs`
     */
    public function toStub(bool $class_is_interface = false): string
    {
        $string = '';
        if (self::shouldAddDescriptionsToStubs()) {
            $description = (string)MarkupDescription::extractDescriptionFromDocComment($this);
            if ($description !== '') {
                $string .= MarkupDescription::convertStringToDocComment($description, '    ');
            }
        }
        $string .= '    ';
        if ($this->isFinal()) {
            $string .= 'final ';
        }
        // It's an error to have visibility or abstract in an interface's stub (e.g. JsonSerializable)
        if (!$class_is_interface) {
            $string .= $this->getVisibilityName() . ' ';

            if ($this->isAbstract()) {
                $string .= 'abstract ';
            }
        }

        if ($this->isStatic()) {
            $string .= 'static ';
        }

        $string .= 'function ';
        if ($this->returnsRef()) {
            $string .= '&';
        }
        $string .= $this->name;

        $string .= '(' . $this->getRealParameterStubText() . ')';

        if (!$this->getRealReturnType()->isEmpty()) {
            $string .= ' : ' . (string)$this->getRealReturnType();
        }
        if ($this->isAbstract()) {
            $string .= ';';
        } else {
            $string .= ' {}';
        }

        return $string;
    }

    /**
     * Does this method have template types anywhere in its parameters or return type?
     * (This check is recursive)
     */
    public function hasTemplateType(): bool
    {
        return $this->getPhanFlagsHasState(Flags::HAS_TEMPLATE_TYPE);
    }

    private function recordHasTemplateType(): void
    {
        $this->setPhanFlags($this->getPhanFlags() | Flags::HAS_TEMPLATE_TYPE);
    }

    /**
     * Attempt to convert this template method into a method with concrete types
     * Either returns the original method or a clone of the method with more type information.
     */
    public function resolveTemplateType(
        CodeBase $code_base,
        UnionType $object_union_type
    ): Method {
        $defining_fqsen = $this->getDefiningClassFQSEN();
        $defining_class = $code_base->getClassByFQSEN($defining_fqsen);
        if (!$defining_class->isGeneric()) {
            // ???
            return $this;
        }
        $expected_type = $defining_fqsen->asType();

        // TODO: Handle intersection types?
        foreach ($object_union_type->getTypeSet() as $type) {
            if (!$type->hasTemplateParameterTypes()) {
                continue;
            }
            if (!$type->isObjectWithKnownFQSEN()) {
                continue;
            }
            $expanded_type = $type->withIsNullable(false)->asExpandedTypes($code_base);
            foreach ($expanded_type->getTypeSet() as $candidate) {
                if (!$candidate->isTemplateSubtypeOf($expected_type)) {
                    continue;
                }
                // $candidate is $expected_type<T...>
                $result = $this->cloneWithTemplateParameterTypeMap($candidate->getTemplateParameterTypeMap($code_base));
                return $result;
            }
        }
        // E.g. we can have `MyClass @implements MyBaseClass<string>` - so we check the expanded types for any template types, as well
        foreach ($object_union_type->asExpandedTypes($code_base)->getTypeSet() as $type) {
            if (!$type->hasTemplateParameterTypes()) {
                continue;
            }
            if (!$type->isObjectWithKnownFQSEN()) {
                continue;
            }
            $expanded_type = $type->withIsNullable(false)->asExpandedTypes($code_base);
            foreach ($expanded_type->getTypeSet() as $candidate) {
                if (!$candidate->isTemplateSubtypeOf($expected_type)) {
                    continue;
                }
                // $candidate is $expected_type<T...>
                $result = $this->cloneWithTemplateParameterTypeMap($candidate->getTemplateParameterTypeMap($code_base));
                return $result;
            }
        }
        return $this;
    }

    /**
     * @param array<string,UnionType> $template_type_map
     * A map from template type identifier to a concrete type
     */
    private function cloneWithTemplateParameterTypeMap(array $template_type_map): Method
    {
        $result = clone($this);
        $result->cloneParameterList();
        foreach ($result->parameter_list as $parameter) {
            $parameter->setUnionType($parameter->getUnionType()->withTemplateParameterTypeMap($template_type_map));
        }
        $result->setUnionType($result->getUnionType()->withTemplateParameterTypeMap($template_type_map));
        $result->setPhanFlags($result->getPhanFlags() & ~Flags::HAS_TEMPLATE_TYPE);
        if (Config::get_track_references()) {
            // Quick and dirty fix to make dead code detection work on this clone.
            // Consider making this an object instead.
            // @see AddressableElement::addReference()
            $result->reference_list = &$this->reference_list;
        }
        return $result;
    }

    /**
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
            if ($file_ref instanceof Context && $file_ref->isInFunctionLikeScope() && $file_ref->getFunctionLikeFQSEN() === $this->fqsen) {
                // Don't track methods calling themselves
                return;
            }
            $this->reference_list[$file_ref->__toString()] = $file_ref;
        }
    }

    public function getFullThrowsUnionType(): UnionType
    {
        $type = $this->getOwnThrowsUnionType();
        if ($this->inherited_throws_union_type) {
            $type = $type->withUnionType($this->inherited_throws_union_type);
        }
        return $type;
    }

    /**
     * Set union type of (at)throws annotations that this method inherits.
     */
    public function setInheritedThrowsUnionType(UnionType $type): void
    {
        $this->inherited_throws_union_type = $type;
    }

}
