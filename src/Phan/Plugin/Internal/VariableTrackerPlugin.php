<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal;

use AssertionError;
use ast;
use ast\Node;
use Exception;
use Phan\AST\ArrowFunc;
use Phan\AST\ASTReverter;
use Phan\AST\UnionTypeVisitor;
use Phan\Config;
use Phan\Exception\CodeBaseException;
use Phan\Issue;
use Phan\IssueFixSuggester;
use Phan\Language\Element\Method;
use Phan\Language\Element\Variable;
use Phan\Plugin\Internal\VariableTracker\VariableGraph;
use Phan\Plugin\Internal\VariableTracker\VariableTrackerVisitor;
use Phan\Plugin\Internal\VariableTracker\VariableTrackingScope;
use Phan\PluginV3;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;
use Phan\Suggestion;

use function array_key_exists;
use function count;
use function is_string;
use function strlen;

/**
 * NOTE: This is automatically loaded by phan based on config settings.
 * Do not include it in the 'plugins' config.
 */
final class VariableTrackerPlugin extends PluginV3 implements
    PostAnalyzeNodeCapability
{

    /**
     * @return string the name of a visitor
     */
    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        return VariableTrackerElementVisitor::class;
    }
}

/**
 * This will analyze any variable definition and uses within function-like scopes,
 * and warn about unused variable definitions.
 *
 * TODO: Hook into the global scope as well?
 */
final class VariableTrackerElementVisitor extends PluginAwarePostAnalysisVisitor
{
    public function visitMethod(Node $node): void
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @override
     */
    public function visitFuncDecl(Node $node): void
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @override
     */
    public function visitClosure(Node $node): void
    {
        $this->analyzeMethodLike($node);
    }

    /**
     * @override
     */
    public function visitArrowFunc(Node $node): void
    {
        $this->analyzeMethodLike($node);
    }

    private function analyzeMethodLike(Node $node): void
    {
        // \Phan\Debug::printNode($node);
        $stmts_node = $node->children['stmts'] ?? null;
        if (!($stmts_node instanceof Node)) {
            return;
        }
        $variable_graph = new VariableGraph();
        $scope = new VariableTrackingScope();
        $issue_categories = self::addParametersAndUseVariablesToGraph($node, $variable_graph, $scope);

        try {
            VariableTrackerVisitor::$variable_graph = $variable_graph;
            $variable_tracker_visitor = new VariableTrackerVisitor($this->code_base, $this->context, $scope);
            $variable_tracker_visitor->__invoke($stmts_node);

            $this->checkSideEffectFreeLoopNodes($variable_graph, $variable_tracker_visitor);
            $this->checkPossiblyInfiniteLoopNodes($variable_graph, $variable_tracker_visitor);
        } finally {
            // @phan-suppress-next-line PhanTypeMismatchPropertyProbablyReal
            VariableTrackerVisitor::$variable_graph = null;
        }
        $this->warnAboutVariableGraph($node, $variable_graph, $issue_categories);
    }

    private function checkSideEffectFreeLoopNodes(
        VariableGraph $variable_graph,
        VariableTrackerVisitor $variable_tracker_visitor
    ): void {
        $loop_nodes = $variable_tracker_visitor->getSideEffectFreeLoopNodes();
        if (!$loop_nodes) {
            // Nothing to do
            return;
        }
        $combined_def_uses = $variable_graph->computeCombinedDefUses();
        foreach ($loop_nodes as $loop_node) {
            if (!self::definitionInsideNodeHasUseOutsideNode($combined_def_uses, $loop_node)) {
                $this->emitIssue(
                    self::SIDE_EFFECT_FREE_LOOP_ISSUE[$loop_node->kind] ?? Issue::SideEffectFreeForBody,
                    $loop_node->lineno
                );
            }
        }
    }

