<?php

declare(strict_types=1);

namespace Phan\Analysis;

use AssertionError;
use ast;
use ast\Node;
use Phan\AST\Visitor\KindVisitorImplementation;

use function count;

/**
 * This checks what exit statuses are possible for AST nodes: `break;`, `continue;`, `throw`, `return`, proceeding, etc.
 *
 * This caches the status for AST nodes, so references to this object
 * should be removed once the source transformation of a file/function is complete.
 *
 * This uses the \ast\Node's themselves in order to cache the status.
 * It reuses $node->flags whenever possible in order to avoid keeping around \ast\Node
 * instances for longer than those would be used.
 * This assumes that Nodes aren't manipulated, or manipulations to Nodes will preserve the semantics (including computed exit status) or clear $node->flags.
 *
 * - Creating an additional object property would increase overall memory usage, which is why properties are used.
 * - AST_IF, AST_IF_ELEM, AST_DO_WHILE, AST_FOR, AST_WHILE, AST_STMT_LIST,
 *   etc (e.g. switch and switch case, try/finally).
 *   are node types which are known to not have flags in AST version 40.
 * - In the future, add a new property such as $node->children['__exitStatus'] if used for a node type with flags, or use the higher bits.
 *
 * TODO: Change to AnalysisVisitor if this ever emits issues.
 * TODO: Analyze switch (if there is a default) in another PR (And handle fallthrough)
 *
 * @phan-file-suppress PhanUnusedPublicFinalMethodParameter
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 */
final class BlockExitStatusChecker extends KindVisitorImplementation
{
    // These should be at most 1 << 31, in order to work in 32-bit php.
    // NOTE: Any exit status must be a combination of at least one of these bits
    // E.g. if STATUS_PROCEED is mixed with STATUS_RETURN, it would mean it is possible both to go to completion or return.
    public const STATUS_PROCEED        = (1 << 20);       // At least one branch continues to completion.
    public const STATUS_GOTO           = (1 << 21);       // At least one branch leads to a goto statement
    public const STATUS_CONTINUE       = (1 << 22);       // At least one branch leads to a continue statement
    public const STATUS_BREAK          = (1 << 23);       // At least one branch leads to a break statement
    public const STATUS_THROW          = (1 << 24);       // At least one branch leads to a throw statement
    public const STATUS_RETURN         = (1 << 25);       // At least one branch leads to a return statement
    public const STATUS_NORETURN       = (1 << 26);       // At least one branch leads to a exit() statement (or an infinite loop)

    public const STATUS_THROW_OR_RETURN_BITMASK =
        self::STATUS_THROW |
        self::STATUS_RETURN |
        self::STATUS_NORETURN;

    public const STATUS_NOT_RETURN_BITMASK =
        self::STATUS_THROW |
        self::STATUS_NORETURN;

    // Any status which doesn't lead to proceeding.
    public const STATUS_NOT_PROCEED_BITMASK =
        self::STATUS_GOTO |
        self::STATUS_CONTINUE |
        self::STATUS_BREAK |
        self::STATUS_THROW |
        self::STATUS_RETURN |
        self::STATUS_NORETURN;

    public const STATUS_BITMASK =
        self::STATUS_PROCEED |
        self::STATUS_NOT_PROCEED_BITMASK;

    public const STATUS_MAYBE_PROCEED =
        self::STATUS_PROCEED |
        self::STATUS_GOTO;

    public function __construct()
    {
    }

    /**
     * Computes the bitmask representing the possible ways this block of code might exit.
     *
     * This currently does not handle goto or `break N` comprehensively.
     */
    public function check(?Node $node = null): int
    {
        if (!$node) {
            return self::STATUS_PROCEED;
        }
        $result = $this->__invoke($node);
        if (!\is_int($result) || $result <= 0) {
            throw new AssertionError('Expected positive int');
        }
        return $result;
    }

    /**
     * If we don't know how to analyze a node type (or left it out), assume it always proceeds
     * @return int - The status bitmask corresponding to always proceeding
     */
    public function visit(Node $node): int
    {
        return self::STATUS_PROCEED;
    }

