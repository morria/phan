<?php declare(strict_types=1);
// .phan/plugins/NonBoolBranchPlugin.php

use Phan\AST\UnionTypeVisitor;
use Phan\Exception\IssueException;
use Phan\Language\Context;
use Phan\PluginV2;
use Phan\PluginV2\PostAnalyzeNodeCapability;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;
use ast\Node;

class NonBoolBranchPlugin extends PluginV2 implements PostAnalyzeNodeCapability
{
    /**
     * @return string - name of PluginAwarePostAnalysisVisitor subclass
     *
     * @override
     */
    public static function getPostAnalyzeNodeVisitorClassName() : string
    {
        return NonBoolBranchVisitor::class;
    }
}

class NonBoolBranchVisitor extends PluginAwarePostAnalysisVisitor
{
    // A plugin's visitors should not override visit() unless they need to.

    /**
     * @override
     */
    public function visitIf(Node $node) : Context
    {
        // Here, we visit the group of if/elseif/else instead of the individuals (visitIfElem)
        // so that we have the Union types of the variables **before** the PreOrderAnalysisVisitor makes inferences
        foreach ($node->children as $if_node) {
            $condition = $if_node->children['cond'];

            // dig nodes to avoid NOT('!') operator's converting its value to boolean type
            // Also, use right hand side of $x = (expr)
            while (($condition instanceof Node) && (
                ($condition->flags === ast\flags\UNARY_BOOL_NOT && $condition->kind === ast\AST_UNARY_OP)
                || (\in_array($condition->kind, [\ast\AST_ASSIGN, \ast\AST_ASSIGN_REF], true)))
            ) {
                $condition = $condition->children['expr'];
            }

            if ($condition === null) {
                // $condition === null will be appeared in else-clause, then avoid them
                continue;
            }

            if ($condition instanceof Node) {
                $this->context = $this->context->withLineNumberStart($condition->lineno);
            }
            // evaluate the type of conditional expression
            try {
                $union_type = UnionTypeVisitor::unionTypeFromNode($this->code_base, $this->context, $condition);
            } catch (IssueException $_) {
                return $this->context;
            }
            if (!$union_type->isExclusivelyBoolTypes()) {
                $this->emit(
                    'PhanPluginNonBoolBranch',
                    'Non bool value of type {TYPE} evaluated in if clause',
                    [(string)$union_type]
                );
            }
        }
        return $this->context;
    }
}

return new NonBoolBranchPlugin();