    private function checkPossiblyInfiniteLoopNodes(
        VariableGraph $variable_graph,
        VariableTrackerVisitor $variable_tracker_visitor
    ): void {
        $loop_nodes = $variable_tracker_visitor->getPossiblyInfiniteLoopNodes();
        if (!$loop_nodes) {
            // Nothing to do
            return;
        }
        $combined_use_defs = $variable_graph->computeCombinedUseDefs();
        foreach ($loop_nodes as $loop_node) {
            // Check if any variables read by the loop condition were set within the statements.
            $cond = $loop_node->children['cond'];
            if ($cond instanceof Node && $cond->kind === ast\AST_EXPR_LIST) {
                $cond = \end($cond->children);
            }
            if ($cond instanceof Node) {
                if (UnionTypeVisitor::checkCondUnconditionalTruthiness($cond) !== null) {
                    // e.g. false, null
                    continue;
                }
                $id_set_in_loop = self::extractNodeIdSet($cond);
                if ($id_set_in_loop) {
                    foreach ($id_set_in_loop as $id => $_) {
                        if (isset($combined_use_defs[$id][VariableGraph::USE_ID_FOR_SHARED_STATE])) {
                            continue 2;
                        }
                    }
                    $stmts_node = $loop_node->children['stmts'];
                    $id_set_of_stmts = $stmts_node instanceof Node ? self::extractNodeIdSet($stmts_node) : [];
                    if (isset($loop_node->children['loop'])) {
                        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                        $id_set_of_stmts += self::extractNodeIdSet($loop_node->children['loop']);
                    }
                    if ($id_set_of_stmts) {
                        foreach ($id_set_in_loop as $id => $_) {
                            if (!isset($combined_use_defs[$id])) {
                                continue;
                            }
                            if (\array_intersect_key($combined_use_defs[$id], $id_set_of_stmts)) {
                                continue 2;
                            }
                        }
                    }
                }
            } elseif (!$cond) {  // @phan-suppress-current-line PhanSuspiciousTruthyString
                continue;
            }

            $this->emitIssue(
                Issue::PossiblyInfiniteLoop,
                $loop_node->lineno,
                ASTReverter::toShortString($cond)
            );
        }
    }

    private const SIDE_EFFECT_FREE_LOOP_ISSUE = [
        ast\AST_FOR      => Issue::SideEffectFreeForBody,
        ast\AST_FOREACH  => Issue::SideEffectFreeForeachBody,
        ast\AST_WHILE    => Issue::SideEffectFreeWhileBody,
        ast\AST_DO_WHILE => Issue::SideEffectFreeDoWhileBody,
    ];