    /**
     * @param Node|string|int|float $cond
     */
    private static function isTruthyLiteral($cond): bool
    {
        if ($cond instanceof Node) {
            // TODO: Could look up values for remaining constants and inline expressions, but doing that has low value.
            if ($cond->kind === ast\AST_CONST) {
                $cond_name_string = $cond->children['name']->children['name'] ?? null;
                return \is_string($cond_name_string) && \strcasecmp($cond_name_string, 'true') === 0;
            }
            return false;
        }
        // Cast string, int, etc. literal to a bool
        return (bool)$cond;
    }

    /**
     * A break statement unconditionally breaks out of a loop/switch
     * @return int the corresponding status code
     */
    public function visitBreak(Node $node): int
    {
        return self::STATUS_BREAK;
    }

    /**
     * A continue statement unconditionally continues out of a loop/switch.
     * TODO: Make this account for levels
     * @return int the corresponding status code
     */
    public function visitContinue(Node $node): int
    {
        return self::STATUS_CONTINUE;
    }

    /**
     * A throw statement unconditionally throws
     * @return int the corresponding status code
     */
    public function visitThrow(Node $node): int
    {
        return self::STATUS_THROW;
    }

    /**
     * @return int the corresponding status code for the try/catch/finally block
     */
    public function visitTry(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfTry($node);
        $node->flags = $status;
        return $status;
    }

    private function computeStatusOfTry(Node $node): int
    {
        $main_status = $this->check($node->children['try']);
        // Finding good heuristics is difficult.
        // e.g. "return someFunctionThatMayThrow()" in try{} block would be inferred as STATUS_RETURN, but may actually be STATUS_THROW

        $finally_node = $node->children['finally'];
        if ($finally_node) {
            $finally_status = $this->check($finally_node);
            // TODO: Could emit an issue as a side effect
            // Having any sort of status in a finally statement is
            // likely to have unintuitive behavior.
            if (($finally_status & (~self::STATUS_THROW_OR_RETURN_BITMASK)) === 0) {
                return $finally_status;
            }
        } else {
            $finally_status = self::STATUS_PROCEED;
        }
        $catches_node = $node->children['catches'];
        if (\count($catches_node->children) === 0) {
            return self::mergeFinallyStatus($main_status, $finally_status);
        }
        // TODO: Could enhance slightly by checking for catch nodes with the exact same types (or subclasses) as names of exception thrown.
        $combined_status = self::mergeFinallyStatus($main_status, $finally_status) | $this->visitCatchList($catches_node);
        if (($finally_status & self::STATUS_PROCEED) === 0) {
            $combined_status &= ~self::STATUS_PROCEED;
        }
        // No idea.
        return $combined_status;
    }

    /**
     * @return int the corresponding status code
     */
    public function visitCatchList(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfCatchList($node);
        $node->flags = $status;
        return $status;
    }

    private function computeStatusOfCatchList(Node $node): int
    {
        $catch_list = $node->children;
        if (count($catch_list) === 0) {
            return self::STATUS_PROCEED;  // status probably won't matter
        }
        // TODO: Could enhance slightly by checking for catch nodes with the exact same types (or subclasses) as names of exception thrown.
        $combined_status = 0;
        // Try to cover all possible cases, such as try { return throwsException(); } catch(Exception $e) { break; }
        foreach ($node->children as $catch_node) {
            if (!$catch_node instanceof Node) {
                throw new AssertionError('Expected catch statement to be a Node');
            }
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null for catch nodes
            $catch_node_status = $this->visitStmtList($catch_node->children['stmts']);
            $combined_status |= $catch_node_status;
        }
        // No idea.
        return $combined_status;
    }

    private static function mergeFinallyStatus(int $try_status, int $finally_status): int
    {
        // If at least one of try or finally are guaranteed not to proceed to completion,
        // then combine those possibilities.
        if (($try_status & $finally_status & self::STATUS_PROCEED) === 0) {
            return ($try_status | $finally_status) & ~self::STATUS_PROCEED;
        }
        return $try_status | $finally_status;
    }

