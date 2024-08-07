<?php

declare(strict_types=1);

namespace Phan\AST;

use AssertionError;
use ast;
use ast\Node;
use Closure;
use Phan\Analysis\AssignOperatorFlagVisitor;
use Phan\Analysis\BinaryOperatorFlagVisitor;
use Phan\Analysis\BlockExitStatusChecker;
use Phan\Analysis\ConditionVisitor;
use Phan\Analysis\NegatedConditionVisitor;
use Phan\AST\Visitor\Element;
use Phan\CodeBase;
use Phan\Config;
use Phan\Debug;
use Phan\Exception\CodeBaseException;
use Phan\Exception\EmptyFQSENException;
use Phan\Exception\FQSENException;
use Phan\Exception\InvalidFQSENException;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Exception\RecursionDepthException;
use Phan\Exception\UnanalyzableException;
use Phan\Exception\UnanalyzableMagicPropertyException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionLikeName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalStructuralElement;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\Scope\BranchScope;
use Phan\Language\Scope\GlobalScope;
use Phan\Language\Type;
use Phan\Language\Type\ArrayShapeType;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\AssociativeArrayType;
use Phan\Language\Type\BoolType;
use Phan\Language\Type\CallableType;
use Phan\Language\Type\ClassStringType;
use Phan\Language\Type\ClosureDeclarationType;
use Phan\Language\Type\ClosureType;
use Phan\Language\Type\FalseType;
use Phan\Language\Type\FloatType;
use Phan\Language\Type\GenericArrayType;
use Phan\Language\Type\IntersectionType;
use Phan\Language\Type\IntType;
use Phan\Language\Type\IterableType;
use Phan\Language\Type\ListType;
use Phan\Language\Type\LiteralIntType;
use Phan\Language\Type\LiteralStringType;
use Phan\Language\Type\MixedType;
use Phan\Language\Type\NeverType;
use Phan\Language\Type\NonEmptyMixedType;
use Phan\Language\Type\NullType;
use Phan\Language\Type\ObjectType;
use Phan\Language\Type\SelfType;
use Phan\Language\Type\StaticOrSelfType;
use Phan\Language\Type\StaticType;
use Phan\Language\Type\StringType;
use Phan\Language\Type\TemplateType;
use Phan\Language\Type\TrueType;
use Phan\Language\Type\VoidType;
use Phan\Language\UnionType;
use Phan\Language\UnionTypeBuilder;
use Phan\Library\StringUtil;
use TypeError;

use function is_scalar;
use function is_string;
use function strpos;

/**
 * Determines the UnionType associated with a given node.
 *
 * @see UnionTypeVisitor::unionTypeFromNode()
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgument node is complicated
 * @phan-file-suppress PhanPartialTypeMismatchArgumentInternal node is complicated
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @method UnionType __invoke(Node $node)
 */
class UnionTypeVisitor extends AnalysisVisitor
{
    /**
     * If an dynamic unpacked array has more elements than this, then give up on building up the union type
     */
    private const ARRAY_UNPACK_COUNT_THRESHOLD = 20;

