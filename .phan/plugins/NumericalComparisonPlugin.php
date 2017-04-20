<?php declare(strict_types=1);
# .phan/plugins/NumericalComparisonPlugin.php

use Phan\Analysis\PostOrderAnalyzer;
use Phan\AST\AnalysisVisitor;
use Phan\CodeBase;
use Phan\Language\Context;
use Phan\Language\UnionType;
use Phan\Plugin;
use Phan\PluginIssue;
use Phan\Plugin\PluginImplementation;
use ast\Node;

class NumericalComparisonPlugin extends AnalysisVisitor implements PostOrderAnalyzer {
    use PluginIssue;

    /** define equal operator list */
    const BINARY_EQUAL_OPERATORS = [
        ast\flags\BINARY_IS_EQUAL,
        ast\flags\BINARY_IS_NOT_EQUAL,
    ];

    /** define identical operator list */
    const BINARY_IDENTICAL_OPERATORS = [
        ast\flags\BINARY_IS_IDENTICAL,
        ast\flags\BINARY_IS_NOT_IDENTICAL,
    ];

    public function visit(Node $node){
    }

    public function visitBinaryop(Node $node) : Context {
        // get the types of left and right values
        $left_node = $node->children['left'];
        $left_type = UnionType::fromNode($this->context, $this->code_base, $left_node);
        $right_node = $node->children['right'];
        $right_type = UnionType::fromNode($this->context, $this->code_base, $right_node);

        // non numerical values are not allowed in the operator equal(==, !=)
        if(in_array($node->flags, self::BINARY_EQUAL_OPERATORS)){
            if(
                !($this->isNumericalType($left_type->serialize())) &
                !($this->isNumericalType($right_type->serialize()))
            ){
                $this->emitPluginIssue(
                    $this->code_base,
                    $this->context,
                    'PhanPluginNumericalComparison',
                    "non numerical values compared by the operators '==' or '!=='"
                );
            }
            // numerical values are not allowed in the operator identical('===', '!==')
        }elseif(in_array($node->flags, self::BINARY_IDENTICAL_OPERATORS)){
            if(
                $this->isNumericalType($left_type->serialize()) |
                $this->isNumericalType($right_type->serialize())
            ){
                $this->emitPluginIssue(
                    $this->code_base,
                    $this->context,
                    'PhanPluginNumericalComparison',
                    "numerical values compared by the operators '===' or '!=='"
                );
            }
        }
        return $this->context;
    }

    /**
     * Judge the argument is 'int', 'float' or not
     *
     * @param string $type serialized UnionType string
     * @return bool argument string indicates numerical type or not
     */
    private function isNumericalType(string $type) : bool {
        return $type === 'int' || $type === 'float';
    }

}

return NumericalComparisonPlugin::class;
