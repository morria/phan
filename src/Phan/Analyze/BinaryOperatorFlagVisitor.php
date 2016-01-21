<?php declare(strict_types=1);
namespace Phan\Analyze;

use \Phan\AST\Visitor\Element;
use \Phan\AST\Visitor\FlagVisitorImplementation;
use \Phan\CodeBase;
use \Phan\Debug;
use \Phan\Exception\CodeBaseException;
use \Phan\Language\Context;
use \Phan\Language\UnionType;
use \Phan\Language\Type\{
    ArrayType,
    BoolType,
    CallableType,
    FloatType,
    GenericArrayType,
    IntType,
    MixedType,
    NativeType,
    NullType,
    ObjectType,
    ResourceType,
    ScalarType,
    StringType,
    VoidType
};
use \Phan\Issue;
use \ast\Node;

class BinaryOperatorFlagVisitor extends FlagVisitorImplementation
{

    /**
     * @var CodeBase
     */
    private $code_base;

    /**
     * @var Context
     */
    private $context;

    /**
     * Create a new BinaryOperatorFlagVisitor
     */
    public function __construct(
        CodeBase $code_base,
        Context $context
    ) {
        $this->code_base = $code_base;
        $this->context = $context;
    }

    /**
     * @param Node $node
     * A node to visit
     */
    public function __invoke(Node $node)
    {
        return (new Element($node))->acceptBinaryFlagVisitor($this);
    }

    /**
     * Default visitor for node kinds that do not have
     * an overriding method
     *
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visit(Node $node) : UnionType
    {
        $left = UnionType::fromNode(
            $this->context,
            $this->code_base,
            $node->children['left']
        );

        $right = UnionType::fromNode(
            $this->context,
            $this->code_base,
            $node->children['right']
        );

        if ($left->hasType(ArrayType::instance())
            || $right->hasType(ArrayType::instance())
        ) {
            Issue::emit(
                Issue::TypeArrayOperator,
                $this->context->getFile(),
                $node->lineno ?? 0
            );

            return new UnionType();
        } elseif ($left->hasType(IntType::instance())
            && $right->hasType(IntType::instance())
        ) {
            return IntType::instance()->asUnionType();
        } elseif ($left->hasType(FloatType::instance())
            && $right->hasType(FloatType::instance())
        ) {
            return FloatType::instance()->asUnionType();
        }

        return new UnionType([
            IntType::instance(),
            FloatType::instance()
        ]);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryBoolAnd(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryBoolXor(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryBoolOr(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryConcat(Node $node) : UnionType
    {
        return StringType::instance()->asUnionType();
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    private function visitBinaryOpCommon(Node $node)
    {
            $left = UnionType::fromNode(
                $this->context,
                $this->code_base,
                $node->children['left']
            );

            $right = UnionType::fromNode(
                $this->context,
                $this->code_base,
                $node->children['right']
            );

            if (!$left->genericArrayElementTypes()->isEmpty()
            && $left->nonGenericArrayTypes()->isEmpty()
            && !$right->canCastToUnionType(
                ArrayType::instance()->asUnionType()
            )
            ) {
                Issue::emit(
                    Issue::TypeComparisonFromArray,
                    $this->context->getFile(),
                    $node->lineno ?? 0,
                    (string)$right
                );
            } elseif (!$right->genericArrayElementTypes()->isEmpty()
            && $right->nonGenericArrayTypes()->isEmpty()
            && !$left->canCastToUnionType(
                ArrayType::instance()->asUnionType()
            )
            ) {
                // and the same for the left side
                Issue::emit(
                    Issue::TypeComparisonToArray,
                    $this->context->getFile(),
                    $node->lineno ?? 0,
                    (string)$left
                );
            }

            return BoolType::instance()->asUnionType();
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsIdentical(Node $node) : UnionType
    {
        return $this->visitBinaryOpCommon($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsNotIdentical(Node $node) : UnionType
    {
        return $this->visitBinaryOpCommon($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsEqual(Node $node) : UnionType
    {
        return $this->visitBinaryOpCommon($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsNotEqual(Node $node) : UnionType
    {
        return $this->visitBinaryOpCommon($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsSmaller(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsSmallerOrEqual(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsGreater(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }

    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryIsGreaterOrEqual(Node $node) : UnionType
    {
        return $this->visitBinaryBool($node);
    }


    /**
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    public function visitBinaryAdd(Node $node) : UnionType
    {
        $left = UnionType::fromNode(
            $this->context,
            $this->code_base,
            $node->children['left']
        );

        $right = UnionType::fromNode(
            $this->context,
            $this->code_base,
            $node->children['right']
        );

        // fast-track common cases
        if ($left->isType(IntType::instance())
            && $right->isType(IntType::instance())
        ) {
            return IntType::instance()->asUnionType();
        }

        if (($left->isType(IntType::instance())
            || $left->isType(FloatType::instance()))
            && ($right->isType(IntType::instance())
            || $right->isType(FloatType::instance()))
        ) {
            return FloatType::instance()->asUnionType();
        }

        $left_is_array = (
            !empty($left->genericArrayElementTypes())
            && empty($left->nonGenericArrayTypes())
        );

        $right_is_array = (
            !empty($right->genericArrayElementTypes())
            && empty($right->nonGenericArrayTypes())
        );

        if ($left_is_array
            && !$right->canCastToUnionType(
                ArrayType::instance()->asUnionType()
            )
        ) {
            Issue::emit(
                Issue::TypeInvalidRightOperand,
                $this->context->getFile(),
                $node->lineno ?? 0
            );
            return new UnionType();
        } elseif ($right_is_array
            && !$left->canCastToUnionType(ArrayType::instance()->asUnionType())
        ) {
            Issue::emit(
                Issue::TypeInvalidLeftOperand,
                $this->context->getFile(),
                $node->lineno ?? 0
            );
            return new UnionType();
        } elseif ($left_is_array || $right_is_array) {
            // If it is a '+' and we know one side is an array
            // and the other is unknown, assume array
            return ArrayType::instance()->asUnionType();
        }

        return new UnionType([
            IntType::instance(),
            FloatType::instance()
        ]);
    }

    /**
     * Common visitor for binary boolean operations
     *
     * @param Node $node
     * A node to check types on
     *
     * @return UnionType
     * The resulting type(s) of the binary operation
     */
    private function visitBinaryBool(Node $node) : UnionType
    {
        return $this->visitBinaryOpCommon($node);
    }
}
