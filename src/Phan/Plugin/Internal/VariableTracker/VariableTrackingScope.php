<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal\VariableTracker;

use ast\Node;

use function spl_object_id;

/**
 * This represents a variable scope, similar to \Phan\Language\Scope.
 * Instead of tracking the union types for variable names, this instead tracks definitions and uses of variable names.
 *
 * @see ContextMergeVisitor for something similar for union types.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
class VariableTrackingScope
{
    /**
     * @var array<string,associative-array<int,bool>>
     * Maps a variable id to a set of definitions ids in that scope.
     *
     * This is true if 100% of the definitions are made within the scope,
     * false if a fraction of the definitions could be from preceding scopes.
     *
     * TODO: Actually set the inner value to false appropriately.
     */
    protected $defs = [];

    /**
     * @var array<string,associative-array<int,true>>
     * Maps a variable id to a list of use ids which occurred within that scope.
     * (of definitions that might have occurred before this scope)
     */
    protected $uses = [];

    /**
     * @var array<string,true>
     * Maps variable names to whether they were redefined in the scope.
     */
    public $defs_shadowing_set = [];

    /**
     * Record that $variable_name had a definition that was created by the Node $node
     */
    public function recordDefinition(string $variable_name, Node $node): void
    {
        // Create a new definition for variable_name.
        // Replace the definitions for $variable_name.
        $this->defs[$variable_name] = [spl_object_id($node) => true];
        // TODO: handle merging branch scopes. If all branches (e.g. if/else, conditional) shadow the variable, then this scope shadows that variable.
        $this->defs_shadowing_set[$variable_name] = true;
    }

    /**
     * Record that $variable_name had a definition that was created by the Node $node where spl_object_id($node) is $node_id
     */
    public function recordDefinitionById(string $variable_name, int $node_id): void
    {
        // Create a new definition for variable_name.
        // Replace the definitions for $variable_name.
        $this->defs[$variable_name] = [$node_id => true];
    }

    /**
     * Record the fact that $node is a usage of $variable_name.
     *
     * If it is already a definition of $variable_name, then don't record that.
     *
     * @suppress PhanUnreferencedPublicMethod used by reference
     */
    public function recordUsage(string $variable_name, Node $node): void
    {
        $node_id = spl_object_id($node);
        // Create a new usage for variable_name.

        if (!isset($this->defs_shadowing_set[$variable_name]) &&
                ($this->defs[$variable_name][$node_id] ?? false) !== true) {
            $this->uses[$variable_name][$node_id] = true;
        }
    }

    /**
     * Record the fact that a node is a usage of $variable_name.
     *
     * Equivalent to $this->recordUsage($variable_name, spl_object_id($node))
     */
    public function recordUsageById(string $variable_name, int $node_id): void
    {
        // Create a new usage for variable_name.
        if (!isset($this->defs_shadowing_set[$variable_name]) &&
                ($this->defs[$variable_name][$node_id] ?? false) !== true) {
            $this->uses[$variable_name][$node_id] = true;
        }
    }

    /**
     * Gets the definitions of $variable_name in this scope.
     *
     * This is overridden by subclasses, some of which will modify $this->defs
     *
     * @return ?associative-array<int,true> the ids of Nodes which defined $variable_name
     */
    public function getDefinition(string $variable_name): ?array
    {
        return $this->defs[$variable_name] ?? null;
    }

    /**
     * Gets the definitions of $variable_name in this scope.
     *
     * This is overridden by subclasses
     *
     * @return ?associative-array<int,true> the ids of Nodes which defined $variable_name
     */
    public function getDefinitionUpToScope(string $variable_name, VariableTrackingScope $forbidden_scope): ?array
    {
        if ($this === $forbidden_scope) {
            return null;
        }
        return $this->defs[$variable_name] ?? null;
    }

    /**
     * Recursively finds the accessible definitions of various variable names
     *
     * @return array<string,associative-array<int,true>>
     */
    public function getDefinitionsRecursively(): array
    {
        return $this->defs;
    }

    /**
     * This creates a new scope where the definitions both inside and outside of the loop are accounted for.
     *
     * Additionally, it will mark references to variables at the beginning of the inner body of the loop as being uses of variables defined at the end of the loop.
     * @return static
     */
    public function mergeInnerLoopScope(
        VariableTrackingLoopScope $inner_loop_scope,
        VariableGraph $graph
    ): VariableTrackingScope {
        $result = clone($this);
        // TODO: Can this be optimized for common use cases?
        // TODO: Track continue and break separately - May require a more complicated graph
        foreach ($inner_loop_scope->skipped_loop_scopes as $alternate_scope) {
            $this->flattenScopeToMergedLoopResult($inner_loop_scope, $alternate_scope, $graph);
        }
        foreach ($inner_loop_scope->skipped_exiting_loop_scopes as $alternate_scope) {
            $this->flattenUsesFromScopeToMergedLoopResult($inner_loop_scope, $alternate_scope, $graph);
        }
        self::addScopeToMergedLoopResult($result, $inner_loop_scope, $graph);
        return $result;
    }

    protected function flattenScopeToMergedLoopResult(
        VariableTrackingLoopScope $inner_loop_scope,
        VariableTrackingBranchScope $alternate_scope,
        VariableGraph $graph
    ): void {
        // Need to flatten these to the same level
        // The LoopScope might have been cloned, so just keep going until the closest loop scope.
        // TODO: Look at this, see if this way of merging definitions and usages will miss any false positives
        $parent_scope = $alternate_scope->parent_scope;
        if (!($parent_scope instanceof VariableTrackingLoopScope)) {
            '@phan-var VariableTrackingBranchScope $parent_scope';
            $this->flattenScopeToMergedLoopResult($inner_loop_scope, $parent_scope, $graph);
        }
        self::addScopeToMergedLoopResult($inner_loop_scope, $alternate_scope, $graph);
        $inner_loop_scope->mergeUses($alternate_scope->uses);
    }

    protected function flattenUsesFromScopeToMergedLoopResult(
        VariableTrackingLoopScope $inner_loop_scope,
        VariableTrackingBranchScope $alternate_scope,
        VariableGraph $graph
    ): void {
        // Need to flatten these to the same level
        // The LoopScope might have been cloned, so just keep going until the closest loop scope.
        // TODO: Look at this, see if this way of merging definitions and usages will miss any false positives
        $parent_scope = $alternate_scope->parent_scope;
        if (!($parent_scope instanceof VariableTrackingLoopScope)) {
            '@phan-var VariableTrackingBranchScope $parent_scope';
            $this->flattenScopeToMergedLoopResult($inner_loop_scope, $parent_scope, $graph);
        }
        $inner_loop_scope->mergeUses($alternate_scope->uses);
    }

    private static function addScopeToMergedLoopResult(
        VariableTrackingScope $result,
        VariableTrackingBranchScope $scope,
        VariableGraph $graph
    ): void {
        // @phan-suppress-next-line PhanUndeclaredProperty
        $parent_scope = $result->parent_scope ?? $result;
        foreach ($scope->getDefinitionsRecursively() as $variable_name => $defs) {
            $defs_for_variable = $result->getDefinitionUpToScope($variable_name, $parent_scope) ?? [];
            $loop_uses_of_own_variable = $scope->uses[$variable_name] ?? null;

            foreach ($defs as $def_id => $_) {
                if ($loop_uses_of_own_variable) {
                    $graph->recordLoopSelfUsage($variable_name, $def_id, $loop_uses_of_own_variable);
                }
                $defs_for_variable[$def_id] = true;
            }
            $result->defs[$variable_name] = $defs_for_variable;
        }
        $result->mergeUses($scope->uses);
    }

    /**
     * @param array<string,associative-array<int,bool>> $uses
     */
    private function mergeUses(array $uses): void
    {
        foreach ($uses as $variable_name => $def_id_set) {
            if (!isset($this->uses[$variable_name])) {
                $this->uses[$variable_name] = $def_id_set;
                continue;
            }
            $this->uses[$variable_name] += $def_id_set;
        }
    }

    /**
     * Equivalent to mergeBranchScopeList([$scope], true, [])
     *
     * @param VariableTrackingBranchScope $scope
     * @return static
     */
    public function mergeWithSingleBranchScope(
        VariableTrackingBranchScope $scope
    ): VariableTrackingScope {
        $result = clone($this);
        $def_key_set = $scope->defs;
        // Anything which is used within a branch is used within the parent
        $result->mergeUses($scope->uses);

        foreach ($def_key_set as $variable_name => $_) {
            $defs_for_variable = $result->getDefinition($variable_name) ?? [];

            foreach ($scope->getDefinition($variable_name) ?? [] as $def_id => $_) {
                $defs_for_variable[$def_id] = true;
            }
            $result->defs[$variable_name] = $defs_for_variable;
        }
        return $result;
    }

    /**
     * @param list<VariableTrackingBranchScope> $branch_scopes
     * @param list<VariableTrackingBranchScope> $inner_exiting_scope_list
     */
    public function mergeBranchScopeList(
        array $branch_scopes,
        bool $merge_parent_scope,
        array $inner_exiting_scope_list
    ): VariableTrackingScope {
        // Compute the keys which were redefined in branch scopes
        // TODO: Optimize
        $result = clone($this);
        $def_key_set = [];
        foreach ($branch_scopes as $scope) {
            foreach ($scope->defs as $variable_name => $_) {
                $def_key_set[$variable_name] = true;
            }
            // Anything which is used within a branch is used within the parent
            $result->mergeUses($scope->uses);
        }
        foreach ($inner_exiting_scope_list as $scope) {
            // TODO: Make this properly recurse until it reaches the right depth (unnecessary right now)
            $result->mergeUses($scope->uses);
        }

        foreach ($def_key_set as $variable_name => $_) {
            if ($merge_parent_scope) {
                $defs_for_variable = $result->getDefinition($variable_name) ?? [];
            } else {
                $defs_for_variable = [];
            }

            foreach ($branch_scopes as $scope) {
                foreach ($scope->getDefinition($variable_name) ?? [] as $def_id => $_) {
                    $defs_for_variable[$def_id] = true;
                }
            }
            $result->defs[$variable_name] = $defs_for_variable;
        }
        if (!$merge_parent_scope && \count($inner_exiting_scope_list) === 0) {
            $result->defs_shadowing_set += self::computeCommonDefsShadowingSet($branch_scopes);
        }
        return $result;
    }

    /**
     * @param list<VariableTrackingScope> $branch_scopes
     * @return array<string,true>
     */
    private static function computeCommonDefsShadowingSet(array $branch_scopes): array
    {
        $result = null;
        foreach ($branch_scopes as $scope) {
            $deps = $scope->defs_shadowing_set;
            if (!\is_array($result)) {
                $result = $deps;
            } else {
                $result = \array_intersect_key($result, $deps);
            }
            if (!$result) {
                return [];
            }
        }
        return $result ?? [];
    }

    /**
     * Record a statement that was unreachable due to break/continue statements.
     *
     * @param VariableTrackingBranchScope $inner_scope @phan-unused-param
     * @param bool $exits true if the branch of $inner_scope will exit. @phan-unused-param
     *             This would mean that the branch uses variables, but does not define them outside of that scope.
     */
    public function recordSkippedScope(VariableTrackingBranchScope $inner_scope, bool $exits): void
    {
        // Subclasses will implement this
    }
}
