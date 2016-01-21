<?php declare(strict_types=1);
namespace Phan\AST;

use \Phan\AST\UnionTypeVisitor;
use \Phan\Analyze\ClassName\ValidationVisitor as ClassNameValidationVisitor;
use \Phan\CodeBase;
use \Phan\Config;
use \Phan\Exception\CodeBaseException;
use \Phan\Exception\IssueException;
use \Phan\Exception\NodeException;
use \Phan\Exception\TypeException;
use \Phan\Exception\UnanalyzableException;
use \Phan\Issue;
use \Phan\Language\Context;
use \Phan\Language\Element\Clazz;
use \Phan\Language\Element\Constant;
use \Phan\Language\Element\Method;
use \Phan\Language\Element\Property;
use \Phan\Language\Element\Variable;
use \Phan\Language\FQSEN\FullyQualifiedClassName;
use \Phan\Language\FQSEN\FullyQualifiedFunctionName;
use \Phan\Language\FQSEN\FullyQualifiedMethodName;
use \Phan\Language\FQSEN\FullyQualifiedPropertyName;
use \Phan\Language\Type\MixedType;
use \Phan\Language\Type\NullType;
use \Phan\Language\Type\ObjectType;
use \Phan\Language\Type\StringType;
use \Phan\Language\UnionType;
use \ast\Node;

/**
 * Methods for an AST node in context
 */
class ContextNode
{

    /** @var CodeBase */
    private $code_base;

    /** @var Context */
    private $context;

    /** @var Node|string */
    private $node;

    /**
     * @param CodeBase $code_base
     * @param Context $context
     * @param Node|string $node
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        $node
    ) {
        $this->code_base = $code_base;
        $this->context = $context;
        $this->node = $node;
    }

    /**
     * Get a list of fully qualified names from a node
     *
     * @return string[]
     */
    public function getQualifiedNameList() : array
    {
        if (!($this->node instanceof Node)) {
            return [];
        }

        return array_map(function ($name_node) {
            return (new ContextNode(
                $this->code_base,
                $this->context,
                $name_node
            ))->getQualifiedName();
        }, $this->node->children ?? []);
    }

    /**
     * Get a fully qualified name form a node
     *
     * @return string
     */
    public function getQualifiedName() : string
    {
        return (string)UnionTypeVisitor::unionTypeFromClassNode(
            $this->code_base,
            $this->context,
            $this->node
        );
    }

    /**
     * @return string
     * A variable name associated with the given node
     */
    public function getVariableName() : string
    {
        if (!$this->node instanceof \ast\Node) {
            return (string)$this->node;
        }

        $node = $this->node;
        $parent = $node;

        while (($node instanceof \ast\Node)
            && ($node->kind != \ast\AST_VAR)
            && ($node->kind != \ast\AST_STATIC)
            && ($node->kind != \ast\AST_MAGIC_CONST)
        ) {
            $parent = $node;
            $node = array_values($node->children ?? [])[0];
        }

        if (!$node instanceof \ast\Node) {
            return (string)$node;
        }

        if (empty($node->children['name'])) {
            return '';
        }

        if ($node->children['name'] instanceof \ast\Node) {
            return '';
        }

        return (string)$node->children['name'];
    }

    /**
     * @return Clazz[]
     * A list of classes representing the non-native types
     * associated with the given node
     *
     * @throws CodeBaseException
     * An exception is thrown if a non-native type does not have
     * an associated class
     */
    public function getClassList()
    {
        $union_type = UnionTypeVisitor::unionTypeFromClassNode(
            $this->code_base,
            $this->context,
            $this->node
        );

        $class_list = [];
        foreach ($union_type->asClassList($this->code_base)
        as $i => $clazz) {
            $class_list[] = $clazz;
        }

        return $class_list;
    }