    /**
     * Returns true if at least one of the variable declarations inside the loop body has a use outside of the loop body
     *
     * @param associative-array<int, associative-array<int, true>> $combined_def_uses
     */
    private static function definitionInsideNodeHasUseOutsideNode(array $combined_def_uses, Node $loop_node): bool
    {
        if (!$combined_def_uses) {
            return false;
        }
        $id_set_in_loop = self::extractNodeIdSet($loop_node);
        foreach ($id_set_in_loop as $possible_def_id) {
            if (array_key_exists($possible_def_id, $combined_def_uses)) {
                foreach ($combined_def_uses[$possible_def_id] as $use_id => $_) {
                    if (!array_key_exists($use_id, $id_set_in_loop)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * @return associative-array<int, int> the node ids that occurred within the loop node, mapped to themselves
     */
    private static function extractNodeIdSet(Node $loop_node): array
    {
        $id_set = [];
        self::extractNodeIdSetInner($loop_node, $id_set);
        return $id_set;
    }

    /**
     * @param Node|string|int|float|null $node
     * @param associative-array<int, int> $id_set
     */
    private static function extractNodeIdSetInner($node, array &$id_set): void
    {
        if (!$node instanceof Node) {
            return;
        }
        $id = \spl_object_id($node);
        $id_set[$id] = $id;
        switch ($node->kind) {
            case ast\AST_CLASS:
            case ast\AST_FUNC_DECL:
            case ast\AST_ARROW_FUNC:
                return;
            case ast\AST_CLOSURE:
                self::extractNodeIdSetInner($node->children['uses'], $id_set);
                return;
            default:
                foreach ($node->children as $child_node) {
                    self::extractNodeIdSetInner($child_node, $id_set);
                }
                return;
        }
    }

    private const PARAM_MODIFIER_FLAG_SET =
        ast\flags\PARAM_MODIFIER_PUBLIC |
        ast\flags\PARAM_MODIFIER_PROTECTED |
        ast\flags\PARAM_MODIFIER_PRIVATE;

    /**
     * @return array<int, string> maps unique definition ids to issue types
     */
    private static function addParametersAndUseVariablesToGraph(
        Node $node,
        VariableGraph $graph,
        VariableTrackingScope $scope
    ): array {
        $result = [];
        // AST_PARAM_LIST of AST_PARAM
        foreach ($node->children['params']->children ?? [] as $parameter) {
            if (!($parameter instanceof Node)) {
                throw new AssertionError("Expected params to be Nodes");
            }
            $parameter_name = $parameter->children['name'];
            if (!is_string($parameter_name)) {
                continue;
            }
            // We narrow this down to the specific category if we need to warn.
            $result[\spl_object_id($parameter)] = Issue::UnusedPublicMethodParameter;

            $graph->recordVariableDefinition($parameter_name, $parameter, $scope, null);
            if ($parameter->flags & ast\flags\PARAM_REF) {
                $graph->markAsReference($parameter_name);
            }
            if ($parameter->flags & self::PARAM_MODIFIER_FLAG_SET) {
                // Workaround to stop warning about `__construct(public string $flags)`.
                // Deliberately use a different arbitrary node.
                $graph->recordVariableUsage($parameter_name, $node, $scope);
            }
        }
        foreach ($node->children['uses']->children ?? [] as $closure_use) {
            if (!($closure_use instanceof Node)) {
                throw new AssertionError("Expected uses to be nodes");
            }
            $name = $closure_use->children['name'];
            if (!is_string($name)) {
                continue;
            }
            $result[\spl_object_id($closure_use)] = Issue::UnusedClosureUseVariable;

            $graph->recordVariableDefinition($name, $closure_use, $scope, null);
            if ($closure_use->flags & ast\flags\CLOSURE_USE_REF) {
                $graph->markAsReference($name);
            }
        }
        if ($node->kind === ast\AST_ARROW_FUNC) {
            foreach (ArrowFunc::getUses($node) as $name => $_) {
                // @phan-suppress-next-line PhanUndeclaredProperty
                if (isset($node->phan_arrow_inherited_vars[$name])) {
                    $result[\spl_object_id($node)] = Issue::ShadowedVariableInArrowFunc;

                    // $node is recorded as the definition so that warnings go on the correct line.
                    $graph->recordVariableDefinition((string)$name, $node, $scope, null);
                }
            }
        }
        return $result;
    }

    private function methodHasOverrides(): bool
    {
        if (Config::getValue('unused_variable_detection_assume_override_exists')) {
            return true;
        }
        try {
            $method = $this->context->getFunctionLikeInScope($this->code_base);
            if (!($method instanceof Method)) {
                // should never happen
                return false;
            }

            return $method->isOverride() || $method->isOverriddenByAnother();
        } catch (Exception $_) {
            // should not happen
            return false;
        }
    }
    private function getParameterCategory(Node $method_node): string
    {
        $kind = $method_node->kind;
        if ($kind === ast\AST_CLOSURE) {
            return Issue::UnusedClosureParameter;
        } elseif ($kind === ast\AST_FUNC_DECL) {
            return Issue::UnusedGlobalFunctionParameter;
        }

        $flags = $method_node->flags;
        $final = $this->isParameterFinal($flags);

        if ($flags & ast\flags\MODIFIER_PRIVATE) {
            return $final ? Issue::UnusedPrivateFinalMethodParameter : Issue::UnusedPrivateMethodParameter;
        } elseif ($flags & ast\flags\MODIFIER_PROTECTED) {
            if ($final) {
                return Issue::UnusedProtectedFinalMethodParameter;
            }
            if (!$this->methodHasOverrides()) {
                return Issue::UnusedProtectedNoOverrideMethodParameter;
            }
            return Issue::UnusedProtectedMethodParameter;
        }
        if ($final) {
            return Issue::UnusedPublicFinalMethodParameter;
        }
        if (!$this->methodHasOverrides()) {
            return Issue::UnusedPublicNoOverrideMethodParameter;
        }
        return Issue::UnusedPublicMethodParameter;
    }

    private function isParameterFinal(int $flags): bool
    {
        if (($flags & ast\flags\MODIFIER_FINAL) !== 0) {
            return true;
        }
        $context = $this->context;
        if ($context->isInClassScope()) {
            try {
                $class = $context->getClassInScope($this->code_base);
                return $class->isFinal();
            } catch (CodeBaseException $_) {
            }
        }
        return false;
    }

    /**
     * @param list<string> $issue_overrides_for_definition_ids maps definition ids to issue types
     */
    private function warnAboutVariableGraph(
        Node $method_node,
        VariableGraph $graph,
        array $issue_overrides_for_definition_ids
    ): void {
        foreach ($graph->def_uses as $variable_name => $def_uses_for_variable) {
            if ($variable_name === 'this') {
                continue;
            }
            if (\preg_match('/^(_$|(unused|raii))/iD', $variable_name) > 0) {
                // Skip over $_, $unused*, and $raii*
                continue;
            }
            if (Variable::isSuperglobalVariableWithName($variable_name)) {
                continue;
            }
            $type_bitmask = $graph->variable_types[$variable_name] ?? 0;
            if ($type_bitmask & VariableGraph::IS_REFERENCE_OR_GLOBAL_OR_STATIC) {
                // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
                if (count($def_uses_for_variable) === 1 && count(\reset($def_uses_for_variable)) === 0) {
                    $this->checkSingleDefinitionReferenceOrGlobalOrStatic($graph, $variable_name, $issue_overrides_for_definition_ids);
                }
                // don't warn about static/global/references
                continue;
            }
            // Check for variable definitions that are unused
            foreach ($def_uses_for_variable as $definition_id => $use_list) {
                if (count($use_list) > 0) {
                    // Don't warn if there's at least one usage of that definition
                    continue;
                }
                if (($graph->def_bitset[$definition_id] ?? 0) & (VariableGraph::IS_UNSET | VariableGraph::IS_DISABLED_WARNINGS)) {
                    // Don't warn about unset($x)
                    continue;
                }
                $line = $graph->def_lines[$variable_name][$definition_id] ?? 1;
                $issue_type = $issue_overrides_for_definition_ids[$definition_id] ?? Issue::UnusedVariable;
                // Choose a more precise issue type
                if ($issue_type === Issue::UnusedPublicMethodParameter) {
                    // Narrow down issues about parameters into more specific issues
                    $doc_comment = $method_node->children['docComment'] ?? null;
                    if (is_string($doc_comment) && \preg_match('/@param\b[^$\n]*\$' . \preg_quote($variable_name) . '\b.*@(phan-)?unused-param\b|@(phan-)?unused-param\b[^$\n]*\$' . \preg_quote($variable_name) . '\b/', $doc_comment)) {
                        // Don't warn about parameters marked with @phan-unused-param or @unused-param
                        continue;
                    }
                    $issue_type = $this->getParameterCategory($method_node);
                    if (\strpos($issue_type, 'NoOverride') === false && \strpos($issue_type, 'MethodParameter') !== false) {
                        $alternate_issue_type = \str_replace('MethodParameter', 'NoOverrideMethodParameter', $issue_type);
                        if (Issue::shouldSuppressIssue($this->code_base, $this->context, $alternate_issue_type, $line, [$variable_name], null)) {
                            continue;
                        }
                    }
                } elseif ($graph->isLoopValueDefinitionId($definition_id)) {
                    $issue_type = Issue::UnusedVariableValueOfForeachWithKey;
                } elseif ($graph->isCaughtException($definition_id)) {
                    $issue_type = Issue::UnusedVariableCaughtException;
                } elseif (isset($graph->def_overwritten_multiple_branches[$variable_name][$definition_id])) {
                    $issue_type = Issue::UnusedVariableOverwrittenAllBranches;
                }
                Issue::maybeEmitWithParameters(
                    $this->code_base,
                    $this->context,
                    $issue_type,
                    $line,
                    [$variable_name],
                    // compute the suggestion for $variable_name based on the $issue_type
                    self::makeSuggestion($graph, $variable_name, $issue_type)
                );
            }
            // Check for variables that could be replaced with constants or literals
            if (Config::getValue('constant_variable_detection') && count($def_uses_for_variable) === 1) {
                foreach ($def_uses_for_variable as $definition_id => $use_list) {
                    if (!$use_list) {
                        // We already warned that this was unused
                        continue;
                    }
                    $value_node = $graph->const_expr_declarations[$variable_name][$definition_id] ?? null;
                    if ($value_node === null) {
                        continue;
                    }
                    if (isset($graph->const_expr_declarations[$variable_name][-1])) {
                        // Set by recordVariableModification
                        continue;
                    }
                    $this->warnAboutCouldBeConstant($graph, $variable_name, $definition_id, $value_node);
                }
            }
        }
    }

    /**
     * @param list<string> $issue_overrides_for_definition_ids maps definition ids to issue types
     */
    private function checkSingleDefinitionReferenceOrGlobalOrStatic(
        VariableGraph $graph,
        string $variable_name,
        array $issue_overrides_for_definition_ids
    ): void {
        $uses = $graph->def_uses[$variable_name];
        \reset($uses);
        $definition_id = \key($uses);
        $issue_type = $issue_overrides_for_definition_ids[$definition_id] ?? Issue::UnusedVariable;
        if ($issue_type === Issue::UnusedPublicMethodParameter) {
            return;
        }
        $type_bitmask = $graph->variable_types[$variable_name] ?? 0;
        $line = $graph->def_lines[$variable_name][$definition_id] ?? 1;
        $def_bitmask = $graph->def_bitset[$definition_id] ?? 0;
        if ($def_bitmask & VariableGraph::IS_DISABLED_WARNINGS) {
            return;
        }
        if ($type_bitmask === VariableGraph::IS_REFERENCE) {
            Issue::maybeEmitWithParameters(
                $this->code_base,
                $this->context,
                Issue::UnusedVariableReference,
                $line,
                [$variable_name]
            );
        } elseif ($type_bitmask === VariableGraph::IS_STATIC) {
            Issue::maybeEmitWithParameters(
                $this->code_base,
                $this->context,
                Issue::UnusedVariableStatic,
                $line,
                [$variable_name]
            );
        } elseif ($type_bitmask === VariableGraph::IS_GLOBAL) {
            if (\is_int($definition_id) && $graph->isGlobal($definition_id)) {
                Issue::maybeEmitWithParameters(
                    $this->code_base,
                    $this->context,
                    Issue::UnusedVariableGlobal,
                    $line,
                    [$variable_name]
                );
            }
        }
    }

    /**
     * @param Node|string|int|float $value_node
     */
    private function warnAboutCouldBeConstant(VariableGraph $graph, string $variable_name, int $definition_id, $value_node): void
    {
        $issue_type = Issue::VariableDefinitionCouldBeConstant;
        if ($value_node instanceof Node) {
            if ($value_node->kind === ast\AST_ARRAY) {
                if (count($value_node->children) === 0) {
                    $issue_type = Issue::VariableDefinitionCouldBeConstantEmptyArray;
                }
            } elseif ($value_node->kind === ast\AST_CONST) {
                $name = \strtolower((string)($value_node->children['name']->children['name'] ?? ''));
                switch ($name) {
                    case 'false':
                        $issue_type = Issue::VariableDefinitionCouldBeConstantFalse;
                        break;
                    case 'true':
                        $issue_type = Issue::VariableDefinitionCouldBeConstantTrue;
                        break;
                    case 'null':
                        $issue_type = Issue::VariableDefinitionCouldBeConstantNull;
                        break;
                }
            }
        } elseif (is_string($value_node)) {
            $issue_type = Issue::VariableDefinitionCouldBeConstantString;
        } elseif (\is_int($value_node)) {
            $issue_type = Issue::VariableDefinitionCouldBeConstantInt;
        } elseif (\is_float($value_node)) {
            $issue_type = Issue::VariableDefinitionCouldBeConstantFloat;
        }
        $line = $graph->def_lines[$variable_name][$definition_id] ?? 1;
        Issue::maybeEmitWithParameters(
            $this->code_base,
            $this->context,
            $issue_type,
            $line,
            [$variable_name]
        );
    }

    private static function makeSuggestion(VariableGraph $graph, string $variable_name, string $issue_type): ?Suggestion
    {
        if ($issue_type !== Issue::UnusedVariable) {
            return null;
        }
        if (strlen($variable_name) <= 1) {
            // No point in guessing short variable names
            return null;
        }
        if (count($graph->def_uses[$variable_name] ?? []) > 1) {
            // We've defined this variable in more than one place, assume it's not a typo
            return null;
        }
        // Take all of the variables that were used anywhere else
        // (don't account for reachability)
        $variable_set = $graph->variable_types;
        // Suggest any variables with a similar name (excluding $variable_name) that were used in this class scope
        // It's possible that the usage just hasn't been typed out yet
        unset($variable_set[$variable_name]);
        if (count($variable_set) > Config::getValue('suggestion_check_limit')) {
            return null;
        }
        $suggestion_set = IssueFixSuggester::getSuggestionsForStringSet($variable_name, $variable_set);
        if (count($suggestion_set) === 0) {
            return null;
        }

        $suggestions = [];
        foreach ($suggestion_set as $suggested_variable_name => $_) {
            $suggestions[] = '$' . $suggested_variable_name;
        }
        \sort($suggestions);

        return Suggestion::fromString('Did you mean ' . \implode(' or ', $suggestions));
    }
}
