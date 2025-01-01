<?php

declare(strict_types=1);

namespace Phan;

use AssertionError;
use ast;
use ast\Node;
use Closure;
use Phan\Analysis\AssignmentVisitor;
use Phan\Analysis\BlockExitStatusChecker;
use Phan\Analysis\ConditionVisitor;
use Phan\Analysis\ContextMergeVisitor;
use Phan\Analysis\LoopConditionVisitor;
use Phan\Analysis\NegatedConditionVisitor;
use Phan\Analysis\PostOrderAnalysisVisitor;
use Phan\Analysis\PreOrderAnalysisVisitor;
use Phan\Analysis\RedundantCondition;
use Phan\AST\AnalysisVisitor;
use Phan\AST\ASTReverter;
use Phan\AST\ContextNode;
use Phan\AST\InferPureSnippetVisitor;
use Phan\AST\ScopeImpactCheckingVisitor;
use Phan\AST\UnionTypeVisitor;
use Phan\AST\Visitor\Element;
use Phan\Exception\IssueException;
use Phan\Exception\NodeException;
use Phan\Exception\RecursionDepthException;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Comment\Builder;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\Scope\BranchScope;
use Phan\Language\Scope\GlobalScope;
use Phan\Language\Scope\PropertyScope;
use Phan\Language\Type;
use Phan\Language\Type\ArrayType;
use Phan\Language\Type\IterableType;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;
use Phan\Parse\ParseVisitor;
use Phan\Plugin\ConfigPluginSet;
use Phan\Plugin\Internal\RedundantConditionVisitor;
use Phan\Plugin\Internal\VariableTracker\VariableTrackerVisitor;

use function array_map;
use function count;
use function end;
use function explode;
use function is_object;
use function is_string;
use function preg_match;
use function rtrim;

/**
 * Analyze blocks of code
 *
 * - Uses `\Phan\Analysis\PreOrderAnalysisVisitor` for pre-order analysis of a node (E.g. entering a function to analyze)
 * - Recursively analyzes child nodes
 * - Uses `\Phan\Analysis\PostOrderAnalysisVisitor` for post-order analysis of a node (E.g. analyzing a statement with the updated Context and emitting issues)
 * - If there is more than one possible child context, merges state from them (variable types)
 *
 * @see self::visit()
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 * @method Context __invoke(Node $node)
 */
class BlockAnalysisVisitor extends AnalysisVisitor
{

    /**
     * @var Node[]
     * The parent of the current node
     */
    private $parent_node_list = [];

    /**
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param ?Node $parent_node
     * The parent of the node being analyzed
     */
    public function __construct(
        CodeBase $code_base,
        Context $context,
        ?Node $parent_node = null
    ) {
        parent::__construct($code_base, $context);
        if ($parent_node) {
            $this->parent_node_list[] = $parent_node;
        }
    }

    // No-ops for frequent node types
    public function visitVar(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        $name_node = $node->children['name'];
        // E.g. ${expr()} is valid PHP. Recurse if that's a node.
        if ($name_node instanceof Node) {
            // Step into each child node and get an
            // updated context for the node
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $name_node);
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node @phan-unused-param this was analyzed in visitUse
     */
    public function visitUseElem(Node $node): Context
    {
        // Could invoke plugins, but not right now
        return $this->context;
    }

    /**
     * Analyzes a namespace block or statement (e.g. `namespace NS\SubNS;` or `namespace OtherNS { ... }`)
     * @param Node $node a node of type AST_NAMESPACE
     */
    public function visitNamespace(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        // If there are multiple namespaces in the file, have to warn about unused entries in the current namespace first.
        // If this is the first namespace, then there wouldn't be any use statements yet.
        // TODO: This may not be the case if the language server is used
        // @phan-suppress-next-line PhanAccessMethodInternal
        $context->warnAboutUnusedUseElements($this->code_base);

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base,
            $context
        ))->visitNamespace($node);

        // We already imported namespace constants earlier; use those.
        // @phan-suppress-next-line PhanAccessMethodInternal
        $context->importNamespaceMapFromParsePhase($this->code_base);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // The namespace may either have a list of statements (`namespace Foo {}`)
        // or be null (`namespace Foo;`)
        $stmts_node = $node->children['stmts'];
        if ($stmts_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $stmts_node);
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * Analyzes a node with type AST_NAME (Relative or fully qualified name)
     */
    public function visitName(Node $node): Context
    {
        $context = $this->context;
        // Only invoke post-order plugins, needed for NodeSelectionPlugin.
        // PostOrderAnalysisVisitor and PreOrderAnalysisVisitor don't do anything.
        // Optimized because this is frequently called
        ConfigPluginSet::instance()->postAnalyzeNode(
            $this->code_base,
            $context,
            $node,
            $this->parent_node_list
        );
        return $context;
    }

    /**
     * For non-special nodes such as statement lists (AST_STMT_LIST),
     * we propagate the context and scope from the parent,
     * through the individual statements, and return a Context with the modified scope.
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
     */
    public function visitStmtList(Node $node): Context
    {
        $context = $this->context;
        $plugin_set = ConfigPluginSet::instance();
        $plugin_set->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                $this->handleScalarStmt($node, $context, $child_node);
                continue;
            }
            $context->clearCachedUnionTypes();