    /**
     * @return int the corresponding status code
     * @suppress PhanTypeMismatchArgumentNullable
     */
    public function visitSwitch(Node $node): int
    {
        $cond = $node->children['cond'];
        if ($cond instanceof Node) {
            $cond_status = $this->check($cond);
            if (($cond_status & self::STATUS_PROCEED) === 0) {
                // handle throw expressions, switch(exit()), etc.
                return $cond_status;
            }
        } else {
            $cond_status = self::STATUS_PROCEED;
        }
        return $this->visitSwitchList($node->children['stmts']) | ($cond_status & ~self::STATUS_PROCEED);
    }

    /**
     * @return int the corresponding status code
     */
    public function visitSwitchList(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfSwitchList($node);
        $node->flags = $status;
        return $status;
    }

    private function computeStatusOfSwitchList(Node $node): int
    {
        $switch_stmt_case_nodes = $node->children ?? [];
        if (\count($switch_stmt_case_nodes) === 0) {
            return self::STATUS_PROCEED;
        }
        $has_default = false;
        $combined_statuses = 0;
        foreach ($switch_stmt_case_nodes as $index => $case_node) {
            if (!$case_node instanceof Node) {
                throw new AssertionError('Expected switch case to be a Node');
            }
            if ($case_node->children['cond'] === null) {
                $has_default = true;
            }
            $case_status = self::getStatusOfSwitchCase($case_node, $index, $switch_stmt_case_nodes);
            if (($case_status & self::STATUS_CONTINUE_OR_BREAK) !== 0) {
                // Ignore statuses such as break/continue. They take effect inside, but are a proceed status outside
                $case_status = ($case_status & ~self::STATUS_CONTINUE_OR_BREAK) | self::STATUS_PROCEED;
            }
            $combined_statuses |= $case_status;
        }
        if (!$has_default) {
            $combined_statuses |= self::STATUS_PROCEED;
        }
        return $combined_statuses;
    }

    /**
     * @param list<Node> $siblings
     */
    private function getStatusOfSwitchCase(Node $case_node, int $index, array $siblings): int
    {
        $status = $case_node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfSwitchCase($case_node, $index, $siblings);
        $case_node->flags = $status;
        return $status;
    }

    /**
     * @param array<mixed,Node|int|string|float> $siblings
     */
    private function computeStatusOfSwitchCase(Node $case_node, int $index, array $siblings): int
    {
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
        $status = $this->visitStmtList($case_node->children['stmts']);
        if (($status & self::STATUS_PROCEED) === 0) {
            // Check if the current switch case will not fall through.
            return $status;
        }
        $next_sibling = $siblings[$index + 1] ?? null;
        if (!$next_sibling instanceof Node) {
            return $status;
        }
        $next_status = self::getStatusOfSwitchCase($next_sibling, $index + 1, $siblings);
        // Combine the possibilities.
        // e.g. `case 1: if (cond()) { return; } case 2: throw;`, case 1 will either break or throw,
        // but won't proceed normally to the outside of the switch statement.
        return ($status & ~self::STATUS_PROCEED) | $next_status;
    }

    /**
     * @return int the corresponding status code
     * @suppress PhanTypeMismatchArgumentNullable
     */
    public function visitMatch(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $cond_status = $this->check($node->children['cond']);
        if (($cond_status & self::STATUS_PROCEED) === 0) {
            return $cond_status;
        }
        return $this->visitMatchArmList($node->children['stmts']) | ($cond_status & ~self::STATUS_PROCEED);
    }

    /**
     * @return int the corresponding status code for the match arm list
     */
    public function visitMatchArmList(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfMatchArmList($node);
        $node->flags = $status;
        return $status;
    }

    /**
     * @return int the corresponding status code
     */
    public function visitMatchArm(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeMatchArmStatus($node);
        $node->flags |= $status;
        return $status;
    }

