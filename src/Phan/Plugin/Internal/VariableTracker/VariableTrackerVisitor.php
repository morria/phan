<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal\VariableTracker;

use AssertionError;
use ast;
use ast\Node;
use Phan\Analysis\BlockExitStatusChecker;
use Phan\AST\AnalysisVisitor;
use Phan\AST\ArrowFunc;
use Phan\AST\InferPureSnippetVisitor;
use Phan\AST\Visitor\Element;
use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Parse\ParseVisitor;

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
 * TODO: Fix tests/files/src/0426_inline_var_force.php
 *
 * @phan-file-suppress PhanTypeMismatchArgumentNullable child nodes as used here are non-null
 */
final class VariableTrackerVisitor extends AnalysisVisitor
{
    /**
     * This shared graph instance maps definitions of variables (in a function-like context)
     * to the uses of that variable.
     *
     * @var VariableGraph
     */
    public static $variable_graph;

    /**
     * This represents the status of variables in the current scope
     * (e.g. which variable definitions are available to be used, etc.)
     *
     * @var VariableTrackingScope
     */
    private $scope;

    /**
     * @var ?Node the most recently visited statement within the AST_STMT_LIST.
     * This can be used to check if an expression such as $x++ is used by something else.
     *
     * Tracking the parent_node_list is possible, but would be much more verbose.
     */
    private $top_level_statement;

    /**
     * @var list<Node> a list of loop nodes that appeared to have no side effects.
     * VariableTrackerPlugin should check that some of the variables defined or redefined in the loop were used outside of the loop.
     */
    private $side_effect_free_loop_nodes = [];

    /**
     * @var list<Node> a list of loop nodes that may have infinite loops with conditions on variables that aren't reassigned in the loop.
     */
    private $possibly_infinite_loop_nodes = [];

    public function __construct(CodeBase $code_base, Context $context, VariableTrackingScope $scope)
    {
        parent::__construct($code_base, $context);
        $this->scope = $scope;
    }

    /**
     * This is the default implementation for node types which don't have any overrides
     * @override
     */
    public function visit(Node $node): VariableTrackingScope
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
     * Record variable usage (in a dynamic manner) due to calls such as compact()
     * @param ?Node $node a node of kind ast\AST_CALL
     * @suppress PhanUndeclaredProperty
     */
    public static function recordDynamicVariableUse(string $var_name, ?Node $node): void
    {
        if (!$node) {
            return;
        }
        if (!isset($node->dynamic_var_uses)) {
            $node->dynamic_var_uses = [];
        }
        $node->dynamic_var_uses[$var_name] = $var_name;
    }

    /**
     * Record the fact that the loop body doesn't seem to have side effects
     * (other than creating variables)
     *
     * @param Node $node a node that's some form of loop
     * @suppress PhanUndeclaredProperty
     */
    public static function recordHasLoopBodyWithoutSideEffects(Node $node): void
    {
        $node->has_loop_body_without_side_effects = true;
    }

    /**
     * @return list<Node>
     * A list of loop nodes that appeared to have no side effects.
     * VariableTrackerPlugin should check that some of the variables defined or redefined in the loop were used outside of the loop.
     */
    public function getSideEffectFreeLoopNodes(): array
    {
        return $this->side_effect_free_loop_nodes;
    }

    /**
     * @return list<Node>
     * A list of loop nodes that are possibly infinite loops.
     */
    public function getPossiblyInfiniteLoopNodes(): array
    {
        return $this->possibly_infinite_loop_nodes;
    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    public function visitCall(Node $node): VariableTrackingScope
    {
        if (isset($node->dynamic_var_uses)) {
            $this->handleDynamicVarUses($node, $node->dynamic_var_uses);
        }
        if (isset($node->check_infinite_recursion)) {
            $this->handleInfiniteRecursion($node, $node->check_infinite_recursion);
        }
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        }
        return $this->scope;
    }

    public function visitNullsafeMethodCall(Node $node): VariableTrackingScope
    {
        return $this->visitCall($node);
    }

    public function visitMethodCall(Node $node): VariableTrackingScope
    {
        return $this->visitCall($node);
    }

    public function visitStaticCall(Node $node): VariableTrackingScope
    {
        return $this->visitCall($node);
    }

    /**
     * @param Node $node a node of kind ast\AST_CALL, e.g. for compact()
     * @param array<string,string> $dynamic_var_uses
     */
    private function handleDynamicVarUses(Node $node, array $dynamic_var_uses): void
    {
        foreach ($dynamic_var_uses as $name) {
            self::$variable_graph->recordVariableUsage($name, $node, $this->scope);
        }
    }

