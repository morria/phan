<?php declare(strict_types=1);
namespace Phan\Plugin\Internal\VariableTracker;

use Phan\Analysis\BlockExitStatusChecker;
use Phan\AST\AnalysisVisitor;
use Phan\AST\Visitor\Element;
use ast\Node;
use ast;  // TODO: Figure out why Phan isn't warning about Phan\Plugin\Internal\VariableTracker\ast\AST_VAR without this.

use function is_string;

/**
 * The planned design for this is similar to the way BlockAnalysisVisitor tracks union types of variables
 * (Tracks locations instead of union types).
 *
 * 1. Track definitions and uses, on a per-statement basis.
 * 2. Use visit*() overrides for individual element types.
 * 3. Split tracking variables into pre-analysis, recursive, and post-analysis steps
 * 4. Track based on an identifier corresponding to the \ast\Node of the assignment (e.g. using \spl_object_id())
 *
 * TODO: Improve analysis within the ternary operator (cond() ? ($x = 2) : ($x = 3);
 * TODO: Support unset
 * TODO: Fix tests/files/src/0426_inline_var_force.php
 */
final class VariableTrackerVisitor extends AnalysisVisitor
{
    /**
     * @var VariableGraph
     */
    public static $variable_graph;

    /** @var VariableTrackingScope */
    private $scope;

    public function __construct(VariableTrackingScope $scope)
    {
        $this->scope = $scope;
    }