    private function computeMatchArmStatus(Node $node): int
    {
        ['cond' => $cond, 'expr' => $expr] = $node->children;
        $cond_status = 0;
        foreach ($cond->children ?? [] as $cond_expr) {
            if (!$cond_expr instanceof Node) {
                $cond_status |= self::STATUS_PROCEED;
                continue;
            }
            $cond_status |= $this->check($cond_expr);
            if (($cond_status & self::STATUS_PROCEED) === 0) {
                return $cond_status;
            }
        }
        return ($cond_status & ~self::STATUS_PROCEED) | ($expr instanceof Node ? $this->check($expr) : self::STATUS_PROCEED);
    }

    private function computeStatusOfMatchArmList(Node $node): int
    {
        $default_status = self::STATUS_THROW;  // UnhandledMatchError if no default node exists
        $combined_status = 0;
        foreach ($node->children as $arm_node) {
            // @phan-suppress-next-line PhanPossiblyUndeclaredProperty
            $arm_cond = $arm_node->children['cond'];
            if ($arm_cond === null) {
                $default_status = $this->visitMatchArm($arm_node);
                continue;
            }
            $combined_status |= $this->visitMatchArm($arm_node);
        }
        return $default_status | $combined_status;
    }

    public const UNEXITABLE_LOOP_INNER_STATUS = self::STATUS_PROCEED | self::STATUS_CONTINUE;

    public const STATUS_CONTINUE_OR_BREAK = self::STATUS_CONTINUE | self::STATUS_BREAK;

    public function visitForeach(Node $node): int
    {
        // We assume foreach loops are over a finite sequence, and that it's possible for that sequence to have at least one element.
        $inner_status = $this->check($node->children['stmts']);

        // 1. break/continue apply to the inside of a loop, not outside. Not going to analyze "break 2;", may emit an info level issue in the future.
        // 2. We assume that it's possible that any given loop can have 0 iterations.
        //    A TODO exists above to check for special cases.
        return ($inner_status & ~self::STATUS_CONTINUE_OR_BREAK) | self::STATUS_PROCEED;
    }

    public function visitWhile(Node $node): int
    {
        $inner_status = $this->check($node->children['stmts']);
        // TODO: Check for unconditionally false conditions.
        if (self::isTruthyLiteral($node->children['cond'])) {
            // Use a special case to analyze "while (1) {exprs}" or "for (; true; ) {exprs}"
            // TODO: identify infinite loops, mark those as STATUS_NO_PROCEED or STATUS_RETURN.
            return self::computeDerivedStatusOfInfiniteLoop($inner_status);
        }
        // This is (to our awareness) **not** an infinite loop


        // 1. break/continue apply to the inside of a loop, not outside. Not going to analyze "break 2;", may emit an info level issue in the future.
        // 2. We assume that it's possible that any given loop can have 0 iterations.
        //    A TODO exists above to check for special cases.
        return ($inner_status & ~self::STATUS_CONTINUE_OR_BREAK) | self::STATUS_PROCEED;
    }

    /**
     * @return int the corresponding status code
     */
    public function visitFor(Node $node): int
    {
        $inner_status = $this->check($node->children['stmts']);
        // for loops have an expression list as a condition.
        $cond_nodes = $node->children['cond']->children ?? [];  // NOTE: $node->children['cond'] is null for the expression `for (;;)`
        // TODO: Check for unconditionally false conditions.
        if (count($cond_nodes) === 0 || self::isTruthyLiteral(\end($cond_nodes))) {
            // Use a special case to analyze "while (1) {exprs}" or "for (; true; ) {exprs}"
            // TODO: identify infinite loops, mark those as STATUS_NO_PROCEED or STATUS_RETURN.
            return self::computeDerivedStatusOfInfiniteLoop($inner_status);
        }
        // This is (to our awareness) **not** an infinite loop


        // 1. break/continue apply to the inside of a loop, not outside. Not going to analyze "break 2;", may emit an info level issue in the future.
        // 2. We assume that it's possible that any given loop can have 0 iterations.
        //    A TODO exists above to check for special cases.
        return ($inner_status & ~self::STATUS_CONTINUE_OR_BREAK) | self::STATUS_PROCEED;
        // TODO: Improve this by checking for loops which almost definitely have at least one iteration,
        // such as "foreach ([$val] as $v)" or "for ($i = 0; $i < 10; $i++)"

        // if (($inner_status & ~self::STATUS_THROW_OR_RETURN_BITMASK) === 0) {
        //     // The inside of the loop will unconditionally throw or return.
        //     return $inner_status
        // }
    }

