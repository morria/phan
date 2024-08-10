<?php

declare(strict_types=1);

namespace Phan\AST;

use ast;
use ast\flags;
use ast\Node;
use Closure;
use Phan\Analysis\PostOrderAnalysisVisitor;
use Phan\AST\TolerantASTConverter\Shim;

use function array_map;
use function implode;
use function is_string;
use function sprintf;
use function var_representation;

use const VAR_REPRESENTATION_SINGLE_LINE;

Shim::load();

/**
 * This converts a PHP AST into an approximate string representation.
 * This ignores line numbers and spacing.
 *
 * Eventual goals:
 *
 * 1. Short representations of constants for LSP hover requests.
 * 2. Short representations for errors (e.g. "Error at $x->foo(self::MY_CONST)")
 * 3. Configuration of rendering this.
 *
 * Similar utilities:
 *
 * - https://github.com/tpunt/php-ast-reverter is a pretty printer.
 * - \Phan\Debug::nodeToString() converts nodes to strings.
 */
class ASTReverter
{
    public const EXEC_NODE_FLAG_NAMES = [
        flags\EXEC_EVAL => 'eval',
        flags\EXEC_INCLUDE => 'include',
        flags\EXEC_INCLUDE_ONCE => 'include_once',
        flags\EXEC_REQUIRE => 'require',
        flags\EXEC_REQUIRE_ONCE => 'require_once',
    ];

    /** @var associative-array<int,Closure(Node):string> this contains maps from node kinds to closures to convert node kinds to strings */
    private static $closure_map;
    /** @var Closure(Node):string this maps unknown node types to strings */
    private static $noop;

    // TODO: Make this configurable, copy instance properties to static properties.
    public function __construct()
    {
    }

    /**
     * Convert $node to a short PHP string representing $node.
     *
     * This does not work for all node kinds, and may be ambiguous.
     *
     * @param Node|string|int|float|bool|null|resource|array $node
     */
    public static function toShortString($node): string
    {
        if (!($node instanceof Node)) {
            if (\is_resource($node)) {
                return 'resource(' . \get_resource_type($node) . ')';
            }
            return var_representation($node, VAR_REPRESENTATION_SINGLE_LINE);
        }
        return (self::$closure_map[$node->kind] ?? self::$noop)($node);
    }

    /**
     * Escapes the inner contents to be suitable for a single-line double quoted string
     *
     * @see https://github.com/nikic/PHP-Parser/tree/master/lib/PhpParser/PrettyPrinter/Standard.php
     */
    public static function escapeInnerString(string $string, ?string $quote = null): string
    {
        if (null === $quote) {
            // For doc strings, don't escape newlines
            $escaped = \addcslashes($string, "\t\f\v$\\");
        } else {
            $escaped = \addcslashes($string, "\n\r\t\f\v$" . $quote . "\\");
        }

        // Escape other control characters
        return \preg_replace_callback('/([\0-\10\16-\37])(?=([0-7]?))/', /** @param list<string> $matches */ static function (array $matches): string {
            $oct = \decoct(\ord($matches[1]));
            if ($matches[2] !== '') {
                // If there is a trailing digit, use the full three character form
                return '\\' . \str_pad($oct, 3, '0', \STR_PAD_LEFT);
            }
            return '\\' . $oct;
        }, $escaped);
    }

