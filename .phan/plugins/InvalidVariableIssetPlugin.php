<?php declare(strict_types=1);
// .phan/plugins/InvalidVariableIssetPlugin.php

use Phan\Language\Context;
use Phan\Language\Element\Variable;
use Phan\PluginV2;
use Phan\PluginV2\PostAnalyzeNodeCapability;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;
use ast\Node;

class InvalidVariableIssetPlugin extends PluginV2 implements PostAnalyzeNodeCapability
{

    /**
     * @return string - name of PluginAwarePostAnalysisVisitor subclass
     *
     * @override
     */
    public static function getPostAnalyzeNodeVisitorClassName() : string
    {
        return InvalidVariableIssetVisitor::class;
    }
}

class InvalidVariableIssetVisitor extends PluginAwarePostAnalysisVisitor
{

    /** define classes to parse */
    const CLASSES = [
        ast\AST_STATIC_CALL,
        ast\AST_STATIC_PROP,
    ];

    /** define expression to parse */
    const EXPRESSIONS = [
        ast\AST_CALL,
        ast\AST_DIM,
        ast\AST_INSTANCEOF,
        ast\AST_METHOD_CALL,
        ast\AST_PROP,
    ];

    // A plugin's visitors should not override visit() unless they need to.

    /** @override */
    public function visitIsset(Node $node) : Context
    {
        $argument = $node->children['var'];
        $variable = $argument;

        // get variable name from argument
        while (!isset($variable->children['name'])) {
            if (in_array($variable->kind, self::EXPRESSIONS, true)) {
                $variable = $variable->children['expr'];
            } elseif (in_array($variable->kind, self::CLASSES, true)) {
                $variable = $variable->children['class'];
            }
        }
        $name = $variable->children['name'];

        // emit issue if name is not declared
        // Check for edge cases such as isset($$var)
        if (is_string($name) && $name) {
            if (!Variable::isHardcodedVariableInScopeWithName($name, $this->context->isInGlobalScope()) &&
                    !$this->context->getScope()->hasVariableWithName($name)) {
                $this->emit(
                    'PhanPluginUndeclaredVariableIsset',
                    'undeclared variable ${VARIABLE} in isset()',
                    [$name]
                );
            }
        } elseif ($argument->kind !== ast\AST_VAR) {
            // emit issue if argument is not array access
            $this->emit(
                'PhanPluginInvalidVariableIsset',
                "non array/property access in isset()",
                []
            );
        } elseif (!is_string($name)) {
            // emit issue if argument is not array access
            $this->emit(
                'PhanPluginComplexVariableInIsset',
                "Unanalyzable complex variable expression in isset",
                []
            );
        }
        return $this->context;
    }
}

return new InvalidVariableIssetPlugin();