    /**
     * Tracks which variable definitions have been overwritten by every branch of a new scope
     */
    private static function registerDefsShadowedAcrossMultipleBranches(
        VariableTrackingScope $prev_scope,
        VariableTrackingScope $new_scope
    ): void {
        foreach ($new_scope->defs_shadowing_set as $variable_name => $_) {
            $prev_defs = $prev_scope->getDefinition($variable_name) ?? [];
            $new_defs = $new_scope->getDefinition($variable_name) ?? [];
            // Which previous definitions cannot be accessed anymore?
            $inaccessible_prev_defs = array_diff_key($prev_defs, $new_defs);
            if ($inaccessible_prev_defs) {
                self::$variable_graph->recordVariableOverwriteOnBranches($variable_name, $inaccessible_prev_defs);
            }
        }
    }

    /**
     * @param array{0:non-empty-list<string>,1:string} $check_infinite_recursion an array of 1 or more argument names to check for redefinition, and a name of the method
     */
    private function handleInfiniteRecursion(Node $node, array $check_infinite_recursion): void
    {
        [$arg_names, $method_name] = $check_infinite_recursion;
        foreach ($arg_names as $arg_name) {
            if (\count(self::$variable_graph->def_lines[$arg_name] ?? []) !== 1) {
                return;
            }
        }
        $this->emitIssue(
            Issue::PossibleInfiniteRecursionSameParams,
            $node->lineno,
            $method_name
        );
    }