    /**
     * This is the default implementation for node types which don't have any overrides
     * @return VariableTrackingScope
     * @override
     */
    public function visit(Node $node)
    {
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        }
        return $this->scope;
    }

    /**
     * This is the default implementation for node types which don't have any overrides
     * @return VariableTrackingScope
     * @override
     */
    public function visitStmtList(Node $node)
    {
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            // TODO: Specialize?
            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        }
        return $this->scope;
    }

    /**
     * @return VariableTrackingScope
     * @override
     */
    public function visitAssignRef(Node $node)
    {
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            $this->scope = $this->analyze($this->scope, $expr);
        }
        $var_node = $node->children['var'];
        if ($var_node->kind === \ast\AST_VAR) {
            $name = $var_node->children['name'];
            if (is_string($name)) {
                self::$variable_graph->recordVariableUsage($name, $var_node, $this->scope);
            }
        }
        return $this->analyzeAssignmentTarget($var_node, false);
    }

    /**
     * @return VariableTrackingScope
     * @override
     */
    public function visitAssignOp(Node $node)
    {
        return $this->analyzeAssign($node, true);
    }

    /**
     * @return VariableTrackingScope
     * @override
     */
    public function visitAssign(Node $node)
    {
        return $this->analyzeAssign($node, false);
    }

    /**
     * @return VariableTrackingScope
     */
    private function analyzeAssign(Node $node, bool $is_ref)
    {
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            $this->scope = $this->analyze($this->scope, $expr);
        }
        return $this->analyzeAssignmentTarget($node->children['var'], $is_ref);
    }

    private function analyzeAssignmentTarget($node, bool $is_ref) : VariableTrackingScope
    {
        // TODO: Push onto the node list?
        if (!($node instanceof Node)) {
            return $this->scope;
        }
        $kind = $node->kind;
        switch ($kind) {
            case ast\AST_VAR:
                $name = $node->children['name'];
                if (!is_string($name)) {
                    break;
                }
                if ($is_ref) {
                    self::$variable_graph->markAsReference($name);
                }
                self::$variable_graph->recordVariableDefinition($name, $node, $this->scope);
                $this->scope->recordDefinition($name, $node);
                return $this->scope;
            case ast\AST_ARRAY:
                return $this->analyzeArrayAssignmentTarget($node);

            case ast\AST_REF:
                return $this->analyzeAssignmentTarget($node->children['var'], true);
            case ast\AST_PROP:
                return $this->analyzePropAssignmentTarget($node);
            case ast\AST_DIM:
                return $this->analyzeDimAssignmentTarget($node);
                // TODO: Analyze array access and param/return references of function/method calls.
            default:
                // Static property or an unexpected target.
                // Analyze this normally.
                return $this->analyze($this->scope, $node);
        }
        return $this->scope;
    }

    private function analyzeArrayAssignmentTarget(Node $node) : VariableTrackingScope
    {
        foreach ($node->children as $elem_node) {
            if (!($elem_node instanceof Node)) {
                continue;
            }
            // Treat $key in `[$key => $y] = $array` as a usage of $key
            $this->scope = $this->analyzeWhenValidNode($this->scope, $elem_node->children['key']);
            $this->scope = $this->analyzeAssignmentTarget($elem_node->children['value'], false);
        }
        return $this->scope;
    }

    private function analyzePropAssignmentTarget(Node $node) : VariableTrackingScope
    {
        // Treat $y in `$x->$y = $z;` as a usage of $y
        $this->scope = $this->analyzeWhenValidNode($this->scope, $node->children['prop']);
        $expr = $node->children['expr'];
        if ($expr instanceof Node && $expr->kind === \ast\AST_VAR) {
            $name = $expr->children['name'];
            if (is_string($name)) {
                // treat $x->prop = 2 like a usage of $x
                self::$variable_graph->recordVariableUsage($name, $expr, $this->scope);
            }
        }
        return $this->analyzeWhenValidNode($this->scope, $expr);  // lower false positives by not treating this as a definition
        // // treat $x->prop = 2 like a definition to $x (in addition to having treated this as a usage)
        // return $this->analyzeAssignmentTarget($expr, false);
    }

    private function analyzeDimAssignmentTarget(Node $node) : VariableTrackingScope
    {
        // Treat $y in `$x[$y] = $z;` as a usage of $y
        $this->scope = $this->analyzeWhenValidNode($this->scope, $node->children['dim']);
        $expr = $node->children['expr'];
        if ($expr instanceof Node && $expr->kind === \ast\AST_VAR) {
            $name = $expr->children['name'];
            if (is_string($name)) {
                // treat $x['dim_name'] = 2 like a usage of $x
                //
                // TODO: More aggressively warn if there is only a single dimension to $x
                self::$variable_graph->recordVariableUsage($name, $expr, $this->scope);
            }
        }
        return $this->analyzeWhenValidNode($this->scope, $expr);  // lower false positives by not treating this as a definition
        // // treat $x['dim_name'] = 2 like a definition to $x (in addition to having treated this as a usage)
        // return $this->analyzeAssignmentTarget($expr, false);
    }

    /**
     * @return VariableTrackingScope
     */
    public function handleMissingNodeKind(Node $unused_node)
    {
        // do nothing
        return $this->scope;
    }

    /**
     * @return VariableTrackingScope
     */
    private function analyzeWhenValidNode(VariableTrackingScope $scope, $child_node)
    {
        if ($child_node instanceof Node) {
            return $this->analyze($scope, $child_node);
        }
        return $scope;
    }

    /**
     * This is an abstraction for getting a new, updated context for a child node.
     *
     * @param Node $child_node - The node which will be analyzed to create the updated context.
     * @return VariableTrackingScope
     */
    private function analyze(VariableTrackingScope $scope, Node $child_node)
    {
        // Modify the original object instead of creating a new BlockAnalysisVisitor.
        // this is slightly more efficient, especially if a large number of unchanged parameters would exist.
        $old_scope = $this->scope;
        $this->scope = $scope;
        try {
            return $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        } finally {
            $this->scope = $old_scope;
        }
    }

    /**
     * Do not recurse into function declarations within a scope
     * @return VariableTrackingScope
     * @override
     */
    public function visitFuncDecl(Node $unused_node)
    {
        return $this->scope;
    }

    /**
     * Do not recurse into class declarations within a scope
     * @return VariableTrackingScope
     * @override
     */
    public function visitClass(Node $unused_node)
    {
        return $this->scope;
    }

    /**
     * Do not recurse into closure declarations within a scope.
     *
     * FIXME: Check closure use variables without checking statements
     * @return VariableTrackingScope
     * @override
     */
    public function visitClosure(Node $node)
    {
        foreach ($node->children['uses']->children ?? [] as $closure_use) {
            if (!($closure_use instanceof Node)) {
                continue;
            }

            $name = $closure_use->children['name'];
            if (!is_string($name)) {
                continue;
            }

            if ($closure_use->flags & ast\flags\PARAM_REF) {
                self::$variable_graph->recordVariableDefinition($name, $closure_use, $this->scope);
                self::$variable_graph->markAsReference($name);
            } else {
                self::$variable_graph->recordVariableUsage($name, $closure_use, $this->scope);
            }
        }
        return $this->scope;
    }

    /**
     * @override
     * @return VariableTrackingScope
     * Common no-op
     */
    public function visitName(Node $unused_node)
    {
        return $this->scope;
    }

    /**
     * TODO: Check if the current context is a function call passing an argument by reference
     * @return VariableTrackingScope
     * @override
     */
    public function visitVar(Node $node)
    {
        $name = $node->children['name'];
        if (\is_string($name)) {
            self::$variable_graph->recordVariableUsage($name, $node, $this->scope);
            // TODO: Determine if the given usage is an assignment, a definition, or both (modifying by reference, $x++, etc.
            // See the way this is done in BlockAnalysisVisitor
        } elseif ($name instanceof Node) {
            return $this->analyze($this->scope, $name);
        }
        return $this->scope;
    }

    /**
     * TODO: Check if the current context is a function call passing an argument by reference
     * @return VariableTrackingScope
     * @override
     */
    public function visitStatic(Node $node)
    {
        $name = $node->children['var']->children['name'] ?? null;
        if (\is_string($name)) {
            self::$variable_graph->markAsStaticVariable($name);
        }
        return $this->scope;
    }

    /**
     * TODO: Check if the current context is a function call passing an argument by reference
     * @return VariableTrackingScope
     * @override
     */
    public function visitGlobal(Node $node)
    {
        $name = $node->children['var']->children['name'] ?? null;
        if (\is_string($name)) {
            self::$variable_graph->markAsGlobalVariable($name);
        }
        return $this->scope;
    }

    /**
     * Analyzes `foreach ($expr as $key => $value) { stmts }
     * @return VariableTrackingScope
     */
    public function visitForeach(Node $node)
    {
        $expr_node = $node->children['expr'];
        $outer_scope = $this->analyzeWhenValidNode($this->scope, $expr_node);

        // Replace the scope with the inner scope
        $this->scope = new VariableTrackingLoopScope($outer_scope);

        $key_node = $node->children['key'];
        $this->scope = $this->analyzeAssignmentTarget($key_node, false);

        $value_node = $node->children['value'];
        if (isset($key_node)) {
            self::$variable_graph->markAsLoopValueNode($value_node);
        }
        $this->scope = $this->analyzeAssignmentTarget($value_node, false);  // analyzeAssignmentTarget checks for AST_REF

        // TODO: Update graph: inner loop definitions can be used inside the loop.
        // TODO: Create a branchScope? - Loop iterations can run 0 times.
        $inner_scope = $this->analyze($this->scope, $node->children['stmts']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgument
        return $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
    }

    /**
     * Analyzes `while (cond) { stmts }`
     * @return VariableTrackingScope
     * @override
     */
    public function visitWhile(Node $node)
    {
        $outer_scope = $this->scope;

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['cond']);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgument
        return $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
    }

    /**
     * Analyzes `do { stmts } while (cond);`
     * @return VariableTrackingScope
     * @override
     */
    public function visitDoWhile(Node $node)
    {
        $outer_scope = $this->scope;

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['cond']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgument
        return $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
    }

    /**
     * Analyzes `for (init; cond; loop) { stmts }`
     * @return VariableTrackingScope
     * @override
     */
    public function visitFor(Node $node)
    {
        $outer_scope = $this->analyzeWhenValidNode($this->scope, $node->children['init']);

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        // TODO: If the graph analysis is removed, look into making this stop analyzing 'loop' twice
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['loop']);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['cond']);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['loop']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgument
        return $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
    }

    /**
     * @param Node $node a node of kind AST_IF
     * Analyzes if statements.
     * @return VariableTrackingScope
     *
     * @see BlockAnalysisVisitor->visitIf (TODO: Use BlockExitStatusChecker)
     * @override
     */
    public function visitIf(Node $node)
    {
        $outer_scope = $this->scope;

        $inner_scope_list = [];
        $merge_parent_scope = true;
        foreach ($node->children as $if_node) {
            if (!($if_node instanceof Node)) {
                // impossible
                continue;
            }
            // Replace the scope with the inner scope
            // Analyzing if_node->children['cond'] should affect $outer_scope.
            // This isn't precise, and doesn't fully understand assignments within conditions.
            $cond_node = $if_node->children['cond'];
            $stmts_node = $if_node->children['stmts'];

            if ($cond_node instanceof Node) {
                $inner_cond_scope = new VariableTrackingBranchScope($outer_scope);
                $inner_cond_scope = $this->analyze($inner_cond_scope, $cond_node);
                '@phan-var VariableTrackingBranchScope $inner_cond_scope';
                $outer_scope = $outer_scope->mergeBranchScopeList([$inner_cond_scope], $merge_parent_scope, []);
            }

            $inner_scope = new VariableTrackingBranchScope($outer_scope);
            $inner_scope = $this->analyze($inner_scope, $stmts_node);

            '@phan-var VariableTrackingBranchScope $inner_scope';

            if (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($stmts_node)) {
                $exits = BlockExitStatusChecker::willUnconditionallyThrowOrReturn($stmts_node);
                $outer_scope->recordSkippedScope($inner_scope, $exits);
            } else {
                $inner_scope_list[] = $inner_scope;
            }
            if ($cond_node === null || (\is_scalar($cond_node) && $cond_node)) {
                $merge_parent_scope = false;
            }
        }

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanPartialTypeMismatchArgument
        return $outer_scope->mergeBranchScopeList($inner_scope_list, $merge_parent_scope, []);
    }

    /**
     * @param Node $node a node of kind AST_SWITCH
     * Analyzes switch statements.
     * @return VariableTrackingScope
     *
     * @see BlockAnalysisVisitor->visitSwitchList (TODO: Use BlockExitStatusChecker)
     * @override
     */
    public function visitSwitchList(Node $node)
    {
        $outer_scope = $this->scope;

        $inner_scope_list = [];
        $inner_exiting_scope_list = [];
        $merge_parent_scope = true;
        foreach ($node->children as $i => $case_node) {
            \assert($case_node instanceof Node);
            $cond_node = $case_node->children['cond'];
            $stmts_node = $case_node->children['stmts'];

            if ($cond_node instanceof Node) {
                // Analyzing if_node->children['cond'] should affect $outer_scope.
                // `switch(cond() { case $x = something(): case 3: use($x); }` is valid code.
                $outer_scope = $this->analyze($outer_scope, $cond_node);
            } elseif ($cond_node === null) {
                // this has a default, the case statements are comprehensive
                $merge_parent_scope = false;
            }

            // Skip over empty case statements (incomplete heuristic), TODO: test
            if (\count($stmts_node->children ?? []) !== 0 || $i === \count($node->children) - 1) {
                $inner_scope = new VariableTrackingLoopScope($outer_scope);
                $inner_scope = $this->analyze($inner_scope, $stmts_node);
                // Merge $inner_scope->skipped_loop_scopes
                '@phan-var VariableTrackingLoopScope $inner_scope';
                $inner_scope->flattenSwitchCaseScopes(self::$variable_graph);

                if (BlockExitStatusChecker::willUnconditionallyThrowOrReturn($stmts_node)) {
                    $inner_exiting_scope_list[] = $inner_scope;
                } else {
                    $inner_scope_list[] = $inner_scope;
                }
            }
        }

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanPartialTypeMismatchArgument
        return $outer_scope->mergeBranchScopeList($inner_scope_list, $merge_parent_scope, $inner_exiting_scope_list);
    }

    /**
     * @param Node $node a node of kind AST_CATCH_LIST
     * Analyzes catch statement lists.
     * @return VariableTrackingScope
     *
     * @see BlockAnalysisVisitor->visitTry (TODO: Use BlockExitStatusChecker)
     * @override
     */
    public function visitTry(Node $node)
    {
        $outer_scope = $this->scope;

        $try_scope = new VariableTrackingBranchScope($outer_scope);
        $try_scope = $this->analyze($try_scope, $node->children['try']);
        '@phan-var VariableTrackingBranchScope $try_scope';

        // TODO: Use BlockExitStatusChecker, like BlockAnalysisVisitor
        // TODO: Optimize
        $main_scope = $outer_scope->mergeBranchScopeList([$try_scope], true, []);

        $catch_node_list = $node->children['catches']->children;
        if (count($catch_node_list) > 0) {
            $main_scope = $this->analyze($main_scope, $node->children['catches']);
        }
        $finally_node = $node->children['finally'];
        if ($finally_node !== null) {
            return $this->analyze($main_scope, $finally_node);
        }
        return $main_scope;
    }

    /**
     * @param Node $node a node of kind AST_CATCH_LIST
     * Analyzes catch statement lists.
     * @return VariableTrackingScope
     *
     * @see BlockAnalysisVisitor->visitTry (TODO: Use BlockExitStatusChecker)
     * @override
     */
    public function visitCatchList(Node $node)
    {
        $outer_scope = $this->scope;

        $inner_scope_list = [];
        foreach ($node->children as $catch_node) {
            if (!($catch_node instanceof Node)) {
                // impossible
                continue;
            }
            // Replace the scope with the inner scope
            // TODO: Analyzing if_node->children['cond'] should affect $outer_scope?
            $inner_scope = new VariableTrackingBranchScope($outer_scope);
            $inner_scope = $this->analyze($inner_scope, $catch_node);
            $inner_scope_list[] = $inner_scope;
        }

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanPartialTypeMismatchArgument
        return $outer_scope->mergeBranchScopeList($inner_scope_list, false, []);
    }
}