    /**
     * Static initializer.
     */
    public static function init(): void
    {
        self::$noop = static function (Node $_): string {
            return '(unknown)';
        };
        self::$closure_map = [
            /**
             * @suppress PhanAccessClassConstantInternal
             */
            ast\AST_TYPE => static function (Node $node): string {
                return PostOrderAnalysisVisitor::AST_CAST_FLAGS_LOOKUP[$node->flags];
            },
            /**
             * @suppress PhanPartialTypeMismatchArgument
             */
            ast\AST_TYPE_INTERSECTION => static function (Node $node): string {
                return implode('&', array_map([self::class, 'toShortTypeString'], $node->children));
            },
            /**
             * @suppress PhanPartialTypeMismatchArgument
             */
            ast\AST_TYPE_UNION => static function (Node $node): string {
                return implode('|', array_map([self::class, 'toShortTypeString'], $node->children));
            },
            /**
             * @suppress PhanTypeMismatchArgumentNullable
             */
            ast\AST_NULLABLE_TYPE => static function (Node $node): string {
                return '?' . self::toShortTypeString($node->children['type']);
            },
            ast\AST_POST_INC => static function (Node $node): string {
                return self::formatIncDec('%s++', $node->children['var']);
            },
            ast\AST_PRE_INC => static function (Node $node): string {
                return self::formatIncDec('++%s', $node->children['var']);
            },
            ast\AST_POST_DEC => static function (Node $node): string {
                return self::formatIncDec('%s--', $node->children['var']);
            },
            ast\AST_PRE_DEC => static function (Node $node): string {
                return self::formatIncDec('--%s', $node->children['var']);
            },
            ast\AST_ARG_LIST => static function (Node $node): string {
                return '(' . implode(', ', array_map([self::class, 'toShortString'], $node->children)) . ')';
            },
            ast\AST_CALLABLE_CONVERT => /** @unused-param $node */ static function (Node $node): string {
                return '(...)';
            },
            ast\AST_ATTRIBUTE_LIST => static function (Node $node): string {
                return implode(' ', array_map([self::class, 'toShortString'], $node->children));
            },
            ast\AST_ATTRIBUTE_GROUP => static function (Node $node): string {
                return implode(', ', array_map([self::class, 'toShortString'], $node->children));
            },
            ast\AST_ATTRIBUTE => static function (Node $node): string {
                $result = self::toShortString($node->children['class']);
                $args = $node->children['args'];
                if ($args) {
                    $result .= self::toShortString($args);
                }
                return $result;
            },
            ast\AST_NAMED_ARG => static function (Node $node): string {
                return $node->children['name'] . ': ' . self::toShortString($node->children['expr']);
            },
            ast\AST_PARAM_LIST => static function (Node $node): string {
                return '(' . implode(', ', array_map([self::class, 'toShortString'], $node->children)) . ')';
            },
            ast\AST_PARAM => static function (Node $node): string {
                $str = '$' . $node->children['name'];
                if ($node->flags & ast\flags\PARAM_VARIADIC) {
                    $str = "...$str";
                }
                if ($node->flags & ast\flags\PARAM_REF) {
                    $str = "&$str";
                }
                if (isset($node->children['type'])) {
                    $str = ASTReverter::toShortString($node->children['type']) . ' ' . $str;
                }
                if (isset($node->children['default'])) {
                    $str .= ' = ' . ASTReverter::toShortString($node->children['default']);
                }
                return $str;
            },
            ast\AST_EXPR_LIST => static function (Node $node): string {
                return implode(', ', array_map([self::class, 'toShortString'], $node->children));
            },
            ast\AST_CLASS_CONST => static function (Node $node): string {
                return self::toShortString($node->children['class']) . '::' . $node->children['const'];
            },
            ast\AST_CLASS_NAME => static function (Node $node): string {
                return self::toShortString($node->children['class']) . '::class';
            },
            ast\AST_MAGIC_CONST => static function (Node $node): string {
                return UnionTypeVisitor::MAGIC_CONST_NAME_MAP[$node->flags] ?? '(unknown)';
            },
            ast\AST_CONST => static function (Node $node): string {
                return self::toShortString($node->children['name']);
            },
            ast\AST_VAR => static function (Node $node): string {
                $name_node = $node->children['name'];
                if (is_string($name_node)) {
                    return '$' . $name_node;
                }
                return '$' . (is_string($name_node) ? $name_node : ('{' . self::toShortString($name_node) . '}'));
            },
            ast\AST_DIM => static function (Node $node): string {
                $expr_str = self::toShortString($node->children['expr']);
                if ($expr_str === '(unknown)') {
                    return  '(unknown)';
                }

                $dim = $node->children['dim'];
                if ($dim !== null) {
                    $dim_str = self::toShortString($dim);
                } else {
                    $dim_str = '';
                }
                if ($node->flags & ast\flags\DIM_ALTERNATIVE_SYNTAX) {
                    return "{$expr_str}{{$dim_str}}";
                }
                return "{$expr_str}[$dim_str]";
            },
            ast\AST_NAME => static function (Node $node): string {
                $result = $node->children['name'];
                switch ($node->flags) {
                    case ast\flags\NAME_FQ:
                        return '\\' . $result;
                    case ast\flags\NAME_RELATIVE:
                        return 'namespace\\' . $result;
                    default:
                        return (string)$result;
                }
            },
            ast\AST_NAME_LIST => static function (Node $node): string {
                return implode('|', array_map([self::class, 'toShortString'], $node->children));
            },
            ast\AST_ARRAY => static function (Node $node): string {
                $parts = [];
                foreach ($node->children as $elem) {
                    if (!$elem instanceof Node) {
                        // Should always either be a Node or null.
                        $parts[] = '';
                        continue;
                    }
                    // AST_ARRAY_ELEM or AST_UNPACK
                    $parts[] = self::toShortString($elem);
                }
                $string = implode(',', $parts);
                switch ($node->flags) {
                    case ast\flags\ARRAY_SYNTAX_SHORT:
                    case ast\flags\ARRAY_SYNTAX_LONG:
                    default:
                        return "[$string]";
                    case ast\flags\ARRAY_SYNTAX_LIST:
                        return "list($string)";
                }
            },
            /** @suppress PhanAccessClassConstantInternal */
            ast\AST_BINARY_OP => static function (Node $node): string {
                return sprintf(
                    "(%s %s %s)",
                    self::toShortString($node->children['left']),
                    PostOrderAnalysisVisitor::NAME_FOR_BINARY_OP[$node->flags] ?? 'unknown',
                    self::toShortString($node->children['right'])
                );
            },
            ast\AST_ASSIGN => static function (Node $node): string {
                return sprintf(
                    "(%s = %s)",
                    self::toShortString($node->children['var']),
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_ASSIGN_REF => static function (Node $node): string {
                return sprintf(
                    "(%s =& %s)",
                    self::toShortString($node->children['var']),
                    self::toShortString($node->children['expr'])
                );
            },
            /** @suppress PhanAccessClassConstantInternal */
            ast\AST_ASSIGN_OP => static function (Node $node): string {
                return sprintf(
                    "(%s %s= %s)",
                    self::toShortString($node->children['var']),
                    PostOrderAnalysisVisitor::NAME_FOR_BINARY_OP[$node->flags] ?? 'unknown',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_UNARY_OP => static function (Node $node): string {
                $operation_name = PostOrderAnalysisVisitor::NAME_FOR_UNARY_OP[$node->flags] ?? null;
                if (!$operation_name) {
                    return '(unknown)';
                }
                $expr = $node->children['expr'];
                $expr_text = self::toShortString($expr);
                if (($expr->kind ?? null) !== ast\AST_UNARY_OP) {
                    return $operation_name . $expr_text;
                }
                return sprintf("%s(%s)", $operation_name, $expr_text);
            },
            ast\AST_PROP => static function (Node $node): string {
                $prop_node = $node->children['prop'];
                return sprintf(
                    '%s->%s',
                    self::toShortString($node->children['expr']),
                    $prop_node instanceof Node ? '{' . self::toShortString($prop_node) . '}' : (string)$prop_node
                );
            },
            ast\AST_NULLSAFE_PROP => static function (Node $node): string {
                $prop_node = $node->children['prop'];
                return sprintf(
                    '%s?->%s',
                    self::toShortString($node->children['expr']),
                    $prop_node instanceof Node ? '{' . self::toShortString($prop_node) . '}' : (string)$prop_node
                );
            },
            ast\AST_STATIC_CALL => static function (Node $node): string {
                $method_node = $node->children['method'];
                return sprintf(
                    '%s::%s%s',
                    self::toShortString($node->children['class']),
                    is_string($method_node) ? $method_node : self::toShortString($method_node),
                    self::toShortString($node->children['args'])
                );
            },
            ast\AST_METHOD_CALL => static function (Node $node): string {
                $method_node = $node->children['method'];
                return sprintf(
                    '%s->%s%s',
                    self::toShortString($node->children['expr']),
                    is_string($method_node) ? $method_node : self::toShortString($method_node),
                    self::toShortString($node->children['args'])
                );
            },
            ast\AST_NULLSAFE_METHOD_CALL => static function (Node $node): string {
                $method_node = $node->children['method'];
                return sprintf(
                    '%s?->%s%s',
                    self::toShortString($node->children['expr']),
                    is_string($method_node) ? $method_node : self::toShortString($method_node),
                    self::toShortString($node->children['args'])
                );
            },
            ast\AST_STATIC_PROP => static function (Node $node): string {
                $prop_node = $node->children['prop'];
                return sprintf(
                    '%s::$%s',
                    self::toShortString($node->children['class']),
                    $prop_node instanceof Node ? '{' . self::toShortString($prop_node) . '}' : (string)$prop_node
                );
            },
            ast\AST_INSTANCEOF => static function (Node $node): string {
                return sprintf(
                    '(%s instanceof %s)',
                    self::toShortString($node->children['expr']),
                    self::toShortString($node->children['class'])
                );
            },
            ast\AST_CAST => static function (Node $node): string {
                return sprintf(
                    '(%s)(%s)',
                    // @phan-suppress-next-line PhanAccessClassConstantInternal
                    PostOrderAnalysisVisitor::AST_CAST_FLAGS_LOOKUP[$node->flags] ?? 'unknown',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_CALL => static function (Node $node): string {
                return sprintf(
                    '%s%s',
                    self::toShortString($node->children['expr']),
                    self::toShortString($node->children['args'])
                );
            },
            ast\AST_NEW => static function (Node $node): string {
                // TODO: add parenthesis in case this is used as (new X())->method(), or properties, but only when necessary
                return sprintf(
                    'new %s%s',
                    self::toShortString($node->children['class']),
                    self::toShortString($node->children['args'])
                );
            },
            ast\AST_CLONE => static function (Node $node): string {
                // clone($x)->someMethod() has surprising precedence,
                // so surround `clone $x` with parenthesis.
                return sprintf(
                    '(clone(%s))',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_CONDITIONAL => static function (Node $node): string {
                ['cond' => $cond, 'true' => $true, 'false' => $false] = $node->children;
                if ($true !== null) {
                    return sprintf('(%s ? %s : %s)', self::toShortString($cond), self::toShortString($true), self::toShortString($false));
                }
                return sprintf('(%s ?: %s)', self::toShortString($cond), self::toShortString($false));
            },
            /** @suppress PhanPossiblyUndeclaredProperty */
            ast\AST_MATCH => static function (Node $node): string {
                ['cond' => $cond, 'stmts' => $stmts] = $node->children;
                return sprintf('match (%s) {%s}', ASTReverter::toShortString($cond), $stmts->children ? ' ' . ASTReverter::toShortString($stmts) . ' ' : '');
            },
            ast\AST_MATCH_ARM_LIST => static function (Node $node): string {
                return implode(', ', array_map(self::class . '::toShortString', $node->children));
            },
            ast\AST_MATCH_ARM => static function (Node $node): string {
                ['cond' => $cond, 'expr' => $expr] = $node->children;
                return sprintf('%s => %s', $cond !== null ? ASTReverter::toShortString($cond) : 'default', ASTReverter::toShortString($expr));
            },
            ast\AST_ISSET => static function (Node $node): string {
                return sprintf(
                    'isset(%s)',
                    self::toShortString($node->children['var'])
                );
            },
            ast\AST_EMPTY => static function (Node $node): string {
                return sprintf(
                    'empty(%s)',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_PRINT => static function (Node $node): string {
                return sprintf(
                    'print(%s)',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_ECHO => static function (Node $node): string {
                return 'echo ' . ASTReverter::toShortString($node->children['expr']) . ';';
            },
            ast\AST_ARRAY_ELEM => static function (Node $node): string {
                $value_representation = self::toShortString($node->children['value']);
                $key_node = $node->children['key'];
                if ($key_node !== null) {
                    return self::toShortString($key_node) . '=>' . $value_representation;
                }
                return $value_representation;
            },
            ast\AST_UNPACK => static function (Node $node): string {
                return sprintf(
                    '...(%s)',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_INCLUDE_OR_EVAL => static function (Node $node): string {
                return sprintf(
                    '%s(%s)',
                    self::EXEC_NODE_FLAG_NAMES[$node->flags],
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_ENCAPS_LIST => static function (Node $node): string {
                $parts = [];
                foreach ($node->children as $c) {
                    if ($c instanceof Node) {
                        $parts[] = '{' . self::toShortString($c) . '}';
                    } else {
                        $parts[] = self::escapeInnerString((string)$c, '"');
                    }
                }
                return '"' . implode('', $parts) . '"';
            },
            ast\AST_SHELL_EXEC => static function (Node $node): string {
                $parts = [];
                $expr = $node->children['expr'];
                if ($expr instanceof Node) {
                    foreach ($expr->children as $c) {
                        if ($c instanceof Node) {
                            $parts[] = '{' . self::toShortString($c) . '}';
                        } else {
                            $parts[] = self::escapeInnerString((string)$c, '`');
                        }
                    }
                } else {
                    $parts[] = self::escapeInnerString((string)$expr, '`');
                }
                return '`' . implode('', $parts) . '`';
            },
            // Slightly better short placeholders than (unknown)
            ast\AST_CLOSURE => static function (Node $_): string {
                return '(function)';
            },
            ast\AST_ARROW_FUNC => static function (Node $_): string {
                return '(fn)';
            },
            ast\AST_RETURN => static function (Node $node): string {
                $expr_node = $node->children['expr'];
                if ($expr_node === null) {
                    return 'return;';
                }
                return sprintf(
                    'return %s;',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_THROW => static function (Node $node): string {
                return sprintf(
                    '(throw %s)',
                    self::toShortString($node->children['expr'])
                );
            },
            ast\AST_FOR => static function (Node $_): string {
                return '(for loop)';
            },
            ast\AST_WHILE => static function (Node $_): string {
                return '(while loop)';
            },
            ast\AST_DO_WHILE => static function (Node $_): string {
                return '(do-while loop)';
            },
            ast\AST_FOREACH => static function (Node $_): string {
                return '(foreach loop)';
            },
            ast\AST_IF => static function (Node $_): string {
                return '(if statement)';
            },
            ast\AST_IF_ELEM => static function (Node $_): string {
                return '(if statement element)';
            },
            ast\AST_TRY => static function (Node $_): string {
                return '(try statement)';
            },
            ast\AST_SWITCH => static function (Node $_): string {
                return '(switch statement)';
            },
            ast\AST_SWITCH_LIST => static function (Node $_): string {
                return '(switch case list)';
            },
            ast\AST_SWITCH_CASE => static function (Node $_): string {
                return '(switch case statement)';
            },
            ast\AST_EXIT => static function (Node $node): string {
                $expr = $node->children['expr'];
                return 'exit(' . (isset($expr) ? self::toShortString($expr) : '') . ')';
            },
            ast\AST_YIELD => static function (Node $node): string {
                ['value' => $value, 'key' => $key] = $node->children;
                if ($value !== null) {
                    return '(yield)';
                }
                if ($key !== null) {
                    return sprintf('(yield %s => %s)', self::toShortString($key), self::toShortString($value));
                }
                return sprintf('(yield %s)', self::toShortString($value));
            },
            ast\AST_YIELD_FROM => static function (Node $node): string {
                return '(yield from ' . self::toShortString($node->children['expr']) . ')';
            },
            // TODO: AST_SHELL_EXEC, AST_ENCAPS_LIST(in shell_exec or double quotes)
        ];
    }

    /**
     * Returns the representation of an AST_TYPE, AST_NULLABLE_TYPE, AST_TYPE_UNION, or AST_NAME, as seen in an element signature
     */
    public static function toShortTypeString(Node $node): string
    {
        if ($node->kind === ast\AST_NULLABLE_TYPE) {
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
            return '?' . self::toShortTypeString($node->children['type']);
        }
        if ($node->kind === ast\AST_TYPE) {
            return PostOrderAnalysisVisitor::AST_TYPE_FLAGS_LOOKUP[$node->flags];
        }
        // Probably AST_NAME
        return self::toShortString($node);
    }


    /**
     * @param Node|string|int|float $node
     */
    private static function formatIncDec(string $format, $node): string
    {
        $str = self::toShortString($node);
        if (!($node instanceof Node && $node->kind === ast\AST_VAR)) {
            $str = '(' . $str . ')';
        }
        // @phan-suppress-next-line PhanPluginPrintfVariableFormatString
        return sprintf($format, $str);
    }
}
ASTReverter::init();