    // Logic to determine status of "while (1) {exprs}" or "for (; true; ) {exprs}"
    // TODO: identify infinite loops, mark those as STATUS_NO_PROCEED or STATUS_RETURN.
    private static function computeDerivedStatusOfInfiniteLoop(int $inner_status): int
    {
        $status = $inner_status & ~self::UNEXITABLE_LOOP_INNER_STATUS;
        if ($status === 0) {
            return self::STATUS_NORETURN;  // this is an infinite loop, it didn't contain break/throw/return statements?
        }
        if (($status & self::STATUS_BREAK) !== 0) {
            // if the inside of "while (true) {} contains a break statement,
            // then execution can proceed past the end of the loop.
            return ($status & ~self::STATUS_BREAK) | self::STATUS_PROCEED;
        }
        return $status;
    }

    /**
     * A return statement unconditionally returns (Assume expression passed in doesn't throw)
     * @return int the corresponding status code
     */
    public function visitReturn(Node $node): int
    {
        return self::STATUS_RETURN;
    }

    /**
     * An exit statement unconditionally exits (Assume expression passed in doesn't throw)
     * @return int the corresponding status code
     */
    public function visitExit(Node $node): int
    {
        return self::STATUS_NORETURN;
    }

    /**
     * @return int the corresponding status code
     */
    public function visitUnaryOp(Node $node): int
    {
        // Don't modify $node->flags, use unmodified flags here
        if ($node->flags !== ast\flags\UNARY_SILENCE) {
            return self::STATUS_PROCEED;
        }
        // Analyze exit status of `@expr` like `expr` (e.g. @trigger_error())
        $expr = $node->children['expr'];
        if (!($expr instanceof Node)) {
            return self::STATUS_PROCEED;
        }
        return $this->__invoke($expr);
    }

    /**
     * Determines the exit status of a function call, such as trigger_error()
     *
     * NOTE: A trigger_error() statement may or may not exit, depending on the constant and user configuration.
     * @return int the corresponding status code
     */
    public function visitCall(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = self::computeStatusOfCall($node);
        $node->flags = $status;
        return $status;
    }

    /**
     * Determines the exit status of a static method call.
     *
     * @return int the corresponding status code
     */
    public function visitStaticCall(Node $node): int
    {
        // TODO: The expression or arguments might unconditionally throw, though that is rare in practice.
        return ($node->flags & self::STATUS_BITMASK) ?: self::STATUS_PROCEED;
    }

    /**
     * Determines the exit status of an instance method call.
     *
     * @return int the corresponding status code
     * @override
     * @see UseReturnValueVisitor::checkIfUsingFunctionThatNeverReturns()
     */
    public function visitMethodCall(Node $node): int
    {
        // TODO: The expression or arguments might unconditionally throw, though that is rare in practice.
        return ($node->flags & self::STATUS_BITMASK) ?: self::STATUS_PROCEED;
    }

    /**
     * Determines the exit status of an instance method call.
     *
     * @return int the corresponding status code
     * @override
     */
    public function visitNullsafeMethodCall(Node $node): int
    {
        // TODO: The expression or arguments might unconditionally throw, though that is rare in practice.
        return ($node->flags & self::STATUS_BITMASK) ?: self::STATUS_PROCEED;
    }

