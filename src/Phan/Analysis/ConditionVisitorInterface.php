<?php

declare(strict_types=1);

namespace Phan\Analysis;

use ast\Node;
use Phan\CodeBase;
use Phan\Exception\IssueException;
use Phan\Language\Context;
use Phan\Language\Element\Variable;

/**
 * This implements common functionality to update variables based on checks within a conditional (of an if/elseif/else/while/for/assert(), etc.)
 *
 * Classes using ConditionVisitorUtil must implement this trait.
 * @method Context __invoke(Node $node)
 */
interface ConditionVisitorInterface
{
    /**
     * Returns this ConditionVisitorInterface's CodeBase.
     * This is needed by subclasses of BinaryCondition.
     */
    public function getCodeBase(): CodeBase;

    /**
     * Returns this ConditionVisitorInterface's Context.
     * This is needed by subclasses of BinaryCondition.
     */
    public function getContext(): Context;

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x == true)`
     */
    public function updateVariableToBeEqual(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context;

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x === 'literal')`
     */
    public function updateVariableToBeIdentical(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context;

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x == 'literal')`
     */
    public function updateVariableToBeNotIdentical(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context;

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @param int $flags (e.g. \ast\flags\BINARY_IS_SMALLER)
     * @return Context - Context after inferring type from an expression such as `if ($x > 0)`
     */
    public function updateVariableToBeCompared(
        Node $var_node,
        $expr,
        int $flags
    ): Context;

    /**
     * @param Node $var_node
     * @param Node|int|float|string $expr
     * @return Context - Context after inferring type from an expression such as `if ($x != 'literal')`
     */
    public function updateVariableToBeNotEqual(
        Node $var_node,
        $expr,
        ?Context $context = null
    ): Context;

    /**
     * Returns a context where the variable for $object_node has the class found in $expr_node
     *
     * @param Node|string|int|float $object_node
     * @param Node|string|int|float|bool $expr_node
     */
    public function analyzeClassAssertion($object_node, $expr_node): ?Context;

    /**
     * @return ?Variable - Returns null if the variable is undeclared and ignore_undeclared_variables_in_global_scope applies.
     *                     or if assertions won't be applied?
     * @throws IssueException if variable is undeclared and not ignored.
     * @see UnionTypeVisitor::visitVar()
     *
     * TODO: support assertions on superglobals, within the current file scope?
     */
    public function getVariableFromScope(Node $var_node, Context $context): ?Variable;
}