    /**
     * Visit a statement list
     * @override
     */
    public function visitStmtList(Node $node): VariableTrackingScope
    {
        $top_level_statement = $this->top_level_statement;
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            // TODO: Specialize?
            $this->top_level_statement = $child_node;
            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        }
        $this->top_level_statement = $top_level_statement;
        return $this->scope;
    }

    /**
     * Visit an expression list
     * @override
     */
    public function visitExprList(Node $node): VariableTrackingScope
    {
        $top_level_statement = $this->top_level_statement;
        foreach ($node->children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            // TODO: Specialize?
            $this->top_level_statement = $child_node;
            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        }
        $this->top_level_statement = $top_level_statement;
        return $this->scope;
    }

    /**
     * Visit a node of kind ast\AST_MATCH_ARM
     * @override
     */
    public function visitMatchArm(Node $node): VariableTrackingScope
    {
        // Traverse the AST_EXPR_LIST or null
        foreach ($node->children['cond']->children ?? [] as $cond_child_node) {
            if (!($cond_child_node instanceof Node)) {
                continue;
            }

            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$cond_child_node->kind] ?? 'handleMissingNodeKind'}($cond_child_node);
        }
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            $this->scope = $this->{Element::VISIT_LOOKUP_TABLE[$expr->kind] ?? 'handleMissingNodeKind'}($expr);
        }
        return $this->scope;
    }

    /**
     * @override
     */
    public function visitAssignRef(Node $node): VariableTrackingScope
    {
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            self::markVariablesAsReference($expr);
            $this->scope = $this->analyze($this->scope, $expr);
        }
        $var_node = $node->children['var'];
        if ($var_node instanceof Node && $var_node->kind === \ast\AST_VAR) {
            $name = $var_node->children['name'];
            if (is_string($name)) {
                self::$variable_graph->recordVariableUsage($name, $var_node, $this->scope);
            }
        }
        return $this->analyzeAssignmentTarget($var_node, true, null);
    }

    private static function markVariablesAsReference(Node $expr): void
    {
        while (\in_array($expr->kind, [ast\AST_DIM, ast\AST_PROP], true)) {
            $expr = $expr->children['expr'];
            if (!$expr instanceof Node) {
                return;
            }
        }
        if ($expr->kind === ast\AST_VAR) {
            $name = $expr->children['name'];
            if (is_string($name)) {
                self::$variable_graph->markAsReference($name);
            }
        }
    }

    /**
     * Analyze X++
     * @override
     */
    public function visitPostInc(Node $node): VariableTrackingScope
    {
        return $this->analyzeIncDec($node);
    }

    /**
     * Analyze X--
     * @override
     */
    public function visitPostDec(Node $node): VariableTrackingScope
    {
        return $this->analyzeIncDec($node);
    }

    /**
     * Analyze ++X
     * @override
     */
    public function visitPreInc(Node $node): VariableTrackingScope
    {
        return $this->analyzeIncDec($node);
    }

    /**
     * Analyze --X
     * @override
     */
    public function visitPreDec(Node $node): VariableTrackingScope
    {
        return $this->analyzeIncDec($node);
    }

    private function analyzeIncDec(Node $node): VariableTrackingScope
    {
        $var_node = $node->children['var'];
        if ($var_node instanceof Node && $var_node->kind === ast\AST_VAR) {
            $name = $var_node->children['name'];
            if (is_string($name)) {
                // $node is the usage of this variable
                // Here, we use $node instead of $var_node as the declaration node so that recordVariableUsage won't treat increments in loops as using themselves.
                self::$variable_graph->recordVariableUsage($name, $node, $this->scope);
                // And the whole inc/dec operation is the redefinition of this variable.
                self::$variable_graph->recordVariableDefinition($name, $node, $this->scope, null);
                $this->scope->recordDefinition($name, $node);
                if ($this->top_level_statement !== $node) {
                    // To reduce false positives, warn about `;$x++;` but not `foo($x++)`
                    self::$variable_graph->markAsDisabledWarnings($node);
                }
                return $this->scope;
            }
        }
        return $this->visit($node);
    }

    /**
     * @override
     */
    public function visitAssignOp(Node $node): VariableTrackingScope
    {
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            $this->scope = $this->analyze($this->scope, $expr);
        }
        $var_node = $node->children['var'];
        if (!($var_node instanceof Node)) {
            return $this->scope;
        }
        switch ($var_node->kind) {
            case ast\AST_VAR:
                $name = $var_node->children['name'];
                if (!is_string($name)) {
                    break;
                }
                // The left-hand node ($node) is the usage of this variable
                // We use the same node id so that phan will warn about unused declarations within loops
                self::$variable_graph->recordVariableUsage($name, $node, $this->scope);
                // And the whole assignment operation is the redefinition of this variable
                self::$variable_graph->recordVariableDefinition($name, $node, $this->scope, null);
                $this->scope->recordDefinition($name, $node);
                return $this->scope;
            case ast\AST_PROP:
                return $this->analyzePropAssignmentTarget($var_node);
            case ast\AST_DIM:
                return $this->analyzeDimAssignmentTarget($var_node);
                // TODO: Analyze array access and param/return references of function/method calls.
            default:
                // Static property or an unexpected target.
                // Analyze this normally.
                return $this->analyze($this->scope, $var_node);
        }
        return $this->scope;
    }

    /**
     * @override
     */
    public function visitAssign(Node $node): VariableTrackingScope
    {
        $expr = $node->children['expr'];
        if ($expr instanceof Node) {
            $this->scope = $this->analyze($this->scope, $expr);
        }
        return $this->analyzeAssignmentTarget($node->children['var'], false, self::getConstExprOrNull($expr));
    }

    public function visitUnset(Node $node): VariableTrackingScope
    {
        $var_node = $node->children['var'];
        if (!$var_node instanceof Node) {
            return $this->scope;
        }
        self::$variable_graph->markAsUnset($var_node);
        // @phan-suppress-next-line PhanUndeclaredProperty
        $var_node->is_unset_target = true;

        return $this->analyzeAssignmentTarget($var_node, false, null);
    }

    /**
     * @param Node|string|int|float $expr
     * @return Node|string|int|float|null
     */
    private static function getConstExprOrNull($expr)
    {
        // Don't allow new expressions
        return ParseVisitor::isConstExpr($expr, ParseVisitor::CONSTANT_EXPRESSION_FORBID_NEW_EXPRESSION) ? $expr : null;
    }

    /**
     * @param Node|int|string|float|null $node
     * @param Node|int|string|float|null $const_expr
     */
    private function analyzeAssignmentTarget($node, bool $is_ref, $const_expr): VariableTrackingScope
    {
        // TODO: Push onto the node list?
        if (!($node instanceof Node)) {
            return $this->scope;
        }
        switch ($node->kind) {
            case ast\AST_VAR:
                $name = $node->children['name'];
                if (!is_string($name)) {
                    break;
                }
                if ($is_ref) {
                    self::$variable_graph->markAsReference($name);
                }
                self::$variable_graph->recordVariableDefinition($name, $node, $this->scope, $const_expr);
                $this->scope->recordDefinition($name, $node);
                return $this->scope;
            case ast\AST_ARRAY:
                return $this->analyzeArrayAssignmentTarget($node, $const_expr);

            case ast\AST_REF:
                return $this->analyzeAssignmentTarget($node->children['var'], true, null);
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

    /**
     * @param Node|int|string|float|null $const_expr
     */
    private function analyzeArrayAssignmentTarget(Node $node, $const_expr): VariableTrackingScope
    {
        foreach ($node->children as $elem_node) {
            if (!($elem_node instanceof Node)) {
                continue;
            }
            if ($elem_node->kind !== ast\AST_ARRAY_ELEM) {
                // We already emitted PhanInvalidNode
                continue;
            }
            // Treat $key in `[$key => $y] = $array` as a usage of $key
            $this->scope = $this->analyzeWhenValidNode($this->scope, $elem_node->children['key']);
            $this->scope = $this->analyzeAssignmentTarget($elem_node->children['value'], false, $const_expr);
        }
        return $this->scope;
    }

    /**
     * @suppress PhanUndeclaredProperty
     */
    private function analyzePropAssignmentTarget(Node $node): VariableTrackingScope
    {
        // Treat $y in `$x->$y = $z;` as a usage of $y
        $this->scope = $this->analyzeWhenValidNode($this->scope, $node->children['prop']);
        $expr = $node->children['expr'];
        if ($expr instanceof Node && $expr->kind === \ast\AST_VAR) {
            $name = $expr->children['name'];
            if (is_string($name)) {
                // treat $x->prop = 2 like a usage of $x
                if (isset($node->is_unset_target)) {
                    self::$variable_graph->markAsUnset($expr);
                }
                self::$variable_graph->recordVariableUsage($name, $expr, $this->scope);
                self::$variable_graph->recordVariableModification($name);
            }
        }
        return $this->analyzeWhenValidNode($this->scope, $expr);  // lower false positives by not treating this as a definition
        // // treat $x->prop = 2 like a definition to $x (in addition to having treated this as a usage)
        // return $this->analyzeAssignmentTarget($expr, false);
    }

    private function analyzeDimAssignmentTarget(Node $node): VariableTrackingScope
    {
        // Treat $y in `$x[$y] = $z;` as a usage of $y
        $this->scope = $this->analyzeWhenValidNode($this->scope, $node->children['dim']);
        $expr = $node->children['expr'];

        while ($expr instanceof Node) {
            if ($expr->kind === \ast\AST_VAR) {
                $name = $expr->children['name'];
                if (is_string($name)) {
                    // treat $x['dim_name'] = 2 like a usage of $x, unless we're certain that $x is an array instead of ArrayAccess.
                    //
                    // TODO: More aggressively warn if there is only a single dimension to $x
                    self::$variable_graph->recordVariableUsage($name, $expr, $this->scope);
                    // @phan-suppress-next-line PhanUndeclaredProperty
                    if (isset($expr->phan_is_assignment_to_real_array)) {
                        self::$variable_graph->recordVariableDefinition($name, $expr, $this->scope, null);
                    // @phan-suppress-next-line PhanUndeclaredProperty
                    } elseif (isset($node->is_unset_target)) {
                        self::$variable_graph->markAsUnset($expr);
                        self::$variable_graph->recordVariableDefinition($name, $expr, $this->scope, null);
                    } else {
                        self::$variable_graph->recordVariableModification($name);
                    }
                }
                break;
            } elseif (\in_array($expr->kind, [ast\AST_DIM, ast\AST_PROP], true)) {
                $expr = $expr->children['expr'];
            } else {
                break;
            }
        }
        return $this->analyzeWhenValidNode($this->scope, $node->children['expr']);  // lower false positives by not treating this as a definition
        // // treat $x['dim_name'] = 2 like a definition to $x (in addition to having treated this as a usage)
        // return $this->analyzeAssignmentTarget($expr, false);
    }

    /**
     * @unused-param $node
     */
    public function handleMissingNodeKind(Node $node): VariableTrackingScope
    {
        // do nothing
        return $this->scope;
    }

    /**
     * @param Node|string|int|float|null $child_node
     */
    private function analyzeWhenValidNode(VariableTrackingScope $scope, $child_node): VariableTrackingScope
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
     */
    private function analyze(VariableTrackingScope $scope, Node $child_node): VariableTrackingScope
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
     * @unused-param $node
     * @override
     */
    public function visitFuncDecl(Node $node): VariableTrackingScope
    {
        return $this->scope;
    }

    /**
     * Do not recurse into class declarations within a scope
     * @unused-param $node
     * @override
     */
    public function visitClass(Node $node): VariableTrackingScope
    {
        return $this->scope;
    }

    /**
     * Do not recurse into closure declarations within a scope.
     * @override
     */
    public function visitClosure(Node $node): VariableTrackingScope
    {
        foreach ($node->children['uses']->children ?? [] as $closure_use) {
            if (!($closure_use instanceof Node)) {
                continue;
            }

            $name = $closure_use->children['name'];
            if (!is_string($name)) {
                continue;
            }

            if ($closure_use->flags & ast\flags\CLOSURE_USE_REF) {
                self::$variable_graph->recordVariableDefinition($name, $closure_use, $this->scope, null);
                self::$variable_graph->markAsReference($name);
            } else {
                self::$variable_graph->recordVariableUsage($name, $closure_use, $this->scope);
            }
        }
        return $this->scope;
    }

    /**
     * Do not recurse into short arrow (`fn() => ...`) closure declarations within a scope.
     *
     * TODO: This could be improved by checking if the short arrow redefines the variable and ignores the original value.
     * @override
     */
    public function visitArrowFunc(Node $node): VariableTrackingScope
    {
        foreach (ArrowFunc::getUses($node) as $name => $var_node) {
            self::$variable_graph->recordVariableUsage((string)$name, $var_node, $this->scope);
        }
        return $this->scope;
    }

    /**
     * Common no-op
     *
     * @override
     * @unused-param $node
     */
    public function visitName(Node $node): VariableTrackingScope
    {
        return $this->scope;
    }

    /**
     * TODO: Check if the current context is a function call passing an argument by reference
     * @override
     */
    public function visitVar(Node $node): VariableTrackingScope
    {
        $name = $node->children['name'];
        if (\is_string($name)) {
            self::$variable_graph->recordVariableUsage($name, $node, $this->scope);
            // @phan-suppress-next-line PhanUndeclaredProperty
            if ($node === $this->top_level_statement || isset($node->modified_by_reference)) {
                // @phan-suppress-next-line PhanUndeclaredProperty
                if (isset($node->modified_by_reference)) {
                    self::$variable_graph->markAsDisabledWarnings($node);
                }
                self::$variable_graph->recordVariableDefinition($name, $node, $this->scope, null);
                $this->scope->recordDefinition($name, $node);
            }
        } elseif ($name instanceof Node) {
            return $this->analyze($this->scope, $name);
        }
        return $this->scope;
    }

    /**
     * Marks a node of kind ast\AST_VAR as modified by reference (e.g. by a call)
     *
     * @suppress PhanUndeclaredProperty
     */
    public static function markVariableAsModifiedByReference(Node $node): void
    {
        $node->modified_by_reference = true;
    }

    /**
     * Analyzes `static $var [ = default ];`
     * @override
     */
    public function visitStatic(Node $node): VariableTrackingScope
    {
        $name = $node->children['var']->children['name'] ?? null;
        if (\is_string($name)) {
            self::$variable_graph->markAsStaticVariable($name);
            self::$variable_graph->recordVariableDefinition($name, $node, $this->scope, null);
            $this->scope->recordDefinition($name, $node);
        }
        return $this->scope;
    }

    /**
     * Analyzes `global $var;` (analyzed like it was declared with the value from the global scope).
     * @override
     */
    public function visitGlobal(Node $node): VariableTrackingScope
    {
        self::$variable_graph->markAsGlobal($node, $this->scope);
        return $this->scope;
    }

    /**
     * Analyzes `foreach ($expr as $key => $value) { stmts }
     */
    public function visitForeach(Node $node): VariableTrackingScope
    {
        $this->checkIsSideEffectFreeLoopNode($node);

        $expr_node = $node->children['expr'];
        $outer_scope_unbranched = $this->analyzeWhenValidNode($this->scope, $expr_node);
        $outer_scope = new VariableTrackingBranchScope($outer_scope_unbranched);

        // Replace the scope with the inner scope
        $this->scope = new VariableTrackingLoopScope($outer_scope);

        $key_node = $node->children['key'];
        $this->scope = $this->analyzeAssignmentTarget($key_node, false, null);

        $value_node = $node->children['value'];
        if (isset($key_node)) {
            self::$variable_graph->markAsLoopValueNode($value_node);
        }
        $this->scope = $this->analyzeAssignmentTarget($value_node, false, null);  // analyzeAssignmentTarget checks for AST_REF

        // TODO: Update graph: inner loop definitions can be used inside the loop.
        // TODO: Create a branchScope? - Loop iterations can run 0 times.
        $inner_scope = $this->analyze($this->scope, $node->children['stmts']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
        $outer_scope = $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);

        return $outer_scope_unbranched->mergeWithSingleBranchScope($outer_scope);
    }

    /**
     * Analyzes `while (cond) { stmts }` with kind ast\AST_WHILE
     * @override
     */
    public function visitWhile(Node $node): VariableTrackingScope
    {
        $this->checkIsSideEffectFreeLoopNode($node);

        $outer_scope_unbranched = $this->analyzeWhenValidNode($this->scope, $node->children['cond']);
        $outer_scope = new VariableTrackingBranchScope($outer_scope_unbranched);

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['cond']);
        '@phan-var VariableTrackingLoopScope $inner_scope';

        // Merge inner scope into outer scope
        $outer_scope = $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
        return $outer_scope_unbranched->mergeWithSingleBranchScope($outer_scope);
    }

    /**
     * Analyzes `do { stmts } while (cond);`
     *
     * TODO: Fix https://github.com/phan/phan/issues/2029
     *
     * @param Node $node a node of type AST_DO_WHILE
     * @override
     */
    public function visitDoWhile(Node $node): VariableTrackingScope
    {
        $this->checkIsSideEffectFreeLoopNode($node);

        $outer_scope_unbranched = $this->scope;
        $outer_scope = new VariableTrackingBranchScope($outer_scope_unbranched);

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);
        $inner_scope = $this->analyzeWhenValidNode($inner_scope, $node->children['cond']);
        '@phan-var VariableTrackingLoopScope $inner_scope';

        // Merge inner scope into outer scope
        $outer_scope = $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
        '@phan-var VariableTrackingLoopScope $inner_scope';
        return $outer_scope_unbranched->mergeWithSingleBranchScope($outer_scope);
    }

    /**
     * Analyzes `for (init; cond; loop) { stmts }`
     * @param Node $node a node of type AST_FOR
     * @override
     */
    public function visitFor(Node $node): VariableTrackingScope
    {
        $this->checkIsSideEffectFreeLoopNode($node);

        $top_level_statement = $this->top_level_statement;
        $init_node = $node->children['init'];
        if ($init_node instanceof Node) {
            $this->top_level_statement = $init_node;
            $outer_scope_unbranched = $this->analyze($this->scope, $init_node);
        } else {
            $outer_scope_unbranched = $this->scope;
        }
        $outer_scope_unbranched = $this->analyzeCondExprList($outer_scope_unbranched, $node->children['cond']);
        $outer_scope = new VariableTrackingBranchScope($outer_scope_unbranched);

        $inner_scope = new VariableTrackingLoopScope($outer_scope);
        // Iterate over the nodes in AST_EXPR_LIST `loop` for `for (init; cond; loop)` to check their uses and definitions of variables
        foreach ($node->children['loop']->children ?? [] as $loop_node) {
            if (!($loop_node instanceof Node)) {
                continue;
            }
            $this->top_level_statement = $loop_node;
            $loop_scope = $this->analyze(new VariableTrackingBranchScope($inner_scope), $loop_node);
            // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
            $inner_scope = $inner_scope->mergeWithSingleBranchScope($loop_scope);
            $this->top_level_statement = $top_level_statement;
        }
        // TODO: If the graph analysis is improved, look into making this stop analyzing 'loop' twice
        $inner_scope = $this->analyzeCondExprList($inner_scope, $node->children['cond']);
        $inner_scope = $this->analyze($inner_scope, $node->children['stmts']);
        foreach ($node->children['loop']->children ?? [] as $loop_node) {
            if ($loop_node instanceof Node) {
                $this->top_level_statement = $loop_node;
                $loop_scope = $this->analyze(new VariableTrackingBranchScope($inner_scope), $loop_node);
                // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
                $inner_scope = $inner_scope->mergeWithSingleBranchScope($loop_scope);
                $this->top_level_statement = $top_level_statement;
            }
        }
        $inner_scope = $this->analyzeCondExprList($inner_scope, $node->children['cond']);

        // Merge inner scope into outer scope
        // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
        $outer_scope = $outer_scope->mergeInnerLoopScope($inner_scope, self::$variable_graph);
        return $outer_scope_unbranched->mergeWithSingleBranchScope($outer_scope);
    }

    /**
     * @param Node|float|int|string|null $cond
     */
    private function analyzeCondExprList(VariableTrackingScope $scope, $cond): VariableTrackingScope
    {
        if (!$cond instanceof Node) {
            return $scope;
        }
        $children = $cond->children;
        $last_child_node = \end($children);
        $top_level_statement = $this->top_level_statement;
        foreach ($children as $child_node) {
            if (!($child_node instanceof Node)) {
                continue;
            }

            $this->top_level_statement = $child_node === $last_child_node ? $cond : $child_node;
            $scope = $this->analyze($scope, $child_node);
        }
        $this->top_level_statement = $top_level_statement;
        return $scope;
    }

    /**
     * Analyzes if statements.
     *
     * @param Node $node a node of kind AST_IF
     * @see BlockAnalysisVisitor::visitIf()
     * @override
     */
    public function visitIf(Node $node): VariableTrackingScope
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
            $this->scope = $outer_scope;

            $inner_scope = new VariableTrackingBranchScope($outer_scope);
            $inner_scope = $this->analyze($inner_scope, $stmts_node);

            '@phan-var VariableTrackingBranchScope $inner_scope';

            if (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($stmts_node)) {
                $exits = BlockExitStatusChecker::willUnconditionallyThrowOrReturn($stmts_node);
                $outer_scope->recordSkippedScope($inner_scope, $exits);
            } else {
                $inner_scope_list[] = $inner_scope;
            }
            // @phan-suppress-next-line PhanSuspiciousTruthyString
            if ($cond_node === null || (\is_scalar($cond_node) && $cond_node)) {
                $merge_parent_scope = false;
            }
        }

        // Merge inner scope into outer scope
        $new_scope = $outer_scope->mergeBranchScopeList($inner_scope_list, $merge_parent_scope, []);
        self::registerDefsShadowedAcrossMultipleBranches($outer_scope, $new_scope);
        return $new_scope;
    }

    /**
     * Analyzes switch statements.
     *
     * @param Node $node a node of kind AST_SWITCH
     * @override
     */
    public function visitSwitchList(Node $node): VariableTrackingScope
    {
        $outer_scope = $this->scope;

        $inner_scope_list = [];
        $inner_exiting_scope_list = [];
        $merge_parent_scope = true;
        $inner_scope = null;
        foreach ($node->children as $i => $case_node) {
            if (!($case_node instanceof Node)) {
                throw new AssertionError("Expected case statements to be nodes");
            }
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
                if ($inner_scope) {
                    // $this->analyze() returns a VariableTrackingLoopScope when a VariableTrackingLoopScope is passed in.
                    '@phan-var VariableTrackingLoopScope $inner_scope';
                    $inner_scope = clone($inner_scope);
                    $inner_scope->inheritDefsFromOuterScope($outer_scope);
                } else {
                    $inner_scope = new VariableTrackingLoopScope($outer_scope);
                }
                $inner_scope = $this->analyze($inner_scope, $stmts_node);
                // Merge $inner_scope->skipped_loop_scopes
                '@phan-var VariableTrackingLoopScope $inner_scope';
                $inner_scope->flattenSwitchCaseScopes(self::$variable_graph);

                // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
                $block_exit_status = (new BlockExitStatusChecker())->__invoke($stmts_node);
                if (($block_exit_status & BlockExitStatusChecker::STATUS_THROW_OR_RETURN_BITMASK) === $block_exit_status) {
                    $inner_exiting_scope_list[] = $inner_scope;
                } elseif ($block_exit_status !== BlockExitStatusChecker::STATUS_PROCEED ||
                        $i === \count($node->children) - 1) {
                    $inner_scope_list[] = $inner_scope;
                }
                if (!($block_exit_status & BlockExitStatusChecker::STATUS_PROCEED)) {
                    // This won't fall through, so don't clone the inner scope for the next loop.
                    $inner_scope = null;
                }
            }
        }

        // Merge inner scope into outer scope
        $new_scope = $outer_scope->mergeBranchScopeList($inner_scope_list, $merge_parent_scope, $inner_exiting_scope_list);
        self::registerDefsShadowedAcrossMultipleBranches($outer_scope, $new_scope);
        return $new_scope;
    }

    /**
     * Implements analysis of `cond_node ? true_node : false_node` and `cond_node ?: false_node`
     * @override
     */
    public function visitConditional(Node $node): VariableTrackingScope
    {
        $outer_scope = $this->scope;
        $cond_node = $node->children['cond'];
        if ($cond_node instanceof Node) {
            // Could handle non-nodes, optionally
            $outer_scope = $this->analyze($outer_scope, $cond_node);
        }
        $inner_scope_list = [];

        $merge_parent_scope = false;
        foreach ([$node->children['true'], $node->children['false']] as $child_node) {
            if ($child_node instanceof Node) {
                $inner_scope = new VariableTrackingBranchScope($outer_scope);
                $inner_scope = $this->analyze($inner_scope, $child_node);
                '@phan-var VariableTrackingBranchScope $inner_scope';
                $inner_scope_list[] = $inner_scope;
            } else {
                $merge_parent_scope = true;
            }
        }
        // Merge inner scope into outer scope
        $new_scope = $outer_scope->mergeBranchScopeList($inner_scope_list, $merge_parent_scope, []);
        self::registerDefsShadowedAcrossMultipleBranches($outer_scope, $new_scope);
        return $new_scope;
    }

    /**
     * Analyzes try nodes and their catch statement lists and finally blocks.
     *
     * @param Node $node a node of kind AST_TRY
     * @override
     */
    public function visitTry(Node $node): VariableTrackingScope
    {
        $outer_scope = $this->scope;

        $try_scope = new VariableTrackingBranchScope($outer_scope);
        ['try' => $try_node, 'catches' => $catches_node, 'finally' => $finally_node] = $node->children;
        $try_scope = $this->analyze($try_scope, $try_node);
        '@phan-var VariableTrackingBranchScope $try_scope';

        // TODO: Use BlockExitStatusChecker, like BlockAnalysisVisitor
        // TODO: Optimize
        $main_scope = $outer_scope->mergeWithSingleBranchScope($try_scope);
        $catches_will_throw_or_return = BlockExitStatusChecker::willUnconditionallyThrowOrReturn($catches_node);

        $catch_node_list = $catches_node->children;
        if (\count($catch_node_list) > 0) {
            $catches_scope = new VariableTrackingBranchScope($main_scope);
            $catches_scope = $this->analyze($catches_scope, $catches_node);
            if (!$catches_will_throw_or_return) {
                if (BlockExitStatusChecker::willUnconditionallyThrowOrReturn($try_node)) {
                    // @phan-suppress-next-line PhanTypeMismatchArgument
                    $main_scope = $main_scope->mergeBranchScopeList([$catches_scope], false, []);
                } else {
                    // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
                    $main_scope = $main_scope->mergeWithSingleBranchScope($catches_scope);
                }
            }
        }
        if ($finally_node !== null) {
            return $this->analyze($main_scope, $finally_node);
        }
        if ($catches_will_throw_or_return) {
            $combined_scope = $outer_scope->mergeBranchScopeList([$try_scope], false, []);
            self::registerDefsShadowedAcrossMultipleBranches($outer_scope, $combined_scope);
            return $combined_scope;
        }
        self::registerDefsShadowedAcrossMultipleBranches($outer_scope, $main_scope);
        return $main_scope;
    }

    /**
     * Analyzes catch statement lists.
     * @param Node $node a node of kind AST_CATCH_LIST
     * @override
     */
    public function visitCatchList(Node $node): VariableTrackingScope
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

    /**
     * Analyzes catch statement lists.
     * @param Node $node a node of kind AST_CATCH
     * @override
     */
    public function visitCatch(Node $node): VariableTrackingScope
    {
        $var_node = $node->children['var'];
        $scope = $this->scope;
        // handle php 8.0 non-capturing catches
        if ($var_node instanceof Node && $var_node->kind === \ast\AST_VAR) {
            $name = $var_node->children['name'];
            if (is_string($name)) {
                self::$variable_graph->recordVariableDefinition($name, $var_node, $scope, null);
                self::$variable_graph->markAsCaughtException($var_node);
                $scope->recordDefinition($name, $var_node);
            }
        }
        return $this->analyze($scope, $node->children['stmts']);
    }

    private function checkIsSideEffectFreeLoopNode(Node $node): void
    {
        // @phan-suppress-next-line PhanUndeclaredProperty
        if (isset($node->has_loop_body_without_side_effects)) {
            $this->side_effect_free_loop_nodes[] = $node;
        }
        $cond = $node->children['cond'] ?? null;
        if ($cond instanceof Node &&
            !((new BlockExitStatusChecker())($node->children['stmts']) & ~(BlockExitStatusChecker::STATUS_PROCEED | BlockExitStatusChecker::STATUS_CONTINUE)) &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $this->context, $cond) &&
            !self::hasUnknownTypeLoopNodeKinds($cond)) {
            if (!isset($node->children['loop']) ||
                !((new BlockExitStatusChecker())($node->children['loop']) & ~(BlockExitStatusChecker::STATUS_PROCEED))) {
                $this->possibly_infinite_loop_nodes[] = $node;
            }
        }
    }

    private static function hasUnknownTypeLoopNodeKinds(Node $node): bool
    {
        switch ($node->kind) {
            case ast\AST_CLOSURE:
            case ast\AST_ARROW_FUNC:
            case ast\AST_PROP:
            case ast\AST_STATIC_PROP:
            case ast\AST_PRE_DEC:
            case ast\AST_PRE_INC:
            case ast\AST_POST_DEC:
            case ast\AST_POST_INC:
            case ast\AST_ASSIGN_OP:
                return true;
            case ast\AST_CALL:
                if (self::isNonDeterministicCall($node)) {
                    return true;
                }
                break;
        }
        foreach ($node->children as $c) {
            if ($c instanceof Node && self::hasUnknownTypeLoopNodeKinds($c)) {
                return true;
            }
        }
        return false;
    }

    private const NON_DETERMINISTIC_FUNCTIONS = [
        'feof' => true,
        'fgetcsv' => true,
        'fgets' => true,
        'fread' => true,
        'ftell' => true,
        'readdir' => true,
        'rand' => true,
        'array_rand' => true,
        'mt_rand' => true,
        'openssl_random_pseudo_bytes' => true,
        'random_bytes' => true,
        'random_int' => true,
        'next' => true,
        'prev' => true,
    ];

    private static function isNonDeterministicCall(Node $node): bool
    {
        $name_node = $node->children['expr'];
        if (!$name_node instanceof Node || $name_node->kind !== ast\AST_NAME) {
            return false;
        }
        $name = $name_node->children['name'];
        if (!is_string($name)) {
            return false;
        }
        return \array_key_exists($name, self::NON_DETERMINISTIC_FUNCTIONS);
    }
}