    /**
     * @var bool
     * Set to true to cause loggable issues to be thrown
     * instead of emitted as issues to the log.
     */
    private $should_catch_issue_exception = false;

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param bool $should_catch_issue_exception
     * Set to true to cause loggable issues to be thrown
     * instead of emitted as issues to the log.
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        bool $should_catch_issue_exception = true
    ) {
        // Inlined to be more efficient.
        // parent::__construct($code_base, $context);
        $this->code_base = $code_base;
        $this->context = $context;

        $this->should_catch_issue_exception =
            $should_catch_issue_exception;
    }

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param Node|string|bool|int|float|null $node
     * The node for which we'd like to determine its type
     *
     * @param bool $should_catch_issue_exception
     * Set to true to cause loggable issues to be thrown
     * instead
     *
     * @return UnionType
     * The UnionType associated with the given node
     * in the given Context within the given CodeBase
     *
     * @throws IssueException
     * If $should_catch_issue_exception is false an IssueException may
     * be thrown for optional issues.
     */
    public static function unionTypeFromNode(
        CodeBase $code_base,
        Context $context,
        $node,
        bool $should_catch_issue_exception = true
    ): UnionType {
        if (!($node instanceof Node)) {
            if ($node === null) {
                // NOTE: Parameter default checks expect this to return empty
                return UnionType::empty();
            }
            return Type::fromObject($node)->asRealUnionType();
        }
        $node_id = \spl_object_id($node);

        $cached_union_type = $context->getUnionTypeOfNodeIfCached($node_id, $should_catch_issue_exception);
        if ($cached_union_type !== null) {
            return $cached_union_type;
        }

        if ($should_catch_issue_exception) {
            try {
                $union_type = (new self(
                    $code_base,
                    $context,
                    true
                ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'visit'}($node);
                $context->setCachedUnionTypeOfNode($node_id, $union_type, true);
                return $union_type;
            } catch (IssueException $exception) {
                Issue::maybeEmitInstance(
                    $code_base,
                    $context,
                    $exception->getIssueInstance()
                );
                return UnionType::empty();
            }
        }

        $union_type = (new self(
            $code_base,
            $context,
            false
        ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'visit'}($node);

        $context->setCachedUnionTypeOfNode($node_id, $union_type, false);
        return $union_type;
    }

    /**
     * Default visitor for node kinds that do not have
     * an overriding method
     *
     * @param Node $node (@phan-unused-param)
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return UnionType
     * The set of types associated with the given node
     */
    public function visit(Node $node): UnionType
    {
        /*
        throw new NodeException($node,
            'Visitor not implemented for node of type '
            . Debug::nodeName($node)
        );
        */
        return UnionType::empty();
    }

    /**
     * Visit a node with kind `\ast\AST_POST_INC`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitPostInc(Node $node): UnionType
    {
        // Real types aren't certain, since this doesn't throw even for object or array types
        // TODO: Check if union type is sane (string/int)
        return self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['var'],
            $this->should_catch_issue_exception
        )->asNonLiteralType();
    }

    /**
     * Visit a node with kind `\ast\AST_POST_DEC`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitPostDec(Node $node): UnionType
    {
        // Real types aren't certain, since this doesn't throw even for object or array types
        // TODO: Check if union type is sane (string/int)
        return self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['var'],
            $this->should_catch_issue_exception
        )->asNonLiteralType();
    }

    /**
     * Visit a node with kind `\ast\AST_PRE_DEC`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitPreDec(Node $node): UnionType
    {
        // Real types aren't certain, since this doesn't throw even for object or array types
        // TODO: Check if union type is sane (string/int)
        return self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['var'],
            $this->should_catch_issue_exception
        )->asNonLiteralType()->getTypeAfterIncOrDec();
    }

    /**
     * Visit a node with kind `\ast\AST_PRE_INC`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * TODO: in PostOrderAnalysisVisitor, set the type to unknown for ++/--
     */
    public function visitPreInc(Node $node): UnionType
    {
        // Real types aren't certain, since this doesn't throw even for object or array types
        // TODO: Check if union type is sane (string/int)
        return self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['var'],
            $this->should_catch_issue_exception
        )->asNonLiteralType()->getTypeAfterIncOrDec();
    }

    /**
     * Visit a node with kind `\ast\AST_CLONE`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitClone(Node $node): UnionType
    {
        // Phan checks elsewhere if union type is sane (Any object type)
        $type = self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        )->objectTypes();
        if ($type->isEmpty()) {
            return ObjectType::instance(false)->asRealUnionType();
        }
        $type = $type->nonNullableClone();
        if (!$type->hasRealTypeSet()) {
            $type = $type->withRealTypeSet([ObjectType::instance(false)]);
        }
        return $type;
    }

    /**
     * Visit a node with kind `\ast\AST_EMPTY`
     *
     * @param Node $node (@phan-unused-param)
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitEmpty(Node $node): UnionType
    {
        return BoolType::instance(false)->asRealUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_ISSET`
     *
     * @param Node $node (@phan-unused-param)
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitIsset(Node $node): UnionType
    {
        return BoolType::instance(false)->asRealUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_INCLUDE_OR_EVAL`
     *
     * @param Node $node (@phan-unused-param)
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitIncludeOrEval(Node $node): UnionType
    {
        // require() can return arbitrary objects. Lets just
        // say that we don't know what it is and move on
        return UnionType::empty();
    }

    private static function literalIntUnionType(int $value): UnionType
    {
        return LiteralIntType::instanceForValue($value, false)->asRealUnionType();
    }

    private static function literalStringUnionType(string $value): UnionType
    {
        return LiteralStringType::instanceForValue($value, false)->asRealUnionType();
    }

    public const MAGIC_CONST_NAME_MAP = [
        ast\flags\MAGIC_LINE => '__LINE__',
        ast\flags\MAGIC_FILE => '__FILE__',
        ast\flags\MAGIC_DIR => '__DIR__',
        ast\flags\MAGIC_NAMESPACE => '__NAME__',
        ast\flags\MAGIC_FUNCTION => '__FUNCTION__',
        ast\flags\MAGIC_METHOD => '__METHOD__',
        ast\flags\MAGIC_CLASS => '__CLASS__',
        ast\flags\MAGIC_TRAIT => '__TRAIT__',
    ];

    private function warnAboutUndeclaredMagicConstant(Node $node, string $details): void
    {
        $this->emitIssue(
            Issue::UndeclaredMagicConstant,
            $node->lineno,
            self::MAGIC_CONST_NAME_MAP[$node->flags],
            $details
        );
    }

    /**
     * Visit a node with kind `\ast\AST_MAGIC_CONST`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitMagicConst(Node $node): UnionType
    {
        $flags = $node->flags;
        switch ($flags) {
            case ast\flags\MAGIC_CLASS:
                if ($this->context->isInClassScope()) {
                    // Works in classes, traits, and interfaces
                    return self::literalStringUnionType(\ltrim($this->context->getClassFQSEN()->__toString(), '\\'));
                }
                $this->warnAboutUndeclaredMagicConstant($node, 'used outside of classlike');
                break;
            case ast\flags\MAGIC_FUNCTION:
                if ($this->context->isInFunctionLikeScope()) {
                    $fqsen = $this->context->getFunctionLikeFQSEN();
                    if ($fqsen instanceof FullyQualifiedMethodName) {
                        // For NS\MyClass::methodName, return 'methodName'
                        $value = $fqsen->getName();
                    } else {
                        if ($fqsen->isClosure()) {
                            $this->emitIssue(
                                Issue::SuspiciousMagicConstant,
                                $node->lineno,
                                '__FUNCTION__',
                                "used inside of a closure instead of a function/method - the value is always '{closure}'"
                            );
                            $value = '{closure}';
                        } else {
                            // For \NS\my_function, return 'NS\my_function'.
                            $value = \ltrim($fqsen->__toString(), '\\');
                        }
                    }
                    return self::literalStringUnionType($value);
                }
                $this->warnAboutUndeclaredMagicConstant($node, 'used outside of functionlike');
                break;
            case ast\flags\MAGIC_METHOD:
                if ($this->context->isInFunctionLikeScope()) {
                    // Emits method or function FQSEN.
                    $fqsen = $this->context->getFunctionLikeFQSEN();
                    if (!$fqsen instanceof FullyQualifiedMethodName) {
                        $this->emitIssue(
                            Issue::SuspiciousMagicConstant,
                            $node->lineno,
                            '__METHOD__',
                            'used inside of a function/closure instead of a method'
                        );
                    }
                    return self::literalStringUnionType($fqsen->isClosure() ? '{closure}' : \ltrim($fqsen->__toString(), '\\'));
                }
                $this->warnAboutUndeclaredMagicConstant($node, 'used outside of a functionlike');
                break;
            case ast\flags\MAGIC_DIR:
                return self::literalStringUnionType(\dirname(Config::projectPath($this->context->getFile())));
            case ast\flags\MAGIC_FILE:
                return self::literalStringUnionType(Config::projectPath($this->context->getFile()));
            case ast\flags\MAGIC_LINE:
                return self::literalIntUnionType($node->lineno);
            case ast\flags\MAGIC_NAMESPACE:
                return self::literalStringUnionType(\ltrim($this->context->getNamespace(), '\\'));
            case ast\flags\MAGIC_TRAIT:
                // TODO: Could check if in trait, low importance.
                if (!$this->context->isInClassScope()) {
                    $this->warnAboutUndeclaredMagicConstant($node, 'used outside of a trait');
                    break;
                }
                $fqsen = $this->context->getClassFQSEN();
                if ($this->code_base->hasClassWithFQSEN($fqsen)) {
                    if (!$this->code_base->getClassByFQSEN($fqsen)->isTrait()) {
                        $this->warnAboutUndeclaredMagicConstant($node, 'used in a classlike that wasn\'t a trait');
                        break;
                    }
                }
                return self::literalStringUnionType(\ltrim($this->context->getClassFQSEN()->__toString(), '\\'));
            default:
                return StringType::instance(false)->asPHPDocUnionType();
        }

        return self::literalStringUnionType('');
    }

    /**
     * Visit a node with kind `\ast\AST_ASSIGN_REF`
     * @see self::visitAssign()
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitAssignRef(Node $node): UnionType
    {
        // TODO: Is there any way this should differ from analysis
        // (e.g. should subsequent assignments affect the right-hand Node?)
        return $this->visitAssign($node);
    }

    /**
     * Visit a node with kind `\ast\AST_SHELL_EXEC`
     *
     * @param Node $node (@phan-unused-param)
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitShellExec(Node $node): UnionType
    {
        return StringType::instance(true)->asRealUnionType();
    }

    /**
     * @throws IssueException if the parent type could not be resolved
     */
    public static function findParentType(Context $context, CodeBase $code_base): ?Type
    {
        if (!$context->isInClassScope()) {
            throw new IssueException(
                Issue::fromType(Issue::ContextNotObject)(
                    $context->getFile(),
                    $context->getLineNumberStart(),
                    ['parent']
                )
            );
        }
        $class = $context->getClassInScope($code_base);

        $parent_type_option = $class->getParentTypeOption();
        if ($parent_type_option->isDefined()) {
            return $parent_type_option->get();
        }

        // Using `parent` in a class or interface without a parent is always invalid.
        // Doing this in a trait may or not be valid.
        if (!$class->isTrait()) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::ParentlessClass,
                $context->getLineNumberStart(),
                (string)$class->getFQSEN()
            );
        }

        return null;
    }

    /**
     * Visit a node with kind `\ast\AST_NAME`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitName(Node $node): UnionType
    {
        $name = $node->children['name'];
        try {
            if ($node->flags & \ast\flags\NAME_NOT_FQ) {
                if (\strcasecmp('parent', $name) === 0) {
                    $parent_type = self::findParentType($this->context, $this->code_base);
                    return $parent_type ? $parent_type->asRealUnionType() : UnionType::empty();
                }

                return Type::fromStringInContext(
                    $name,
                    $this->context,
                    Type::FROM_NODE,
                    $this->code_base
                )->asRealUnionType();
            }

            if ($node->flags & \ast\flags\NAME_RELATIVE) {  // $x = new namespace\Foo();
                $name = \rtrim($this->context->getNamespace(), '\\') . '\\' . $name;
                return Type::fromFullyQualifiedString(
                    $name
                )->asRealUnionType();
            }
            // Sometimes 0 for a fully qualified name?

            return Type::fromFullyQualifiedString(
                '\\' . $name
            )->asRealUnionType();
        } catch (FQSENException $e) {
            $this->emitIssue(
                $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                $node->lineno,
                $e->getFQSEN()
            );
            return UnionType::empty();
        }
    }

    /**
     * Visit a node with kind `\ast\AST_TYPE`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws AssertionError if the type flags were unknown
     */
    public function visitType(Node $node): UnionType
    {
        switch ($node->flags) {
            case \ast\flags\TYPE_ARRAY:
                return ArrayType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_BOOL:
                return BoolType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_CALLABLE:
                return CallableType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_DOUBLE:
                return FloatType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_ITERABLE:
                return IterableType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_LONG:
                return IntType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_NULL:
                return NullType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_OBJECT:
                return ObjectType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_STRING:
                return StringType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_VOID:
                return VoidType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_FALSE:
                return FalseType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_TRUE:
                return TrueType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_STATIC:
                return StaticType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_MIXED:
                return MixedType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_NEVER:
                return NeverType::instance(false)->asRealUnionType();
            default:
                \Phan\Debug::printNode($node);
                throw new AssertionError("All flags must match. Found ($node->flags) "
                    . Debug::astFlagDescription($node->flags ?? 0, $node->kind));
        }
    }

    /**
     * Visit a node with kind `\ast\AST_TYPE_INTERSECTION`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws AssertionError if the type flags were unknown
     */
    public function visitTypeIntersection(Node $node): UnionType
    {
        // TODO: Validate that there aren't any duplicates
        if (\count($node->children) === 1) {
            // Might be possible due to the polyfill in the future.
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
            return $this->__invoke($node->children[0]);
        }
        $types = [];
        foreach ($node->children as $c) {
            if (!$c instanceof Node) {
                throw new AssertionError("Saw non-node in union type");
            }
            $kind = $c->kind;
            if ($kind === ast\AST_TYPE) {
                $types[] = $this->visitType($c);
            } elseif ($kind === ast\AST_NAME) {
                if ($this->context->getScope()->isInTraitScope()) {
                    $name = \strtolower($c->children['name']);
                    if ($name === 'self') {
                        $types[] = SelfType::instance(false)->asRealUnionType();
                        continue;
                    } elseif ($name === 'static') {
                        $types[] = StaticType::instance(false)->asRealUnionType();
                        continue;
                    }
                }
                $types[] = $this->visitName($c);
            } else {
                throw new AssertionError("Expected intersection type to be composed of types and names");
            }
        }
        $result = [IntersectionType::createFromTypes($types, $this->code_base, $this->context)];
        return UnionType::of($result, $result);
    }

    /**
     * Visit a node with kind `\ast\AST_TYPE_UNION`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws AssertionError if the type flags were unknown
     */
    public function visitTypeUnion(Node $node): UnionType
    {
        // TODO: Validate that there aren't any duplicates
        if (\count($node->children) === 1) {
            // Might be possible due to the polyfill in the future.
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
            return $this->__invoke($node->children[0]);
        }
        $types = [];
        foreach ($node->children as $c) {
            if (!$c instanceof Node) {
                throw new AssertionError("Saw non-node in union type");
            }
            $kind = $c->kind;
            if ($kind === ast\AST_TYPE) {
                $types[] = $this->visitType($c);
            } elseif ($kind === ast\AST_NAME) {
                if ($this->context->getScope()->isInTraitScope()) {
                    $name = \strtolower($c->children['name']);
                    if ($name === 'self') {
                        $types[] = SelfType::instance(false)->asRealUnionType();
                        continue;
                    } elseif ($name === 'static') {
                        $types[] = StaticType::instance(false)->asRealUnionType();
                        continue;
                    }
                }
                $types[] = $this->visitName($c);
            } elseif ($kind === ast\AST_TYPE_INTERSECTION) {
                $types[] = $this->visitTypeIntersection($c);
            } else {
                throw new AssertionError("Expected union type to be composed of types and names but got " . Debug::nodeName($c));
            }
        }
        $result = [];
        foreach ($types as $union_type) {
            foreach ($union_type->getTypeSet() as $type) {
                $result[] = $type;
            }
        }
        return UnionType::of($result, $result);
    }

    /**
     * Visit a node with kind `\ast\AST_NULLABLE_TYPE` representing
     * a nullable type such as `?string`.
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitNullableType(Node $node): UnionType
    {
        // Get the type
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable other node kinds have nullable type
        $union_type = $this->__invoke($node->children['type']);

        // Make each nullable
        return $union_type->asMappedUnionType(static function (Type $type): Type {
            return $type->withIsNullable(true);
        });
    }

    /**
     * @param int|float|string|Node $node
     */
    public static function unionTypeFromLiteralOrConstant(CodeBase $code_base, Context $context, $node): ?UnionType
    {
        if ($node instanceof Node) {
            // TODO: There are a lot more types of expressions that have known union types that this doesn't handle.
            // Maybe callers should call something else if this fails (e.g. it's useful for them to know if an expression becomes a string)
            if (\in_array($node->kind, [\ast\AST_CONST, \ast\AST_CLASS_CONST, \ast\AST_CLASS_NAME], true)) {
                try {
                    return UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node, false);
                } catch (IssueException $_) {
                    return null;
                }
            }
            $result = (new ContextNode($code_base, $context, $node))->getEquivalentPHPValue();

            if ($result instanceof Node) {
                return null;
            }
            // XXX This isn't 100% accurate for constants that can have different definitions based on the environment, etc.
            return Type::fromObjectExtended($result)->asRealUnionType();
        }
        // Otherwise, this is an int/float/string.
        if (!is_scalar($node)) {
            throw new TypeError('node must be Node or scalar');
        }
        return Type::fromObject($node)->asRealUnionType();
    }

    /**
     * Returns the union type from a type in a parameter/return signature of a function-like.
     * This preserves `self` and `static`
     * @param Node $node
     */
    public function fromTypeInSignature(Node $node): UnionType
    {
        $is_nullable = $node->kind === ast\AST_NULLABLE_TYPE;
        if ($is_nullable) {
            $node = $node->children['type'];
            if (!$node instanceof Node) {
                // Work around bug (in polyfill parser?)
                return UnionType::empty();
            }
        }
        $kind = $node->kind;
        if ($kind === ast\AST_TYPE) {
            $result = $this->visitType($node);
        } elseif ($kind === ast\AST_NAME) {
            if ($this->context->getScope()->isInTraitScope()) {
                $name = \strtolower($node->children['name']);
                if ($name === 'self') {
                    return SelfType::instance($is_nullable)->asRealUnionType();
                } elseif ($name === 'static') {
                    return StaticType::instance($is_nullable)->asRealUnionType();
                }
            }
            $result = $this->visitName($node);
        } elseif ($kind === ast\AST_TYPE_UNION) {
            $result = $this->visitTypeUnion($node);
        } elseif ($kind === ast\AST_TYPE_INTERSECTION) {
            $result = $this->visitTypeIntersection($node);
        } else {
            throw new AssertionError("Expected a type, union type, intersection type, or a name in the signature: node: " . Debug::nodeToString($node));
        }
        if ($is_nullable) {
            return $result->nullableClone();
        }
        return $result;
    }

    /**
     * @param int|float|string|Node $cond
     */
    public static function checkCondUnconditionalTruthiness($cond): ?bool
    {
        if ($cond instanceof Node) {
            if ($cond->kind === \ast\AST_CONST) {
                switch (\strtolower($cond->children['name']->children['name'] ?? '')) {
                    case 'true':
                        return true;
                    case 'false':
                        return false;
                    case 'null':
                        return false;
                    default:
                        // Could add heuristics based on internal/user-defined constant values, but that is unreliable.
                        // (E.g. feature flags for an extension may be true or false, depending on the environment)
                        // (and Phan doesn't store constant values for user-defined constants, only the types)
                        return null;
                }
            }
            return null;
        }
        // Otherwise, this is an int/float/string.
        // Use the exact same truthiness rules as PHP to check if the conditional is truthy.
        // (e.g. "0" and 0.0 and '' are false)
        if (!is_scalar($cond)) {
            // Phan should have emitted a PhanSyntaxError elsewhere
            return null;
        }
        return (bool)$cond;
    }

    /**
     * Visit a node with kind `\ast\AST_CONDITIONAL`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitConditional(Node $node): UnionType
    {
        $cond_node = $node->children['cond'];
        $cond_truthiness = self::checkCondUnconditionalTruthiness($cond_node);
        // For the shorthand $a ?: $b, the cond node will be the truthy value.
        // Note: an ast node will never be null(can be unset), it will be a const AST node with the name null.
        $true_node = $node->children['true'] ?? $cond_node;
        $false_node = $node->children['false'];

        // Rarely, a conditional will always be true or always be false.
        if ($cond_truthiness !== null) {
            // TODO: Add no-op checks in another PR, if they don't already exist for conditional.
            if ($cond_truthiness) {
                // The condition is unconditionally true
                return UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $true_node,
                    $this->should_catch_issue_exception
                );
            } else {
                // The condition is unconditionally false

                // Add the type for the 'false' side
                return UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $node->children['false'],
                    $this->should_catch_issue_exception
                );
            }
        }
        if ($true_node !== $cond_node) {
            // Visit the condition to check for undefined variables.
            UnionTypeVisitor::unionTypeFromNode(
                $this->code_base,
                $this->context,
                $cond_node,
                $this->should_catch_issue_exception
            );
        }
        // TODO: emit no-op if $cond_node is a literal, such as `if (2)`
        // - Also note that some things such as `true` and `false` are \ast\AST_NAME nodes.

        if ($cond_node instanceof Node) {
            $base_context = $this->context;
            // TODO: Use different contexts and merge those, in case there were assignments or assignments by reference in both sides of the conditional?
            // Reuse the BranchScope (sort of unintuitive). The ConditionVisitor returns a clone and doesn't modify the original.
            $base_context_scope = $this->context->getScope();
            if ($base_context_scope instanceof GlobalScope) {
                $base_context = $base_context->withScope(new BranchScope($base_context_scope));
            }
            // Doesn't seem to be necessary to run BlockAnalysisVisitor
            // $base_context = (new BlockAnalysisVisitor($this->code_base, $base_context))->__invoke($cond_node);
            $true_context = (new ConditionVisitor(
                $this->code_base,
                isset($node->children['true']) ? $base_context : $this->context  // special case: $c = (($d = foo()) ?: 'fallback')
            ))->__invoke($cond_node);
            $false_context = (new NegatedConditionVisitor(
                $this->code_base,
                $base_context
            ))->__invoke($cond_node);

            if (!isset($node->children['true'])) {
                $true_type = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $true_context,
                    $true_node,
                    $this->should_catch_issue_exception
                );

                $false_type = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $false_context,
                    $false_node,
                    $this->should_catch_issue_exception
                );
                if ($false_node instanceof Node && BlockExitStatusChecker::willUnconditionallyThrowOrReturn($false_node)) {
                    return $true_type->nonFalseyClone();
                }

                $true_type_is_empty = $true_type->isEmpty();
                if (!$false_type->isEmpty()) {
                    // E.g. `foo() ?: 2` where foo is nullable or possibly false.
                    if ($true_type->containsFalsey()) {
                        $true_type = $true_type->nonFalseyClone();
                    }
                }

                // Add the type for the 'true' side to the 'false' side
                $union_type = $true_type->withUnionType($false_type);

                // If one side has an unknown type but the other doesn't
                // we can't let the unseen type get erased. Unfortunately,
                // we need to add 'mixed' in so that we know it could be
                // anything at all.
                //
                // See Issue #104
                if ($true_type_is_empty xor $false_type->isEmpty()) {
                    $union_type = $union_type->withType(
                        MixedType::instance(false)
                    );
                }

                return $union_type;
            }
        } else {
            $true_context = $this->context;
            $false_context = $this->context;
        }
        // Postcondition: This is (cond_expr) ? (true_expr) : (false_expr)

        $true_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $true_context,
            $true_node,
            $this->should_catch_issue_exception
        );

        $false_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $false_context,
            $node->children['false'],
            $this->should_catch_issue_exception
        );
        if ($false_type->isNeverType()) {
            return $true_type;
        }
        if ($true_type->isNeverType()) {
            return $false_type;
        }

        // Add the type for the 'true' side to the 'false' side
        $union_type = $true_type->withUnionType($false_type);

        // If one side has an unknown type but the other doesn't
        // we can't let the unseen type get erased. Unfortunately,
        // we need to add 'mixed' in so that we know it could be
        // anything at all.
        //
        // See Issue #104
        if ($true_type->isEmpty() xor $false_type->isEmpty()) {
            $union_type = $union_type->withType(
                MixedType::instance(false)
            );
        }

        return $union_type;
    }

    /**
     * Visit a node with kind `\ast\AST_MATCH`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     * @suppress PhanPossiblyUndeclaredProperty
     */
    public function visitMatch(Node $node): UnionType
    {
        // TODO: Support inferring the type from the conditional
        $union_types = [];
        foreach ($node->children['stmts']->children as $arm_node) {
            if (!BlockExitStatusChecker::willUnconditionallyThrowOrReturn($arm_node)) {
                $union_types[] = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    clone($this->context),
                    $arm_node->children['expr'],
                    $this->should_catch_issue_exception
                );
            }
        }
        if (!$union_types) {
            return VoidType::instance(false)->asRealUnionType();
        }
        return UnionType::merge($union_types);
    }

    /**
     * Visit a node with kind `\ast\AST_ARRAY`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitArray(Node $node): UnionType
    {
        $children = $node->children;
        if (\count($children) > 0) {
            $key_set = $this->getEquivalentArraySet($node);
            if (\is_array($key_set)) {
                // XXX decide how to deal with array components when the top level array is real
                return $this->createArrayShapeType($key_set)->asRealUnionType();
            }

            $value_types_builder = new UnionTypeBuilder();
            $real_value_types_builder = new UnionTypeBuilder();
            $record_real_union_type = static function (UnionType $union_type) use (&$real_value_types_builder): void {
                if (!$real_value_types_builder) {
                    return;
                }
                $real_types = $union_type->getRealTypeSet();
                if (!$real_types) {
                    $real_value_types_builder = null;
                    return;
                }
                foreach ($real_types as $type) {
                    $real_value_types_builder->addType($type);
                }
            };

            // XXX is this slow for extremely large arrays because of in_array check in UnionTypeBuilder?
            $is_definitely_non_empty = false;
            $has_key = false;
            $has_unpack_string_key = false;
            foreach ($children as $child) {
                if (!($child instanceof Node)) {
                    // Skip this, we already emitted a syntax error.
                    $real_value_types_builder = null;
                    $has_key = true;
                    continue;
                }
                if ($child->kind === ast\AST_UNPACK) {
                    // Analyze PHP 7.4's array spread operator, e.g. `[$a, ...$array, $b]`
                    [$new_union_type, $new_union_type_has_string_keys] = $this->analyzeUnpack($child, true);
                    if ($new_union_type_has_string_keys) {
                        $has_key = true;
                        $has_unpack_string_key = true;
                    }

                    $has_key = $has_key || $new_union_type_has_string_keys;
                    $value_types_builder->addUnionType($new_union_type);
                    $record_real_union_type($new_union_type);
                    continue;
                }
                $is_definitely_non_empty = true;
                $value = $child->children['value'];
                $has_key = $has_key || isset($child->children['key']);
                if ($value instanceof Node) {
                    $element_value_type = UnionTypeVisitor::unionTypeFromNode(
                        $this->code_base,
                        $this->context,
                        $value,
                        $this->should_catch_issue_exception
                    );
                    if ($element_value_type->isEmpty()) {
                        $value_types_builder->addType(MixedType::instance(false));
                        $real_value_types_builder = null;
                    } else {
                        if ($element_value_type->isVoidType()) {
                            $this->emitIssue(
                                Issue::TypeVoidExpression,
                                $node->lineno,
                                ASTReverter::toShortString($value)
                            );
                        }
                        $value_types_builder->addUnionType($element_value_type);
                        $record_real_union_type($element_value_type);
                    }
                } else {
                    $new_type = Type::fromObject($value);
                    $value_types_builder->addType($new_type);
                    if ($real_value_types_builder) {
                        $real_value_types_builder->addType($new_type);
                    }
                }
            }
            // TODO: Normalize value_types, e.g. false+true=bool, array<int,T>+array<string,T>=array<mixed,T>

            $key_type_enum = GenericArrayType::getKeyTypeOfArrayNode($this->code_base, $this->context, $node, $this->should_catch_issue_exception);
            $result = $value_types_builder->getPHPDocUnionType();
            if ($has_key) {
                $result = $result->asNonEmptyAssociativeArrayTypes($key_type_enum);
            } else {
                $result = $result->asNonEmptyListTypes();
            }
            $result = $result->withRealTypeSet($this->arrayTypeFromRealTypeBuilder($real_value_types_builder, $node, $has_key, $has_unpack_string_key));
            if ($is_definitely_non_empty) {
                return $result->nonFalseyClone();
            }
            return $result;
        }

        // TODO: Also return types such as array<int, mixed>?
        // TODO: Fix or suppress false positives PhanTypeArraySuspicious caused by loops...
        return ArrayShapeType::empty(false)->asRealUnionType();
    }

    /**
     * @return list<ArrayType>
     */
    private function arrayTypeFromRealTypeBuilder(?UnionTypeBuilder $builder, Node $node, bool $has_key, bool $has_unpack_string_key): array
    {
        // Here, we only check for the real type being an integer.
        // Unknown strings such as '0' will cast to integers when used as array keys,
        // and if we knew all of the array keys were literals we would have generated an array shape instead.
        $has_exclusively_int_keys = !$has_unpack_string_key;
        if ($has_key && $has_exclusively_int_keys) {
            foreach ($node->children as $child_node) {
                $key = $child_node->children['key'] ?? null;
                if (!isset($key)) {
                    continue;
                }
                $key_type = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $key,
                    $this->should_catch_issue_exception
                );
                if (!$key_type->getRealUnionType()->isIntTypeOrNull()) {
                    $has_exclusively_int_keys = false;
                    break;
                }
            }
        }
        if (!$builder || $builder->isEmpty()) {
            if (!$has_key) {
                // @phan-suppress-next-line PhanTypeMismatchReturn
                return UnionType::typeSetFromString('list');
            }
            // @phan-suppress-next-line PhanTypeMismatchReturn
            return UnionType::typeSetFromString($has_exclusively_int_keys ? 'array<int,mixed>' : 'array');
        }
        $real_types = [];
        foreach ($builder->getTypeSet() as $type) {
            if ($has_key) {
                // TODO: Could be more precise if all keys are known to be non-numeric strings or integers
                $real_types[] = GenericArrayType::fromElementType(
                    $type,
                    false,
                    $has_exclusively_int_keys ? GenericArrayType::KEY_INT : GenericArrayType::KEY_MIXED
                );
            } else {
                $real_types[] = ListType::fromElementType($type, false, GenericArrayType::KEY_MIXED);
            }
        }
        return $real_types;
    }

    /**
     * Visit a node with kind `\ast\AST_YIELD`
     *
     * @param Node $node @unused-param
     * A yield node. Does not affect the union type
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitYield(Node $node): UnionType
    {
        $context = $this->context;
        if (!$context->isInFunctionLikeScope()) {
            return UnionType::empty();
        }

        // Get the method/function/closure we're in
        $method = $context->getFunctionLikeInScope($this->code_base);
        $method_generator_type = $method->getReturnTypeAsGeneratorTemplateType();
        $type_list = $method_generator_type->getTemplateParameterTypeList();
        if (\count($type_list) < 3 || \count($type_list) > 4) {
            return UnionType::empty();
        }
        // Return TSend of Generator<TKey,TValue,TSend[,TReturn]>
        return $type_list[2];
    }

    /**
     * @return ?array<int|string,Node>
     * Caller should check if the result size is too small and handle it (for duplicate keys)
     * Returns null if one or more keys could not be resolved
     *
     * @see ContextNode::getEquivalentPHPArrayElements()
     */
    private function getEquivalentArraySet(Node $node): ?array
    {
        $elements = [];
        $context_node = null;
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                ContextNode::warnAboutEmptyArrayElements($this->code_base, $this->context, $node);
                continue;
            }
            if ($child_node->kind === ast\AST_UNPACK) {
                if ($this->getPackedArrayFieldTypes($child_node->children['expr']) !== null) {
                    // This is a placeholder of a deliberately - the caller checks that the count of elements matches the count of AST child nodes.
                    // TODO: Refactor to handle edge cases such as `[...[1], 0 => 2]`
                    $elements[] = $child_node;
                    continue;
                }
                return null;
            }

            $key_node = $child_node->children['key'];
            // NOTE: this has some overlap with DuplicateKeyPlugin
            if ($key_node === null) {
                $elements[] = $child_node;
                continue;
            }
            if (\is_object($key_node)) {
                if ($context_node === null) {
                    $context_node = new ContextNode($this->code_base, $this->context, null);
                }
                $key_node = $context_node->getEquivalentPHPValueForNode($key_node, ContextNode::RESOLVE_CONSTANTS);
                if (\is_object($key_node)) {
                    return null;
                }
            }
            // TODO: Add a warning elsewhere about implicit float to int conversion when analyzing arrays
            // PHP 8.1 deprecated implicit float to int conversions
            if (\is_scalar($key_node)) {
                if (!\is_string($key_node)) {
                    $key_node = (int)$key_node;
                }
            } elseif (\is_array($key_node)) {
                return null;
            } elseif (\is_resource($key_node)) {
                $key_node = \get_resource_id($key_node);
            } else {
                // null
                $key_node = (string)$key_node;
            }
            $elements[$key_node] = $child_node;  // Check for float?
        }
        return $elements;
    }

    /**
     * @param Node|mixed $expr
     * @return ?list<UnionType> the type of $x in ...$x, provided that it's a packed array (with keys 0, 1, ...)
     */
    private function getPackedArrayFieldTypes($expr): ?array
    {
        if (!$expr instanceof Node) {
            // TODO: Warn if non-array
            return null;
        }
        // e.g. `[$x, ...$array]` in PHP 7.4
        // TODO: Support array expressions when their value is constant
        $union_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $expr, $this->should_catch_issue_exception);
        // TODO: Warn if non-array

        if ($union_type->typeCount() === 1 && $union_type->hasTopLevelArrayShapeTypeInstances() && !$union_type->hasTopLevelNonArrayShapeTypeInstances()) {
            $type_set = $union_type->getTypeSet();
            $type = \reset($type_set);
            // TODO: Warn if the keys aren't consecutive 0-based integers
            $expected = 0;
            if (!$type instanceof ArrayShapeType) {
                return null;
            }
            $field_types = $type->getFieldTypes();
            if ($expr->kind !== ast\AST_ARRAY && \count($field_types) >= self::ARRAY_UNPACK_COUNT_THRESHOLD) {
                return null;
            }
            foreach ($field_types as $i => $type) {
                if ($i !== $expected || $type->isPossiblyUndefined()) {
                    return null;
                }
                $expected++;
            }
            return $field_types;
        }
        return null;
    }

    /**
     * @param array<int|string,Node> $key_set
     */
    private function createArrayShapeType(array $key_set): ArrayShapeType
    {
        $field_types = [];

        foreach ($key_set as $key => $child) {
            // Keep iteration over $children and key_set in sync
            if ($child->kind === ast\AST_UNPACK) {
                // handle [other_expr, ...expr, other_exprs]
                $element_value_type = $this->getPackedArrayFieldTypes($child->children['expr']);
                if (!\is_array($element_value_type)) {
                    // impossible
                    continue;
                }
                foreach ($element_value_type as $type) {
                    $field_types[] = $type;
                }
                continue;
            }
            $value = $child->children['value'];

            if ($value instanceof Node) {
                $element_value_type = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $value,
                    $this->should_catch_issue_exception
                );
                if ($element_value_type->isEmpty()) {
                    $element_value_type = MixedType::instance(false)->asPHPDocUnionType();
                } else {
                    $element_value_type = $element_value_type->convertUndefinedToNullable();
                }
            } else {
                $element_value_type = Type::fromObject($value)->asRealUnionType();
            }
            if ($child->children['key'] === null) {
                $field_types[] = $element_value_type;
            } else {
                $field_types[$key] = $element_value_type;
            }
        }
        return ArrayShapeType::fromFieldTypes($field_types, false);
    }

    /**
     * Visit a node with kind `\ast\AST_BINARY_OP`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitBinaryOp(Node $node): UnionType
    {
        return (new BinaryOperatorFlagVisitor(
            $this->code_base,
            $this->context,
            $this->should_catch_issue_exception
        ))->__invoke($node);
    }

    /**
     * Visit a node with kind `\ast\AST_ASSIGN_OP` (E.g. $x .= 'suffix')
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitAssignOp(Node $node): UnionType
    {
        return (new AssignOperatorFlagVisitor(
            $this->code_base,
            $this->context
        ))->__invoke($node);
    }

    /**
     * Visit a node with kind `\ast\AST_CAST`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws NodeException if the flags are a value we aren't expecting
     */
    public function visitCast(Node $node): UnionType
    {
        // This calls unionTypeFromNode to trigger any warnings
        // TODO: Check if the cast would throw an error at runtime, based on the type (e.g. casting object to string/int)

        // RedundantConditionCallPlugin contains unrelated checks of whether this is redundant.
        $expr = $node->children['expr'];
        $expr_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $expr, $this->should_catch_issue_exception);
        if ($expr_type->isVoidType()) {
            $this->emitIssue(
                Issue::TypeVoidExpression,
                $expr->lineno ?? $node->lineno,
                ASTReverter::toShortString($expr)
            );
        }
        switch ($node->flags) {
            case \ast\flags\TYPE_NULL:
                return NullType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_BOOL:
                return $expr_type->applyBoolCast();
                // TODO: Warn about invalid casts (#2806)
            case \ast\flags\TYPE_LONG:
                return IntType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_DOUBLE:
                return FloatType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_STRING:
                return StringType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_ARRAY:
                return ArrayType::instance(false)->asRealUnionType();
            case \ast\flags\TYPE_OBJECT:
                return $this->typeAfterCastToObject($expr_type);
            default:
                throw new NodeException(
                    $node,
                    'Unknown type (' . $node->flags . ') in cast'
                );
        }
    }

    /**
     * @suppress PhanThrowTypeAbsentForCall
     */
    private static function typeAfterCastToObject(UnionType $expr_type): UnionType
    {
        static $stdclass;
        if ($stdclass === null) {
            $stdclass = Type::fromFullyQualifiedString('\stdClass');
        }
        $has_array = $expr_type->hasArray();
        if ($has_array) {
            if ($expr_type->isExclusivelyArray()) {
                return $stdclass->asRealUnionType();
            }
        }
        $expr_type = $expr_type->objectTypes();
        if ($expr_type->isEmpty()) {
            return ObjectType::instance(false)->asRealUnionType();
        }
        $expr_type = $expr_type->nonNullableClone();
        if ($has_array) {
            $expr_type = $expr_type->withType($stdclass);
            if ($expr_type->hasRealTypeSet()) {
                return $expr_type->withRealTypeSet(\array_merge($expr_type->getRealTypeSet(), [$stdclass]));
            } else {
                return $expr_type->withRealType(ObjectType::instance(false));
            }
        }
        if (!$expr_type->hasRealTypeSet()) {
            return $expr_type->withRealType(ObjectType::instance(false));
        }
        return $expr_type;
    }

    /**
     * Visit a node with kind `\ast\AST_NEW`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitNew(Node $node): UnionType
    {
        static $object_type;
        if ($object_type === null) {
            $object_type = ObjectType::instance(false);
        }
        $class_node = $node->children['class'];
        if (!($class_node instanceof Node)) {
            $this->emitIssue(
                Issue::InvalidNode,
                $node->lineno,
                "Invalid ClassName for new ClassName()"
            );
            return $object_type->asRealUnionType();
        }
        $args_node = $node->children['args'];
        if ($args_node->kind !== ast\AST_ARG_LIST) {
            $this->emitIssue(
                Issue::InvalidNode,
                $node->lineno,
                "Cannot create Closure for new expression"
            );
            return $object_type->asRealUnionType();
        }
        $union_type = $this->visitClassNameNode($class_node);
        if ($union_type->isEmpty()) {
            return $object_type->asRealUnionType();
        }

        // TODO: re-use the underlying type set in the common case
        // Maybe UnionType::fromMap

        // For any types that are templates, map them to concrete
        // types based on the parameters passed in.
        $type_set = \array_map(function (Type $type) use ($args_node): Type {

            // Get a fully qualified name for the type
            // TODO: Add a test of `new $closure()` warning.
            $fqsen = FullyQualifiedClassName::fromType($type);

            // If we don't have the class, we'll catch that problem
            // elsewhere
            if (!$this->code_base->hasClassWithFQSEN($fqsen)) {
                return $type;
            }

            $class = $this->code_base->getClassByFQSEN($fqsen);

            // If this class doesn't have any generics on it, we're
            // fine as we are with this Type
            if (!$class->isGeneric()) {
                return $type;
            }

            // Now things are interesting. We need to map the
            // arguments to the generic types and return a special
            // kind of type.

            // Map each argument to its type
            /** @param Node|string|int|float $arg_node */
            $arg_type_list = \array_map(function ($arg_node): UnionType {
                return UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $arg_node,
                    $this->should_catch_issue_exception
                );
            }, $args_node->children);

            // Get closures to extract template types based on the types of the constructor
            // so that we can figure out what template types we're going to be mapping
            $template_type_resolvers = $class->getGenericConstructorBuilder($this->code_base);

            // And use those closures to infer the (possibly transformed) types
            $template_type_list = [];
            foreach ($template_type_resolvers as $template_type_resolver) {
                $template_type_list[] = $template_type_resolver($arg_type_list, $this->context);
            }

            // Create a new type that assigns concrete
            // types to template type identifiers.
            return Type::fromType($type, $template_type_list);
        }, $union_type->getTypeSet());

        if (!$type_set) {
            return $object_type->asRealUnionType();
        }

        if ($class_node->kind === ast\AST_NAME) {
            $real_type_set = $type_set;
        } else {
            $real_type_set = [$object_type];
        }

        return UnionType::of($type_set, $real_type_set);
    }

    /**
     * Visit a node with kind `\ast\AST_INSTANCEOF`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitInstanceOf(Node $node): UnionType
    {
        $code_base = $this->code_base;
        $context = $this->context;
        // Check to make sure the left side is valid
        UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node->children['expr'], $this->should_catch_issue_exception);
        // Get the type that we're checking it against, check if it is valid.
        $class_node = $node->children['class'];
        if (!($class_node instanceof Node)) {
            return BoolType::instance(false)->asRealUnionType();
        }
        $type = UnionTypeVisitor::unionTypeFromNode(
            $code_base,
            $context,
            $class_node,
            $this->should_catch_issue_exception
        );
        // TODO: Unify UnionTypeVisitor, AssignmentVisitor, and PostOrderAnalysisVisitor
        if (!$type->isEmpty() && $type->objectTypesWithKnownFQSENs()->isEmpty()) {
            if ($class_node->kind === \ast\AST_NAME || !$type->hasStringType()) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::TypeInvalidInstanceof,
                    $context->getLineNumberStart(),
                    ASTReverter::toShortString($class_node),
                    (string)$type
                );
            }
        }

        return BoolType::instance(false)->asRealUnionType();
    }

    /** @internal - Duplicated for performance. Use the constant from PhanAnnotationAdder instead */
    private const FLAG_IGNORE_NULLABLE = 1 << 29;

    /**
     * Visit a node with kind `\ast\AST_DIM`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws IssueException
     * if the dimension access is invalid
     */
    public function visitDim(Node $node, bool $treat_undef_as_nullable = false): UnionType
    {
        $code_base = $this->code_base;
        $context = $this->context;
        $union_type = self::unionTypeFromNode(
            $code_base,
            $context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        )->withStaticResolvedInContext($context);

        if ($union_type->isEmpty()) {
            return UnionType::empty();
        }

        // If none of the types we found were arrays with elements,
        // then check for ArrayAccess
        static $array_access_type;
        static $simple_xml_element_type;  // SimpleXMLElement doesn't `implement` ArrayAccess, but can be accessed that way. See #542
        static $null_type;
        static $string_type;
        static $string_union_type;
        static $int_union_type;
        static $int_or_string_union_type;

        if ($array_access_type === null) {
            // array offsets work on strings, unfortunately
            // Double check that any classes in the type don't
            // have ArrayAccess
            $array_access_type =
                Type::fromNamespaceAndName('\\', 'ArrayAccess', false);
            $simple_xml_element_type =
                Type::fromNamespaceAndName('\\', 'SimpleXMLElement', false);
            $null_type = NullType::instance(false);
            $string_type = StringType::instance(false);
            $string_union_type = $string_type->asPHPDocUnionType();
            $int_union_type = IntType::instance(false)->asPHPDocUnionType();
            $int_or_string_union_type = UnionType::fromFullyQualifiedPHPDocString('int|string');
        }

        if (self::hasArrayShapeOrList($union_type)) {
            $element_type = $this->resolveArrayShapeElementTypes($node, $union_type);
            if ($element_type !== null) {
                if ($element_type->isPossiblyUndefined() && !($node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF)) {
                    $this->emitIssue(
                        Issue::TypePossiblyInvalidDimOffset,
                        $node->lineno,
                        ASTReverter::toShortString($node->children['dim']),
                        ASTReverter::toShortString($node->children['expr']),
                        $union_type
                    );
                    if ($treat_undef_as_nullable || Config::getValue('convert_possibly_undefined_offset_to_nullable')) {
                        return $element_type->nullableClone()->withIsPossiblyUndefined(false);
                    }
                    return $element_type->withNullableRealTypes()->withIsPossiblyUndefined(false);
                }
                // echo "Returning $element_type for {$union_type->getDebugRepresentation()}\n";
                return $element_type;
            }
        }

        $dim_type = self::unionTypeFromNode(
            $code_base,
            $context,
            $node->children['dim'],
            $this->should_catch_issue_exception
        );

        // Figure out what the types of accessed array
        // elements would be.
        $generic_types = $union_type->genericArrayElementTypes(true, $code_base);

        // If we have generics, we're all set
        if (!$generic_types->isEmpty()) {
            $generic_types = $generic_types->asNormalizedTypes();
            if (!($node->flags & self::FLAG_IGNORE_NULLABLE) && $union_type->containsNonMixedNullable()) {
                $this->emitIssue(
                    Issue::TypeArraySuspiciousNullable,
                    $node->lineno,
                    ASTReverter::toShortString($node->children['expr']),
                    (string)$union_type
                );
            }

            if (!$dim_type->isEmpty()) {
                try {
                    $should_check = !$union_type->hasMixedOrNonEmptyMixedType() && !$union_type->hasArrayAccess($code_base);
                } catch (RecursionDepthException $_) {
                    $should_check = false;
                }
                if ($should_check) {
                    if (Config::getValue('scalar_array_key_cast')) {
                        $expected_key_type = $int_or_string_union_type;
                    } else {
                        $expected_key_type = GenericArrayType::unionTypeForKeyType(
                            GenericArrayType::keyTypeFromUnionTypeKeys($union_type),
                            GenericArrayType::CONVERT_KEY_MIXED_TO_INT_OR_STRING_UNION_TYPE
                        );
                    }

                    if (!$dim_type->canCastToUnionType($expected_key_type, $code_base)) {
                        $issue_type = Issue::TypeMismatchDimFetch;

                        if ($dim_type->containsNullable() && $dim_type->nonNullableClone()->canCastToUnionType($expected_key_type, $code_base)) {
                            $issue_type = Issue::TypeMismatchDimFetchNullable;
                        }

                        if ($this->should_catch_issue_exception) {
                            $this->emitIssue(
                                $issue_type,
                                $node->lineno,
                                (string)$union_type,
                                (string)$dim_type,
                                (string)$expected_key_type
                            );
                            return $generic_types;
                        }

                        throw new IssueException(
                            Issue::fromType($issue_type)(
                                $context->getFile(),
                                $node->lineno,
                                [(string)$union_type, (string)$dim_type, (string)$expected_key_type]
                            )
                        );
                    }
                }
            }
            return $generic_types;
        }

        // If the only type is null, we don't know what
        // accessed items will be
        if ($union_type->isType($null_type)) {
            if (!($node->flags & self::FLAG_IGNORE_NULLABLE)) {
                $this->emitIssue(
                    Issue::TypeArraySuspiciousNull,
                    $node->lineno,
                    ASTReverter::toShortString($node->children['expr'])
                );
            }
            if ($union_type->getRealUnionType()->isNull()) {
                return NullType::instance(false)->asRealUnionType();
            }
            return NullType::instance(false)->asPHPDocUnionType();
        }

        $element_types = UnionType::empty();

        // You can access string characters via array index,
        // so we'll add the string type to the result if we're
        // indexing something that could be a string
        if ($union_type->isNonNullStringType()
            || ($union_type->canCastToUnionType($string_union_type, $code_base) && !$union_type->hasMixedOrNonEmptyMixedType())
        ) {
            if (Config::get_closest_minimum_target_php_version_id() < 70100 && $union_type->isNonNullStringType()) {
                $this->analyzeNegativeStringOffsetCompatibility($node, $dim_type);
            }
            $this->checkIsValidStringOffset($union_type, $node, $dim_type);

            if (!$dim_type->isEmpty() && !$dim_type->canCastToUnionType($int_union_type, $code_base)) {
                if (!$union_type->isEmpty() && !$union_type->hasArrayLike($code_base)) {
                    $this->emitIssue(
                        Issue::TypeMismatchDimFetch,
                        $node->lineno,
                        $union_type,
                        (string)$dim_type,
                        $int_union_type
                    );
                }
            }
            $element_types = $element_types->withType($string_type);
            if ($union_type->hasRealTypeSet()) {
                // @phan-suppress-next-line PhanAccessMethodInternal
                $element_types = $element_types->withRealTypeSet(UnionType::computeRealElementTypesForDimAccess($union_type->getRealTypeSet(), $code_base));
            }
        }

        if ($element_types->isEmpty()) {
            // Hunt for any types that are viable class names and
            // see if they inherit from ArrayAccess
            try {
                foreach ($union_type->asClassList($code_base, $context) as $class) {
                    $expanded_types = $class->getUnionType()->asExpandedTypes($code_base);
                    if ($expanded_types->hasType($array_access_type) ||
                            $expanded_types->hasType($simple_xml_element_type)
                    ) {
                        return $element_types;
                    }
                }
            } catch (CodeBaseException | RecursionDepthException $_) {
                // ignore
            }

            if (!$union_type->hasArrayLike($code_base) && !$union_type->hasMixedOrNonEmptyMixedType()) {
                $this->emitIssue(
                    Issue::TypeArraySuspicious,
                    $node->lineno,
                    ASTReverter::toShortString($node->children['expr']),
                    (string)$union_type
                );
                return $element_types;
            }
            if (!($node->flags & self::FLAG_IGNORE_NULLABLE) && $union_type->containsNullable()) {
                $this->emitIssue(
                    Issue::TypeArraySuspiciousNullable,
                    $node->lineno,
                    ASTReverter::toShortString($node->children['expr']),
                    (string)$union_type
                );
            }
        }

        return $element_types;
    }

    /**
     * Check for invalid string offsets, e.g. `'val'[3]`, `''[$i]`, etc.
     *
     * @param UnionType $union_type the union type of the expression
     * @param UnionType $dim_type the union type of the dimension being accessed on the expression
     */
    private function checkIsValidStringOffset(UnionType $union_type, Node $node, UnionType $dim_type): void
    {
        $max_len = -1;
        foreach ($union_type->getRealTypeSet() as $type) {
            if ($type instanceof StringType) {
                if ($type instanceof LiteralStringType) {
                    $max_len = \max($max_len, \strlen($type->getValue()));
                    continue;
                }
                return;
            } elseif ($type instanceof IterableType) {
                return;
            }
        }
        if ($max_len < 0) {
            return;
        }
        if ($max_len > 0) {
            $dim_value = $dim_type->asSingleScalarValueOrNullOrSelf();
            if (\is_object($dim_value)) {
                return;
            }
            $dim_value_as_int = (int)$dim_value;
            if ($dim_value_as_int < 0) {
                // Convert -1 to 0, etc.
                $dim_value_as_int = ~$dim_value_as_int;
            }
            if ($dim_value_as_int < $max_len) {
                return;
            }
        }
        $exception = new IssueException(
            Issue::fromType(Issue::TypeInvalidDimOffset)(
                $this->context->getFile(),
                $node->children['dim']->lineno ?? $node->lineno,
                [
                    $dim_type,
                    ASTReverter::toShortString($node->children['expr']),
                    (string)$union_type
                ]
            )
        );
        if ($this->should_catch_issue_exception) {
            Issue::maybeEmitInstance($this->code_base, $this->context, $exception->getIssueInstance());
            return;
        } else {
            throw $exception;
        }
    }

    private static function hasArrayShapeOrList(UnionType $union_type): bool
    {
        foreach ($union_type->getTypeSet() as $type) {
            if ($type instanceof ArrayShapeType || $type instanceof ListType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the union type that's the result of accessing the node's dimension on the node's expression $union_type.
     *
     * Precondition: $union_type has array shape types or list types.
     */
    private function resolveArrayShapeElementTypes(Node $node, UnionType $union_type): ?UnionType
    {
        $dim_node = $node->children['dim'];
        $dim_value = $dim_node instanceof Node ? (new ContextNode($this->code_base, $this->context, $dim_node))->getEquivalentPHPScalarValue() : $dim_node;
        // TODO: detect and warn about null
        $has_non_empty_array = false;
        $check_invalid_dim = !($node->flags & self::FLAG_IGNORE_NULLABLE);
        if ($check_invalid_dim && $union_type->hasRealTypeSet()) {
            foreach ($union_type->getRealTypeSet() as $type) {
                if (!$type->isPossiblyTruthy()) {
                    if ($type instanceof LiteralStringType && \strlen($type->getValue()) > 0) {
                        $has_non_empty_array = true;
                        break;
                    }
                    continue;
                }
                if ($type instanceof IterableType || $type instanceof MixedType) {
                    $has_non_empty_array = true;
                    break;
                }
            }
            if (!$has_non_empty_array) {
                $exception = new IssueException(
                    Issue::fromType(Issue::TypeInvalidDimOffset)(
                        $this->context->getFile(),
                        $dim_node->lineno ?? $node->lineno,
                        [
                            is_scalar($dim_value) ? StringUtil::jsonEncode($dim_value) : ASTReverter::toShortString($dim_value),
                            ASTReverter::toShortString($node->children['expr']),
                            (string)$union_type
                        ]
                    )
                );
                if ($this->should_catch_issue_exception) {
                    Issue::maybeEmitInstance($this->code_base, $this->context, $exception->getIssueInstance());
                    return null;
                } else {
                    throw $exception;
                }
            }
        }
        if (!is_scalar($dim_value) && $dim_value !== null) {
            return null;
        }

        $resulting_element_type = self::resolveArrayShapeElementTypesForOffset($union_type, $dim_value, false, $this->code_base);

        if ($resulting_element_type === null) {
            return null;
        }
        if ($resulting_element_type === false) {
            // XXX not sure what to do here. For now, just return null and only warn in cases where requested to.
            if ($check_invalid_dim) {
                $exception = new IssueException(
                    Issue::fromType(Issue::TypeInvalidDimOffset)(
                        $this->context->getFile(),
                        $dim_node->lineno ?? $node->lineno,
                        [StringUtil::jsonEncode($dim_value), ASTReverter::toShortString($node->children['expr']), (string)$union_type]
                    )
                );
                if ($this->should_catch_issue_exception) {
                    Issue::maybeEmitInstance($this->code_base, $this->context, $exception->getIssueInstance());
                } else {
                    throw $exception;
                }
            }
            // $union_type is exclusively array shape types, but those don't contain the field $dim_value.
            // It's undefined (which becomes null)
            if (self::couldRealTypesHaveKey($union_type->getRealTypeSet(), $dim_value)) {
                return NullType::instance(false)->asPHPDocUnionType();
            }
            return NullType::instance(false)->asRealUnionType();
        }
        return $resulting_element_type;
    }

    /**
     * @param list<Type> $real_type_set
     * @param int|string|float|bool|null $dim_value a scalar dimension.
     */
    private static function couldRealTypesHaveKey(array $real_type_set, $dim_value): bool
    {
        if (\is_float($dim_value)) {
            $dim_value = (int)$dim_value;
        }
        foreach ($real_type_set as $type) {
            if ($type instanceof ArrayShapeType) {
                // @phan-suppress-next-line PhanTypeMismatchDimFetchNullable this is deliberate to emulate php's runtime behavior
                $element_type = $type->getFieldTypes()[$dim_value] ?? null;
                if (\is_object($element_type)) {
                    return true;
                }
            } elseif ($type instanceof ListType) {
                $filtered = \is_int($dim_value) ? $dim_value : \filter_var($dim_value, \FILTER_VALIDATE_INT);
                if (\is_int($filtered) && $filtered >= 0) {
                    return true;
                }
            } else {
                return true;
            }
        }
        return \count($real_type_set) === 0;
    }

    /**
     * @param UnionType $union_type a union type with at least one top-level array shape type
     * @param int|string|float|bool|null $dim_value a scalar dimension. TODO: Warn about null?
     * @return ?UnionType|?false
     *  returns false if there the offset was invalid and there are no ways to get that offset
     *  returns null if the dim_value offset could not be found, but there were other generic array types
     */
    public static function resolveArrayShapeElementTypesForOffset(UnionType $union_type, $dim_value, bool $is_computing_real_type_set, CodeBase $code_base)
    {
        /**
         * @var bool $has_non_array_shape_type this will be true if there are types that support array access
         *           but have unknown array shapes in $union_type
         */
        $has_generic_array = false;
        $has_string = false;
        $resulting_element_type = null;
        foreach ($union_type->getTypeSet() as $type) {
            if (!($type instanceof ArrayShapeType)) {
                if ($type instanceof StringType) {
                    $has_string = true;
                    if (\is_int($dim_value) || \filter_var($dim_value, \FILTER_VALIDATE_INT) !== false) {
                        // If we request a string offset from a string, that's not valid. Only accept integer dimensions as valid.
                        // in php, indices of strings can be negative
                        if ($resulting_element_type instanceof UnionType) {
                            $resulting_element_type = $resulting_element_type->withType(StringType::instance(false));
                        } else {
                            $resulting_element_type = StringType::instance(false)->asPHPDocUnionType();
                        }
                    } else {
                        // TODO: Warn about string indices of strings?
                    }
                } elseif ($type->isArrayLike($code_base) || $type->isObject() || $type instanceof MixedType) {
                    if ($type instanceof ListType && (!\is_numeric($dim_value) || $dim_value < 0)) {
                        continue;
                    }
                    if ($is_computing_real_type_set) {
                        // Avoid false positives for real type checking.
                        // TODO: Improve handling for GenericArrayType, strings, etc.
                        return null;
                    }
                    // TODO: Could be more precise about check for ArrayAccess
                    $has_generic_array = true;
                    continue;
                }
                continue;
            }
            if (\is_float($dim_value)) {
                $dim_value = (int)$dim_value;
            }
            // @phan-suppress-next-line PhanTypeMismatchDimFetchNullable this is deliberate to emulate php's runtime behavior
            $element_type = $type->getFieldTypes()[$dim_value] ?? null;
            if ($element_type !== null) {
                // $element_type may be non-null but $element_type->isEmpty() may be true.
                // So, we use null to indicate failure below
                if ($resulting_element_type instanceof UnionType) {
                    $resulting_element_type = $resulting_element_type->withUnionType($element_type);
                } else {
                    $resulting_element_type = $element_type;
                }
            }
        }
        if ($resulting_element_type === null) {
            if (!$has_string && !$has_generic_array) {
                // This is exclusively array shape types.
                // Return false to indicate that the offset doesn't exist in any of those array shape types.
                return false;
            }
            return null;
        }
        if ($has_string || $has_generic_array) {
            if ($has_string && $has_generic_array) {
                return null;
            }
            if ($resulting_element_type->hasRealTypeSet()) {
                $resulting_element_type = UnionType::of(
                    $resulting_element_type->getTypeSet(),
                    \array_map(static function (Type $type): Type {
                        return $type->withIsNullable(true);
                    }, $resulting_element_type->getRealTypeSet())
                );
            }
        }
        if (!$resulting_element_type->containsNullableOrUndefined() && $union_type->containsNullableOrUndefined()) {
            // Here, this uses Foo|null instead of ?Foo to only warn when strict types are used.
            $resulting_element_type = $resulting_element_type->withType(NullType::instance(false));
        }
        if (!$is_computing_real_type_set) {
            $resulting_real_element_type = self::resolveArrayShapeElementTypesForOffset($union_type->getRealUnionType(), $dim_value, true, $code_base);
            return $resulting_element_type->withRealTypeSet(
                \is_object($resulting_real_element_type) ? $resulting_real_element_type->getRealTypeSet() : []
            );
        }
        return $resulting_element_type;
    }

    /**
     * Visit a node with kind `\ast\AST_UNPACK`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node (the type of a value OF the unpacked iterable)
     *
     * @throws IssueException
     * if the unpack is on an invalid expression
     * @suppress PhanUndeclaredProperty
     */
    public function visitUnpack(Node $node): UnionType
    {
        return $this->analyzeUnpack($node, isset($node->is_in_array))[0];
    }

    /**
     * Visit a node with kind `\ast\AST_UNPACK`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @param bool $is_array_spread
     * If true, this is the array spread operator,
     * which tolerates integers that aren't consecutive.
     *
     * @return array{0:UnionType, 1:bool}
     * The set of types that are possibly produced by the
     * given node (the type of a value OF the unpacked iterable),
     * as well as whether this is likely to contain non-integer keys (imperfect check).
     *
     * @throws IssueException
     * if the unpack is on an invalid expression
     */
    private function analyzeUnpack(Node $node, bool $is_array_spread): array
    {
        $union_type = self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        )->withStaticResolvedInContext($this->context);

        if ($union_type->isEmpty()) {
            return [$union_type, false];
        }

        // Figure out what the types of accessed array
        // elements would be
        // TODO: Account for Traversable once there are generics for Traversable
        // TODO: Warn about possibly invalid unpack (e.g. nullable)
        $generic_types = $union_type->iterableValueUnionType($this->code_base);

        // If we have generics, we're all set
        try {
            if ($generic_types->isEmpty()) {
                if (!$union_type->hasIterable($this->code_base) && !$union_type->hasTypeMatchingCallback(static function (Type $type): bool {
                    return !$type->isNullableLabeled() && $type instanceof MixedType;
                })) {
                    throw new IssueException(
                        Issue::fromType(Issue::TypeMismatchUnpackValue)(
                            $this->context->getFile(),
                            $node->lineno,
                            [(string)$union_type]
                        )
                    );
                }
                return [$generic_types, false];
            }
            $this->checkInvalidUnpackKeyType($node, $union_type, $is_array_spread);
            foreach ($union_type->iterableKeyUnionType($this->code_base)->getTypeSet() as $key_type) {
                if ($key_type instanceof StringType) {
                    return [$generic_types, true];
                }
            }
        } catch (IssueException $exception) {
            Issue::maybeEmitInstance($this->code_base, $this->context, $exception->getIssueInstance());
            return [$generic_types, true];
        }
        return [$generic_types, false];
    }

    private function checkInvalidUnpackKeyType(Node $node, UnionType $union_type, bool $is_array_spread): void
    {
        $is_invalid_because_associative = false;
        $minimum_target_php_version_id = Config::get_closest_minimum_target_php_version_id();
        // Treat foo(...$associativeArgs) as invalid unless the minimum target php version is 8.0 (i.e. array unpacking is supported)
        if (!$is_array_spread && $minimum_target_php_version_id < 80000) {
            foreach ($union_type->getTypeSet() as $type) {
                if ($type->isIterable($this->code_base)) {
                    if ($type instanceof AssociativeArrayType) {
                        $is_invalid_because_associative = true;
                    } else {
                        $is_invalid_because_associative = false;
                        break;
                    }
                }
            }
        }
        $key_type = $union_type->iterableKeyUnionType($this->code_base);
        // Check that this is possibly valid, e.g. array<int, mixed>, Generator<int, mixed>, or iterable<int, mixed>
        // TODO: Warn if key_type contains nullable types (excluding VoidType)
        // TODO: Warn about union types that are partially invalid.
        if ($is_invalid_because_associative || (!$key_type->isEmpty() && !$key_type->hasTypeMatchingCallback(static function (Type $type) use ($minimum_target_php_version_id, $is_array_spread): bool {
            if ($type instanceof IntType || $type instanceof MixedType) {
                return true;
            }
            if ($type instanceof StringType) {
                // TODO: Forbid invalid parameter identifiers such as 'foo-bar' in the overall array shape?
                return ($is_array_spread ? $minimum_target_php_version_id >= 80100 : $minimum_target_php_version_id >= 80000);
            }
            return false;
        }))) {
            if (Config::get_closest_target_php_version_id() < 80100) {
                throw new IssueException(
                    Issue::fromType($is_array_spread ? Issue::TypeMismatchUnpackKeyArraySpread : Issue::TypeMismatchUnpackKey)(
                        $this->context->getFile(),
                        $node->lineno,
                        [(string)$union_type, $key_type]
                    )
                );
            }
        }
    }

    /**
     * Visit a node with kind `\ast\AST_CLOSURE`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitClosure(Node $node): UnionType
    {
        // The type of a closure is the fqsen pointing
        // at its definition
        $closure_fqsen =
            FullyQualifiedFunctionName::fromClosureInContext(
                $this->context,
                $node
            );

        if ($this->code_base->hasFunctionWithFQSEN($closure_fqsen)) {
            $func = $this->code_base->getFunctionByFQSEN($closure_fqsen);
        } else {
            $func = null;
        }

        return ClosureType::instanceWithClosureFQSEN(
            $closure_fqsen,
            $func
        )->asRealUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_ARROW_FUNC`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitArrowFunc(Node $node): UnionType
    {
        return $this->visitClosure($node);
    }

    /**
     * Visit a node with kind `\ast\AST_VAR`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws IssueException
     * if variable is undefined and being fetched
     */
    public function visitVar(Node $node): UnionType
    {
        // $$var or ${...} (whose idea was that anyway?)
        $name_node = $node->children['name'];
        if (($name_node instanceof Node)) {
            // This is nonsense. Give up.
            $name_node_type = $this->__invoke($name_node);
            static $int_or_string_type;
            if ($int_or_string_type === null) {
                $int_or_string_type = UnionType::fromFullyQualifiedPHPDocString('int|string|null');
            }
            if (!$name_node_type->canCastToUnionType($int_or_string_type, $this->code_base)) {
                Issue::maybeEmit($this->code_base, $this->context, Issue::TypeSuspiciousIndirectVariable, $name_node->lineno, (string)$name_node_type);
                return MixedType::instance(false)->asPHPDocUnionType();
            }
            $name_node = $name_node_type->asSingleScalarValueOrNull();
            if ($name_node === null) {
                return MixedType::instance(false)->asPHPDocUnionType();
            }
            // fall through
        }

        // foo(${42}) is technically valid PHP code, avoid TypeError
        $variable_name =
            (string)$name_node;

        if ($this->context->getScope()->hasVariableWithName($variable_name)) {
            $variable = $this->context->getScope()->getVariableByName(
                $variable_name
            );
            $union_type = $variable->getUnionType();
            if ($union_type->isPossiblyUndefined()) {
                if ($node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF) {
                    if ($this->context->isInGlobalScope()) {
                        $union_type = $union_type->eraseRealTypeSet();
                    }
                    return $union_type->convertUndefinedToNullable();
                }
                if ($this->context->isInGlobalScope()) {
                    $union_type = $union_type->eraseRealTypeSet();
                    if ($this->should_catch_issue_exception) {
                        if (!Config::getValue('ignore_undeclared_variables_in_global_scope')) {
                            $this->emitIssue(
                                Issue::PossiblyUndeclaredGlobalVariable,
                                $node->lineno,
                                $variable_name
                            );
                        }
                    }
                } else {
                    if ($this->should_catch_issue_exception) {
                        $this->emitIssue(
                            Issue::PossiblyUndeclaredVariable,
                            $node->lineno,
                            $variable_name
                        );
                    }
                }
            }

            return $union_type;
        }
        if (Variable::isHardcodedVariableInScopeWithName($variable_name, $this->context->isInGlobalScope())) {
            // @phan-suppress-next-line PhanTypeMismatchReturnNullable variable existence was checked
            return Variable::getUnionTypeOfHardcodedGlobalVariableWithName($variable_name);
        }
        if ($node->flags & PhanAnnotationAdder::FLAG_IGNORE_UNDEF) {
            if (!$this->context->isInGlobalScope()) {
                if ($this->should_catch_issue_exception && !(($node->flags & PhanAnnotationAdder::FLAG_INITIALIZES) && $this->context->isInLoop())) {
                    // Warn about `$var ??= expr;`, except when it's done in a loop.
                    $this->emitIssueWithSuggestion(
                        Variable::chooseIssueForUndeclaredVariable($this->context, $variable_name),
                        $node->lineno,
                        [$variable_name],
                        IssueFixSuggester::suggestVariableTypoFix($this->code_base, $this->context, $variable_name)
                    );
                }
                if ($variable_name === 'this') {
                    return ObjectType::instance(false)->asRealUnionType();
                }
                // Be more certain that unknown variables are not set inside of function scopes than the global scope.
                return NullType::instance(false)->asRealUnionType();
            }
            if ($variable_name === 'this') {
                return ObjectType::instance(false)->asRealUnionType();
            }
            return NullType::instance(false)->asPHPDocUnionType();
        }

        if (!($this->context->isInGlobalScope() && Config::getValue('ignore_undeclared_variables_in_global_scope'))) {
            if (!$this->should_catch_issue_exception) {
                throw new IssueException(
                    Issue::fromType(Variable::chooseIssueForUndeclaredVariable($this->context, $variable_name))(
                        $this->context->getFile(),
                        $node->lineno,
                        [$variable_name],
                        IssueFixSuggester::suggestVariableTypoFix($this->code_base, $this->context, $variable_name)
                    )
                );
            }
            Issue::maybeEmitWithParameters(
                $this->code_base,
                $this->context,
                Variable::chooseIssueForUndeclaredVariable($this->context, $variable_name),
                $node->lineno,
                [$variable_name],
                IssueFixSuggester::suggestVariableTypoFix($this->code_base, $this->context, $variable_name)
            );
        }
        if ($variable_name === 'this') {
            return ObjectType::instance(false)->asRealUnionType();
        }

        if (!$this->context->isInGlobalScope()) {
            if (!$this->context->isInLoop()) {
                return NullType::instance(false)->asRealUnionType()->withIsDefinitelyUndefined();
            }
            return NullType::instance(false)->asRealUnionType();
        }

        return UnionType::empty();
    }

    /**
     * Visit a node with kind `\ast\AST_ENCAPS_LIST`
     *
     * @param Node $node (@phan-unused-param)
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitEncapsList(Node $node): UnionType
    {
        $result = '';
        foreach ($node->children as $part) {
            $part_string = $part instanceof Node ? UnionTypeVisitor::unionTypeFromNode(
                $this->code_base,
                $this->context,
                $part,
                $this->should_catch_issue_exception
            )->asSingleScalarValueOrNullOrSelf() : $part;
            if (\is_object($part_string)) {
                return StringType::instance(false)->asRealUnionType();
            }
            $result .= $part_string;
        }
        return LiteralStringType::instanceForValue($result, false)->asRealUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_CONST`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitConst(Node $node): UnionType
    {
        // Figure out the name of the constant if it's
        // a string.
        $constant_name = $node->children['name']->children['name'] ?? '';

        // If the constant is referring to the current
        // class, return that as a type
        if (Type::isSelfTypeString($constant_name) || Type::isStaticTypeString($constant_name)) {
            return Type::fromStringInContext($constant_name, $this->context, Type::FROM_NODE, $this->code_base)->asRealUnionType();
        }

        try {
            $constant = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getConst();
        } catch (IssueException $exception) {
            Issue::maybeEmitInstance(
                $this->code_base,
                $this->context,
                $exception->getIssueInstance()
            );
            return UnionType::empty();
        }

        return $constant->getUnionType();
    }

    /**
     * @throws UnanalyzableException
     */
    public function visitClass(Node $node): UnionType
    {
        if ($node->flags & ast\flags\CLASS_ANONYMOUS) {
            $class_name =
                (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $node
                ))->getUnqualifiedNameForAnonymousClass();
        } else {
            $class_name = (string)$node->children['name'];
        }

        if ($class_name === '') {
            // Should only occur with --use-fallback-parser
            throw new UnanalyzableException($node, "Class name cannot be empty");
        }

        // @phan-suppress-next-line PhanThrowTypeMismatchForCall
        return FullyQualifiedClassName::fromStringInContext(
            $class_name,
            $this->context
        )->asType()->asRealUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_CLASS_CONST`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the constant
     */
    public function visitClassConst(Node $node): UnionType
    {
        try {
            $constant = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getClassConst();
            $union_type = $constant->getUnionType();
            if ($constant->isFinal()) {
                return $union_type;
            }
            $class_node = $node->children['class'];
            if (!$class_node instanceof Node || $class_node->kind !== ast\AST_NAME) {
                // ignore nonsense like (0)::class, and dynamic accesses such as $var::CLASS
                return $union_type->eraseRealTypeSet();
            }
            if (\strcasecmp($class_node->children['name'], 'static') === 0) {
                if ($this->context->isInClassScope() && $this->context->getClassInScope($this->code_base)->isFinal()) {
                    // static::X should be treated like self::X in a final class.
                    return $union_type;
                }
                return $union_type->eraseRealTypeSet();
            }
            return $union_type;
        } catch (NodeException $_) {
            // ignore, this should warn elsewhere
        }

        return UnionType::empty();
    }

    /**
     * Visit a node with kind `\ast\AST_CLASS_NAME`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws IssueException
     * An exception is thrown if we can't find the constant
     */
    public function visitClassName(Node $node): UnionType
    {
        $class_node = $node->children['class'];
        try {
            $class_list = (new ContextNode(
                $this->code_base,
                $this->context,
                $class_node
            ))->getClassList(false, ContextNode::CLASS_LIST_ACCEPT_OBJECT_OR_CLASS_NAME);
        } catch (IssueException $exception) {
            if ($this->should_catch_issue_exception) {
                Issue::maybeEmitInstance($this->code_base, $this->context, $exception->getIssueInstance());
                return ClassStringType::instance(false)->asRealUnionType();
            }
            throw $exception;
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            // We might still be in the parse phase.
            // Throw the same IssueException that would be thrown in Phan 1 and let the caller decide how to handle this.
            $new_exception = new IssueException(
                Issue::fromType(Issue::UndeclaredClassReference)(
                    $this->context->getFile(),
                    $node->lineno,
                    [(string)$exception_fqsen],
                    IssueFixSuggester::suggestSimilarClassForGenericFQSEN($this->code_base, $this->context, $exception_fqsen)
                )
            );
            if ($this->should_catch_issue_exception) {
                Issue::maybeEmitInstance($this->code_base, $this->context, $new_exception->getIssueInstance());
                return LiteralStringType::instanceForValue(
                    \ltrim($exception_fqsen->__toString(), '\\'),
                    false
                )->asRealUnionType();
            }
            throw $new_exception;
        }
        if (!$class_list) {
            return ClassStringType::instance(false)->asRealUnionType();
        }
        // Return the first class FQSEN
        $types = [];
        $name = $class_node->children['name'] ?? null;
        foreach ($class_list as $class) {
            $types[] = LiteralStringType::instanceForValue(
                \ltrim($class->getFQSEN()->__toString(), '\\'),
                false
            );
        }
        if (\is_string($name) && \strcasecmp($name, 'static') === 0 && (!isset($class) || !$class->isFinal())) {
            return UnionType::of($types, [ClassStringType::instance(false)]);
        }
        return UnionType::of($types, $types);
    }

    /**
     * Visit a node with kind `\ast\AST_NULLSAFE_PROP`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     * @override
     */
    public function visitNullsafeProp(Node $node): UnionType
    {
        $expr_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        )->getRealUnionType();
        $result = $this->analyzeProp($node, false);
        if ($expr_type->isEmpty()) {
            return $result->nullableClone();
        }
        if ($expr_type->isNull()) {
            return NullType::instance(false)->asRealUnionType();
        }
        if ($expr_type->containsNullableOrUndefined()) {
            return $result->nullableClone();
        }
        return $result;
    }

    /**
     * Visit a node with kind `\ast\AST_PROP`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitProp(Node $node): UnionType
    {
        return $this->analyzeProp($node, false);
    }

    /**
     * Analyzes a node with kind `\ast\AST_PROP` or `\ast\AST_STATIC_PROP`
     *
     * @param Node $node
     * The instance/static property access node.
     *
     * @param bool $is_static
     * True if this is a static property fetch,
     * false if this is an instance property fetch.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    private function analyzeProp(Node $node, bool $is_static): UnionType
    {
        // Either expr(instance) or class(static) is set
        $expr_node = $node->children['expr'] ?? null;
        try {
            $property = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getProperty($is_static);

            if ($property->isWriteOnly()) {
                $this->emitIssue(
                    $property->isFromPHPDoc() ? Issue::AccessWriteOnlyMagicProperty : Issue::AccessWriteOnlyProperty,
                    $node->lineno,
                    $property->asPropertyFQSENString(),
                    $property->getContext()->getFile(),
                    $property->getContext()->getLineNumberStart()
                );
            }

            if ($expr_node instanceof Node &&
                    $expr_node->kind === ast\AST_VAR &&
                    $expr_node->children['name'] === 'this'
            ) {
                $override_union_type = $this->context->getThisPropertyIfOverridden($property->getName());
                if ($override_union_type) {
                    $this->warnIfPossiblyUndefinedProperty($node, $property->getName(), $override_union_type);
                    // There was an earlier assignment in scope such as `$this->prop = 2;`
                    return $override_union_type;
                }
            }

            $union_type = $property->getUnionType()->withStaticResolvedInContext($property->getContext());

            // Map template types to concrete types
            if ($union_type->hasTemplateTypeRecursive()) {
                // Get the type of the object calling the property
                $expression_type = UnionTypeVisitor::unionTypeFromNode(
                    $this->code_base,
                    $this->context,
                    $expr_node,
                    $this->should_catch_issue_exception
                );

                $union_type = $union_type->withTemplateParameterTypeMap(
                    $expression_type->getTemplateParameterTypeMap($this->code_base)
                );

                return $union_type;
            } elseif (!$is_static) {
                // Inherit any new additional inferred union types from the declaring class,
                // unless the property type has template types.
                $defining_fqsen = $property->getDefiningFQSEN();
                if ($property->getFQSEN() !== $defining_fqsen) {
                    if ($this->code_base->hasPropertyWithFQSEN($defining_fqsen)) {
                        $declaring_union_type = $this->code_base->getPropertyByFQSEN($defining_fqsen)->getUnionType();
                        if ($declaring_union_type !== $union_type && !$declaring_union_type->hasTemplateTypeRecursive()) {
                            $union_type = $union_type->withUnionType($declaring_union_type);
                        }
                    }
                }
            }

            if ($union_type->isEmptyArrayShape() && $property->getPHPDocUnionType()->isEmpty()) {
                return UnionType::of(
                    [ArrayType::instance($union_type->containsNullable())],
                    $property->getRealUnionType()->getTypeSet()
                );
            }
            return $union_type;
        } catch (IssueException $exception) {
            Issue::maybeEmitInstance(
                $this->code_base,
                $this->context,
                $exception->getIssueInstance()
            );
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            $suggestion = null;
            $property_name = $node->children['prop'];
            if ($exception_fqsen instanceof FullyQualifiedClassName && $this->code_base->hasClassWithFQSEN($exception_fqsen)) {
                $suggestion_class = $this->code_base->getClassByFQSEN($exception_fqsen);
                $suggestion = IssueFixSuggester::suggestSimilarProperty(
                    $this->code_base,
                    $this->context,
                    $suggestion_class,
                    $property_name,
                    false
                );
            }
            $this->emitIssueWithSuggestion(
                Issue::UndeclaredProperty,
                $node->lineno,
                ["{$exception_fqsen}->{$property_name}"],
                $suggestion
            );
        } catch (UnanalyzableMagicPropertyException $exception) {
            $class = $exception->getClass();
            return $class->getMethodByName($this->code_base, '__get')->getUnionType();
        } catch (NodeException $_) {
            // Swallow it. There are some constructs that we
            // just can't figure out.
        }
        $property_name = $property_name ?? $node->children['prop'];
        if (\is_string($property_name) && $expr_node instanceof Node &&
                $expr_node->kind === ast\AST_VAR &&
                $expr_node->children['name'] === 'this'
        ) {
            $override_union_type = $this->context->getThisPropertyIfOverridden($property_name);
            if ($override_union_type) {
                $this->warnIfPossiblyUndefinedProperty($node, $property_name, $override_union_type);
                // There was an earlier expression such as `$this->prop = 2;`
                // fwrite(STDERR, "Saw override '$override_union_type' for $property\n");
                return $override_union_type;
            }
        }

        return UnionType::empty();
    }

    private function warnIfPossiblyUndefinedProperty(Node $node, string $prop_name, UnionType $union_type): void
    {
        if (!$union_type->isPossiblyUndefined()) {
            return;
        }
        $this->emitIssue(
            Issue::PossiblyUnsetPropertyOfThis,
            $node->lineno,
            '$this->' . $prop_name
        );
    }

    /**
     * Visit a node with kind `\ast\AST_STATIC_PROP`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitStaticProp(Node $node): UnionType
    {
        return $this->analyzeProp($node, true);
    }


    /**
     * Visit a node with kind `\ast\AST_CALL`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws FQSENException if the fqsen for the called function is empty/invalid
     */
    public function visitCall(Node $node): UnionType
    {
        $expression = $node->children['expr'];
        $function_list_generator = (new ContextNode(
            $this->code_base,
            $this->context,
            $expression
        ))->getFunctionFromNode(true);

        $possible_types = null;
        foreach ($function_list_generator as $function) {
            $function->analyzeReturnTypes($this->code_base);  // For daemon/server mode, call this to consistently ensure accurate return types.

            $function_types = $this->getDependentReturnTypeOfCall($function, $node);
            if ($possible_types) {
                '@phan-var UnionType $possible_types';
                $possible_types = $possible_types->withUnionType($function_types);
            } else {
                $possible_types = $function_types;
            }
        }

        return $possible_types ?? UnionType::empty();
    }

    /**
     * @return UnionType - the union type of the result of the call, or of the closure generated by first-class callable conversion
     */
    private function getDependentReturnTypeOfCall(FunctionInterface $function, Node $node): UnionType
    {
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            if ($function instanceof ClosureDeclarationType) {
                return $function->asRealUnionType();
            } else {
                return ClosureType::instanceWithClosureFQSEN($function->getFQSEN(), $function)->asRealUnionType();
            }
        } elseif ($function->hasDependentReturnType()) {
            return $function->getDependentReturnType($this->code_base, $this->context, $node->children['args']->children);
        }
        return $function->getUnionType();
    }

    /**
     * Visit a node with kind `\ast\AST_STATIC_CALL`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitStaticCall(Node $node): UnionType
    {
        return $this->visitMethodCall($node);
    }

    /**
     * Visit a node with kind `\ast\AST_NULLSAFE_METHOD_CALL`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitNullsafeMethodCall(Node $node): UnionType
    {
        $expr_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        )->getRealUnionType();
        $result = $this->visitMethodCall($node);
        if ($result->isEmpty()) {
            return $result->nullableClone();
        }
        if ($expr_type->isNull()) {
            return NullType::instance(false)->asRealUnionType();
        }
        if ($expr_type->containsNullableOrUndefined()) {
            return $result->nullableClone();
        }
        return $result;
    }

    /**
     * Visit a node with kind `\ast\AST_METHOD_CALL`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitMethodCall(Node $node): UnionType
    {
        $method_name = $node->children['method'] ?? '';

        // Give up on any complicated nonsense where the
        // method name is a variable such as in
        // `$variable->$function_name()`.
        if ($method_name instanceof Node) {
            $method_name = $this->__invoke($method_name)->asSingleScalarValueOrNullOrSelf();
            if (!is_string($method_name)) {
                return UnionType::empty();
            }
        }

        // Method names can some times turn up being
        // other method calls.
        if (!is_string($method_name)) {
            $method_name = (string)$method_name;
        }

        try {
            $static_class_node = $node->children['class'] ?? null;
            $class_node = $static_class_node ?? $node->children['expr'];
            if (!($class_node instanceof Node)) {
                // E.g. `'string_literal'->method()`
                // Other places will also emit NonClassMethodCall for the same node
                $this->emitIssue(
                    Issue::NonClassMethodCall,
                    $node->lineno,
                    $method_name,
                    UnionTypeVisitor::unionTypeFromNode(
                        $this->code_base,
                        $this->context,
                        $class_node,
                        $this->should_catch_issue_exception
                    )
                );
                return UnionType::empty();
            }
            $combined_union_type = null;
            foreach ($this->classListFromNode($class_node) as $class) {
                if (!$class->hasMethodWithName($this->code_base, $method_name, true)) {
                    continue;
                }

                try {
                    $method = $class->getMethodByName(
                        $this->code_base,
                        $method_name
                    );
                    $method->analyzeReturnTypes($this->code_base);  // For daemon/server mode, call this to consistently ensure accurate return types.

                    if ($method->hasTemplateType()) {
                        try {
                            $method = $method->resolveTemplateType(
                                $this->code_base,
                                UnionTypeVisitor::unionTypeFromNode(
                                    $this->code_base,
                                    $this->context,
                                    $class_node,
                                    $this->should_catch_issue_exception
                                )
                            );
                        } catch (RecursionDepthException $_) {
                        }
                    }

                    $union_type = $this->getDependentReturnTypeOfCall($method, $node);

                    // Map template types to concrete types
                    // TODO: When the template types are part of the method doc comment, don't look it up in the class union type
                    if (isset($node->children['expr']) && $union_type->hasTemplateTypeRecursive()) {
                        // Get the type of the object calling the method
                        $expression_type = UnionTypeVisitor::unionTypeFromNode(
                            $this->code_base,
                            $this->context,
                            $node->children['expr'],
                            $this->should_catch_issue_exception
                        );

                        // Map template types to concrete types
                        $union_type = $union_type->withTemplateParameterTypeMap(
                            $expression_type->getTemplateParameterTypeMap($this->code_base)
                        );
                    }

                    // Resolve any references to `static` or `static[]`
                    if ($this->context->isInClassScope() &&
                        $static_class_node instanceof Node &&
                        $static_class_node->kind === ast\AST_NAME &&
                        \strcasecmp($static_class_node->children['name'], 'parent') === 0) {
                        // If parent::foo() returns `static`, then use the current class instead of the parent class
                        $union_type = $union_type->withStaticResolvedInContext($this->context);
                    } else {
                        $union_type = $union_type->withStaticResolvedInContext($class->getInternalContext());
                    }

                    if ($combined_union_type) {
                        '@phan-var UnionType $combined_union_type';
                        $combined_union_type = $combined_union_type->withUnionType($union_type);
                    } else {
                        $combined_union_type = $union_type;
                    }
                } catch (IssueException $_) {
                    continue;
                }
            }
        } catch (IssueException $_) {
            // Swallow it
        } catch (CodeBaseException $exception) {
            $exception_fqsen = $exception->getFQSEN();
            $this->emitIssueWithSuggestion(
                Issue::UndeclaredClassMethod,
                $node->lineno,
                [$method_name, (string)$exception->getFQSEN()],
                ($exception_fqsen instanceof FullyQualifiedClassName
                    ? IssueFixSuggester::suggestSimilarClassForMethod($this->code_base, $this->context, $exception_fqsen, $method_name, $node->kind === \ast\AST_STATIC_CALL)
                    : null)
            );
        }

        return $combined_union_type ?? UnionType::empty();
    }

    /**
     * Visit a node with kind `\ast\AST_ASSIGN`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitAssign(Node $node): UnionType
    {
        // XXX typed properties/references will change the type of the result from the right hand side
        return self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        );
    }

    /**
     * Visit a node with kind `\ast\AST_UNARY_OP`
     *
     * @param Node $node
     * A node of the type indicated by the method name that we'd
     * like to figure out the type that it produces.
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     */
    public function visitUnaryOp(Node $node): UnionType
    {
        $result = self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node->children['expr'],
            $this->should_catch_issue_exception
        );

        // Shortcut some easy operators
        $flags = $node->flags;
        if ($flags === \ast\flags\UNARY_BOOL_NOT) {
            return $result->applyUnaryNotOperator();
        }

        if ($flags === \ast\flags\UNARY_MINUS) {
            $this->warnAboutInvalidUnaryOp(
                $node,
                static function (Type $type): bool {
                    return $type->isValidNumericOperand();
                },
                $result,
                '-',
                Issue::TypeInvalidUnaryOperandNumeric
            );
            $new_result = $result->applyUnaryMinusOperator();
            return $new_result;
        } elseif ($flags === \ast\flags\UNARY_PLUS) {
            $this->warnAboutInvalidUnaryOp(
                $node,
                static function (Type $type): bool {
                    // NOTE: Don't be as strict because this is a way to cast to a number
                    return $type->isValidNumericOperand() || \get_class($type) === StringType::class;
                },
                $result,
                '+',
                Issue::TypeInvalidUnaryOperandNumeric
            );
            return $result->applyUnaryPlusOperator();
        } elseif ($flags === \ast\flags\UNARY_BITWISE_NOT) {
            $this->warnAboutInvalidUnaryOp(
                $node,
                static function (Type $type): bool {
                    // Adding $type instanceof StringType in case it becomes necessary later
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    return ($type->isValidNumericOperand() && $type->isValidBitwiseOperand()) || $type instanceof StringType;
                },
                $result,
                '~',
                Issue::TypeInvalidUnaryOperandBitwiseNot
            );
            return $result->applyUnaryBitwiseNotOperator();
        }
        // UNARY_SILENCE
        return $result;
    }

    /**
     * @param Node $node with type AST_BINARY_OP
     * @param Closure(Type):bool $is_valid_type
     */
    private function warnAboutInvalidUnaryOp(
        Node $node,
        Closure $is_valid_type,
        UnionType $type,
        string $operator,
        string $issue_type
    ): void {
        if ($type->isEmpty()) {
            return;
        }
        if (!$type->hasTypeMatchingCallback($is_valid_type)) {
            $this->emitIssue(
                $issue_type,
                $node->children['left']->lineno ?? $node->lineno,
                $operator,
                $type
            );
        }
    }

    /**
     * `print($str)` always returns 1.
     * See https://secure.php.net/manual/en/function.print.php#refsect1-function.print-returnvalues
     * @param Node $node @phan-unused-param
     */
    public function visitPrint(Node $node): UnionType
    {
        return LiteralIntType::instanceForValue(1, false)->asRealUnionType();
    }

    /**
     * @param Node $node
     * A node holding a class name
     *
     * @return UnionType
     * The set of types that are possibly produced by the
     * given node
     *
     * @throws IssueException
     * An exception is thrown if we can't find a class for
     * the given type
     */
    private function visitClassNameNode(Node $node): UnionType
    {
        $kind = $node->kind;
        // Anonymous class of form `new class { ... }`
        if ($kind === \ast\AST_CLASS
            && ($node->flags & \ast\flags\CLASS_ANONYMOUS)
        ) {
            // Generate a stable name for the anonymous class
            $anonymous_class_name =
                (new ContextNode(
                    $this->code_base,
                    $this->context,
                    $node
                ))->getUnqualifiedNameForAnonymousClass();

            // Turn that into a fully qualified name, and that into a union type
            // @phan-suppress-next-line PhanThrowTypeMismatchForCall
            $fqsen = FullyQualifiedClassName::fromStringInContext(
                $anonymous_class_name,
                $this->context
            );

            // Turn that into a union type
            return $fqsen->asType()->asRealUnionType();
        }

        // Things of the form `new $className()`, `new $obj()`, `new (foo())()`, etc.
        if ($kind !== \ast\AST_NAME) {
            return $this->classTypesForNonName($node);
        }

        // Get the name of the class
        $class_name = $node->children['name'];

        // If this is a straight-forward class name, recurse into the
        // class node and get its type
        if (Type::isStaticTypeString($class_name)) {
            return StaticType::instance(false)->asRealUnionType();
        }
        if (!Type::isSelfTypeString($class_name)) {
            // @phan-suppress-next-line PhanThrowTypeMismatchForCall assuming FQSENException won't be thrown optimistically for valid ASTs
            return self::unionTypeFromClassNode(
                $this->code_base,
                $this->context,
                $node
            );
        }

        // This node references `self` or `static`
        if (!$this->context->isInClassScope()) {
            $this->emitIssue(
                Issue::ContextNotObject,
                $node->lineno,
                $class_name
            );

            return UnionType::empty();
        }

        // Reference to a parent class
        if (\strcasecmp($class_name, 'parent') === 0) {
            $class = $this->context->getClassInScope(
                $this->code_base
            );

            $parent_type_option = $class->getParentTypeOption();
            if (!$parent_type_option->isDefined()) {
                $this->emitIssue(
                    Issue::ParentlessClass,
                    $node->lineno,
                    (string)$class->getFQSEN()
                );

                return UnionType::empty();
            }

            return $parent_type_option->get()->asRealUnionType();
        }

        return $this->context->getClassFQSEN()->asType()->asRealUnionType();
    }

    /**
     * @param Node $node @phan-unused-param
     * A node containing a throw expression.
     *
     * @return UnionType
     * `void` is as close as possible to `no-return` or `never` for types currently available in Phan.
     */
    public function visitThrow(Node $node): UnionType
    {
        return NeverType::instance(false)->asRealUnionType();
    }

    private function classTypesForNonName(Node $node): UnionType
    {
        $node_type = UnionTypeVisitor::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node,
            $this->should_catch_issue_exception
        );
        if ($node_type->isEmpty()) {
            return UnionType::empty();
        }
        $result = UnionType::empty();
        $is_valid = true;
        foreach ($node_type->getTypeSet() as $sub_type) {
            if ($sub_type instanceof LiteralStringType) {
                $value = $sub_type->getValue();
                if (!\preg_match('/\\\\?[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff\\\]*/', $value)) {
                    $is_valid = false;
                    continue;
                }
                try {
                    $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($value);
                } catch (FQSENException $e) {
                    $this->emitIssue(
                        $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                        $node->lineno,
                        $e->getFQSEN()
                    );
                    continue;
                }
                if (!$this->code_base->hasClassWithFQSEN($fqsen)) {
                    $is_valid = false;
                    continue;
                }
                $result = $result->withType($fqsen->asType());
            } elseif (\get_class($sub_type) === Type::class || $sub_type instanceof ClosureType || $sub_type instanceof StaticType) {
                $result = $result->withType($sub_type);
            } else {
                if ($sub_type instanceof StringType) {
                    if ($sub_type instanceof ClassStringType) {
                        $result = $result->withUnionType($sub_type->getClassUnionType());
                    }
                    continue;
                }
                if (!($sub_type instanceof MixedType)) {
                    $is_valid = false;
                }
            }
        }
        if ($result->isEmpty() && !$is_valid) {
            // See https://github.com/phan/phan/issues/1926 - `new $obj()` is valid PHP and documented in the manual.
            $this->emitIssue(
                Issue::TypeExpectedObjectOrClassName,
                $node->lineno,
                ASTReverter::toShortString($node),
                $node_type
            );
        }
        return $result;
    }

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param Node|mixed $node
     * The node which we'd like to determine the type of.
     *
     * @return UnionType
     * The UnionType of the class for the node representing
     * a usage of a class in the given Context within the given CodeBase
     *
     * @throws IssueException
     * An exception is thrown if we can't find a class for
     * the given type
     *
     * @throws FQSENException
     * An exception is thrown if we can find a class name,
     * but it is empty/invalid
     */
    public static function unionTypeFromClassNode(
        CodeBase $code_base,
        Context $context,
        $node
    ): UnionType {
        // If this is a list, build a union type by
        // recursively visiting the child nodes
        if ($node instanceof Node
            && $node->kind === \ast\AST_NAME_LIST
        ) {
            $union_type = UnionType::empty();
            foreach ($node->children as $child_node) {
                $union_type = $union_type->withUnionType(
                    self::unionTypeFromClassNode(
                        $code_base,
                        $context,
                        $child_node
                    )
                );
            }
            return $union_type;
        }

        // For simple nodes or very complicated nodes,
        // recurse
        if (!($node instanceof Node)
            || $node->kind !== \ast\AST_NAME
        ) {
            return self::unionTypeFromNode(
                $code_base,
                $context,
                $node
            );
        }

        $class_name = (string)$node->children['name'];

        if (\strcasecmp('parent', $class_name) === 0) {
            if (!$context->isInClassScope()) {
                throw new IssueException(
                    Issue::fromType(Issue::ContextNotObject)(
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart(),
                        [$class_name]
                    )
                );
            }

            $class = $context->getClassInScope($code_base);

            if ($class->isTrait()) {
                throw new IssueException(
                    Issue::fromType(Issue::TraitParentReference)(
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart(),
                        [(string)$context->getClassFQSEN() ]
                    )
                );
            }

            if (!$class->hasParentType()) {
                throw new IssueException(
                    Issue::fromType(Issue::ParentlessClass)(
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart(),
                        [ (string)$context->getClassFQSEN() ]
                    )
                );
            }

            $parent_class_fqsen = $class->getParentClassFQSEN();

            if (!$code_base->hasClassWithFQSEN($parent_class_fqsen)) {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredClass)(
                        $context->getFile(),
                        $node->lineno ?? $context->getLineNumberStart(),
                        [ (string)$parent_class_fqsen ],
                        IssueFixSuggester::suggestSimilarClass($code_base, $context, $parent_class_fqsen)
                    )
                );
            } else {
                $parent_class = $code_base->getClassByFQSEN(
                    $parent_class_fqsen
                );

                return $parent_class->getUnionType();
            }
        }

        // We're going to convert the class reference to a type

        // Check to see if the name is fully qualified
        if ($node->flags & \ast\flags\NAME_NOT_FQ) {
            self::checkValidClassFQSEN($context, $node, $class_name);
            $type = Type::fromStringInContext(
                $class_name,
                $context,
                Type::FROM_NODE,
                $code_base
            );
        } elseif ($node->flags & \ast\flags\NAME_RELATIVE) {
            // Relative to current namespace
            if (0 !== strpos($class_name, '\\')) {
                $class_name = '\\' . $class_name;
            }

            $type = Type::fromFullyQualifiedString(
                $context->getNamespace() . $class_name
            );
        } else {
            // Fully qualified
            if (0 !== strpos($class_name, '\\')) {
                $class_name = '\\' . $class_name;
            }

            self::checkValidClassFQSEN($context, $node, $class_name);
            $type = Type::fromFullyQualifiedString(
                $class_name
            );
        }

        return $type->asRealUnionType();
    }

    /**
     * @throws FQSENException if invalid
     */
    private static function checkValidClassFQSEN(Context $context, Node $node, string $class_name): void
    {
        // @phan-suppress-next-line PhanAccessClassConstantInternal
        if (\preg_match(FullyQualifiedGlobalStructuralElement::VALID_STRUCTURAL_ELEMENT_REGEX, $class_name)) {
            return;
        }
        throw new IssueException(
            Issue::fromType($class_name === '\\' ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike)(
                $context->getFile(),
                $node->lineno,
                [ $class_name ]
            )
        );
    }

    /**
     * @return \Generator|Clazz[]
     */
    public static function classListFromNodeAndContext(CodeBase $code_base, Context $context, Node $node)
    {
        return (new UnionTypeVisitor($code_base, $context, true))->classListFromNode($node);
    }

    /**
     * @phan-return \Generator<Clazz>
     * @return \Generator|Clazz[]
     * A list of classes associated with the given node
     *
     * @throws IssueException
     * An exception is thrown if we can't find a class for
     * the given type
     */
    private function classListFromNode(Node $node): \Generator
    {
        // Get the types associated with the node
        $union_type = self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node,
            $this->should_catch_issue_exception
        )->withStaticResolvedInContext($this->context);

        // Iterate over each viable class type to see if any
        // have the constant we're looking for
        foreach ($union_type->nonNativeTypes()->getUniqueFlattenedTypeSet() as $class_type) {
            if (!$class_type->isObjectWithKnownFQSEN()) {
                continue;
            }
            // Get the class FQSEN
            try {
                $class_fqsen = FullyQualifiedClassName::fromType($class_type);
            } catch (InvalidFQSENException $e) {
                throw new IssueException(
                    Issue::fromType($e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ (string)$class_type ]
                    )
                );
            }

            // See if the class exists
            if (!$this->code_base->hasClassWithFQSEN($class_fqsen)) {
                throw new IssueException(
                    Issue::fromType(Issue::UndeclaredClassReference)(
                        $this->context->getFile(),
                        $node->lineno,
                        [ (string)$class_fqsen ]
                    )
                );
            }

            yield $this->code_base->getClassByFQSEN($class_fqsen);
        }
    }

    /**
     * @param CodeBase $code_base
     * @param Context $context
     * @param int|string|float|Node $node the node to fetch CallableType instances for.
     * @param bool $log_error whether or not to log errors while searching @phan-unused-param
     * @return list<FunctionInterface>
     * TODO: use log_error
     */
    public static function functionLikeListFromNodeAndContext(CodeBase $code_base, Context $context, $node, bool $log_error): array
    {
        try {
            $function_fqsens = (new UnionTypeVisitor($code_base, $context, true))->functionLikeFQSENListFromNode($node);
        } catch (FQSENException $e) {
            Issue::maybeEmit(
                $code_base,
                $context,
                $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInCallable : Issue::InvalidFQSENInCallable,
                $context->getLineNumberStart(),
                $e->getFQSEN()
            );
            return [];
        } catch (\InvalidArgumentException $_) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::InvalidFQSENInCallable,
                $context->getLineNumberStart(),
                '(unknown)'
            );
            return [];
        }
        $functions = [];
        foreach ($function_fqsens as $fqsen) {
            if ($fqsen instanceof FullyQualifiedMethodName) {
                if (!$code_base->hasMethodWithFQSEN($fqsen)) {
                    // TODO: error PhanArrayMapClosure
                    continue;
                }
                $functions[] = $code_base->getMethodByFQSEN($fqsen);
            } else {
                if (!($fqsen instanceof FullyQualifiedFunctionName)) {
                    throw new TypeError('Expected fqsen to be a FullyQualifiedFunctionName or FullyQualifiedMethodName');
                }
                if (!$code_base->hasFunctionWithFQSEN($fqsen)) {
                    // TODO: error PhanArrayMapClosure
                    continue;
                }
                $functions[] = $code_base->getFunctionByFQSEN($fqsen);
            }
        }
        return $functions;
    }

    /**
     * Fetch known classes for a place where a class name was provided as a string or string expression.
     * Warn if this is an invalid class name.
     * @param \ast\Node|string|int|float $node
     * @return list<Clazz>
     */
    public static function classListFromClassNameNode(CodeBase $code_base, Context $context, $node): array
    {
        $results = [];
        $strings = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node)->asStringScalarValues();
        foreach ($strings as $string) {
            try {
                $fqsen = FullyQualifiedClassName::fromFullyQualifiedString($string);
            } catch (FQSENException $e) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                    $context->getLineNumberStart(),
                    $e->getFQSEN()
                );
                continue;
            } catch (\InvalidArgumentException $_) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::InvalidFQSENInClasslike,
                    $context->getLineNumberStart(),
                    '(unknown)'
                );
                continue;
            }
            if (!$code_base->hasClassWithFQSEN($fqsen)) {
                // TODO: Different issue type?
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::UndeclaredClassReference,
                    $context->getLineNumberStart(),
                    (string)$fqsen
                );
                continue;
            }
            $results[] = $code_base->getClassByFQSEN($fqsen);
        }
        return $results;
    }

    /**
     * @param CodeBase $code_base
     * @param Context $context
     * @param string|Node $node the node to fetch CallableType instances for.
     * @return list<FullyQualifiedFunctionLikeName>
     * @suppress PhanUnreferencedPublicMethod may be used in the future.
     */
    public static function functionLikeFQSENListFromNodeAndContext(CodeBase $code_base, Context $context, $node): array
    {
        return (new UnionTypeVisitor($code_base, $context, true))->functionLikeFQSENListFromNode($node);
    }

    /**
     * @param string|Node $class_or_expr
     * @param string $method_name
     *
     * @return list<FullyQualifiedMethodName>
     * A list of CallableTypes associated with the given node
     */
    private function methodFQSENListFromObjectAndMethodName($class_or_expr, string $method_name): array
    {
        $code_base = $this->code_base;
        $context = $this->context;

        $union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $class_or_expr, $this->should_catch_issue_exception);
        if ($union_type->isEmpty()) {
            return [];
        }
        $object_types = $union_type->objectTypes();
        if ($object_types->isEmpty()) {
            if (!$union_type->canCastToUnionType(StringType::instance(false)->asPHPDocUnionType(), $code_base)) {
                $this->emitIssue(
                    Issue::TypeInvalidCallableObjectOfMethod,
                    $context->getLineNumberStart(),
                    (string)$union_type,
                    $method_name
                );
            }
            return [];
        }
        $result_types = [];
        $class = null;
        foreach ($object_types->getTypeSet() as $object_type) {
            // TODO: support templates here.
            if ($object_type instanceof ObjectType || $object_type instanceof TemplateType) {
                continue;
            }
            $class_fqsen = FullyQualifiedClassName::fromType($object_type);
            if ($object_type instanceof StaticOrSelfType) {
                if (!$context->isInClassScope()) {
                    $this->emitIssue(
                        Issue::ContextNotObjectInCallable,
                        $context->getLineNumberStart(),
                        (string)$class_fqsen,
                        "$class_fqsen::$method_name"
                    );
                    continue;
                }
                $class_fqsen = $context->getClassFQSEN();
            }
            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                $this->emitIssue(
                    Issue::UndeclaredClassInCallable,
                    $context->getLineNumberStart(),
                    (string)$class_fqsen,
                    "$class_fqsen::$method_name"
                );
                continue;
            }
            $class = $code_base->getClassByFQSEN($class_fqsen);
            if (!$class->hasMethodWithName($code_base, $method_name, true)) {
                // emit error below
                continue;
            }
            $method_fqsen = FullyQualifiedMethodName::make(
                $class_fqsen,
                $method_name
            );
            $result_types[] = $method_fqsen;
        }
        if (\count($result_types) === 0 && $class instanceof Clazz) {
            // TODO: Include suggestion for method name
            $this->emitIssue(
                Issue::UndeclaredMethodInCallable,
                $context->getLineNumberStart(),
                $method_name,
                (string)$union_type
            );
        }
        return $result_types;
    }

    /**
     * @param string $class_name (may also be 'self', 'parent', or 'static')
     * @throws FQSENException
     */
    private function lookupClassOfCallableByName(string $class_name): ?FullyQualifiedClassName
    {
        switch (\strtolower($class_name)) {
            case 'self':
            case 'static':
                $context = $this->context;
                if (!$context->isInClassScope()) {
                    $this->emitIssue(
                        Issue::ContextNotObject,
                        $context->getLineNumberStart(),
                        \strtolower($class_name)
                    );
                    return null;
                }
                return $context->getClassFQSEN();
            case 'parent':
                $context = $this->context;
                if (!$context->isInClassScope()) {
                    $this->emitIssue(
                        Issue::ContextNotObject,
                        $context->getLineNumberStart(),
                        \strtolower($class_name)
                    );
                    return null;
                }
                $class = $context->getClassInScope($this->code_base);
                if ($class->isTrait()) {
                    $this->emitIssue(
                        Issue::TraitParentReference,
                        $context->getLineNumberStart(),
                        (string)$class->getFQSEN()
                    );
                    return null;
                }
                if (!$class->hasParentType()) {
                    $this->emitIssue(
                        Issue::ParentlessClass,
                        $context->getLineNumberStart(),
                        (string)$class->getFQSEN()
                    );
                    return null;
                }
                return $class->getParentClassFQSEN();  // may or may not exist.
            default:
                // TODO: Reject invalid/empty class names earlier
                return FullyQualifiedClassName::fromFullyQualifiedString($class_name);
        }
    }

    /**
     * @param ?string|?int|?float|?bool|?Node $method_name
     */
    private function emitDeprecatedPartiallySupportedCallable(string $class_name, $method_name): void
    {
        if (!is_string($method_name)) {
            $method_name = '(unknown)';
        }
        $class_name = \strtolower($class_name);
        $this->emitIssue(
            Issue::DeprecatedPartiallySupportedCallable,
            $this->context->getLineNumberStart(),
            "$class_name::$method_name",
            $class_name,
            \var_representation($method_name)
        );
    }
    /**
     * @param ?string|?int|?float|?bool|?Node $class_name
     */
    private function emitDeprecatedPartiallySupportedCallableAlternateScope($class_name, string $method_name): void
    {
        $this->emitIssue(
            Issue::DeprecatedPartiallySupportedCallableAlternateScope,
            $this->context->getLineNumberStart(),
            ASTReverter::toShortString($class_name),
            \var_representation($method_name)
        );
    }

    /**
     * @param ?string|?int|?float|?bool|?Node $method_name
     */
    private function emitNonObjectContextInCallableIssue(string $class_name, $method_name): void
    {
        if (!is_string($method_name)) {
            $method_name = '(unknown)';
        }
        $this->emitIssue(
            Issue::ContextNotObjectInCallable,
            $this->context->getLineNumberStart(),
            $class_name,
            "$class_name::$method_name"
        );
    }

    /**
     * @param string|Node $class_or_expr
     * @param string|Node $method_name
     *
     * @return list<FullyQualifiedMethodName>
     * A list of `FullyQualifiedMethodName`s associated with the given node
     */
    private function methodFQSENListFromParts($class_or_expr, $method_name, bool $from_array): array
    {
        $code_base = $this->code_base;
        $context = $this->context;

        if (!is_string($method_name)) {
            if (!($method_name instanceof Node)) {
                $method_name = UnionTypeVisitor::anyStringLiteralForNode($this->code_base, $this->context, $method_name);
            }
            $method_name = (new ContextNode($code_base, $context, $method_name))->getEquivalentPHPScalarValue();
            if (!is_string($method_name)) {
                $method_name_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $method_name, $this->should_catch_issue_exception);
                if (!$method_name_type->canCastToUnionType(StringType::instance(false)->asPHPDocUnionType(), $code_base)) {
                    Issue::maybeEmit(
                        $code_base,
                        $context,
                        Issue::TypeInvalidCallableMethodName,
                        $method_name->lineno ?? $context->getLineNumberStart(),
                        $method_name_type
                    );
                }
            }
        }
        if ($from_array && is_string($method_name) && strpos($method_name, '::') !== false) {
            $this->emitDeprecatedPartiallySupportedCallableAlternateScope($class_or_expr, $method_name);
        }
        try {
            if (is_string($class_or_expr)) {
                if (\in_array(\strtolower($class_or_expr), ['static', 'self', 'parent'], true)) {
                    $this->emitDeprecatedPartiallySupportedCallable($class_or_expr, $method_name);
                    // Allow 'static' but not '\static'
                    if (!$context->isInClassScope()) {
                        $this->emitNonObjectContextInCallableIssue($class_or_expr, $method_name);
                        return [];
                    }
                    $class_fqsen = $context->getClassFQSEN();
                } else {
                    $class_fqsen = $this->lookupClassOfCallableByName($class_or_expr);
                    if (!$class_fqsen) {
                        return [];
                    }
                }
            } else {
                $class_fqsen = (new ContextNode($code_base, $context, $class_or_expr))->resolveClassNameInContext();
                if (!$class_fqsen) {
                    if (!is_string($method_name)) {
                        return [];
                    }
                    return $this->methodFQSENListFromObjectAndMethodName($class_or_expr, $method_name);
                }
                if (\in_array(\strtolower($class_fqsen->getName()), ['static', 'self', 'parent'], true)) {
                    $this->emitDeprecatedPartiallySupportedCallable($class_fqsen->getName(), $method_name);
                    if (!$context->isInClassScope()) {
                        $this->emitNonObjectContextInCallableIssue((string)$class_fqsen, $method_name);
                        return [];
                    }
                    $class_fqsen = $context->getClassFQSEN();
                }
            }
        } catch (FQSENException $e) {
            $this->emitIssue(
                $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInClasslike : Issue::InvalidFQSENInClasslike,
                $context->getLineNumberStart(),
                $e->getFQSEN()
            );
            return [];
        }
        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
            $this->emitIssue(
                Issue::UndeclaredClassInCallable,
                $context->getLineNumberStart(),
                (string)$class_fqsen,
                "$class_fqsen::" . (is_string($method_name) ? $method_name : '(unknown)')
            );
            return [];
        }
        if (!is_string($method_name)) {
            return [];
        }
        $class = $code_base->getClassByFQSEN($class_fqsen);
        if (!$class->hasMethodWithName($code_base, $method_name, true)) {
            $this->emitIssue(
                Issue::UndeclaredStaticMethodInCallable,
                $context->getLineNumberStart(),
                "$class_fqsen::$method_name"
            );
            return [];
        }
        $method = $class->getMethodByName($code_base, $method_name);
        if (!$method->isStatic()) {
            $this->emitIssue(
                Issue::StaticCallToNonStatic,
                $context->getLineNumberStart(),
                (string)$method->getFQSEN(),
                $method->getFileRef()->getFile(),
                (string)$method->getFileRef()->getLineNumberStart()
            );
        }
        return [$method->getFQSEN()];
    }

    /**
     * @see ContextNode::getFunction() for a similar function
     * @return list<FullyQualifiedFunctionName>
     */
    private function functionFQSENListFromFunctionName(string $function_name): array
    {
        // TODO: Catch invalid code such as call_user_func('\\\\x\\\\y')
        try {
            $function_fqsen = FullyQualifiedFunctionName::fromFullyQualifiedString($function_name);
        } catch (FQSENException $e) {
            $this->emitIssue(
                $e instanceof EmptyFQSENException ? Issue::EmptyFQSENInCallable : Issue::InvalidFQSENInCallable,
                $this->context->getLineNumberStart(),
                $function_name
            );
            return [];
        }
        if (!$this->code_base->hasFunctionWithFQSEN($function_fqsen)) {
            $this->emitIssue(
                Issue::UndeclaredFunctionInCallable,
                $this->context->getLineNumberStart(),
                $function_name
            );
            return [];
        }
        return [$function_fqsen];
    }

    /**
     * @param string|Node $node
     *
     * @return list<FullyQualifiedFunctionLikeName>
     * A list of `FullyQualifiedFunctionLikeName`s associated with the given node
     *
     * @throws IssueException
     * An exception is thrown if we can't find a class for
     * the given type
     */
    private function functionLikeFQSENListFromNode($node): array
    {
        $orig_node = $node;
        if ($node instanceof Node) {
            $node = (new ContextNode($this->code_base, $this->context, $node))->getEquivalentPHPValue();
        }
        if (is_string($node)) {
            if (strpos($node, '::') !== false) {
                [$class_name, $method_name] = \explode('::', $node, 2);
                return $this->methodFQSENListFromParts($class_name, $method_name, false);
            }
            return $this->functionFQSENListFromFunctionName($node);
        }
        if (\is_array($node)) {
            if (\count($node) !== 2) {
                $this->emitIssue(
                    Issue::TypeInvalidCallableArraySize,
                    $orig_node->lineno ?? $this->context->getLineNumberStart(),
                    \count($node)
                );
                return [];
            }
            $i = 0;
            foreach ($node as $key => $_) {
                if ($key !== $i) {
                    $this->emitIssue(
                        Issue::TypeInvalidCallableArrayKey,
                        $orig_node->lineno ?? $this->context->getLineNumberStart(),
                        $i
                    );
                    return [];
                }
                $i++;
            }
            return $this->methodFQSENListFromParts($node[0], $node[1], true);
        }
        if (!($node instanceof Node)) {
            // TODO: Warn?
            return [];
        }

        // Get the types associated with the node
        $union_type = self::unionTypeFromNode(
            $this->code_base,
            $this->context,
            $node,
            $this->should_catch_issue_exception
        );

        $closure_types = [];
        foreach ($union_type->getTypeSet() as $type) {
            if ($type instanceof ClosureType && $type->hasKnownFQSEN()) {
                // TODO: Support class instances with __invoke()
                $fqsen = $type->asFQSEN();
                if (!($fqsen instanceof FullyQualifiedFunctionLikeName)) {
                    throw new AssertionError('Expected fqsen of closure to be a FullyQualifiedFunctionLikeName');
                }
                $closure_types[] = $fqsen;
            }
        }
        return $closure_types;
    }

    /**
     * @param CodeBase $code_base
     * @param Context $context
     * @param Node|string|float|int $node
     *
     * @return ?UnionType (Returns null when mixed)
     * TODO: Add an equivalent for Traversable and subclasses, once we have template support for Traversable<Key,T>
     */
    public static function unionTypeOfArrayKeyForNode(CodeBase $code_base, Context $context, $node): ?UnionType
    {
        $arg_type = UnionTypeVisitor::unionTypeFromNode($code_base, $context, $node);
        return self::arrayKeyUnionTypeOfUnionType($arg_type);
    }

    /**
     * @return ?UnionType (Returns null when mixed)
     * TODO: Add an equivalent for Traversable and subclasses, once we have template support for Traversable<Key,T>
     * TODO: Move into UnionType?
     */
    public static function arrayKeyUnionTypeOfUnionType(UnionType $union_type): ?UnionType
    {
        if ($union_type->isEmpty()) {
            return null;
        }
        static $int_type;
        static $string_type;
        static $int_or_string_type;
        if ($int_type === null) {
            $int_type = IntType::instance(false)->asPHPDocUnionType();
            $string_type = StringType::instance(false)->asPHPDocUnionType();
            $int_or_string_type = UnionType::fromFullyQualifiedPHPDocString('int|string');
        }
        $key_enum_type = GenericArrayType::keyTypeFromUnionTypeKeys($union_type);
        switch ($key_enum_type) {
            case GenericArrayType::KEY_INT:
                return $int_type;
            case GenericArrayType::KEY_STRING:
                return $string_type;
            default:
                foreach ($union_type->getTypeSet() as $type) {
                    // The exact class Type is potentially invalid (includes objects) but not the subclass NativeType.
                    // The subclass IterableType of Native type is invalid, but ArrayType is a valid subclass of IterableType.
                    // And we just ignore scalars.
                    // And mixed could be a Traversable.
                    // So, don't infer anything if the union type contains any instances of the four classes.
                    // TODO: Check the expanded union type instead of anything with a class of exactly Type, searching for Traversable?
                    if (\in_array(\get_class($type), [Type::class, IterableType::class, TemplateType::class, MixedType::class, NonEmptyMixedType::class], true)) {
                        return null;
                    }
                }
                return $int_or_string_type;
        }
    }

    /**
     * @param Node|array|string|bool|float|int|null $node
     * @return ?string - One of the values for the LiteralStringType, or null
     */
    public static function anyStringLiteralForNode(
        CodeBase $code_base,
        Context $context,
        $node
    ): ?string {
        if (!($node instanceof Node)) {
            return is_string($node) ? $node : null;
        }
        $node_type = self::unionTypeFromNode(
            $code_base,
            $context,
            $node
        );
        foreach ($node_type->getTypeSet() as $type) {
            if ($type instanceof LiteralStringType) {
                // Arbitrarily return only the first value.
                // TODO: Rewrite code using this to work with lists of possible values?
                return $type->getValue();
            }
        }
        return null;
    }

    // Precondition: minimum_target_php_version_id < 70100
    private function analyzeNegativeStringOffsetCompatibility(Node $node, UnionType $dim_type): void
    {
        $dim_value = $dim_type->asSingleScalarValueOrNull();
        if (!\is_int($dim_value) || $dim_value >= 0) {
            return;
        }
        $this->emitIssue(
            Issue::CompatibleNegativeStringOffset,
            $node->children['dim']->lineno ?? $node->lineno
        );
    }

    /**
     * Returns the union of all union types of expressions in this expression list (ast\AST_EXPR_LIST).
     *
     * This is useful for match arm conditions.
     *
     * For other use cases, get the union type of the last node (if one exists) instead.
     *
     * @override
     */
    public function visitExprList(Node $node): UnionType
    {
        $types = [];
        foreach ($node->children as $child_node) {
            $types[] = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $child_node, $this->should_catch_issue_exception);
        }
        return UnionType::merge($types);
    }

    /**
     * @unused-param $node
     * @override
     */
    public function visitExit(Node $node): UnionType
    {
        return NeverType::instance(false)->asRealUnionType();
    }
}
