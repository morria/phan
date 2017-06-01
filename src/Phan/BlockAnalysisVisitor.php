<?php declare(strict_types=1);
namespace Phan;

use Phan\AST\AnalysisVisitor;
use Phan\AST\Visitor\Element;
use Phan\Analysis\ConditionVisitor;
use Phan\Analysis\ContextMergeVisitor;
use Phan\Analysis\PostOrderAnalysisVisitor;
use Phan\Analysis\PreOrderAnalysisVisitor;
use Phan\Language\Context;
use Phan\Language\Scope\BranchScope;
use Phan\Plugin\ConfigPluginSet;
use ast\Node;
use ast\Node\Decl;

/**
 * Analyze blocks of code
 */
class BlockAnalysisVisitor extends AnalysisVisitor {

    /**
     * @var ?Node
     * The parent of the current node
     */
    private $parent_node;

    /**
     * @var int
     * The depth of the node being analyzed in the
     * AST
     */
    private $depth;

    /**
     * @var bool
     * Whether or not this visitor will visit all nodes
     */
    private $should_visit_everything;

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param Node $parent_node
     * The parent of the node being analyzed
     *
     * @param int $depth
     * The depth of the node being analyzed in the AST
     *
     * @param bool|null $should_visit_everything
     * Determined from the Config instance. Cached to avoid overhead of function calls.
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        Node $parent_node = null,
        int $depth = 0,
        bool $should_visit_everything = null
    ) {
        $should_visit_everything = $should_visit_everything ?? Analysis::shouldVisitEverything();
        parent::__construct($code_base, $context);
        $this->parent_node = $parent_node;
        $this->depth = $depth;
        $this->should_visit_everything = $should_visit_everything;
    }

    /**
     * For non-special nodes, we propagate the context and scope
     * from the parent, through the children and return the
     * modified scope
     *
     *          │
     *          ▼
     *       ┌──●
     *       │
     *       ●──●──●
     *             │
     *          ●──┘
     *          │
     *          ▼
     *
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visit(Node $node) : Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno ?? 0
        );

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base, $context
        ))($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base, $context, $node
        );

        assert(!empty($context), 'Context cannot be null');

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children ?? [] as $child_node) {
            // Skip any non Node children or boring nodes
            // that are too deep.
            if (!($child_node instanceof Node)
                || !($this->should_visit_everything || Analysis::shouldVisitNode($child_node))
            ) {
                $context->withLineNumberStart(
                    $child_node->lineno ?? 0
                );
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $child_node);
        }

        $context = $this->postOrderAnalyze($context, $node);

        return $context;
    }

    /**
     * This is an abstraction for getting a new, updated context for a child node.
     *
     * Effectively the same as (new BlockAnalysisVisitor(..., $context, $node, ..., $depth + 1, ...)($child_node))
     * but is much less repetitive and verbose, and slightly more efficient.
     *
     * @param Context $context - The original context for $node, before analyzing $child_node
     *
     * @param Node $node - The parent node of $child_node
     *
     * @param Node $child_node - The node which will be analyzed to create the updated context.
     *
     * @return Context (The unmodified $context, or a different Context instance with modifications)
     */
    private function analyzeAndGetUpdatedContext(Context $context, Node $node, Node $child_node) : Context
    {
        // Modify the original object instead of creating a new BlockAnalysisVisitor.
        // this is slightly more efficient, especially if a large number of unchanged parameters would exist.
        $old_context = $this->context;
        $old_parent_node = $this->parent_node;
        $old_depth = $this->depth++;
        $this->context = $context;
        $this->parent_node = $node;
        try {
            return Element::acceptNodeAndKindVisitor($child_node, $this);
        } finally {
            $this->context = $old_context;
            $this->parent_node = $old_parent_node;
            $this->depth = $old_depth;
        }
    }

    /**
     * For nodes that are the root of mutually exclusive child
     * nodes (if, try), we analyze each child in the parent context
     * and then merge them together to try to guess what happens
     * after the branching finishes.
     *
     *           │
     *           ▼
     *        ┌──●──┐
     *        │  │  │
     *        ●  ●  ●
     *        │  │  │
     *        └──●──┘
     *           │
     *           ▼
     *
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitBranchedContext(Node $node) : Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno ?? 0
        );

        $context = $this->preOrderAnalyze($context, $node);

        assert(!empty($context), 'Context cannot be null');

        // We collect all child context so that the
        // PostOrderAnalysisVisitor can optionally operate on
        // them
        $child_context_list = [];

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children ?? [] as $node_key => $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            if (!($this->should_visit_everything || Analysis::shouldVisitNode($child_node))) {
                continue;
            }

            // The conditions need to communicate to the outter
            // scope for things like assigning veriables.
            if ($child_node->kind != \ast\AST_IF_ELEM) {
                $child_context = $context->withScope(
                    new BranchScope($context->getScope())
                );
            } else {
                $child_context = $context;
            }

            $child_context->withLineNumberStart(
                $child_node->lineno ?? 0
            );

            // Step into each child node and get an
            // updated context for the node
            $child_context = $this->analyzeAndGetUpdatedContext($child_context, $node, $child_node);

            // TODO(Issue #406): We can improve analysis of `if` blocks by using
            // a BlockExitStatusChecker to avoid propogating invalid inferences.
            // However, we need to check for a try block between this line's scope
            // and the parent function's (or global) scope,
            // to reduce false positives.
            // (Variables will be available in `catch` and `finally`)
            $child_context_list[] = $child_context;
        }

        // For if statements, we need to merge the contexts
        // of all child context into a single scope based
        // on any possible branching structure
        $context = (new ContextMergeVisitor(
            $this->code_base,
            $context,
            $child_context_list
        ))($node);

        $context = $this->postOrderAnalyze($context, $node);

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $context;
    }

    /**
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitIfElem(Node $node) : Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno ?? 0
        );

        $context = $this->preOrderAnalyze($context, $node);

        assert(!empty($context), 'Context cannot be null');

        $condition_node = $node->children['cond'];
        if ($condition_node && $condition_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext(
                $context->withLineNumberStart($condition_node->lineno ?? 0),
                $node,
                $condition_node
            );
        }

        if ($stmts_node = $node->children['stmts']) {
            if ($stmts_node instanceof Node) {
                $context = $this->analyzeAndGetUpdatedContext(
                    $context->withScope(
                        new BranchScope($context->getScope())
                    )->withLineNumberStart($stmts_node->lineno ?? 0),
                    $node,
                    $stmts_node
                );
            }
        }

        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.
        $context = $this->postOrderAnalyze($context, $node);

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $context;
    }

    /**
     * For 'closed context' items (classes, methods, functions,
     * closures), we analyze children in the parent context, but
     * then return the parent context itself unmodified by the
     * children.
     *
     *           │
     *           ▼
     *        ┌──●────┐
     *        │       │
     *        ●──●──● │
     *           ┌────┘
     *           ●
     *           │
     *           ▼
     *
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitClosedContext(Node $node) : Context
    {
        // Make a copy of the internal context so that we don't
        // leak any changes within the closed context to the
        // outer scope
        $context = clone($this->context->withLineNumberStart(
            $node->lineno ?? 0
        ));

        $context = $this->preOrderAnalyze($context, $node);

        assert(!empty($context), 'Context cannot be null');

        // We collect all child context so that the
        // PostOrderAnalysisVisitor can optionally operate on
        // them
        $child_context_list = [];

        $child_context = $context;

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children ?? [] as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            if (!($this->should_visit_everything || Analysis::shouldVisit($child_node))) {
                $child_context->withLineNumberStart(
                    $child_node->lineno ?? 0
                );
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $child_context = $this->analyzeAndGetUpdatedContext($child_context, $node, $child_node);

            $child_context_list[] = $child_context;
        }

        // For if statements, we need to merge the contexts
        // of all child context into a single scope based
        // on any possible branching structure
        $context = (new ContextMergeVisitor(
            $this->code_base,
            $context,
            $child_context_list
        ))($node);

        $context = $this->postOrderAnalyze($context, $node);

        // Return the initial context as we exit
        return $this->context;
    }

    /**
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitIf(Node $node) : Context
    {
        return $this->visitBranchedContext($node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitCatchList(Node $node) : Context
    {
        return $this->visitBranchedContext($node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitTry(Node $node) : Context
    {
        return $this->visitBranchedContext($node);
    }

    public function visitConditional(Node $node) : Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno ?? 0
        );

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        // NOTE: unused for AST_CONDITIONAL
        // $context = (new PreOrderAnalysisVisitor(
        //     $this->code_base, $context
        // ))($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base, $context, $node
        );

        assert(!empty($context), 'Context cannot be null');

        $true_node =
            $node->children['trueExpr'] ??
                $node->children['true'] ?? null;
        $false_node =
            $node->children['falseExpr'] ??
                $node->children['false'] ?? null;

        $cond_node = $node->children['cond'];
        if (($cond_node instanceof Node) && ($this->should_visit_everything || Analysis::shouldVisitNode($cond_node))) {
            // Step into each child node and get an
            // updated context for the node
            // (e.g. there may be assignments such as '($x = foo()) ? $a : $b)
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $cond_node);

            // TODO: false_context once there is a NegatedConditionVisitor
            $true_context = (new ConditionVisitor(
                $this->code_base,
                $this->context
            ))($cond_node);
        } else {
            $true_context = $context;
        }

        $child_context_list = [];
        // In the long form, there's a $true_node, but in the short form (?:),
        // $cond_node is the (already processed) value for truthy.
        if ($true_node instanceof Node) {
            if ($this->should_visit_everything || Analysis::shouldVisit($true_node)) {
                $child_context = $this->analyzeAndGetUpdatedContext($true_context, $node, $true_node);
                $child_context_list[] = $child_context;
            }
        }

        if ($false_node instanceof Node) {
            if ($this->should_visit_everything || Analysis::shouldVisit($false_node)) {
                $child_context = $this->analyzeAndGetUpdatedContext($context, $node, $false_node);
                $child_context_list[] = $child_context;
            }
        }
        if (count($child_context_list) >= 1) {
            $context = (new ContextMergeVisitor(
                $this->code_base,
                $context,
                $child_context_list
            ))($node);
        }

        $context = $this->postOrderAnalyze($context, $node);

        return $context;
    }
    /**
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitClass(Decl $node) : Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * @param Decl $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitMethod(Decl $node) : Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * @param Decl $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitFuncDecl(Decl $node) : Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * @param Decl $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitClosure(Decl $node) : Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * Common options for pre-order analysis phase of a Node.
     * Run pre-order analysis steps, then run plugins.
     *
     * @param Context $context - The context before pre-order analysis
     *
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after pre-order analysis of the node
     */
    private function preOrderAnalyze(Context $context, Node $node) : Context
    {
        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base, $context
        ))($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base, $context, $node
        );
        return $context;
    }

    /**
     * Common options for post-order analysis phase of a Node.
     * Run analysis steps and run plugins.
     *
     * @param Context $context - The context before post-order analysis
     *
     * @param Node $node
     * An AST node we'd like to determine the UnionType
     * for
     *
     * @return Context
     * The updated context after post-order analysis of the node
     */
    private function postOrderAnalyze(Context $context, Node $node) : Context
    {
        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.
        $context = (new PostOrderAnalysisVisitor(
            $this->code_base,
            $context->withLineNumberStart($node->lineno ?? 0),
            $this->parent_node
        ))($node);

        // let any configured plugins analyze the node
        ConfigPluginSet::instance()->analyzeNode(
            $this->code_base, $context, $node, $this->parent_node
        );
        return $context;
    }

    /**
     * Analyzes a node of type \ast\AST_GROUP_USE
     * This is the same as visit(), but does not recurse into the child nodes.
     *
     * If this function override didn't exist,
     * then visit() would recurse into \ast\AST_USE,
     * which would lack part of the namespace.
     * (E.g. for use \NS\{const X, const Y}, we don't want to analyze const X or const Y
     * without the preceding \NS\)
     */
    public function visitGroupUse(Node $node) : Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno ?? 0
        );

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base, $context
        ))($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base, $context, $node
        );

        assert(!empty($context), 'Context cannot be null');

        $context = $this->postOrderAnalyze($context, $node);

        return $context;
    }
}