    private static function computeStatusOfCall(Node $node): int
    {
        $expression = $node->children['expr'];
        if ($expression instanceof Node) {
            if ($expression->kind !== ast\AST_NAME) {
                return self::STATUS_PROCEED;  // best guess
            }
            $function_name = $expression->children['name'];
            if (!\is_string($function_name)) {
                return self::STATUS_PROCEED;
            }
        } else {
            if (!\is_string($expression)) {
                return self::STATUS_THROW;  // Probably impossible.
            }
            $function_name = $expression;
        }
        if ($function_name === '') {
            // TODO: Check for all invalid fqsens, allowing 'NS\ClassName::methodName'
            return self::STATUS_THROW;  // nonsense such as ''();
        }
        if ($node->children['args']->kind === ast\AST_CALLABLE_CONVERT) {
            // This is creating a closure, not calling it.
            return self::STATUS_PROCEED;
        }
        if ($function_name[0] === '\\') {
            $function_name = \substr($function_name, 1);
        }
        // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
        if (\strcasecmp($function_name, 'trigger_error') === 0) {
            return self::computeTriggerErrorStatusCodeForConstant($node->children['args']->children[1] ?? null);
        }
        // TODO: Could allow .phan/config.php or plugins to define additional behaviors, e.g. for methods.
        // E.g. if (!$var) {HttpFramework::generate_302_and_die(); }
        return self::STATUS_PROCEED;
    }

    /**
     * @param ?(Node|string|int|float) $constant_ast
     */
    private static function computeTriggerErrorStatusCodeForConstant($constant_ast): int
    {
        // return PROCEED if this can't be determined.
        // TODO: Could check for integer literals
        if (!($constant_ast instanceof Node)) {
            return self::STATUS_PROCEED;
        }
        if ($constant_ast->kind !== ast\AST_CONST) {
            return self::STATUS_PROCEED;
        }
        $name = $constant_ast->children['name']->children['name'] ?? null;
        if (!\is_string($name)) {
            return self::STATUS_PROCEED;
        }
        // The returned code for exit() is 'return', e.g. E_USER_ERROR makes trigger_error emit an error then abort execution.
        // NOTE: Native errors either emit a notice about being invalid error types to pass to this function (e.g. E_ERROR).
        // must be one of E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE, or E_USER_DEPRECATED
        if ($name === 'E_USER_ERROR') {
            // Fatal error
            return self::STATUS_NORETURN;
        }
        if (!\in_array($name, ['E_USER_WARNING', 'E_USER_NOTICE', 'E_USER_DEPRECATED'], true)) {
            // Newer php versions throw a ValueError for invalid types.
            return self::STATUS_THROW;
        }

        return self::STATUS_PROCEED;  // Assume this is a warning or notice?
    }

    /**
     * A statement list has the weakest return status out of all of the (non-PROCEEDing) statements.
     * FIXME: This is buggy, doesn't account for one statement having STATUS_CONTINUE some of the time but not all of it.
     *       (We don't check for STATUS_CONTINUE yet, so this doesn't matter yet.)
     * @return int the corresponding status code
     */
    public function visitStmtList(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfBlock($node->children);
        $node->flags = $status;
        return $status;
    }

    /**
     * An expression list has the weakest return status out of all of the (non-PROCEEDing) statements.
     * @return int the corresponding status code
     * @override
     */
    public function visitExprList(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfBlock($node->children);
        $node->flags = $status;
        return $status;
    }

    /**
     * Analyzes a node with kind \ast\AST_IF
     * @return int the exit status of a block (whether or not it would unconditionally exit, return, throw, etc.
     * @override
     */
    public function visitIf(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        $status = $this->computeStatusOfIf($node);
        $node->flags = $status;
        return $status;
    }

    private function computeStatusOfIf(Node $node): int
    {
        $has_if_elems_for_all_cases = false;
        $combined_statuses = 0;
        foreach ($node->children as $child_node) {
            '@phan-var Node $child_node';
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
            $status = $this->visitStmtList($child_node->children['stmts']);
            $combined_statuses |= $status;

            $cond_node = $child_node->children['cond'];
            // check for "else" or "elseif (true)"
            if ($cond_node === null || self::isTruthyLiteral($cond_node)) {
                $has_if_elems_for_all_cases = true;
                break;
            }
        }
        if (!$has_if_elems_for_all_cases) {
            $combined_statuses |= self::STATUS_PROCEED;
        }
        return $combined_statuses;
    }