            // Step into each child node and get an
            // updated context for the node
            try {
                $context = $this->analyzeAndGetUpdatedContext($context, $node, $child_node);
            } catch (IssueException $e) {
                // This is a fallback - Exceptions should be caught at a deeper level if possible
                Issue::maybeEmitInstance($this->code_base, $context, $e->getIssueInstance());
            }
        }
        $plugin_set->postAnalyzeNode(
            $this->code_base,
            $context,
            $node,
            $this->parent_node_list
        );
        return $context;
    }

    /**
     * Optimized visitor for arrays, skipping unnecessary steps.
     * Equivalent to visit()
     */
    public function visitArray(Node $node): Context
    {
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        $this->parent_node_list[] = $node;
        try {
            foreach ($node->children as $child_node) {
                // Skip any non Node children.
                if (!($child_node instanceof Node)) {
                    continue;
                }

                // Step into each child node and get an
                // updated context for the node
                if ($child_node->kind === ast\AST_ARRAY_ELEM) {
                    $context = $this->visitArrayElem($child_node);
                } elseif ($child_node->kind === ast\AST_UNPACK) {
                    // @phan-suppress-next-line PhanUndeclaredProperty set to distinguish this from unpack in calls, which does require array keys to be consecutive
                    $child_node->is_in_array = true;
                    $context = $this->visitUnpack($child_node);
                } else {
                    throw new AssertionError("Unexpected node in ast\AST_ARRAY: " . \Phan\Debug::nodeToString($child_node));
                }
            }
        } finally {
            \array_pop($this->parent_node_list);
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * Optimized visitor for array elements, skipping unnecessary steps.
     * Equivalent to visitArrayElem
     */
    public function visitArrayElem(Node $node): Context
    {
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        $plugin_set = ConfigPluginSet::instance();
        $plugin_set->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $key = $node->children['key'];
        if ($key !== null) {
            $this->analyzeArrayKeyType($key, $node->lineno);
        }

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $child_node);
        }

        $plugin_set->postAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );
        return $context;
    }

    /**
     * @param int|string|float|Node $key_node
     */
    private function analyzeArrayKeyType($key_node, int $start_line): void
    {
        if (is_object($key_node)) {
            $union_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $key_node);
            $key = $union_type->asSingleScalarValueOrNullOrSelf();
            if (is_object($key)) {
                if (!$union_type->canCastToUnionType(UnionType::fromFullyQualifiedPHPDocString('?int|?string'), $this->code_base)) {
                    $this->emitIssue(
                        Issue::TypeInvalidArrayKey,
                        $start_line,
                        ASTReverter::toShortString($key_node),
                        $union_type
                    );
                }
                return;
            }
        } else {
            $key = $key_node;
        }
        if (\is_int($key) || is_string($key)) {
            return;
        }
        if (\is_float($key) && (float)(int)$key === $key) {
            // php 8.1 deprecates casting floats to integers.
            return;
        }
        // php 8.1 deprecates casting resources to integers implicitly as well

        $this->emitIssue(
            Issue::TypeInvalidArrayKeyLiteral,
            $start_line,
            ASTReverter::toShortString($key_node),
            \var_representation($key)
        );
    }

    /**
     * @param Node $node
     * @param Context $context
     * @param int|float|string|null $child_node (probably not null)
     */
    private function handleScalarStmt(Node $node, Context $context, $child_node): void
    {
        if (\is_string($child_node)) {
            $consumed = false;
            if (\strpos($child_node, '@phan-') !== false) {
                // Add @phan-var and @phan-suppress annotations in string literals to the local scope
                $this->analyzeSubstituteVarAssert($this->code_base, $context, $child_node);
                $consumed = true;
            }
            $consumed = ConfigPluginSet::instance()->analyzeStringLiteralStatement($this->code_base, $context, $child_node) || $consumed;
            if (!$consumed) {
                Issue::maybeEmit(
                    $this->code_base,
                    $context,
                    Issue::NoopStringLiteral,
                    $context->getLineNumberStart() ?: $this->getLineNumberOfParent() ?: $node->lineno,
                    StringUtil::jsonEncode($child_node)
                );
            }
        } elseif (\is_scalar($child_node)) {
            Issue::maybeEmit(
                $this->code_base,
                $context,
                Issue::NoopNumericLiteral,
                $context->getLineNumberStart() ?: $this->getLineNumberOfParent() ?: $node->lineno,
                \var_representation($child_node)
            );
        }
    }

    private function getLineNumberOfParent(): int
    {
        $parent = end($this->parent_node_list);
        if (!($parent instanceof Node)) {
            return 0;
        }
        return $parent->lineno;
    }

    private const PHAN_FILE_SUPPRESS_REGEX =
        '/@phan-file-suppress\s+' . Builder::SUPPRESS_ISSUE_LIST . '/';  // @phan-suppress-current-line PhanAccessClassConstantInternal


    private const PHAN_VAR_REGEX =
        '/@(phan-var(?:-force)?)\b\s*(' . UnionType::union_type_regex . ')\s*&?\\$' . Builder::WORD_REGEX . '/';
    // @phan-suppress-previous-line PhanAccessClassConstantInternal

    private const PHAN_DEBUG_VAR_REGEX =
        '/@phan-debug-var\s+\$(' . Builder::WORD_REGEX . '(,\s*\$' . Builder::WORD_REGEX . ')*)/';
    // @phan-suppress-previous-line PhanAccessClassConstantInternal

    /**
     * Parses annotations such as "(at)phan-var int $myVar" and "(at)phan-var-force ?MyClass $varName" annotations from inline string literals.
     * (php-ast isn't able to parse inline doc comments, so string literals are used for rare edge cases where assert/if statements don't work)
     *
     * Modifies the type of the variable (in the scope of $context) to be identical to the annotated union type.
     */
    private function analyzeSubstituteVarAssert(CodeBase $code_base, Context $context, string $text): void
    {
        $has_known_annotations = false;
        if (\preg_match_all(self::PHAN_VAR_REGEX, $text, $matches, \PREG_SET_ORDER) > 0) {
            $has_known_annotations = true;
            foreach ($matches as $group) {
                $annotation_name = $group[1];
                $type_string = $group[2];
                $var_name = $group[16];
                $type = UnionType::fromStringInContext($type_string, $context, Type::FROM_PHPDOC);
                self::createVarForInlineComment($code_base, $context, $var_name, $type, $annotation_name === 'phan-var-force');
            }
        }

        if (\preg_match_all(self::PHAN_FILE_SUPPRESS_REGEX, $text, $matches, \PREG_SET_ORDER) > 0) {
            $has_known_annotations = true;
            if (!Config::getValue('disable_file_based_suppression')) {
                foreach ($matches as $group) {
                    $issue_name_list = $group[1];
                    foreach (array_map('trim', explode(',', $issue_name_list)) as $issue_name) {
                        $code_base->addFileLevelSuppression($context->getFile(), $issue_name);
                    }
                }
            }
        }
        if (\preg_match_all(self::PHAN_DEBUG_VAR_REGEX, $text, $matches, \PREG_SET_ORDER) > 0) {
            $has_known_annotations = true;
            foreach ($matches as $group) {
                foreach (explode(',', $group[1]) as $var_name) {
                    $var_name = \ltrim(\trim($var_name), '$');
                    if ($context->getScope()->hasVariableWithName($var_name)) {
                        $union_type_string = $context->getScope()->getVariableByName($var_name)->getUnionType()->getDebugRepresentation();
                    } else {
                        $union_type_string = '(undefined)';
                    }
                    Issue::maybeEmit(
                        $this->code_base,
                        $context,
                        Issue::DebugAnnotation,
                        $context->getLineNumberStart(),
                        $var_name,
                        $union_type_string
                    );
                }
            }
        }
        // @phan-suppress-next-line PhanAccessClassConstantInternal
        if (\preg_match_all(Builder::PHAN_TYPE_ALIAS_REGEX, $text, $matches, \PREG_SET_ORDER) > 0) {
            $has_known_annotations = true;
            foreach ($matches as $group) {
                $alias_name = $group[1];
                $union_type_string = $group[2];
                // @phan-suppress-next-line PhanAccessMethodInternal
                Builder::addTypeAliasMapping($this->code_base, $this->context, $alias_name, $union_type_string);
            }
        }

        if (!$has_known_annotations && preg_match('/@phan-.*/', $text, $match) > 0) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::UnextractableAnnotation,
                $context->getLineNumberStart(),
                rtrim($match[0])
            );
        }
        return;
    }

    /**
     * @see ConditionVarUtil::getVariableFromScope()
     */
    private static function createVarForInlineComment(CodeBase $code_base, Context $context, string $variable_name, UnionType $type, bool $create_variable): void
    {
        if (!$context->getScope()->hasVariableWithName($variable_name)) {
            if (Variable::isHardcodedVariableInScopeWithName($variable_name, $context->isInGlobalScope())) {
                return;
            }
            if (!$create_variable && !($context->isInGlobalScope() && Config::getValue('ignore_undeclared_variables_in_global_scope'))) {
                Issue::maybeEmitWithParameters(
                    $code_base,
                    $context,
                    Variable::chooseIssueForUndeclaredVariable($context, $variable_name),
                    $context->getLineNumberStart(),
                    [$variable_name],
                    IssueFixSuggester::suggestVariableTypoFix($code_base, $context, $variable_name)
                );
                return;
            }
            $variable = new Variable(
                $context,
                $variable_name,
                $type,
                0
            );
            $context->addScopeVariable($variable);
            return;
        }
        $variable = clone($context->getScope()->getVariableByName(
            $variable_name
        ));
        $variable->setUnionType($type);
        $context->addScopeVariable($variable);
    }

    /**
     * For non-special nodes, we propagate the context and scope
     * from the parent, through the children and return the
     * modified scope,
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
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visit(Node $node): Context
    {
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base,
            $context
        ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'handleMissingNodeKind'}($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $child_node);
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * This is an abstraction for getting a new, updated context for a child node.
     *
     * Effectively the same as (new BlockAnalysisVisitor(..., $context, $node, ...)child_node))
     * but is much less repetitive and verbose, and slightly more efficient.
     *
     * @param Context $context - The original context for $node, before analyzing $child_node
     *
     * @param Node $node - The parent node of $child_node
     *
     * @param Node $child_node - The node which will be analyzed to create the updated context.
     *
     * @return Context (The unmodified $context, or a different Context instance with modifications)
     *
     * @suppress PhanPluginCanUseReturnType
     * NOTE: This is called extremely frequently, so the real signature types were omitted for performance.
     */
    private function analyzeAndGetUpdatedContext(Context $context, Node $node, Node $child_node)
    {
        // Modify the original object instead of creating a new BlockAnalysisVisitor.
        // this is slightly more efficient, especially if a large number of unchanged parameters would exist.
        $old_context = $this->context;
        $this->context = $context;
        $this->parent_node_list[] = $node;
        try {
            return $this->{Element::VISIT_LOOKUP_TABLE[$child_node->kind] ?? 'handleMissingNodeKind'}($child_node);
        } finally {
            $this->context = $old_context;
            \array_pop($this->parent_node_list);
        }
    }

    /**
     * For "for loop" nodes, we analyze the components in the following order as a heuristic:
     *
     * 1. propagate the context and scope from the parent,
     * 2. Update the scope with the initializer of the loop,
     * 3. Update the scope with the side effects (e.g. assignments) of the condition of the loop
     * 4. Update the scope with the child statements both inside and outside the loop (ignoring branches which will continue/break),
     * 5. Update the scope with the statement evaluated after the loop
     *
     * Then, Phan returns the context with the modified scope.
     *
     * TODO: merge the contexts together, for better analysis of possibly undefined variables
     *
     *               │
     *        cond   ▼
     *   ●──────●────● init
     *   │
     *   │         (TODO: merge contexts instead)
     *   ●──●──▶●
     *   stmts  │
     *          │
     *          ● 'loop' child node (after inner statements)
     *          │
     *          ▼
     *
     * Note: Loop analysis uses heuristics for performance and simplicity.
     * If we analyzed the stmts of the inner loop body another time,
     * we might discover even more possible types of input/resulting variables.
     *
     * Current limitations:
     *
     * - contexts from individual break/continue stmts aren't merged
     * - contexts from individual break/continue stmts aren't merged
     *
     * @param Node $node
     * An AST node (for a for loop) we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @suppress PhanUndeclaredProperty
     * TODO: Add similar handling (e.g. of possibility of 0 iterations) for foreach
     */
    public function visitFor(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $init_node = $node->children['init'];
        if ($init_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext(
                $context->withLineNumberStart($init_node->lineno),
                $node,
                $init_node
            );
        }
        $context = $context->withEnterLoop($node);

        $condition_node = $node->children['cond'];
        if ($condition_node instanceof Node) {
            $this->parent_node_list[] = $node;
            $condition_subnode = false;
            try {
                // The typical case is `for (init; $x; loop) {}`
                // But `for (init; $x, $y; loop) {}` is rare but possible, which requires evaluating those in order.
                // Evaluate the list of cond expressions in order.
                foreach ($condition_node->children as $condition_subnode) {
                    if ($condition_subnode instanceof Node) {
                        $context = $this->analyzeAndGetUpdatedContext(
                            $context->withLineNumberStart($condition_subnode->lineno),
                            $condition_node,
                            $condition_subnode
                        );
                    }
                }
            } finally {
                \array_pop($this->parent_node_list);
            }
            if ($condition_subnode instanceof Node) {
                // Analyze the cond expression for its side effects and the code it contains,
                // not the effect of the condition.
                // e.g. `while ($x = foo())`
                $context = $this->analyzeAndGetUpdatedContext(
                    $context->withLineNumberStart($condition_subnode->lineno),
                    $node,
                    $condition_subnode
                );
                if (!$this->context->isInGlobalScope() && !$this->context->isInLoop()) {
                    $condition_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $condition_subnode);
                    $always_iterates_at_least_once = !$condition_type->containsFalsey() && !$condition_type->isEmpty();
                } else {
                    $always_iterates_at_least_once = UnionTypeVisitor::checkCondUnconditionalTruthiness($condition_subnode);
                }
            } else {
                $always_iterates_at_least_once = (bool)$condition_subnode;
            }
        } else {
            $always_iterates_at_least_once = true;
        }
        $original_context = $context;

        $stmts_node = $node->children['stmts'];
        if (!($stmts_node instanceof Node)) {
            throw new AssertionError('Expected php-ast to always return a statement list for ast\AST_FOR');
        }
        // Look to see if any proofs we do within the condition of the for
        // can say anything about types within the statement
        // list.
        // TODO: Distinguish between inner and outer context.
        //   E.g. `for (; $x = cond(); ) {}` will have truthy $x within the loop
        //   but falsey outside the loop, if there are no breaks.
        if ($condition_node instanceof Node) {
            $context = (new LoopConditionVisitor(
                $this->code_base,
                $context,
                $condition_node,
                false,
                BlockExitStatusChecker::willUnconditionallyProceed($stmts_node)
            ))->__invoke($condition_node);
        } elseif (Config::getValue('redundant_condition_detection')) {
            $condition_node = $condition_node ?? new Node(
                ast\AST_CONST,
                0,
                ['name' => new Node(ast\AST_NAME, ast\flags\NAME_NOT_FQ, ['name' => 'true'], $node->lineno)],
                $node->lineno
            );
            (new LoopConditionVisitor(
                $this->code_base,
                $context,
                $condition_node,
                false,
                BlockExitStatusChecker::willUnconditionallyProceed($stmts_node)
            ))->checkRedundantOrImpossibleTruthyCondition($condition_node, $context, null, false);
        }

        // Give plugins a chance to analyze the loop condition now
        ConfigPluginSet::instance()->analyzeLoopBeforeBody(
            $this->code_base,
            $context,
            $node
        );

        $context = $this->analyzeAndGetUpdatedContext(
            $context->withScope(
                new BranchScope($context->getScope())
            )->withLineNumberStart($stmts_node->lineno),
            $node,
            $stmts_node
        );
        // Analyze the loop after analyzing the statements, in case it uses variables defined within the statements.
        $loop_node = $node->children['loop'];
        if ($loop_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext(
                $context->withLineNumberStart($loop_node->lineno),
                $node,
                $loop_node
            );
        }

        if (isset($node->phan_loop_contexts)) {
            // Combine contexts from continue/break statements within this for loop
            $context = (new ContextMergeVisitor($context, \array_merge([$context], $node->phan_loop_contexts)))->combineChildContextList();
            unset($node->phan_loop_contexts);
        }

        $context = $context->withExitLoop($node);
        if (!$always_iterates_at_least_once) {
            $context = (new ContextMergeVisitor($context, [$context, $original_context]))->combineChildContextList();
        }

        // Check if this is side effect free with the variable types inferred by analyzing the loop body (heuristic).
        if (Config::getValue('unused_variable_detection') &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $context, $loop_node) &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $context, $condition_node) &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $context, $stmts_node)) {
            VariableTrackerVisitor::recordHasLoopBodyWithoutSideEffects($node);
        }

        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * For "while loop" nodes, we analyze the components in the following order as a heuristic:
     * (This is pretty much the same as analyzing a for loop with the 'init' and 'loop' nodes left blank)
     *
     * 1. propagate the context and scope from the parent,
     * 2. Update the scope with the side effects (e.g. assignments) of the condition of the loop
     * 3. Update the scope with the child statements both inside and outside the loop (ignoring branches which will continue/break),
     *
     * Then, Phan returns the context with the modified scope.
     *
     * TODO: merge the contexts together, for better analysis of possibly undefined variables
     *
     * NOTE: "Do while" loops are just handled by visit(), Phan sees and analyzes 'stmts' before 'cond'.
     *
     *
     *          │
     *          ▼
     *   ●──────● cond
     *   │
     *   │         (TODO: merge contexts instead)
     *   ●──●──▶●
     *   stmts  │
     *          │
     *          │
     *          │
     *          ▼
     *
     * @param Node $node
     * An AST node (for a while loop) we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @suppress PhanUndeclaredProperty
     */
    public function visitWhile(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        )->withEnterLoop($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $condition_node = $node->children['cond'];
        if ($condition_node instanceof Node) {
            // Analyze the cond expression for its side effects and the code it contains,
            // not the effect of the condition.
            // e.g. `while ($x = foo())`
            $context = $this->analyzeAndGetUpdatedContext(
                $context->withLineNumberStart($condition_node->lineno),
                $node,
                $condition_node
            );
            if (!$this->context->isInGlobalScope() && !$this->context->isInLoop()) {
                $condition_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $condition_node);
                $always_iterates_at_least_once = !$condition_type->containsFalsey() && !$condition_type->isEmpty();
            } else {
                $always_iterates_at_least_once = UnionTypeVisitor::checkCondUnconditionalTruthiness($condition_node);
            }
        } else {
            $always_iterates_at_least_once = (bool)$condition_node;
        }
        $original_context = $context;

        $stmts_node = $node->children['stmts'];
        if (!$stmts_node instanceof Node) {
            throw new AssertionError('Expected php-ast to always return an ast\AST_STMT_LIST for a while loop\'s statement list');
        }

        // Look to see if any proofs we do within the condition of the while
        // can say anything about types within the statement
        // list.
        // TODO: Distinguish between inner and outer context.
        //   E.g. `while ($x = cond()) {}` will have truthy $x within the loop
        //   but falsey outside the loop, if there are no breaks.
        if ($condition_node instanceof Node) {
            $context = (new LoopConditionVisitor(
                $this->code_base,
                $context,
                $condition_node,
                false,
                BlockExitStatusChecker::willUnconditionallyProceed($stmts_node)
            ))->__invoke($condition_node);
        } elseif (Config::getValue('redundant_condition_detection')) {
            (new LoopConditionVisitor(
                $this->code_base,
                $context,
                $condition_node,
                false,
                BlockExitStatusChecker::willUnconditionallyProceed($stmts_node)
            ))->checkRedundantOrImpossibleTruthyCondition($condition_node, $context, null, false);
        }

        // Give plugins a chance to analyze the loop condition now
        ConfigPluginSet::instance()->analyzeLoopBeforeBody(
            $this->code_base,
            $context,
            $node
        );

        $context = $this->analyzeAndGetUpdatedContext(
            $context->withScope(
                new BranchScope($context->getScope())
            )->withLineNumberStart($stmts_node->lineno),
            $node,
            $stmts_node
        );

        if (isset($node->phan_loop_contexts)) {
            // Combine contexts from continue/break statements within this while loop
            $context = (new ContextMergeVisitor($context, \array_merge([$context], $node->phan_loop_contexts)))->combineChildContextList();
            unset($node->phan_loop_contexts);
        }

        $context = $context->withExitLoop($node);
        if (!$always_iterates_at_least_once) {
            $context = (new ContextMergeVisitor($context, [$context, $original_context]))->combineChildContextList();
        }

        // Check if this is side effect free with the variable types inferred by analyzing the loop body (heuristic).
        if (Config::getValue('unused_variable_detection') &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $context, $node)) {
            VariableTrackerVisitor::recordHasLoopBodyWithoutSideEffects($node);
        }

        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * For "foreach loop" nodes, we analyze the loop variables,
     * then analyze the statements in a different scope (e.g. BranchScope when there may be 0 iterations)
     *
     * @param Node $node a node of type ast\AST_FOREACH
     * @throws NodeException
     * @suppress PhanUndeclaredProperty
     */
    public function visitForeach(Node $node): Context
    {
        $code_base = $this->code_base;
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);

        $expr_node = $node->children['expr'];

        $has_at_least_one_iteration = false;
        $expression_union_type = UnionTypeVisitor::unionTypeFromNode(
            $code_base,
            $context,
            $expr_node
        )->withStaticResolvedInContext($context);

        if ($expr_node instanceof Node) {
            if ($expr_node->kind === ast\AST_ARRAY) {
                // e.g. foreach ([1, 2] as $value) has at least one
                $has_at_least_one_iteration = \count($expr_node->children) > 0;
            } else {
                // e.g. look up global constants and class constants.
                // TODO: Handle non-empty-array, etc.
                $union_type = UnionTypeVisitor::unionTypeFromNode($code_base, $this->context, $expr_node);
                $has_at_least_one_iteration = !$union_type->containsFalsey() && !$union_type->isEmpty() &&
                    !$union_type->hasTypeMatchingCallback(static function (Type $type): bool {
                        return !$type instanceof ArrayType;
                    });
            }
        }

        // Check the expression type to make sure it's
        // something we can iterate over
        $this->checkCanIterate($expression_union_type, $node);

        $expr_node = $node->children['expr'];
        if ($expr_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $expr_node);
        }

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $code_base,
            $context,
            $node
        );

        // PreOrderAnalysisVisitor is not used, to avoid issues analyzing edge cases such as `foreach ($x->method() as $x)`

        // Analyze the context inside the loop. The keys/values would not get created in the outer scope if the iterable expression was empty.
        if ($has_at_least_one_iteration) {
            $context_inside_loop_start = $context;
        } else {
            $context_inside_loop_start = $context->withScope(new BranchScope($context->getScope()));
        }

        // withEnterLoop and withExitLoop must get called on the same Scope,
        // so that the deferred callbacks get called.
        $context_inside_loop_start = $context_inside_loop_start->withEnterLoop($node);

        // Add types of the key and value expressions,
        // and check for errors in the foreach expression
        $inner_context = $this->analyzeForeachIteration($context_inside_loop_start, $expression_union_type, $node);

        $value_node = $node->children['value'];
        if ($value_node instanceof Node) {
            $inner_context = $this->analyzeAndGetUpdatedContext($inner_context, $node, $value_node);
        }
        $key_node = $node->children['key'];
        if ($key_node instanceof Node) {
            $inner_context = $this->analyzeAndGetUpdatedContext($inner_context, $node, $key_node);
        }

        // Give plugins a chance to analyze the loop condition now
        ConfigPluginSet::instance()->analyzeLoopBeforeBody(
            $code_base,
            $inner_context,
            $node
        );

        $stmts_node = $node->children['stmts'];
        if ($stmts_node instanceof Node) {
            $inner_context = $this->analyzeAndGetUpdatedContext($inner_context, $node, $stmts_node);
        }

        // TODO: Also warn about object types when iterating over that class should not have side effects
        if (Config::getValue('unused_variable_detection') &&
            !$expression_union_type->isEmpty() && !$expression_union_type->hasPossiblyObjectTypes() &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $inner_context, $stmts_node) &&
            self::isLoopVariableWithoutSideEffects($key_node) &&
            self::isLoopVariableWithoutSideEffects($value_node)
        ) {
            VariableTrackerVisitor::recordHasLoopBodyWithoutSideEffects($node);
        }

        if ($has_at_least_one_iteration) {
            $context = $inner_context;
            $context_list = [$inner_context];
            if (isset($node->phan_loop_contexts)) {
                $context_list = \array_merge($context_list, $node->phan_loop_contexts);
                // Combine contexts from continue/break statements within this foreach loop
                unset($node->phan_loop_contexts);
            }

            if (\count($context_list) >= 2) {
                $context = (new ContextMergeVisitor($context, $context_list))->combineChildContextList();
            }
            // Perform deferred checks about the inside of the loop.
            $context = $context->withExitLoop($node);

            // This is the context after performing at least one iteration of the foreach loop.
        } else {
            $inner_context_list = [$context_inside_loop_start, $inner_context];

            if (isset($node->phan_loop_contexts)) {
                $inner_context_list = \array_merge($inner_context_list, $node->phan_loop_contexts);
                // Combine contexts from continue/break statements within this foreach loop
                unset($node->phan_loop_contexts);
            }
            // Perform deferred checks about the inside of the loop.
            // Here, this combines the states of the inner loop (but not the outer loop) to avoid some types of false positives
            // such as undeclared variable warnings. (imperfect heuristic but works well for most uses)
            $context_inside_loop_start = (new ContextMergeVisitor($context_inside_loop_start, $inner_context_list))->combineChildContextList();
            $context_inside_loop_start = $context_inside_loop_start->withExitLoop($node);

            // Combine the outer scope with the inner scope
            $context_list = [$context, $context_inside_loop_start];
            $context = (new ContextMergeVisitor($context, $context_list))->combineChildContextList();
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node|string|int|float|null $node
     *
     * Returns true if this is probably a loop variable without side effects
     * (e.g. not a reference, not modifying properties, etc)
     */
    private static function isLoopVariableWithoutSideEffects($node): bool
    {
        if (!$node instanceof Node) {
            return true;
        }
        switch ($node->kind) {
            case ast\AST_VAR:
                return is_string($node->children['name']);
            case ast\AST_ARRAY:
            case ast\AST_ARRAY_ELEM:
                foreach ($node->children as $child_node) {
                    if (!self::isLoopVariableWithoutSideEffects($child_node)) {
                        return false;
                    }
                }
                return true;
            default:
                return ParseVisitor::isConstExpr($node, ParseVisitor::CONSTANT_EXPRESSION_FORBID_NEW_EXPRESSION);
        }
    }

    /**
     * @param UnionType $union_type the type of $node->children['expr']
     * @param Node $node a node of kind AST_FOREACH
     */
    private function checkCanIterate(UnionType $union_type, Node $node): void
    {
        if ($union_type->isEmpty()) {
            return;
        }
        if (!$union_type->hasPossiblyObjectTypes() && !$union_type->hasIterable($this->code_base)) {
            $this->emitIssue(
                Issue::TypeMismatchForeach,
                $node->children['expr']->lineno ?? $node->lineno,
                (string)$union_type
            );
            return;
        }
        $has_object = false;
        foreach ($union_type->getTypeSet() as $type) {
            if (!$type->hasObjectWithKnownFQSEN()) {
                continue;
            }
            try {
                // e.g. don't warn about ArrayObject&CustomInterface because the expanded type set includes Traversable
                if ($type->isTraversable($this->code_base)) {
                    continue;
                }
            } catch (RecursionDepthException $_) {
            }
            $this->warnAboutNonTraversableType($node, $type);
            $has_object = true;
        }
        if ($has_object) {
            return;
        }
        if (self::isEmptyIterable($union_type)) {
            RedundantCondition::emitInstance(
                $node->children['expr'],
                $this->code_base,
                (clone $this->context)->withLineNumberStart($node->children['expr']->lineno ?? $node->lineno),
                Issue::EmptyForeach,
                [(string)$union_type],
                Closure::fromCallable([self::class, 'isEmptyIterable'])
            );
        }
        if (self::isDefinitelyNotObject($union_type)) {
            if (!($node->children['stmts']->children ?? null)) {
                RedundantCondition::emitInstance(
                    $node->children['expr'],
                    $this->code_base,
                    (clone $this->context)->withLineNumberStart($node->children['expr']->lineno ?? $node->lineno),
                    Issue::EmptyForeachBody,
                    [(string)$union_type],
                    Closure::fromCallable([self::class, 'isDefinitelyNotObject'])
                );
            }
        }
    }

    private static function isDefinitelyNotObject(UnionType $type): bool
    {
        $type_set = $type->getRealTypeSet();
        if (!$type_set) {
            return false;
        }
        foreach ($type_set as $type) {
            if ($type->isPossiblyObject()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Returns true if there is at least one iterable type,
     * no object types, and that iterable type is the empty array shape.
     */
    public static function isEmptyIterable(UnionType $union_type): bool
    {
        $has_iterable_types = false;
        foreach ($union_type->getRealTypeSet() as $type) {
            if ($type->isPossiblyObject()) {
                return false;
            }
            if (!$type instanceof IterableType) {
                continue;
            }
            if ($type->isPossiblyTruthy()) {
                // This has possibly non-empty iterable types.
                // We only track emptiness of array shapes.
                return false;
            }
            $has_iterable_types = true;
        }
        return $has_iterable_types;
    }

    private function warnAboutNonTraversableType(Node $node, Type $type): void
    {
        // @phan-suppress-next-line PhanPluginUseReturnValueKnown
        $type->anyTypePartsMatchCallback(function (Type $part) use ($node, $type): bool {
            if (!$part->isObjectWithKnownFQSEN()) {
                return false;
            }
            $fqsen = FullyQualifiedClassName::fromType($part);
            if (!$this->code_base->hasClassWithFQSEN($fqsen)) {
                return true;
            }
            if (\in_array($fqsen->__toString(), ['\stdClass', '\Countable'], true)) {
                // stdClass is the only non-Traversable that I'm aware of that's commonly traversed over.
                // Countable as the only known interface is a common false positive. (`if (count(x)) {foreach...}`)
                return true;
            }
            $class = $this->code_base->getClassByFQSEN($fqsen);
            $status = $class->checkCanIterateFromContext(
                $this->code_base,
                $this->context
            );
            switch ($status) {
                case Clazz::CAN_ITERATE_STATUS_NO_ACCESSIBLE_PROPERTIES:
                    $issue = Issue::TypeNoAccessiblePropertiesForeach;
                    break;
                case Clazz::CAN_ITERATE_STATUS_NO_PROPERTIES:
                    $issue = Issue::TypeNoPropertiesForeach;
                    break;
                default:
                    $issue = Issue::TypeSuspiciousNonTraversableForeach;
                    break;
            }

            $this->emitIssue(
                $issue,
                $node->children['expr']->lineno ?? $node->lineno,
                $type
            );
            return true;
        });
    }

    private function analyzeForeachIteration(Context $context, UnionType $expression_union_type, Node $node): Context
    {
        $code_base = $this->code_base;
        $value_node = $node->children['value'];
        if ($value_node instanceof Node) {
            // should be a parse error when not a Node
            if ($value_node->kind === ast\AST_ARRAY) {
                if (Config::get_closest_minimum_target_php_version_id() < 70100) {
                    self::analyzeArrayAssignBackwardsCompatibility($code_base, $context, $value_node);
                }
            }

            $context = (new AssignmentVisitor(
                $code_base,
                $context,
                $value_node,
                $expression_union_type->iterableValueUnionType($code_base)
            ))->__invoke($value_node);
        }

        // If there's a key, make a variable out of that too
        $key_node = $node->children['key'];
        if ($key_node instanceof Node) {
            if ($key_node->kind === ast\AST_ARRAY) {
                $this->emitIssue(
                    Issue::InvalidNode,
                    $key_node->lineno,
                    "Can't use list() as a key element - aborting"
                );
            } else {
                // If we see array<int,T> or array<string,T> and no other array types, we're reasonably sure the foreach key is an integer or a string, so set it.
                // For Traversable<Key, T> or iterable<Key, T>, return Key.
                $context = (new AssignmentVisitor(
                    $code_base,
                    $context,
                    $key_node,
                    $expression_union_type->iterableKeyUnionType($code_base)
                ))->__invoke($key_node);
            }
        }

        // Note that we're not creating a new scope, just
        // adding variables to the existing scope
        return $context;
    }

    /**
     * Analyze an expression such as `[$a] = $values` or `list('key' => $v) = $values` for backwards compatibility issues
     * Precondition: minimum_target_php_version_id >-= 70100
     */
    public static function analyzeArrayAssignBackwardsCompatibility(CodeBase $code_base, Context $context, Node $node): void
    {
        if ($node->flags !== ast\flags\ARRAY_SYNTAX_LIST) {
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::CompatibleShortArrayAssignPHP70,
                $node->lineno
            );
        }
        foreach ($node->children as $array_elem) {
            if (isset($array_elem->children['key'])) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::CompatibleKeyedArrayAssignPHP70,
                    $array_elem->lineno
                );
                break;
            }
        }
    }


    /**
     * For "do-while loop" nodes of kind ast\AST_DO_WHILE, we analyze the 'stmts', 'cond' in order.
     * (right now, the statements are just analyzed without creating a BranchScope)
     *
     * @suppress PhanUndeclaredProperty
     */
    public function visitDoWhile(Node $node): Context
    {
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);
        $context = $context->withEnterLoop($node);


        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base,
            $context
        ))->visitDoWhile($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        // (copied from visit(), this ensures plugins and other code get called)
        $stmts_node = $node->children['stmts'];
        if ($stmts_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $stmts_node);
        }
        $cond_node = $node->children['cond'];
        if ($cond_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $cond_node);
        }
        if (Config::getValue('redundant_condition_detection')) {
            // Analyze - don't warn about `do...while(true)` or `do...while(false)` because they might be a way to `break;` out of a group of statements
            (new LoopConditionVisitor(
                $this->code_base,
                $context,
                $cond_node,
                true,
                !$stmts_node || BlockExitStatusChecker::willUnconditionallyProceed($stmts_node)
            ))->checkRedundantOrImpossibleTruthyCondition($cond_node, $context, null, false);
        }

        if (isset($node->phan_loop_contexts)) {
            // Combine contexts from continue/break statements within this do-while loop
            $context = (new ContextMergeVisitor($context, \array_merge([$context], $node->phan_loop_contexts)))->combineChildContextList();
            unset($node->phan_loop_contexts);
        }
        $context = $context->withExitLoop($node);

        // Check if this is side effect free with the variable types inferred by analyzing the loop body (heuristic).
        if (Config::getValue('unused_variable_detection') &&
            InferPureSnippetVisitor::isSideEffectFreeSnippet($this->code_base, $context, $node)) {
            VariableTrackerVisitor::recordHasLoopBodyWithoutSideEffects($node);
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * NOTE: This should never get called.
     */
    public function visitIfElem(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        // NOTE: This is different from other analysis visitors because analyzing 'cond' with `||` has side effects
        // after supporting `BlockAnalysisVisitor->visitBinaryOp()`
        // TODO: Calling analyzeAndGetUpdatedContext before preOrderAnalyze is a hack.

        // TODO: This is redundant and has worse knowledge of the specific types of blocks than ConditionVisitor does.
        // TODO: Implement a hybrid BlockAnalysisVisitor+ConditionVisitor that will do a better job of inferences and reducing false positives? (and reduce the redundant work)

        // E.g. the below code would update the context of BlockAnalysisVisitor in BlockAnalysisVisitor->visitBinaryOp()
        //
        //     if (!(is_string($x) || $x === null)) {}
        //
        // But we want to let BlockAnalysisVisitor modify the context for cases such as the below:
        //
        // $result = !($x instanceof User) || $x->meetsCondition()
        $condition_node = $node->children['cond'];
        if ($condition_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext(
                $context->withLineNumberStart($condition_node->lineno),
                $node,
                $condition_node
            );
        } elseif (Config::getValue('redundant_condition_detection')) {
            (new ConditionVisitor($this->code_base, $context))->checkRedundantOrImpossibleTruthyCondition($condition_node, $context, null, false);
        }

        $context = $this->preOrderAnalyze($context, $node);

        if ($stmts_node = $node->children['stmts']) {
            if ($stmts_node instanceof Node) {
                $context = $this->analyzeAndGetUpdatedContext(
                    $context->withScope(
                        new BranchScope($context->getScope())
                    )->withLineNumberStart($stmts_node->lineno),
                    $node,
                    $stmts_node
                );
            }
        }

        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $this->postOrderAnalyze($context, $node);
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
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitClosedContext(Node $node): Context
    {
        // Make a copy of the internal context so that we don't
        // leak any changes within the closed context to the
        // outer scope
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);
        $context = $this->preOrderAnalyze(clone($context), $node);

        // We collect all child context so that the
        // PostOrderAnalysisVisitor can optionally operate on
        // them
        $child_context_list = [];

        $child_context = $context;

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
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
            $context,
            $child_context_list
        ))->__invoke($node);

        $this->postOrderAnalyze($context, $node);

        // Return the initial context as we exit
        return $this->context;
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     * @suppress PhanAccessMethodInternal
     */
    public function visitSwitchList(Node $node): Context
    {
        // Make a copy of the internal context so that we don't
        // leak any changes within the closed context to the
        // outer scope
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);
        $context = $this->preOrderAnalyze(clone($context), $node);

        $child_context_list = [];

        // TODO: Improve inferences in switch statements?
        // TODO: Behave differently if switch lists don't cover every case (e.g. if there is no default)
        $has_default = false;
        // parent_node_list should always end in AST_SWITCH
        // @phan-suppress-next-next-line PhanPossiblyUndeclaredProperty
        [$switch_variable_node, $switch_variable_condition, $switch_variable_negated_condition] = $this->createSwitchConditionAnalyzer(
            end($this->parent_node_list)->children['cond']
        );
        if (($switch_variable_condition || $switch_variable_negated_condition) && $switch_variable_node instanceof Node) {
            $switch_variable_cond_variable_set = RedundantCondition::getVariableSet($switch_variable_node);
        } else {
            $switch_variable_cond_variable_set = [];
        }
        $children = $node->children;
        if (\count($children) <= 1 && !isset($children[0]->children['cond'])) {
            $this->emitIssue(
                Issue::NoopSwitchCases,
                end($this->parent_node_list)->lineno ?? $node->lineno
            );
        }
        $fallthrough_context = $context;

        $previous_child_context = null;
        foreach ($node->children as $i => $child_node) {
            if (!$child_node instanceof Node) {
                throw new AssertionError("Switch case statement must be a node");
            }
            ['cond' => $case_cond_node, 'stmts' => $case_stmts_node] = $child_node->children;
            // Step into each child node and get an
            // updated context for the node

            try {
                $this->parent_node_list[] = $node;
                $fallthrough_context->withLineNumberStart($child_node->lineno);
                ConfigPluginSet::instance()->preAnalyzeNode(
                    $this->code_base,
                    $fallthrough_context,
                    $child_node
                );
                // Statements such as `case $x = 2;` should affect both the body of that case statements and following case statements.
                // Modify the $fallthrough_context.
                if ($case_cond_node instanceof Node) {
                    $fallthrough_context = $this->analyzeAndGetUpdatedContext($fallthrough_context, $child_node, $case_cond_node);
                }
                if ($previous_child_context instanceof Context) {
                    // The previous case statement fell through some of the time or all of the time.
                    $child_context = (new ContextMergeVisitor(
                        $previous_child_context,
                        [$previous_child_context, $fallthrough_context]
                    ))->combineScopeList([$previous_child_context->getScope(), $fallthrough_context->getScope()]);
                } else {
                    // The previous case statement did not fall through, or does not exist.
                    $child_context = $fallthrough_context->withScope(clone($fallthrough_context->getScope()));
                }
                if ($case_cond_node !== null) {
                    if ($switch_variable_condition) {
                        // e.g. make sure to handle $x from `switch (true) { case $x instanceof stdClass: }` or `switch ($x)`
                        // Note that this won't properly combine types from `case $x = expr: case $x = expr2:` (latter would override former),
                        // but I don't expect to see that in reasonable code.
                        $variables_to_check = $switch_variable_cond_variable_set + RedundantCondition::getVariableSet($case_cond_node);
                        // Add the variable type from the above case statements, if it was possible for it to fall through
                        // TODO: Also support switch(get_class($variable))
                        $child_context = $switch_variable_condition($child_context, $case_cond_node);
                        foreach ($variables_to_check as $var_name) {
                            if ($previous_child_context !== null) {
                                $variable = $child_context->getScope()->getVariableByNameOrNull($var_name);
                                if ($variable) {
                                    $old_variable = $previous_child_context->getScope()->getVariableByNameOrNull($var_name);

                                    if ($old_variable) {
                                        $variable = clone($variable);
                                        $variable->setUnionType($variable->getUnionType()->withUnionType($old_variable->getUnionType()));
                                        $child_context->addScopeVariable($variable);
                                    }
                                }
                            }
                        }
                    }
                    if ($switch_variable_negated_condition) {
                        // e.g. make sure to handle $x from `switch (true) { case $x instanceof stdClass: }` or `switch ($x)`
                        // Note that this won't properly combine types from `case $x = expr: case $x = expr2:` (latter would override former),
                        // but I don't expect to see that in reasonable code.
                        $variables_to_check = $switch_variable_cond_variable_set + RedundantCondition::getVariableSet($case_cond_node);
                        foreach ($variables_to_check as $var_name) {
                            // Add the variable type that were ruled out by the above case statements, if it was possible for it to fall through
                            // TODO: Also support switch(get_class($variable))
                            $fallthrough_context = $switch_variable_negated_condition($fallthrough_context, $case_cond_node);
                        }
                    }
                } else {
                    foreach ($switch_variable_cond_variable_set as $var_name) {
                        // Add the variable types from the default to the
                        // TODO: Handle the default not being the last case statement
                        // TODO: Improve handling of possibly undefined variables
                        $variable = $child_context->getScope()->getVariableByNameOrNull($var_name);
                        if (!$variable) {
                            continue;
                        }
                        if ($previous_child_context) {
                            $old_variable = $previous_child_context->getScope()->getVariableByNameOrNull($var_name);

                            if ($old_variable) {
                                $variable = clone($variable);
                                $variable->setUnionType($variable->getUnionType()->withUnionType($old_variable->getUnionType()));
                                $child_context->addScopeVariable($variable);
                            }
                        }
                    }
                }

                if ($case_stmts_node instanceof Node) {
                    $child_context = $this->analyzeAndGetUpdatedContext($child_context, $child_node, $case_stmts_node);
                }
                ConfigPluginSet::instance()->postAnalyzeNode(
                    $this->code_base,
                    $fallthrough_context,
                    $child_node
                );
            } finally {
                \array_pop($this->parent_node_list);
            }


            if ($case_cond_node === null) {
                $has_default = true;
            }
            // We can improve analysis of `case` blocks by using
            // a BlockExitStatusChecker to avoid propagating invalid inferences.
            $stmts_node = $child_node->children['stmts'];
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable this is never null
            $block_exit_status = (new BlockExitStatusChecker())->__invoke($stmts_node);
            // equivalent to !willUnconditionallyThrowOrReturn()
            $previous_child_context = null;
            if (($block_exit_status & ~BlockExitStatusChecker::STATUS_THROW_OR_RETURN_BITMASK)) {
                // Skip over case statements that only ever throw or return
                if (count($stmts_node->children ?? []) !== 0 || $i === count($node->children) - 1) {
                    // and skip over empty statement lists, unless they're the last in a long line of empty statement lists
                    // @phan-suppress-next-line PhanPossiblyUndeclaredVariable the finally block is not perfectly analyzed by Phan
                    $child_context_list[] = $child_context;
                }

                if ($block_exit_status & BlockExitStatusChecker::STATUS_PROCEED) {
                    // @phan-suppress-next-line PhanPossiblyUndeclaredVariable the finally block is not perfectly analyzed by Phan
                    $previous_child_context = $child_context;
                }
            }
        }

        if (count($child_context_list) > 0) {
            if (!$has_default) {
                $child_context_list[] = $fallthrough_context;
            }
            if (count($child_context_list) >= 2) {
                // For case statements, we need to merge the contexts
                // of all child context into a single scope based
                // on any possible branching structure
                $context = (new ContextMergeVisitor(
                    $context,
                    $child_context_list
                ))->combineChildContextList();
            } else {
                $context = $child_context_list[0];
            }
        }

        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable Phan cannot infer child_context_list was non-empty due to finally block
        return $this->postOrderAnalyze($context, $node);
    }

    private const NOOP_SWITCH_COND_ANALYZER = [null, null, null];

    /**
     * @param Node|int|string|float $switch_case_node
     * @return array{0:?Node, 1:?Closure(Context, mixed): Context, 2:?Closure(Context, mixed): Context}
     */
    private function createSwitchConditionAnalyzer($switch_case_node): array
    {
        $switch_kind = ($switch_case_node->kind ?? null);
        try {
            if ($switch_kind === ast\AST_VAR) {
                $switch_variable = (new ConditionVisitor($this->code_base, $this->context))->getVariableFromScope($switch_case_node, $this->context);
                if (!$switch_variable) {
                    return self::NOOP_SWITCH_COND_ANALYZER;
                }
                return [
                    $switch_case_node,
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($switch_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->updateVariableToBeEqual($switch_case_node, $cond_node, $child_context);
                    },
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($switch_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->updateVariableToBeNotEqual($switch_case_node, $cond_node, $child_context);
                    },
                ];
            } elseif ($switch_kind === ast\AST_CALL) {
                $name = $switch_case_node->children['expr']->children['name'] ?? null;
                if (\is_string($name)) {
                    $name = \strtolower($name);
                    if ($name === 'get_class') {
                        $switch_variable_node = $switch_case_node->children['args']->children[0] ?? null;
                        if (!$switch_variable_node instanceof Node) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        if ($switch_variable_node->kind !== ast\AST_VAR) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        $switch_variable = (new ConditionVisitor($this->code_base, $this->context))->getVariableFromScope($switch_variable_node, $this->context);
                        if (!$switch_variable) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        return [
                            $switch_variable_node,
                            /**
                             * @param Node|string|int|float $cond_node
                             */
                            function (Context $child_context, $cond_node) use ($switch_variable_node): Context {
                                $visitor = new ConditionVisitor($this->code_base, $child_context);
                                return $visitor->analyzeClassAssertion(
                                    $switch_variable_node,
                                    $cond_node
                                ) ?? $child_context;
                            },
                            null,
                        ];
                    }
                }
            }

            if (!ScopeImpactCheckingVisitor::hasPossibleImpact($this->code_base, $this->context, $switch_case_node)) {
                // e.g. switch(true), switch(MY_CONST), switch(['x'])
                return [
                    $switch_case_node,
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($switch_case_node): Context {
                        // Handle match(cond) { $x = constexpr => ... }. The assignment was already analyzed.
                        while ($cond_node instanceof Node && \in_array($cond_node->kind, [ast\AST_ASSIGN, ast\AST_ASSIGN_REF, ast\AST_ASSIGN_OP], true)) {
                            $cond_node = $cond_node->children['var'];
                        }
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->analyzeAndUpdateToBeEqual($switch_case_node, $cond_node);
                    },
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($switch_case_node): Context {
                        // Handle match(cond) { $x = constexpr => ... }. The assignment was already analyzed.
                        while ($cond_node instanceof Node && \in_array($cond_node->kind, [ast\AST_ASSIGN, ast\AST_ASSIGN_REF, ast\AST_ASSIGN_OP], true)) {
                            $cond_node = $cond_node->children['var'];
                        }
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->analyzeAndUpdateToBeNotEqual($switch_case_node, $cond_node);
                    },
                ];
            }
        } catch (IssueException $_) {
            // do nothing, we warn elsewhere
        }
        return self::NOOP_SWITCH_COND_ANALYZER;
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * Based on visitSwitchList
     */
    public function visitMatchArmList(Node $node): Context
    {
        // Make a copy of the internal context so that we don't
        // leak any changes within the closed context to the
        // outer scope
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);
        $context = $this->preOrderAnalyze(clone($context), $node);

        $child_context_list = [];

        // parent_node_list should always end in kind ast\AST_MATCH
        $match_expression_node = end($this->parent_node_list);
        if (!$match_expression_node instanceof Node) {
            throw new AssertionError('Expected AST_MATCH node as parent of AST_MATCH_ARM_LIST');
        }
        $children = $node->children;
        if (\count($children) <= 1 && !isset($children[0]->children['cond'])) {
            // Warn about match expressions with only the default condition.
            $this->emitIssue(
                Issue::NoopMatchArms,
                $match_expression_node->lineno,
                ASTReverter::toShortString($match_expression_node)
            );
        }
        // Create closures to infer the effects of checking the match expression against the match condition lists (assertions and their negations)
        $cond_node = $match_expression_node->children['cond'];
        [$unused_match_variable_node, $match_variable_condition, $match_variable_negated_condition] = $this->createMatchConditionAnalyzer(
            $cond_node
        );
        if (Config::getValue('redundant_condition_detection')) {
            $cond_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $match_expression_node->children['cond']);
            if ($cond_type->hasRealTypeSet()) {
                $cond_type = $cond_type->getRealUnionType()->withStaticResolvedInContext($context);
            } else {
                $cond_type = null;
            }
        } else {
            $cond_type = null;
        }
        /*
        if (($match_variable_condition || $match_variable_negated_condition) && $match_variable_node instanceof Node) {
            $match_variable_cond_variable_set = RedundantCondition::getVariableSet($match_variable_node);
        } else {
            $match_variable_cond_variable_set = [];
        }
         */
        $fallthrough_context = $context;

        $default_arm_node = null;

        foreach ($node->children as $arm_node) {
            if (!$arm_node instanceof Node) {
                throw new AssertionError("Match arm must be a node");
            }

            if ($arm_node->children['cond'] === null) {
                if ($default_arm_node !== null) {
                    $this->emitIssue(
                        Issue::InvalidNode,
                        $arm_node->lineno,
                        'Saw an invalid match arm with multiple default nodes'
                    );
                    // fall through
                } else {
                    // TODO: Warn about duplicate default nodes
                    $default_arm_node = $arm_node;
                    continue;
                }
            }
            // Step into each child node and get an
            // updated context for the node

            $child_context = $fallthrough_context->withScope(clone($fallthrough_context->getScope()));

            $child_context->withLineNumberStart($arm_node->lineno);
            try {
                $this->parent_node_list[] = $node;
                [$child_context, $fallthrough_context] = $this->analyzeMatchArm(
                    $child_context,
                    $fallthrough_context,
                    $match_variable_condition,
                    $match_variable_negated_condition,
                    $arm_node,
                    $cond_node,
                    $cond_type
                );
            } finally {
                \array_pop($this->parent_node_list);
            }

            // We can improve analysis of arms by using
            // a BlockExitStatusChecker to avoid propagating invalid inferences.
            if (self::willExecutionProceedAfterMatchArm($arm_node)) {
                $child_context_list[] = $child_context;
            }
        }

        if ($default_arm_node instanceof Node) {
            // Duplicates above code
            // TODO: Check if the default is unreachable

            // Step into each child node and get an
            // updated context for the node

            $child_context = $fallthrough_context->withScope(clone($fallthrough_context->getScope()));

            $child_context->withLineNumberStart($default_arm_node->lineno);
            try {
                $this->parent_node_list[] = $node;
                [$child_context, $unused_fallthrough_context] = $this->analyzeMatchArm(
                    $child_context,
                    $fallthrough_context,
                    $match_variable_condition,
                    $match_variable_negated_condition,
                    $default_arm_node,
                    $cond_node,
                    $cond_type
                );
            } finally {
                \array_pop($this->parent_node_list);
            }

            // We can improve analysis of arms by using
            // a BlockExitStatusChecker to avoid propagating invalid inferences.
            if (self::willExecutionProceedAfterMatchArm($default_arm_node)) {
                $child_context_list[] = $child_context;
            }
        }

        // Match will throw an UnhandledMatchError if none of the arms apply.
        if (count($child_context_list) > 0) {
            if (count($child_context_list) >= 2) {
                // For case statements, we need to merge the contexts
                // of all child context into a single scope based
                // on any possible branching structure
                $context = (new ContextMergeVisitor(
                    $context,
                    $child_context_list
                ))->combineChildContextList();
            } else {
                $context = $child_context_list[0];
            }
        }

        return $this->postOrderAnalyze($context, $node);
    }

    private static function willExecutionProceedAfterMatchArm(Node $arm_node): bool
    {
        $expr_node = $arm_node->children['expr'];
        if (!$expr_node instanceof Node) {
            return true;
        }
        $block_exit_status = (new BlockExitStatusChecker())->__invoke($expr_node);
        // Skip over arms that only ever throw/exit.
        // equivalent to !willUnconditionallyThrowOrReturn()
        return ($block_exit_status & ~BlockExitStatusChecker::STATUS_THROW_OR_RETURN_BITMASK) !== 0;
    }

    /**
     * @param ?Closure(Context, mixed): Context $match_variable_condition
     * @param ?Closure(Context, mixed): Context $match_variable_negated_condition
     * @param Node|int|string|float $match_cond_node
     * @param ?UnionType $cond_type for impossible condition detection
     * @return array{0: Context, 1: Context} new values of [$child_context, $fallthrough_context]
     */
    private function analyzeMatchArm(
        Context $child_context,
        Context $fallthrough_context,
        ?Closure $match_variable_condition,
        ?Closure $match_variable_negated_condition,
        Node $arm_node,
        $match_cond_node,
        ?UnionType $cond_type
    ): array {
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $fallthrough_context,
            $arm_node
        );
        ['expr' => $arm_expr_node, 'cond' => $arm_cond_node] = $arm_node->children;
        if ($arm_cond_node !== null) {
            if ($arm_cond_node instanceof Node) {
                $child_context = $this->analyzeAndGetUpdatedContext($child_context, $arm_node, $arm_cond_node);
            }
            if ($cond_type) {
                (new RedundantConditionVisitor($this->code_base, $child_context))->checkImpossibleMatchArm($match_cond_node, $cond_type, $arm_node);
            }
            if ($match_variable_condition) {
                // e.g. make sure to handle $x from `match (true) { $x instanceof stdClass => ... }` or `match ($x)`
                // Note that this won't properly combine types from `($x = expr) => ... , ($x = expr2) => ...` (latter would override former),
                // but I don't expect to see that in reasonable code.
                //$variables_to_check = $match_variable_cond_variable_set + RedundantCondition::getVariableSet($arm_cond_node);

                // Add the variable type from the **conditions of the** above arms,
                // if it was possible for it to fall through
                // TODO: Also support match(get_class($variable))
                $child_context = $match_variable_condition($child_context, $arm_cond_node);
            }
            if ($match_variable_negated_condition) {
                // Add the variable types that were ruled out by the above case statements, if it was possible for it to fall through.
                // TODO: Also support match(get_class($variable))
                $fallthrough_context = $match_variable_negated_condition($fallthrough_context, $arm_cond_node);
            }
        }

        if ($arm_expr_node instanceof Node) {
            $child_context = $this->analyzeAndGetUpdatedContext($child_context, $arm_node, $arm_expr_node);
        }
        ConfigPluginSet::instance()->postAnalyzeNode(
            $this->code_base,
            $fallthrough_context,
            $arm_node
        );
        return [$child_context, $fallthrough_context];
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements
     *
     * @return Context
     * The updated context after visiting the node
     *
     * XXX this is complicated because we need to know for each `if`/`elseif` clause
     *
     * - What the side effects of executing the expression are on the chain (e.g. variable assignments, assignments by references)
     * - What the context would be if that expression were truthy (ConditionVisitor)
     * - What the context would be if that expression were falsey (NegatedConditionVisitor)
     *
     * The code in visitIfElem had to be inlined in order to properly modify the associated contexts.
     * @suppress PhanSuspiciousTruthyCondition, PhanSuspiciousTruthyString the value Phan infers for conditions can be array literals. This seems to be handled properly.
     */
    public function visitIf(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        $context = $this->preOrderAnalyze($context, $node);

        // We collect all child context so that the
        // PostOrderAnalysisVisitor can optionally operate on
        // them
        $child_context_list = [];

        $scope = $context->getScope();
        if ($scope instanceof GlobalScope) {
            $fallthrough_context = $context->withScope(new BranchScope($scope));
        } else {
            $fallthrough_context = $context;
        }

        $child_nodes = $node->children;
        $excluded_elem_count = 0;

        $first_unconditionally_true_index = null;

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($child_nodes as $child_node) {
            // The conditions need to communicate to the outer
            // scope for things like assigning variables.
            // $child_context = $fallthrough_context->withClonedScope();

            '@phan-var Node $child_node';
            $fallthrough_context->setLineNumberStart($child_node->lineno);

            $old_context = $this->context;
            $this->context = $fallthrough_context;
            $this->parent_node_list[] = $node;

            try {
                // NOTE: This is different from other analysis visitors because analyzing 'cond' with `||` has side effects
                // after supporting `BlockAnalysisVisitor->visitBinaryOp()`
                // TODO: Calling analyzeAndGetUpdatedContext before preOrderAnalyze is a hack.

                // TODO: This is redundant and has worse knowledge of the specific types of blocks than ConditionVisitor does.
                // TODO: Implement a hybrid BlockAnalysisVisitor+ConditionVisitor that will do a better job of inferences and reducing false positives? (and reduce the redundant work)

                // E.g. the below code would update the context of BlockAnalysisVisitor in BlockAnalysisVisitor->visitBinaryOp()
                //
                //     if (!(is_string($x) || $x === null)) {}
                //
                // But we want to let BlockAnalysisVisitor modify the context for cases such as the below:
                //
                // $result = !($x instanceof User) || $x->meetsCondition()
                [$child_context, $fallthrough_context] = $this->preAnalyzeIfElemCondition($child_node, $fallthrough_context);
                $condition_node = $child_node->children['cond'];

                $stmts_node = $child_node->children['stmts'];
                if (!$stmts_node instanceof Node) {
                    throw new AssertionError('Did not expect null/empty statements list node');
                }

                $this->parent_node_list[] = $child_node;
                $old_context = $this->context;
                $this->context = $child_context->withScope(
                    new BranchScope($child_context->getScope())
                )->withLineNumberStart($stmts_node->lineno);
                try {
                    $child_context = $this->visitStmtList($stmts_node);
                } finally {
                    \array_pop($this->parent_node_list);
                    $this->context = $old_context;
                }

                // Now that we know all about our context (like what
                // 'self' means), we can analyze statements like
                // assignments and method calls.
                $child_context = $this->postOrderAnalyze($child_context, $child_node);

                // Issue #406: We can improve analysis of `if` blocks by using
                // a BlockExitStatusChecker to avoid propagating invalid inferences.
                // TODO: we may wish to check for a try block between this line's scope
                // and the parent function's (or global) scope,
                // to reduce false positives.
                // (Variables will be available in `catch` and `finally`)
                // This is mitigated by finally and catch blocks being unaware of new variables from try{} blocks.

                // inferred_value is either:
                // 1. truthy non-Node if the value could be inferred
                // 2. falsy non-Node if the value could be inferred
                // 3. A Node if the value could not be inferred (most conditionals)
                if ($condition_node instanceof Node) {
                    $inferred_cond_value = (new ContextNode($this->code_base, $fallthrough_context, $condition_node))->getEquivalentPHPValueForControlFlowAnalysis();
                } else {
                    // Treat `else` as equivalent to `elseif (true)`
                    $inferred_cond_value = $condition_node ?? true;
                }
                if (!$inferred_cond_value) {
                    // Don't merge this scope into the outer scope
                    // e.g. "if (false) { anything }"
                    $excluded_elem_count++;
                } elseif (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($stmts_node)) {
                    // e.g. "if (!is_string($x)) { return; }" or break
                    $excluded_elem_count++;
                    if (!BlockExitStatusChecker::willUnconditionallyThrowOrReturn($stmts_node)) {
                        $this->recordLoopContextForBreakOrContinue($child_context);
                    }
                } else {
                    $child_context_list[] = $child_context;
                }

                if ($condition_node instanceof Node) {
                    // fwrite(STDERR, "Checking if unconditionally true: " . \Phan\Debug::nodeToString($condition_node) . "\n");
                    // TODO: Could add a check for conditions that are unconditionally falsey and warn
                    if (!$inferred_cond_value instanceof Node && $inferred_cond_value) {
                        // TODO: Could warn if this is not a condition on a static variable
                        $first_unconditionally_true_index = $first_unconditionally_true_index ?? \count($child_context_list);
                    }
                    $fallthrough_context = (new NegatedConditionVisitor($this->code_base, $fallthrough_context))->__invoke($condition_node);
                } elseif ($condition_node) {
                    $first_unconditionally_true_index = $first_unconditionally_true_index ?? \count($child_context_list);
                }
                // If cond_node was null, it would be an else statement.
            } finally {
                $this->context = $old_context;
                \array_pop($this->parent_node_list);
            }
        }
        // fprintf(STDERR, "First unconditionally true index is %s: %s\n", $first_unconditionally_true_index ?? 'null', \Phan\Debug::nodeToString($node));

        if ($excluded_elem_count === count($child_nodes)) {
            // If all of the AST_IF_ELEM bodies would unconditionally throw or return,
            // then analyze the remaining statements with the negation of all of the conditions.
            $context = $fallthrough_context;
        } elseif ($first_unconditionally_true_index > 0) {
            // If we have at least one child context that falls through, then use that one.
            $context = (new ContextMergeVisitor(
                $fallthrough_context,  // e.g. "if (!is_string($x)) { $x = ''; }" should result in inferring $x is a string.
                \array_slice($child_context_list, 0, $first_unconditionally_true_index)
            ))->mergePossiblySingularChildContextList();
        } else {
            // For if statements, we need to merge the contexts
            // of all child context into a single scope based
            // on any possible branching structure

            // ContextMergeVisitor will include the incoming scope($context) if the if elements aren't comprehensive
            $context = (new ContextMergeVisitor(
                $fallthrough_context,  // e.g. "if (!is_string($x)) { $x = ''; }" should result in inferring $x is a string.
                $child_context_list
            ))->visitIf($node);
        }


        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * Returns contexts in which the condition was true and which the condition was false.
     *
     * This has special cases for handling `if (A && (B = C)) {}`
     *
     * @param Node $if_elem_node a node of kind ast\AST_IF_ELEM
     * @return array{0:Context,1:Context} [$child_context, new value of $fallthrough_context]
     */
    private function preAnalyzeIfElemCondition(Node $if_elem_node, Context $fallthrough_context): array
    {
        $condition_node = $if_elem_node->children['cond'];
        if ($condition_node instanceof Node) {
            if ($condition_node->kind === ast\AST_BINARY_OP) {
                // TODO: Support BINARY_BOOL_OR for fallthrough_context.
                // This will be inconvenient with needing to check for initially false/initially true condition nodes in loops.
                if ($condition_node->flags === ast\flags\BINARY_BOOL_AND) {
                    $child_context = $this->analyzeAndGetUpdatedContextAndAssertTruthy(
                        $fallthrough_context,
                        $if_elem_node,
                        $condition_node
                    );
                    $fallthrough_context = $this->analyzeAndGetUpdatedContext(
                        $fallthrough_context->withLineNumberStart($condition_node->lineno),
                        $if_elem_node,
                        $condition_node
                    );
                    return [$child_context, $fallthrough_context];
                }
            }
            $fallthrough_context = $this->analyzeAndGetUpdatedContext(
                $fallthrough_context->withLineNumberStart($condition_node->lineno),
                $if_elem_node,
                $condition_node
            );
        } elseif (Config::getValue('redundant_condition_detection')) {
            (new ConditionVisitor($this->code_base, $fallthrough_context))->checkRedundantOrImpossibleTruthyCondition($condition_node, $fallthrough_context, null, false);
        }

        $child_context = $fallthrough_context->withClonedScope();
        $child_context = $this->preOrderAnalyze($child_context, $if_elem_node);

        return [$child_context, $fallthrough_context];
    }

    /**
     * @param Node|string|int|float $condition_node
     */
    private function analyzeAndGetUpdatedContextAndAssertTruthy(
        Context $context,
        Node $parent_node,
        $condition_node
    ): Context {
        if (!$condition_node instanceof Node) {
            return $context;
        }
        if ($condition_node->kind === ast\AST_BINARY_OP) {
            if ($condition_node->flags === ast\flags\BINARY_BOOL_AND) {
                return $this->analyzeAndGetUpdatedContextAndAssertTruthy(
                    $this->analyzeAndGetUpdatedContextAndAssertTruthy(
                        $context,
                        $condition_node,
                        $condition_node->children['left']
                    ),
                    $condition_node,
                    $condition_node->children['right']
                );
            }
        }
        // Let any configured plugins do a pre-order
        // analysis of the node.
        // This may run multiple times on the same node due to need to analyze conditions and their negation.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $condition_node
        );
        // Infer the side effects of a generic node kind
        $context = $this->analyzeAndGetUpdatedContext(
            $context->withLineNumberStart($condition_node->lineno),
            $parent_node,
            $condition_node
        );
        // Assert the generic node kind is truthy
        return (new ConditionVisitor(
            $this->code_base,
            $context
        ))->__invoke($condition_node);
    }

    /**
     * Returns a closure to analyze the conditions for match expressions for a match arm
     *
     * @param Node|int|string|float $match_case_node
     * @return array{0:?Node, 1:?Closure(Context, mixed): Context, 2:?Closure(Context, mixed): Context}
     * @see self::createSwitchConditionAnalyzer() - Based on that but uses strict equality instead
     */
    private function createMatchConditionAnalyzer($match_case_node): array
    {
        $match_kind = ($match_case_node->kind ?? null);
        try {
            if ($match_kind === ast\AST_VAR) {
                $match_variable = (new ConditionVisitor($this->code_base, $this->context))->getVariableFromScope($match_case_node, $this->context);
                if (!$match_variable) {
                    return self::NOOP_SWITCH_COND_ANALYZER;
                }
                return [
                    $match_case_node,
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($match_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->updateVariableToBeIdentical($match_case_node, $cond_node, $child_context);
                    },
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($match_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->updateVariableToBeNotIdentical($match_case_node, $cond_node, $child_context);
                    },
                ];
            } elseif ($match_kind === ast\AST_CALL) {
                $name = $match_case_node->children['expr']->children['name'] ?? null;
                if (\is_string($name)) {
                    $name = \strtolower($name);
                    if ($name === 'get_class') {
                        $match_variable_node = $match_case_node->children['args']->children[0] ?? null;
                        if (!$match_variable_node instanceof Node) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        if ($match_variable_node->kind !== ast\AST_VAR) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        $match_variable = (new ConditionVisitor($this->code_base, $this->context))->getVariableFromScope($match_variable_node, $this->context);
                        if (!$match_variable) {
                            return self::NOOP_SWITCH_COND_ANALYZER;
                        }
                        return [
                            $match_variable_node,
                            /**
                             * @param Node|string|int|float $cond_node
                             */
                            function (Context $child_context, $cond_node) use ($match_variable_node): Context {
                                $visitor = new ConditionVisitor($this->code_base, $child_context);
                                return $visitor->analyzeClassAssertion(
                                    $match_variable_node,
                                    $cond_node
                                ) ?? $child_context;
                            },
                            null,
                        ];
                    }
                }
            }
            if (!ScopeImpactCheckingVisitor::hasPossibleImpact($this->code_base, $this->context, $match_case_node)) {
                // e.g. match(true), match(MY_CONST), match(['x'])
                return [
                    $match_case_node,
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($match_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->analyzeAndUpdateToBeIdentical($match_case_node, $cond_node);
                    },
                    /**
                     * @param Node|string|int|float $cond_node
                     */
                    function (Context $child_context, $cond_node) use ($match_case_node): Context {
                        $visitor = new ConditionVisitor($this->code_base, $child_context);
                        return $visitor->analyzeAndUpdateToBeNotIdentical($match_case_node, $cond_node);
                    },
                ];
            }
        } catch (IssueException $_) {
            // do nothing, we warn elsewhere
        }
        return self::NOOP_SWITCH_COND_ANALYZER;
    }

    /**
     * Handle break/continue statements in conditionals within a loop.
     * Record scope with the inferred variable types so it can be merged later outside of the loop.
     *
     * TODO: This is a heuristic that could be improved (differentiate break/continue, check if all branches are already handled, etc.)
     * @suppress PhanUndeclaredProperty
     */
    private function recordLoopContextForBreakOrContinue(Context $child_context): void
    {
        for ($i = \count($this->parent_node_list) - 1; $i >= 0; $i--) {
            $node = $this->parent_node_list[$i];
            switch ($node->kind) {
                // switch handles continue/break the same way as regular loops. (in PostOrderAnalysisVisitor::visitSwitch)
                case ast\AST_SWITCH:
                case ast\AST_FOR:
                case ast\AST_WHILE:
                case ast\AST_DO_WHILE:
                case ast\AST_FOREACH:
                    if (isset($node->phan_loop_contexts)) {
                        $node->phan_loop_contexts[] = $child_context;
                    } else {
                        $node->phan_loop_contexts = [$child_context];
                    }
                    break;
                case ast\AST_FUNC_DECL:
                case ast\AST_CLOSURE:
                case ast\AST_ARROW_FUNC:
                case ast\AST_METHOD:
                case ast\AST_CLASS:
                    // We didn't find it.
                    return;
            }
        }
    }

    /**
     * TODO: Diagram similar to visit() for this and other visitors handling branches?
     *
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitTry(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        $context = $this->preOrderAnalyze($context, $node);

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.

        $try_node = $node->children['try'];

        // The conditions need to communicate to the outer
        // scope for things like assigning variables.
        $try_context = $context->withScope(
            new BranchScope($context->getScope())
        );

        $try_context->withLineNumberStart(
            $try_node->lineno
        );

        // Step into each try node and get an
        // updated context for the node
        $try_context = $this->analyzeAndGetUpdatedContext($try_context, $node, $try_node);

        // Analyze the catch blocks and finally blocks with a mix of the types
        // from the try block and the catch blocks.
        // There's still some ways this could be improved for combining contexts.
        // (It's difficult to do this perfectly, especially since almost any expression in a try block
        // may throw under some circumstances)
        //
        // NOTE: We let ContextMergeVisitor->visitTry decide if the block exit status is valid.
        $context = (new ContextMergeVisitor(
            $context,
            [$try_context]
        ))->mergeTryContext($node);

        // We collect all child context so that the
        // PostOrderAnalysisVisitor can optionally operate on
        // them
        $catch_context_list = [$try_context];

        $catch_nodes = $node->children['catches']->children ?? [];

        foreach ($catch_nodes as $catch_node) {
            // Note: ContextMergeVisitor expects to get each individual catch
            if (!$catch_node instanceof Node) {
                throw new AssertionError("Expected catch_node to be a Node");
            }

            // The conditions need to communicate to the outer
            // scope for things like assigning variables.
            $catch_context = $context->withScope(
                new BranchScope($context->getScope())
            );

            $catch_context->withLineNumberStart(
                $catch_node->lineno
            );

            // Step into each catch node and get an
            // updated context for the node
            $catch_context = $this->analyzeAndGetUpdatedContext($catch_context, $node, $catch_node);
            $catch_stmts_node = $catch_node->children['stmts'];
            if ($catch_stmts_node instanceof Node && BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($catch_stmts_node)) {
                // e.g. "} catch (Exception $e) { break; }"
                if (!BlockExitStatusChecker::willUnconditionallyThrowOrReturn($catch_stmts_node)) {
                    $this->recordLoopContextForBreakOrContinue($catch_context);
                }
            }
            // NOTE: We let ContextMergeVisitor->mergeCatchContext decide if the block exit status is valid.
            $catch_context_list[] = $catch_context;
        }

        $this->checkUnreachableCatch($catch_nodes, $context);

        // first context is the try. If there's a second context, it's a catch.
        if (count($catch_context_list) >= 2) {
            // For switch/try statements, we need to merge the contexts
            // of all child context into a single scope based
            // on any possible branching structure
            $context = (new ContextMergeVisitor(
                $context,
                $catch_context_list
            ))->mergeCatchContext($node, BlockExitStatusChecker::willUnconditionallyThrowOrReturn($try_node));
        }

        $finally_node = $node->children['finally'] ?? null;
        if ($finally_node) {
            if (!($finally_node instanceof Node)) {
                throw new AssertionError("Expected non-null finally node to be a Node");
            }
            // Because finally is always executed, we reuse $context

            // The conditions need to communicate to the outer
            // scope for things like assigning variables.
            $context = $context->withScope(
                new BranchScope($context->getScope())
            );

            $context->withLineNumberStart(
                $finally_node->lineno
            );

            // Step into each finally node and get an
            // updated context for the node.
            // Don't bother checking if finally unconditionally returns here
            // If it does, dead code detection would also warn.
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $finally_node);
        }

        // When coming out of a scoped element, we pop the
        // context to be the incoming context. Otherwise,
        // we pass our new context up to our parent
        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param list<Node> $catch_nodes
     * @param Context $context
     */
    private function checkUnreachableCatch(array $catch_nodes, Context $context): void
    {
        if (count($catch_nodes) <= 1) {
            return;
        }
        $caught_union_types = [];
        $code_base = $this->code_base;

        foreach ($catch_nodes as $catch_node) {
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall should be impossible to throw
            $union_type = UnionTypeVisitor::unionTypeFromClassNode(
                $code_base,
                $context,
                $catch_node->children['class']
            )->objectTypesWithKnownFQSENs();

            $catch_line = $catch_node->lineno;

            foreach ($union_type->getUniqueFlattenedTypeSet() as $type) {
                foreach ($type->asExpandedTypes($code_base)->getTypeSet() as $ancestor_type) {
                    // Check if any of the ancestors were already caught by a previous catch statement
                    $line = $caught_union_types[\spl_object_id($ancestor_type)] ?? null;

                    if ($line !== null) {
                        Issue::maybeEmit(
                            $code_base,
                            $context,
                            Issue::UnreachableCatch,
                            $catch_line,
                            (string)$type,
                            $line,
                            (string)$ancestor_type
                        );
                        break;
                    }
                }
            }
            foreach ($union_type->getTypeSet() as $type) {
                // Track where this ancestor type was thrown
                $caught_union_types[\spl_object_id($type)] = $catch_line;
            }
        }
    }

    /**
     * @param Node $node
     * A node to parse
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function visitBinaryOp(Node $node): Context
    {
        $flags = $node->flags;
        switch ($flags) {
            case ast\flags\BINARY_BOOL_AND:
                return $this->analyzeBinaryBoolAnd($node);
            case ast\flags\BINARY_BOOL_OR:
                return $this->analyzeBinaryBoolOr($node);
            case ast\flags\BINARY_COALESCE:
                return $this->analyzeBinaryCoalesce($node);
        }
        return $this->visit($node);
    }

    /**
     * @param Node $node
     * A node to parse (for `&&` or `and` operator)
     *
     * @return Context
     * A new context resulting from analyzing this logical `&&` operator.
     */
    public function analyzeBinaryBoolAnd(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $left_node = $node->children['left'];
        $right_node = $node->children['right'];

        // With (left) && (right)
        // 1. Update context with any side effects of left
        // 2. Create a context to analyze the right-hand side with any inferences possible from left (e.g. ($x instanceof MyClass) && $x->foo()
        // 3. Analyze the right-hand side
        // 4. Merge the possibly evaluated $right_context for the right-hand side into the original context. (The left_node is guaranteed to have been evaluated, so it becomes $context)

        // TODO: Warn about non-node, they're guaranteed to be always false or true
        if ($left_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $left_node);

            $base_context = $context;
            $base_context_scope = $base_context->getScope();
            if ($base_context_scope instanceof GlobalScope) {
                $base_context = $context->withScope(new BranchScope($base_context_scope));
            }
            $context_with_left_condition = (new ConditionVisitor(
                $this->code_base,
                $base_context
            ))->__invoke($left_node);
            $context_with_false_left_condition = (new NegatedConditionVisitor(
                $this->code_base,
                $base_context
            ))->__invoke($left_node);
        } else {
            $context_with_left_condition = $context;
            $context_with_false_left_condition = $context;
        }

        if ($right_node instanceof Node) {
            $right_context = $this->analyzeAndGetUpdatedContext($context_with_left_condition, $node, $right_node);
            if (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($right_node)) {
                return $this->postOrderAnalyze($context_with_false_left_condition, $node);
            }
            if (ScopeImpactCheckingVisitor::hasPossibleImpact($this->code_base, $context, $right_node)) {
                // If the expression on the right side does have side effects (e.g. `$cond || $x = foo()`), then we need to merge all possibilities.
                //
                // However, if it doesn't have side effects (e.g. `$a && $b` in `var_export($a || $b)`, then adding the inferences is counterproductive)
                $context = (new ContextMergeVisitor(
                    $context,
                    [$context, $context_with_false_left_condition, $right_context]
                ))->combineChildContextList();
            }
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node
     * A node to parse (for `||` or `or` operator)
     *
     * @return Context
     * A new context resulting from analyzing this `||` operator.
     */
    public function analyzeBinaryBoolOr(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $left_node = $node->children['left'];
        $right_node = $node->children['right'];

        // With (left) || (right)
        // 1. Update context with any side effects of left
        // 2. Create a context to analyze the right-hand side with any inferences possible from left (e.g. (!($x instanceof MyClass)) || $x->foo()
        // 3. Analyze the right-hand side
        // 4. Merge the possibly evaluated $right_context for the right-hand side into the original context. (The left_node is guaranteed to have been evaluated, so it becomes $context)

        // TODO: Warn about non-node, they're guaranteed to be always false or true
        if ($left_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $left_node);

            $base_context = $context;
            $base_context_scope = $base_context->getScope();
            if ($base_context_scope instanceof GlobalScope) {
                $base_context = $context->withScope(new BranchScope($base_context_scope));
            }
            $context_with_true_left_condition = (new ConditionVisitor(
                $this->code_base,
                $base_context
            ))($left_node);
            $context_with_false_left_condition = (new NegatedConditionVisitor(
                $this->code_base,
                $base_context
            ))($left_node);
        } else {
            $context_with_false_left_condition = $context;
            $context_with_true_left_condition = $context;
        }

        if ($right_node instanceof Node) {
            $right_context = $this->analyzeAndGetUpdatedContext($context_with_false_left_condition, $node, $right_node);
            if (BlockExitStatusChecker::willUnconditionallySkipRemainingStatements($right_node)) {
                return $this->postOrderAnalyze($context_with_true_left_condition, $node);
            }
            if (ScopeImpactCheckingVisitor::hasPossibleImpact($this->code_base, $context, $right_node)) {
                // If the expression on the right side does have side effects (e.g. `$cond || $x = foo()`), then we need to merge all possibilities.
                //
                // However, if it doesn't have side effects (e.g. `$a || $b` in `var_export($a || $b)`, then adding the inferences is counterproductive)
                $context = (new ContextMergeVisitor(
                    $context,
                    // XXX don't merge $context?
                    [$context, $context_with_true_left_condition, $right_context]
                ))->combineChildContextList();
            }
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node
     * A node to parse (for `??` operator)
     *
     * @return Context
     * A new or an unchanged context resulting from
     * parsing the node
     */
    public function analyzeBinaryCoalesce(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        $left_node = $node->children['left'];
        $right_node = $node->children['right'];

        // With (left) ?? (right)
        // 1. Analyze left and update context with any side effects of left
        // 2. Check if left is always null or never null, if redundant_condition_detection is enabled
        // 3. Analyze right-hand side and update context with any side effects of the right-hand side
        //    (TODO: consider using a branch here for analyzing variable assignments, etc.)
        // 4. Return the updated context

        if ($left_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $left_node);
        }
        if (Config::getValue('redundant_condition_detection')) {
            // Check for always null or never null values *before* modifying the context with inferences from the right hand side.
            // Useful for analyzing `$x ?? ($x = expr)`
            $this->analyzeBinaryCoalesceForRedundantCondition($context, $node);
        }
        if ($right_node instanceof Node) {
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $right_node);
        }
        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * Returns true if $node represents an expression that can't be undefined
     */
    private static function isAlwaysDefined(Context $context, Node $node): bool
    {
        switch ($node->kind) {
            case ast\AST_VAR:
                $var_name = $node->children['name'];
                if (is_string($var_name) && !$context->isInLoop() && $context->isInFunctionLikeScope() && !Variable::isSuperglobalVariableWithName($var_name)) {
                    $variable = $context->getScope()->getVariableByNameOrNull($var_name);
                    return $variable && !$variable->getUnionType()->isPossiblyUndefined();
                }
                return false;
            case ast\AST_BINARY_OP:
                if ($node->flags === ast\flags\BINARY_COALESCE) {
                    $right_node = $node->children['right'];
                    return !($right_node instanceof Node) || self::isAlwaysDefined($context, $right_node);
                }
                return true;
            case ast\AST_PROP:
            case ast\AST_DIM:
                return false;
            default:
                return true;
        }
    }

    /**
     * Checks if the left hand side of a null coalescing operator is never null or always null
     */
    private function analyzeBinaryCoalesceForRedundantCondition(Context $context, Node $node): void
    {
        $left_node = $node->children['left'];
        $right_node = $node->children['right'];
        // @phan-suppress-next-line PhanPartialTypeMismatchArgumentInternal
        if ($right_node instanceof Node && $right_node->kind === ast\AST_CONST && \strcasecmp($right_node->children['name']->children['name'] ?? '', 'null') === 0) {
            if ($left_node instanceof Node && self::isAlwaysDefined($context, $left_node)) {
                $this->emitIssue(
                    Issue::CoalescingNeverUndefined,
                    $node->lineno,
                    ASTReverter::toShortString($left_node)
                );
            }
        }
        $left = UnionTypeVisitor::unionTypeFromNode($this->code_base, $context, $left_node);
        if (!$left->hasRealTypeSet()) {
            return;
        }
        $left = $left->getRealUnionType();
        if (!$left->containsNullableOrUndefined()) {
            if (RedundantCondition::shouldNotWarnAboutIssetCheckForNonNullExpression($this->code_base, $context, $left_node)) {
                return;
            }
            RedundantCondition::emitInstance(
                $left_node,
                $this->code_base,
                (clone $context)->withLineNumberStart($node->lineno),
                Issue::CoalescingNeverNull,
                [
                    ASTReverter::toShortString($left_node),
                    $left
                ],
                static function (UnionType $type): bool {
                    return !$type->containsNullableOrUndefined();
                },
                self::canNodeKindBeNull($left_node)
            );
            return;
        } elseif ($left->isNull()) {
            RedundantCondition::emitInstance(
                $left_node,
                $this->code_base,
                (clone $context)->withLineNumberStart($node->lineno),
                Issue::CoalescingAlwaysNull,
                [
                    ASTReverter::toShortString($left_node),
                    $left
                ],
                static function (UnionType $type): bool {
                    return $type->isNull();
                }
            );
            return;
        }
    }

    /**
     * @param Node|string|int|float $node
     */
    private static function canNodeKindBeNull($node): bool
    {
        if (!$node instanceof Node) {
            return false;
        }
        // Look at the nodes that can be null
        switch ($node->kind) {
            case ast\AST_CAST:
                return $node->flags === ast\flags\TYPE_NULL;
            case ast\AST_UNARY_OP:
                return $node->flags === ast\flags\UNARY_SILENCE &&
                    self::canNodeKindBeNull($node->children['expr']);
            case ast\AST_BINARY_OP:
                return $node->flags === ast\flags\BINARY_COALESCE &&
                    self::canNodeKindBeNull($node->children['left']) &&
                    self::canNodeKindBeNull($node->children['right']);
            case ast\AST_CONST:
            case ast\AST_VAR:
            case ast\AST_SHELL_EXEC:  // SHELL_EXEC will return null instead of an empty string for no output.
            case ast\AST_INCLUDE_OR_EVAL:
                // $x++, $x--, and --$x return null when $x is null. ++$x doesn't.
            case ast\AST_PRE_INC:
            case ast\AST_PRE_DEC:
            case ast\AST_POST_DEC:
            case ast\AST_YIELD_FROM:
            case ast\AST_YIELD:
            case ast\AST_DIM:
            case ast\AST_PROP:
            case ast\AST_NULLSAFE_PROP:
            case ast\AST_STATIC_PROP:
            case ast\AST_CALL:
            case ast\AST_CLASS_CONST:
            case ast\AST_ASSIGN:
            case ast\AST_ASSIGN_REF:
            case ast\AST_ASSIGN_OP:  // XXX could figure out what kinds of assign ops are guaranteed to be non-null
            case ast\AST_METHOD_CALL:
            case ast\AST_NULLSAFE_METHOD_CALL:
            case ast\AST_STATIC_CALL:
                return true;
            case ast\AST_CONDITIONAL:
                return self::canNodeKindBeNull($node->children['right']);
            default:
                return false;
        }
    }

    public function visitConditional(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
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
            $this->code_base,
            $context,
            $node
        );

        $true_node = $node->children['true'] ?? null;
        $false_node = $node->children['false'] ?? null;

        $cond_node = $node->children['cond'];
        if ($cond_node instanceof Node) {
            // Step into each child node and get an
            // updated context for the node
            // (e.g. there may be assignments such as '($x = foo()) ? $a : $b)
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $cond_node);

            // TODO: Use different contexts and merge those, in case there were assignments or assignments by reference in both sides of the conditional?
            // Reuse the BranchScope (sort of unintuitive). The ConditionVisitor returns a clone and doesn't modify the original.
            $base_context = $context;
            $base_context_scope = $base_context->getScope();
            if ($base_context_scope instanceof GlobalScope) {
                $base_context = $context->withScope(new BranchScope($base_context_scope));
            }
            $true_context = (new ConditionVisitor(
                $this->code_base,
                isset($true_node) ? $base_context : $context  // special case: (($d = foo()) ?: 'fallback')
            ))->__invoke($cond_node);
            $false_context = (new NegatedConditionVisitor(
                $this->code_base,
                $base_context
            ))->__invoke($cond_node);
        } else {
            $true_context = $context;
            $false_context = $context;
            if (Config::getValue('redundant_condition_detection')) {
                (new ConditionVisitor($this->code_base, $context))->warnRedundantOrImpossibleScalar($cond_node);
            }
        }

        $child_context_list = [];
        // In the long form, there's a $true_node, but in the short form (?:),
        // $cond_node is the (already processed) value for truthy.
        if ($true_node instanceof Node) {
            $child_context = $this->analyzeAndGetUpdatedContext($true_context, $node, $true_node);
            $child_context_list[] = $child_context;
        }

        if ($false_node instanceof Node) {
            $child_context = $this->analyzeAndGetUpdatedContext($false_context, $node, $false_node);
            $child_context_list[] = $child_context;
        }
        if (count($child_context_list) >= 1) {
            if (count($child_context_list) < 2) {
                $child_context_list[] = $context;
            }
            $context = (new ContextMergeVisitor(
                $context,
                $child_context_list
            ))->combineChildContextList();
        }

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @see self::visitClosedContext()
     */
    public function visitClass(Node $node): Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @see self::visitClosedContext()
     */
    public function visitMethod(Node $node): Context
    {
        // Make a copy of the internal context so that we don't
        // leak any changes within the method to the
        // outer scope
        $context = $this->context;
        $context->setLineNumberStart($node->lineno);
        $context = $this->preOrderAnalyze($context, $node);

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (!($child_node instanceof Node)) {
                continue;
            }

            // Step into each child node and get an
            // updated context for the node
            $this->analyzeAndGetUpdatedContext($context, $node, $child_node);
        }

        $this->postOrderAnalyze($context, $node);

        // Return the initial context as we exit
        return $this->context;
    }

    /**
     * @param Node $node
     * An AST node of kind ast\AST_FUNC_DECL we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @see self::visitClosedContext()
     */
    public function visitFuncDecl(Node $node): Context
    {
        // Analyze nodes with AST_FUNC_DECL the same way as AST_METHOD
        return $this->visitMethod($node);
    }

    /**
     * @param Node $node
     * An AST node of kind ast\AST_CLOSURE we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @see self::visitClosedContext()
     */
    public function visitClosure(Node $node): Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * @param Node $node
     * An AST node of kind ast\AST_ARROW_FUNC we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after visiting the node
     *
     * @see self::visitClosedContext()
     */
    public function visitArrowFunc(Node $node): Context
    {
        return $this->visitClosedContext($node);
    }

    /**
     * Run the common steps for pre-order analysis phase of a Node.
     *
     * 1. Run the pre-order analysis steps, updating the context and scope
     * 2. Run plugins with pre-order steps, usually (but not always) updating the context and scope.
     *
     * @param Context $context - The context before pre-order analysis
     *
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after pre-order analysis of the node
     */
    private function preOrderAnalyze(Context $context, Node $node): Context
    {
        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        // Equivalent to (new PostOrderAnalysisVisitor(...)($node)) but faster than using __invoke()
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base,
            $context
        ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'handleMissingNodeKind'}($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );
        return $context;
    }

    /**
     * Common options for post-order analysis phase of a Node.
     *
     * 1. Run analysis steps and update the scope and context
     * 2. Run plugins (usually (but not always) without updating the scope)
     *
     * @param Context $context - The context before post-order analysis
     *
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     *
     * @return Context
     * The updated context after post-order analysis of the node
     */
    private function postOrderAnalyze(Context $context, Node $node): Context
    {
        // Now that we know all about our context (like what
        // 'self' means), we can analyze statements like
        // assignments and method calls.
        // Equivalent to (new PostOrderAnalysisVisitor(...)($node)) but faster than using __invoke()
        $context = (new PostOrderAnalysisVisitor(
            $this->code_base,
            $context->withLineNumberStart($node->lineno),
            $this->parent_node_list
        ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'handleMissingNodeKind'}($node);

        // let any configured plugins analyze the node
        ConfigPluginSet::instance()->postAnalyzeNode(
            $this->code_base,
            $context,
            $node,
            $this->parent_node_list
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
    public function visitGroupUse(Node $node): Context
    {
        $context = $this->context->withLineNumberStart(
            $node->lineno
        );

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node
        $context = (new PreOrderAnalysisVisitor(
            $this->code_base,
            $context
        ))->{Element::VISIT_LOOKUP_TABLE[$node->kind] ?? 'handleMissingNodeKind'}($node);

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        return $this->postOrderAnalyze($context, $node);
    }

    /**
     * @param Node $node
     * An AST node we'd like to analyze the statements for
     * @see self::visit() - This is similar to visit() except that the check if $is_static requires parent_node,
     * so PreOrderAnalysisVisitor can't be used to modify the Context.
     *
     * @return Context
     * The updated context after visiting the node
     */
    public function visitPropElem(Node $node): Context
    {
        $prop_name = (string)$node->children['name'];

        $context = $this->context;
        $class = $context->getClassInScope($this->code_base);

        $context = $this->context->withScope(new PropertyScope(
            $context->getScope(),
            FullyQualifiedPropertyName::make($class->getFQSEN(), $prop_name)
        ))->withLineNumberStart(
            $node->lineno
        );

        // Don't bother calling PreOrderAnalysisVisitor, it does nothing

        // Let any configured plugins do a pre-order
        // analysis of the node.
        ConfigPluginSet::instance()->preAnalyzeNode(
            $this->code_base,
            $context,
            $node
        );

        // With a context that is inside of the node passed
        // to this method, we analyze all children of the
        // node.
        $default = $node->children['default'] ?? null;
        if ($default instanceof Node) {
            // Step into each child node and get an
            // updated context for the node
            $context = $this->analyzeAndGetUpdatedContext($context, $node, $default);
        }

        return $this->postOrderAnalyze($context, $node);
    }
}