    /**
     * @param Node|string $method_name
     * Either then name of the method or a node that
     * produces the name of the method.
     *
     * @param bool $is_static
     * Set to true if this is a static method call
     *
     * @return Method
     * A method with the given name on the class referenced
     * from the given node
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseExtension
     * An exception is thrown if we can't find the given
     * method
     *
     * @throws TypeException
     * An exception may be thrown if the only viable candidate
     * is a non-class type.
     *
     * @throws IssueException
     */
    public function getMethod(
        $method_name,
        bool $is_static
    ) : Method {

        if ($method_name instanceof Node) {
            // The method_name turned out to be a variable.
            // There isn't much we can do to figure out what
            // it's referring to.
            throw new NodeException(
                $method_name,
                "Unexpected method node"
            );
        }

        assert(
            is_string($method_name),
            "Method name must be a string. Found non-string at {$this->context}"
        );

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $this->node->children['expr']
                ?? $this->node->children['class']
            ))->getClassList();
        } catch (CodeBaseException $exception) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassMethod)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ $method_name, (string)$exception->getFQSEN() ]
                )
            );
        }

        // If there were no classes on the left-type, figure
        // out what we were trying to call the method on
        // and send out an error.
        if (empty($class_list)) {
            $union_type = UnionTypeVisitor::unionTypeFromClassNode(
                $this->code_base,
                $this->context,
                $this->node->children['expr']
                ?? $this->node->children['class']
            );

            if (!$union_type->isEmpty()
                && $union_type->isNativeType()
                && !$union_type->hasAnyType([
                    MixedType::instance(),
                    ObjectType::instance(),
                    StringType::instance()
                ])
                && !(
                    Config::get()->null_casts_as_any_type
                    && $union_type->hasType(NullType::instance())
                )
            ) {
                throw new IssueException(
                    Issue::fromType(Issue::NonClassMethodCall)(
                        $this->context->getFile(),
                        $this->node->lineno ?? 0,
                        [ $method_name, (string)$union_type ]
                    )
                );
            }

            throw new NodeException(
                $this->node,
                "Can't figure out method call for $method_name"
            );
        }

        // Hunt to see if any of them have the method we're
        // looking for
        foreach ($class_list as $i => $class) {
            if ($class->hasMethodWithName($this->code_base, $method_name)) {
                return $class->getMethodByNameInContext(
                    $this->code_base,
                    $method_name,
                    $this->context
                );
            }
        }

        // Figure out an FQSEN for the method we couldn't find
        $method_fqsen = FullyQualifiedMethodName::make(
            $class_list[0]->getFQSEN(),
            $method_name
        );

        if ($is_static) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredStaticMethod)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ (string)$method_fqsen ]
                )
            );
        }

        throw new IssueException(
            Issue::fromType(Issue::UndeclaredMethod)(
                $this->context->getFile(),
                $this->node->lineno ?? 0,
                [ (string)$method_fqsen ]
            )
        );
    }

    /**
     * @param string $function_name
     * The name of the function we'd like to look up
     *
     * @param bool $is_function_declaration
     * This must be set to true if we're getting a function
     * that is being declared and false if we're getting a
     * function being called.
     *
     * @return Method
     * A method with the given name in the given context
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * function
     */
    public function getFunction(
        string $function_name,
        bool $is_function_declaration = false
    ) : Method {

        if ($is_function_declaration) {
            $function_fqsen =
                FullyQualifiedFunctionName::make(
                    $this->context->getNamespace(),
                    $function_name
                );
        } else {
            $function_fqsen =
                FullyQualifiedFunctionName::make(
                    $this->context->getNamespace(),
                    $function_name
                );

            // If it doesn't exist in the local namespace, try it
            // in the global namespace
            if (!$this->code_base->hasMethod($function_fqsen)) {
                $function_fqsen =
                    FullyQualifiedFunctionName::fromStringInContext(
                        $function_name,
                        $this->context
                    );
            }

        }

        // Make sure the method we're calling actually exists
        if (!$this->code_base->hasMethod($function_fqsen)) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredFunction)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ "$function_fqsen()" ]
                )
            );
        }

        $method = $this->code_base->getMethod($function_fqsen);

        return $method;
    }

    /**
     * @return Variable
     * A variable in scope or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * A IssueException is thrown if the variable doesn't
     * exist
     */
    public function getVariable() : Variable
    {
        // Get the name of the variable
        $variable_name = $this->getVariableName();

        if (empty($variable_name)) {
            throw new NodeException(
                $this->node,
                "Variable name not found"
            );
        }

        // Check to see if the variable exists in this scope
        if (!$this->context->getScope()->hasVariableWithName($variable_name)) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredVariable)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ $variable_name ]
                )
            );
        }

        return $this->context->getScope()->getVariableWithName(
            $variable_name
        );
    }

    /**
     * @return Variable
     * A variable in scope or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     */
    public function getOrCreateVariable() : Variable
    {
        try {
            return $this->getVariable();
        } catch (IssueException $exception) {
            // Swallow it
        }

        // Create a new variable
        $variable = Variable::fromNodeInContext(
            $this->node,
            $this->context,
            $this->code_base,
            false
        );

        $this->context->addScopeVariable($variable);

        return $variable;
    }

    /**
     * @param string|Node $property_name
     * The name of the property we're looking up
     *
     * @return Property
     * A variable in scope or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the given
     * class or if we don't have access to the property (its
     * private or protected).
     *
     * @throws TypeException
     * An exception may be thrown if the only viable candidate
     * is a non-class type.
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     */
    public function getProperty(
        $property_name
    ) : Property {

        $property_name = $this->node->children['prop'];

        // Give up for things like C::$prop_name
        if (!is_string($property_name)) {
            throw new NodeException(
                $this->node,
                "Cannot figure out non-string property name"
            );
        }

        $class_fqsen = null;

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $this->node->children['expr'] ??
                $this->node->children['class']
            ))->getClassList();
        } catch (CodeBaseException $exception) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredProperty)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ "{$exception->getFQSEN()}->$property_name" ]
                )
            );
        }

        foreach ($class_list as $i => $class) {
            $class_fqsen = $class->getFQSEN();

            // Keep hunting if this class doesn't have the given
            // property
            if (!$class->hasPropertyWithName(
                $this->code_base,
                $property_name
            )) {
                // If there's a getter on properties than all
                // bets are off.
                if ($class->hasMethodWithName(
                    $this->code_base,
                    '__get'
                )) {
                    throw new UnanalyzableException(
                        $this->node,
                        "Can't determine if property {$property_name} exists in class {$class->getFQSEN()} with __get defined"
                    );
                }

                continue;
            }

            return $class->getPropertyByNameInContext(
                $this->code_base,
                $property_name,
                $this->context
            );
        }

        // If the class isn't found, we'll get the message elsewhere
        if ($class_fqsen) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredProperty)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ "$class_fqsen->$property_name" ]
                )
            );
        }

        throw new NodeException(
            $this->node,
            "Cannot figure out property"
        );
    }

    /**
     * @return Property
     * A variable in scope or a new variable
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseExtension
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws TypeException
     * An exception may be thrown if the only viable candidate
     * is a non-class type.
     */
    public function getOrCreateProperty(
        string $property_name
    ) : Property {

        try {
            return $this->getProperty($property_name);
        } catch (IssueException $exception) {
            // Ignore it, because we'll create our own
            // property
        } catch (UnanalyzableException $exception) {
            // Ignore it, because we'll create our own
            // property
        }

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $this->node
            ))->getClassList();
        } catch (CodeBaseException $exception) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassReference)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ $exception->getFQSEN() ]
                )
            );
        }

        if (empty($class_list)) {
            throw new UnanalyzableException(
                $this->node,
                "Could not get class name from node"
            );
        }

        $class = array_values($class_list)[0];

        $flags = 0;
        if ($this->node->kind == \ast\AST_STATIC_PROP) {
            $flags |= \ast\flags\MODIFIER_STATIC;
        }

        // Otherwise, we'll create it
        $property = new Property(
            $this->context,
            $property_name,
            new UnionType(),
            $flags
        );

        $property->setFQSEN(
            FullyQualifiedPropertyName::make(
                $class->getFQSEN(),
                $property_name
            )
        );

        $class->addProperty($this->code_base, $property);

        return $property;
    }

    /**
     * @return Constant
     * Get the (non-class) constant associated with this node
     * in this context
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseExtension
     * An exception is thrown if we can't find the given
     * class
     */
    public function getConst() : Constant
    {
        assert(
            $this->node->kind === \ast\AST_CONST,
            "Node must be of type \ast\AST_CONST"
        );

        if ($this->node->children['name']->kind !== \ast\AST_NAME) {
            throw new NodeException(
                $this->node,
                "Can't determine constant name"
            );
        }

        // Get an FQSEN for the root namespace
        $fqsen = null;

        $constant_name =
            $this->node->children['name']->children['name'];

        if (!$this->code_base->hasConstant($fqsen, $constant_name)) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredConstant)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ $constant_name ]
                )
            );
        }

        return $this->code_base->getConstant($fqsen, $constant_name);
    }

    /**
     * @return Constant
     * Get the (non-class) constant associated with this node
     * in this context
     *
     * @throws NodeException
     * An exception is thrown if we can't understand the node
     *
     * @throws CodeBaseExtension
     * An exception is thrown if we can't find the given
     * class
     *
     * @throws UnanalyzableException
     * An exception is thrown if we hit a construct in which
     * we can't determine if the property exists or not
     */
    public function getClassConst() : Constant
    {
        assert(
            $this->node->kind === \ast\AST_CLASS_CONST,
            "Node must be of type \ast\AST_CLASS_CONST"
        );

        $constant_name = $this->node->children['const'];

        // class name fetch
        if ($constant_name == 'class') {
            throw new UnanalyzableException(
                $this->node,
                "Can't get class constant for implicit 'class'"
            );
        }

        $class_fqsen = null;

        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $this->node->children['class']
            ))->getClassList();
        } catch (CodeBaseException $exception) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredClassConstant)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ $constant_name, $exception->getFQSEN() ]
                )
            );
        }

        foreach ($class_list as $i => $class) {
            $class_fqsen = $class->getFQSEN();

            // Check to see if the class has the constant
            if (!$class->hasConstantWithName(
                $this->code_base,
                $constant_name
            )) {
                continue;
            }

            return $class->getConstantWithName(
                $this->code_base,
                $constant_name
            );
        }

        // If no class is found, we'll emit the error elsewhere
        if ($class_fqsen) {
            throw new IssueException(
                Issue::fromType(Issue::UndeclaredConstant)(
                    $this->context->getFile(),
                    $this->node->lineno ?? 0,
                    [ "$class_fqsen::$constant_name" ]
                )
            );
        }

        throw new NodeException(
            $this->node,
            "Can't figure out constant {$constant_name} in node"
        );
    }

    /**
     * @return string
     * A unique and stable name for an anonymous class
     */
    public function getUnqualifiedNameForAnonymousClass() : string
    {
        assert(
            (bool)($this->node->flags & \ast\flags\CLASS_ANONYMOUS),
            "Node must be an anonymous class node"
        );

        $class_name = 'anonymous_class_'
            . substr(md5(implode('|', [
                $this->context->getFile(),
                $this->context->getLineNumberStart()
            ])), 0, 8);

        return $class_name;
    }

    /**
     * @return Method
     */
    public function getClosure() : Method
    {
        $closure_fqsen =
            FullyQualifiedFunctionName::fromClosureInContext(
                $this->context
            );

        if (!$this->code_base->hasMethod($closure_fqsen)) {
            throw new CodeBaseException(
                $closure_fqsen,
                "Could not find closure $closure_fqsen"
            );
        }

        return $this->code_base->getMethod($closure_fqsen);
    }

    /**
     * Perform some backwards compatibility checks on a node
     *
     * @return void
     */
    public function analyzeBackwardCompatibility()
    {
        if (!Config::get()->backward_compatibility_checks) {
            return;
        }

        if (empty($this->node->children['expr'])) {
            return;
        }

        if ($this->node->kind === \ast\AST_STATIC_CALL ||
           $this->node->kind === \ast\AST_METHOD_CALL) {
            return;
        }

        if ($this->node->kind !== \ast\AST_DIM) {
            if (!($this->node->children['expr'] instanceof Node)) {
                return;
            }

            if ($this->node->children['expr']->kind !== \ast\AST_DIM) {
                (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $this->node->children['expr']
                ))->analyzeBackwardCompatibility();
                return;
            }

            $temp = $this->node->children['expr']->children['expr'];
            $lnode = $temp;
        } else {
            $temp = $this->node->children['expr'];
            $lnode = $temp;
        }

        if (!($temp->kind == \ast\AST_PROP
            || $temp->kind == \ast\AST_STATIC_PROP
        )) {
            return;
        }

        while ($temp instanceof Node
            && ($temp->kind == \ast\AST_PROP
            || $temp->kind == \ast\AST_STATIC_PROP)
        ) {
            $lnode = $temp;

            // Lets just hope the 0th is the expression
            // we want
            $temp = array_values($temp->children)[0];
        }

        if (!($temp instanceof Node)) {
            return;
        }

        // Foo::$bar['baz'](); is a problem
        // Foo::$bar['baz'] is not
        if ($lnode->kind === \ast\AST_STATIC_PROP
            && $this->node->kind !== \ast\AST_CALL
        ) {
            return;
        }

        if ((
                (
                    $lnode->children['prop'] instanceof Node
                    && $lnode->children['prop']->kind == \ast\AST_VAR
                )
                ||
                (
                    !empty($lnode->children['class'])
                    && $lnode->children['class'] instanceof Node
                    && (
                        $lnode->children['class']->kind == \ast\AST_VAR
                        || $lnode->children['class']->kind == \ast\AST_NAME
                    )
                )
                ||
                (
                    !empty($lnode->children['expr'])
                    && $lnode->children['expr'] instanceof Node
                    && (
                        $lnode->children['expr']->kind == \ast\AST_VAR
                        || $lnode->children['expr']->kind == \ast\AST_NAME
                    )
                )
            )
            &&
            (
                $temp->kind == \ast\AST_VAR
                || $temp->kind == \ast\AST_NAME
            )
        ) {
            $ftemp = new \SplFileObject($this->context->getFile());
            $ftemp->seek($this->node->lineno-1);
            $line = $ftemp->current();
            unset($ftemp);
            if (strpos($line, '}[') === false
                || strpos($line, ']}') === false
                || strpos($line, '>{') === false
            ) {
                Issue::emit(
                    Issue::CompatiblePHP7,
                    $this->context->getFile(),
                    $this->node->lineno ?? 0
                );
            }
        }
    }
}
