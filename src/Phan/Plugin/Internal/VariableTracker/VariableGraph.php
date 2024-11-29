<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal\VariableTracker;

use ast\Node;

use function spl_object_id;

/**
 * This represents a summary of all of the definitions and uses of all variable within a scope.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
final class VariableGraph
{
    /**
     * @var array<string,associative-array<int,associative-array<int,true>>>
     *
     * Maps variable name to (definition id to (set of use ids of that given definition))
     */
    public $def_uses = [];

    /**
     * @var array<string,array<int,true>>
     *
     * Maps variable name to (definition id to true). This tracks definitions that were overwritten in each of multiple branches.
     */
    public $def_overwritten_multiple_branches = [];

    /**
     * @var array<string,associative-array<int,int>>
     *
     * Maps variable name to a map from definition id to line number of the node.
     */
    public $def_lines = [];

    /**
     * @var array<string,associative-array<int,Node|int|float|string>>
     *
     * Maps variable name to a set of definition ids and their corresponding constant AST nodes
     */
    public $const_expr_declarations = [];

    /**
     * @var associative-array<int,int>
     *
     * Maps definition ids to information about them (e.g. IS_GLOBAL|IS_STATIC|IS_LOOP_DEF|IS_CAUGHT_EXCEPTION)
     */
    public $def_bitset = [];

    /**
     * @var array<string,int> maps variable names to whether
     *    they have ever occurred as a given self::IS_* category in the current scope
     */
    public $variable_types = [];

    // Are these names/definition ids references, globals, or static
    public const IS_REFERENCE      = 1 << 0;
    public const IS_GLOBAL         = 1 << 1;
    public const IS_STATIC         = 1 << 2;
    // Is this possibly a placeholder loop variable
    public const IS_LOOP_DEF       = 1 << 3;
    // Is this a caught exception
    public const IS_CAUGHT_EXCEPTION = 1 << 4;
    public const IS_UNSET          = 1 << 5;
    public const IS_DISABLED_WARNINGS = 1 << 6;

    public const IS_REFERENCE_OR_GLOBAL_OR_STATIC = self::IS_REFERENCE | self::IS_GLOBAL | self::IS_STATIC;

    public const USE_ID_FOR_SHARED_STATE = -1;

    public function __construct()
    {
    }

    /**
     * Record the fact that $node is a definition of the variable with name $name in the scope $scope
     * @param ?(Node|string|int|float) $const_expr is the definition's value a value that could be a constant?
     */
    public function recordVariableDefinition(string $name, Node $node, VariableTrackingScope $scope, $const_expr): void
    {
        // TODO: Measure performance against SplObjectHash
        $id = \spl_object_id($node);
        if (!isset($this->def_uses[$name][$id])) {
            $this->def_uses[$name][$id] = [];
        }
        $this->def_lines[$name][$id] = $node->lineno;
        if ($const_expr !== null) {
            $this->const_expr_declarations[$name][$id] = $const_expr;
        }
        $scope->recordDefinitionById($name, $id);
    }

    /**
     * Records that the variable with name $name was used by Node $node in the given scope.
     *
     * This marks the definitions that are accessible from this scope as being used at $node.
     */
    public function recordVariableUsage(string $name, Node $node, VariableTrackingScope $scope): void
    {
        if (!\array_key_exists($name, $this->variable_types)) {
            // Set this to 0 to record that the variable was used somewhere
            // (it will be overridden later if there are flags to set)
            $this->variable_types[$name] = 0;
        }
        // @phan-suppress-next-line PhanUndeclaredProperty added by ArgumentType analyzer
        if (isset($node->is_reference)) {
            $this->markAsReference($name);
        }
        $node_id = \spl_object_id($node);
        $scope->recordUsageById($name, $node_id);
        $defs_for_variable = $scope->getDefinition($name);
        if (!$defs_for_variable) {
            return;
        }
        foreach ($defs_for_variable as $def_id => $_) {
            if ($def_id !== $node_id) {
                $this->def_uses[$name][$def_id][$node_id] = true;
            }
        }
    }

    /**
     * Record that $name was modified in place
     */
    public function recordVariableModification(string $name): void
    {
        $this->const_expr_declarations[$name][-1] = 0;
    }

    /**
     * Record that $name with previous definitions $def_ids has been overwritten across across all branches
     * @param associative-array<mixed> $def_ids
     */
    public function recordVariableOverwriteOnBranches(string $name, array $def_ids): void
    {
        foreach ($def_ids as $def_id => $_) {
            $this->def_overwritten_multiple_branches[$name][$def_id] = true;
        }
    }

    /**
     * @param associative-array<int,mixed> $loop_uses_of_own_variable any array that has node ids for uses of $def_id as keys
     */
    public function recordLoopSelfUsage(string $name, int $def_id, array $loop_uses_of_own_variable): void
    {
        foreach ($loop_uses_of_own_variable as $node_id => $_) {
            // For expressions such as `;$var++;` or `$var += 1;`, don't count the modifying declaration in a loop as a usage - it's unused if nothing else uses that.
            if ($def_id !== $node_id) {
                $this->def_uses[$name][$def_id][$node_id] = true;
            }
        }
    }

    /**
     * Records that the variable with the name $name was used as a reference
     * somewhere within the function body
     */
    public function markAsReference(string $name): void
    {
        $this->markBitForVariableName($name, self::IS_REFERENCE);
    }

    /**
     * Records that the variable with the name $name was declared as a static variable
     * somewhere within the function body
     */
    public function markAsStaticVariable(string $name): void
    {
        $this->markBitForVariableName($name, self::IS_STATIC);
    }

    /**
     * Records that the variable with the name $name was declared as a global variable
     * somewhere within the function body
     */
    public function markAsGlobalVariable(string $name): void
    {
        $this->markBitForVariableName($name, self::IS_GLOBAL);
    }

    /**
     * Marks something as being a loop variable `$v` in `foreach ($arr as $k => $v)`
     * (Common false positive, since there's no way to avoid setting the value)
     *
     * @param Node|string|int|float|null $node
     */
    public function markAsLoopValueNode($node): void
    {
        if ($node instanceof Node) {
            $this->def_bitset[spl_object_id($node)] = self::IS_LOOP_DEF;
        }
    }

    /**
     * Marks something as being an unset variable `$v` in `unset($v)`
     *
     * @param Node|string|int|float|null $node
     */
    public function markAsUnset($node): void
    {
        if ($node instanceof Node) {
            $this->def_bitset[spl_object_id($node)] = self::IS_UNSET;
        }
    }

    /**
     * Indicates that warnings about unused definitions should be disabled
     *
     * @param Node|string|int|float|null $node
     */
    public function markAsDisabledWarnings($node): void
    {
        if ($node instanceof Node) {
            $this->def_bitset[spl_object_id($node)] = self::IS_DISABLED_WARNINGS;
        }
    }

    /**
     * Checks if the node for this id is defined as the value in a foreach over keys of an array.
     */
    public function isLoopValueDefinitionId(int $definition_id): bool
    {
        return ($this->def_bitset[$definition_id] ?? 0) === self::IS_LOOP_DEF;
    }

    /**
     * Marks something as being a caught exception `$e` in `catch ($e)`
     * (Common false positive, since there's no way to avoid setting the value)
     *
     * @param Node|int|string|float|null $node
     */
    public function markAsCaughtException($node): void
    {
        if ($node instanceof Node) {
            $this->def_bitset[spl_object_id($node)] = self::IS_CAUGHT_EXCEPTION;
        }
    }

    /**
     * Checks if the node for this id is defined as a caught exception
     */
    public function isCaughtException(int $definition_id): bool
    {
        return (($this->def_bitset[$definition_id] ?? 0) & self::IS_CAUGHT_EXCEPTION) !== 0;
    }

    /**
     * Marks something as being a declaration of a global
     */
    public function markAsGlobal(Node $node, VariableTrackingScope $scope): void
    {
        $this->def_bitset[spl_object_id($node)] = self::IS_GLOBAL;
        $name = $node->children['var']->children['name'] ?? null;
        if (\is_string($name)) {
            $this->markAsGlobalVariable($name);
            $this->recordVariableDefinition($name, $node, $scope, null);
        }
    }

    /**
     * Is this definition_id the first declarration of a global?
     */
    public function isGlobal(int $definition_id): bool
    {
        return (($this->def_bitset[$definition_id] ?? 0) & self::IS_GLOBAL) !== 0;
    }

    private function markBitForVariableName(string $name, int $bit): void
    {
        $this->variable_types[$name] = (($this->variable_types[$name] ?? 0) | $bit);
    }

    /**
     * @return associative-array<int,associative-array<int,true>>
     * Returns the combination of all def-use sets for all node ids.
     * Marks globals, references, and static variables as used with the placeholder of -1
     */
    public function computeCombinedDefUses(): array
    {
        $combined_def_use_map = [];
        foreach ($this->def_uses as $var_name => $def_use_map) {
            $is_used_by_shared_state = (($this->variable_types[$var_name] ?? 0) & self::IS_REFERENCE_OR_GLOBAL_OR_STATIC) !== 0;
            foreach ($def_use_map as $def_id => $use_set) {
                if (isset($combined_def_use_map[$def_id])) {
                    $combined_def_use_map[$def_id] += $use_set;
                } else {
                    $combined_def_use_map[$def_id] = $use_set;
                }
                if ($is_used_by_shared_state) {
                    $combined_def_use_map[$def_id][self::USE_ID_FOR_SHARED_STATE] = true;
                }
            }
        }
        return $combined_def_use_map;
    }

    /**
     * @return associative-array<int,associative-array<int,true>>
     * Returns the combination of all use-def sets for all node ids.
     * Marks globals, references, and static variables as defined with the placeholder of -1
     */
    public function computeCombinedUseDefs(): array
    {
        $combined_use_def_map = [];
        foreach ($this->def_uses as $var_name => $def_use_map) {
            $is_used_by_shared_state = (($this->variable_types[$var_name] ?? 0) & self::IS_REFERENCE_OR_GLOBAL_OR_STATIC) !== 0;
            foreach ($def_use_map as $def_id => $use_set) {
                foreach ($use_set as $use_id => $_) {
                    if ($is_used_by_shared_state) {
                        $combined_use_def_map[$use_id][self::USE_ID_FOR_SHARED_STATE] = true;
                    }
                    $combined_use_def_map[$use_id][$def_id] = true;
                }
            }
        }
        return $combined_use_def_map;
    }
}