    /**
     * Analyzes a node with kind \ast\AST_DO_WHILE
     * @return int the exit status of a block (whether or not it would unconditionally exit, return, throw, etc.
     */
    public function visitDoWhile(Node $node): int
    {
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
        $inner_status = $this->visitStmtList($node->children['stmts']);
        if (($inner_status & ~self::STATUS_THROW_OR_RETURN_BITMASK) === 0) {
            // The inner block throws or returns before the end can be reached.
            return $inner_status;
        }
        // TODO: Check for unconditionally false conditions.
        if (self::isTruthyLiteral($node->children['cond'])) {
            // Use a special case to analyze "while (1) {exprs}" or "for (; true; ) {exprs}"
            // TODO: identify infinite loops, mark those as STATUS_NO_PROCEED or STATUS_RETURN.
            return $this->computeDerivedStatusOfInfiniteLoop($inner_status);
        }
        // This is (to our awareness) **not** an infinite loop


        // 1. break/continue apply to the inside of a loop, not outside. Not going to analyze "break 2;", may emit an info level issue in the future.
        // 2. We assume that it's possible that any given loop can have 0 iterations.
        //    A TODO exists above to check for special cases.
        return ($inner_status & ~self::STATUS_CONTINUE_OR_BREAK) | self::STATUS_PROCEED;
    }

    /**
     * Analyzes a node with kind \ast\AST_IF_ELEM
     * @return int the exit status of a block (whether or not it would unconditionally exit, return, throw, etc.
     */
    public function visitIfElem(Node $node): int
    {
        $status = $node->flags & self::STATUS_BITMASK;
        if ($status) {
            return $status;
        }
        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
        $status = $this->visitStmtList($node->children['stmts']);
        $node->flags = $status;
        return $status;
    }

    /**
     * @return int the corresponding status code
     */
    public function visitGoto(Node $node): int
    {
        return self::STATUS_GOTO;
    }

    /**
     * @param list<Node> $block
     * @return int the exit status of a block (whether or not it would unconditionally exit, return, throw, etc.
     */
    private function computeStatusOfBlock(array $block): int
    {
        $maybe_status = 0;
        foreach ($block as $child) {
            if ($child === null) {
                continue;
            }
            // e.g. can be non-Node for statement lists such as `if ($a) { return; }echo "X";2;` (under unknown conditions)
            if (!($child instanceof Node)) {
                continue;
            }
            $status = $this->check($child);
            if (($status & self::STATUS_PROCEED) === 0) {
                // If it's guaranteed we won't proceed after this statement,
                // then skip the subsequent statements.
                return $status | ($maybe_status & ~self::STATUS_PROCEED);
            }
            $maybe_status |= $status;
        }
        return self::STATUS_PROCEED | $maybe_status;
    }

    /**
     * Will the node $node unconditionally never fall through to the following statement?
     */
    public static function willUnconditionallySkipRemainingStatements(Node $node): bool
    {
        return ((new self())->__invoke($node) & self::STATUS_MAYBE_PROCEED) === 0;
    }

    /**
     * Will the node $node unconditionally throw or return (or exit),
     */
    public static function willUnconditionallyThrowOrReturn(Node $node): bool
    {
        return ((new self())->__invoke($node) & ~self::STATUS_THROW_OR_RETURN_BITMASK) === 0;
    }

    /**
     * Will the node $node unconditionally throw or exit (or infinitely loop)
     */
    public static function willUnconditionallyNeverReturn(Node $node): bool
    {
        return ((new self())->__invoke($node) & ~self::STATUS_NOT_RETURN_BITMASK) === 0;
    }

    /**
     * Will the node $node unconditionally proceed (no break/continue, throw, or goto)
     */
    public static function willUnconditionallyProceed(Node $node): bool
    {
        return (new self())->__invoke($node) === self::STATUS_PROCEED;
    }
}
