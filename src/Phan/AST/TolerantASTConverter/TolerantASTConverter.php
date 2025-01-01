<?php

declare(strict_types=1);

namespace Phan\AST\TolerantASTConverter;

use AssertionError;
use ast;
use ast\flags;
use Closure;
use Error;
use Exception;
use InvalidArgumentException;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Diagnostic;
use Microsoft\PhpParser\DiagnosticsProvider;
use Microsoft\PhpParser\FilePositionMap;
use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\TernaryExpression;
use Microsoft\PhpParser\Node\SourceFileNode;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phan\CLI;
use Phan\Library\Cache;
use RuntimeException;

use function class_exists;
use function count;
use function get_class;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function substr;
use function var_export;
use function var_representation;

use const FILTER_FLAG_ALLOW_HEX;
use const FILTER_FLAG_ALLOW_OCTAL;
use const FILTER_VALIDATE_FLOAT;
use const FILTER_VALIDATE_INT;
use const PHP_VERSION_ID;

// If php-ast isn't loaded already, then load this file to generate equivalent
// class, constant, and function definitions.
Shim::load();

/**
 * Source: https://github.com/TysonAndre/tolerant-php-parser-to-php-ast
 *
 * Uses Microsoft/tolerant-php-parser to create an instance of ast\Node.
 * Useful if the php-ast extension isn't actually installed.
 *
 * @author Tyson Andre
 *
 * TODO: Don't need to pass in $start_line for many of these functions
 *
 * This is implemented as a collection of static methods for performance,
 * but functionality is provided through instance methods.
 * (The private methods may become instance methods if the performance impact is negligible
 * in PHP and HHVM)
 *
 * The instance methods set all of the options (static variables)
 * each time they are invoked,
 * so it's possible to have multiple callers use this without affecting each other.
 *
 * Compatibility: PHP 7.0-8.1
 *
 * XXX: This aims to match the line numbers that php-ast would generate (for compatibility) where reasonable,
 * even when counterintuitive. See https://github.com/phan/phan/issues/4520
 *
 * - The way php (and as a result php-ast) is getting the line number for anything
 *   that has 1 or more children is to use the line number of the first non-null child.
 *   (skipping most tokens such as `[`, `return`, etc, and having a line number for literal
 *   values (php-ast does not wrap the AST_ZVAL type php uses internally in a Node)
 * - If there are no non-null child nodes, then php uses the current line number of the lexer.
 *
 * which are the line numbers php uses in
 *
 * ----------------------------------------------------------------------------
 *
 *
 * License for TolerantASTConverter.php:
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2017-2020 Tyson Andre
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 *
 * NOTE: EchoExpression can get converted to multiple `ast\Node`s (e.g. for `echo 'first', 'second';`, which is why this has so many partial mismatches.
 * The current version of tolerant-php-parser prevents EchoExpression (and UnsetIntrinsicExpression) from being anything other than a top-level statement.
 *
 * @phan-file-suppress PhanPartialTypeMismatchReturn
 * @phan-file-suppress PhanPartialTypeMismatchArgument
 * @phan-file-suppress PhanPartialTypeMismatchArgumentInternal
 *
 * TODO: Add a way to report notices that aren't syntax errors?
 * e.g. `The (real) cast is deprecated, use (float) instead`, ending a function call with a comma, etc.
 */
class TolerantASTConverter
{
    use TolerantASTConverterTrait;

    // The latest stable version of php-ast.
    // For something != 85, update the library's release.
    public const AST_VERSION = 85;

    // The versions that this supports
    public const SUPPORTED_AST_VERSIONS = [80, self::AST_VERSION];

    // If this environment variable is set, this will throw.
    // (For debugging, may be removed in the future)
    public const ENV_AST_THROW_INVALID = 'AST_THROW_INVALID';

    public const INCOMPLETE_CLASS_CONST = '__INCOMPLETE_CLASS_CONST__';
    public const INCOMPLETE_PROPERTY = '__INCOMPLETE_PROPERTY__';
    public const INCOMPLETE_VARIABLE = '__INCOMPLETE_VARIABLE__';

    private const CAST_EXPRESSION_TYPE_LOOKUP = [
        // From Parser->parseCastExpression()
        TokenKind::ArrayCastToken   => flags\TYPE_ARRAY,
        TokenKind::BoolCastToken    => flags\TYPE_BOOL,
        TokenKind::DoubleCastToken  => flags\TYPE_DOUBLE,
        TokenKind::IntCastToken     => flags\TYPE_LONG,
        TokenKind::ObjectCastToken  => flags\TYPE_OBJECT,
        TokenKind::StringCastToken  => flags\TYPE_STRING,
        TokenKind::UnsetCastToken   => flags\TYPE_NULL,

        // From Parser->parseCastExpressionGranular()
        // This is a syntax error, but try to match what the intent was
        TokenKind::ArrayKeyword         => flags\TYPE_ARRAY,
        TokenKind::BinaryReservedWord   => flags\TYPE_STRING,
        TokenKind::BoolReservedWord     => flags\TYPE_BOOL,
        TokenKind::BooleanReservedWord  => flags\TYPE_BOOL,
        TokenKind::DoubleReservedWord   => flags\TYPE_DOUBLE,
        TokenKind::IntReservedWord      => flags\TYPE_LONG,
        TokenKind::IntegerReservedWord  => flags\TYPE_LONG,
        TokenKind::FloatReservedWord    => flags\TYPE_DOUBLE,
        TokenKind::ObjectReservedWord   => flags\TYPE_OBJECT,
        TokenKind::RealReservedWord     => flags\TYPE_DOUBLE,
        TokenKind::StringReservedWord   => flags\TYPE_STRING,
        TokenKind::UnsetKeyword         => flags\TYPE_NULL,
        TokenKind::StaticKeyword        => flags\TYPE_STATIC,
    ];

    private const UNARY_OP_EXPRESSION_LOOKUP = [
        TokenKind::TildeToken                   => flags\UNARY_BITWISE_NOT,
        TokenKind::MinusToken                   => flags\UNARY_MINUS,
        TokenKind::PlusToken                    => flags\UNARY_PLUS,
        TokenKind::ExclamationToken             => flags\UNARY_BOOL_NOT,
        // ErrorControlExpression is separate from UnaryOpExpression
    ];

    private const BINARY_EXPRESSION_LOOKUP = [
        TokenKind::AmpersandAmpersandToken              => flags\BINARY_BOOL_AND,
        TokenKind::AmpersandToken                       => flags\BINARY_BITWISE_AND,
        TokenKind::AndKeyword                           => flags\BINARY_BOOL_AND,
        TokenKind::AsteriskAsteriskToken                => flags\BINARY_POW,
        TokenKind::AsteriskToken                        => flags\BINARY_MUL,
        TokenKind::BarBarToken                          => flags\BINARY_BOOL_OR,
        TokenKind::BarToken                             => flags\BINARY_BITWISE_OR,
        TokenKind::CaretToken                           => flags\BINARY_BITWISE_XOR,
        TokenKind::DotToken                             => flags\BINARY_CONCAT,
        TokenKind::EqualsEqualsEqualsToken              => flags\BINARY_IS_IDENTICAL,
        TokenKind::EqualsEqualsToken                    => flags\BINARY_IS_EQUAL,
        TokenKind::ExclamationEqualsEqualsToken         => flags\BINARY_IS_NOT_IDENTICAL,
        TokenKind::ExclamationEqualsToken               => flags\BINARY_IS_NOT_EQUAL,
        TokenKind::GreaterThanEqualsToken               => flags\BINARY_IS_GREATER_OR_EQUAL,
        TokenKind::GreaterThanGreaterThanToken          => flags\BINARY_SHIFT_RIGHT,
        TokenKind::GreaterThanToken                     => flags\BINARY_IS_GREATER,
        TokenKind::LessThanEqualsGreaterThanToken       => flags\BINARY_SPACESHIP,
        TokenKind::LessThanEqualsToken                  => flags\BINARY_IS_SMALLER_OR_EQUAL,
        TokenKind::LessThanLessThanToken                => flags\BINARY_SHIFT_LEFT,
        TokenKind::LessThanToken                        => flags\BINARY_IS_SMALLER,
        TokenKind::MinusToken                           => flags\BINARY_SUB,
        TokenKind::OrKeyword                            => flags\BINARY_BOOL_OR,
        TokenKind::PercentToken                         => flags\BINARY_MOD,
        TokenKind::PlusToken                            => flags\BINARY_ADD,
        TokenKind::QuestionQuestionToken                => flags\BINARY_COALESCE,
        TokenKind::SlashToken                           => flags\BINARY_DIV,
        TokenKind::XorKeyword                           => flags\BINARY_BOOL_XOR,
    ];

    private const BINARY_ASSIGN_EXPRESSION_LOOKUP = [
        TokenKind::AmpersandEqualsToken                 => flags\BINARY_BITWISE_AND,
        TokenKind::AsteriskAsteriskEqualsToken          => flags\BINARY_POW,
        TokenKind::AsteriskEqualsToken                  => flags\BINARY_MUL,
        TokenKind::BarEqualsToken                       => flags\BINARY_BITWISE_OR,
        TokenKind::CaretEqualsToken                     => flags\BINARY_BITWISE_XOR,
        TokenKind::DotEqualsToken                       => flags\BINARY_CONCAT,
        TokenKind::MinusEqualsToken                     => flags\BINARY_SUB,
        TokenKind::PercentEqualsToken                   => flags\BINARY_MOD,
        TokenKind::PlusEqualsToken                      => flags\BINARY_ADD,
        TokenKind::SlashEqualsToken                     => flags\BINARY_DIV,
        TokenKind::GreaterThanGreaterThanEqualsToken    => flags\BINARY_SHIFT_RIGHT,
        TokenKind::LessThanLessThanEqualsToken          => flags\BINARY_SHIFT_LEFT,
        TokenKind::QuestionQuestionEqualsToken          => flags\BINARY_COALESCE,
    ];

    /**
     * @var FilePositionMap maps byte offsets of the currently parsed file to line numbers.
     * @internal
     */
    public static $file_position_map;

    /**
     * @var int - A version in SUPPORTED_AST_VERSIONS
     */
    protected static $php_version_id_parsing = PHP_VERSION_ID;

    /**
     * @var int - Internal counter for declarations, to generate __declId in `ast\Node`s for declarations.
     */
    protected static $decl_id = 0;

    /** @var bool should placeholder nodes be added as child nodes instead of refusing to generate a Node for an invalid statement? */
    protected static $should_add_placeholders = false;

    /** @var string the contents of the file currently being parsed */
    protected static $file_contents = '';

    /** @var bool Sets equivalent static option in self::_start_parsing() */
    protected $instance_should_add_placeholders = false;

    /**
     * @var int can be used to tweak behavior for compatibility.
     * Set to a newer version to support comments on class constants, etc.
     */
    protected $instance_php_version_id_parsing = PHP_VERSION_ID;

    // No-op.
    public function __construct()
    {
    }

    /**
     * Controls whether this should add placeholders for nodes that couldn't be parsed
     * (enabled for code completion)
     */
    public function setShouldAddPlaceholders(bool $value): void
    {
        $this->instance_should_add_placeholders = $value;
    }

    /**
     * Records the PHP major+minor version id (70100, 70200, etc.)
     * that this polyfill should emulate the behavior of php-ast for.
     */
    public function setPHPVersionId(int $value): void
    {
        $this->instance_php_version_id_parsing = $value;
    }

    /**
     * Generates an ast\Node with this converter's current settings. (caching if $cache is non-null)
     *
     * @param Diagnostic[] &$errors @phan-output-reference
     * @param ?Cache<ParseResult> $cache
     * @throws InvalidArgumentException if the requested AST version is invalid.
     */
    public function parseCodeAsPHPAST(string $file_contents, int $version, array &$errors = [], ?Cache $cache = null): \ast\Node
    {
        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS, true)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", \implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }
        $errors = [];
        $cache_key = null;
        if ($cache) {
            $cache_key = $this->generateCacheKey($file_contents, $version);
            $result = \Phan\Library\StringUtil::isNonZeroLengthString($cache_key) ? $cache->getIfExists($cache_key) : null;
            if ($result) {
                $errors = $result->diagnostics;
                return $result->node;
            }
        }
        $result = $this->parseCodeAsPHPASTUncached($file_contents, $version, $errors);
        if ($cache && \Phan\Library\StringUtil::isNonZeroLengthString($cache_key)) {
            $cache->save($cache_key, new ParseResult($result, $errors));
        }
        return $result;
    }

    /**
     * Generates an ast\Node with this converter's current settings.
     *
     * @param Diagnostic[] &$errors @phan-output-reference
     * @throws InvalidArgumentException if the requested AST version is invalid.
     */
    public function parseCodeAsPHPASTUncached(string $file_contents, int $version, array &$errors = []): \ast\Node
    {
        $parser_node = static::phpParserParse($file_contents, $errors);
        try {
            return $this->phpParserToPhpast($parser_node, $version, $file_contents);
        } finally {
            // Remove object reference cycles manually to free memory - automatic cyclic garbage collection is disabled for performance in older php 7 versions.
            self::unlinkDescendantNodes($parser_node);
        }
    }

    /**
     * Unlink the nodes manually to free memory (or to exclude them from var_export())
     *
     * Automatic cyclic garbage collection is disabled for performance in older php 7 versions.
     */
    public static function unlinkDescendantNodes(SourceFileNode $root): void
    {
        // Avoid creating cyclic data structures.
        // Node->getRoot() requires a valid parent node path to a SourceFileNode because it needs getDocCommentText() to work.
        $placeholder_root = new SourceFileNode();
        $placeholder_root->fileContents = $root->fileContents;

        foreach ($root->getDescendantNodes() as $descendant) {
            $descendant->parent = $placeholder_root;
        }
        $root->parent = null;
    }

    /**
     * @param Diagnostic[] &$errors @phan-output-reference (TODO: param-out)
     */
    public static function phpParserParse(string $file_contents, array &$errors = []): PhpParser\Node\SourceFileNode
    {
        // TODO: In php 7.3, we might need to provide a version, due to small changes in lexing?
        // This may stop being an issue when php 7.2 support is dropped.
        $parser = CompatibleParser::create();
        $result = $parser->parseSourceFile($file_contents);
        $errors = DiagnosticsProvider::getDiagnostics($result);
        return $result;
    }

    /**
     * Visible for testing
     *
     * @param PhpParser\Node $parser_node
     * @param int $ast_version
     * @param string $file_contents
     * @throws InvalidArgumentException if the provided AST version isn't valid
     */
    public function phpParserToPhpast(PhpParser\Node $parser_node, int $ast_version, string $file_contents): \ast\Node
    {
        if (!\in_array($ast_version, self::SUPPORTED_AST_VERSIONS, true)) {
            throw new \InvalidArgumentException(sprintf("Unexpected version: want %s, got %d", implode(', ', self::SUPPORTED_AST_VERSIONS), $ast_version));
        }
        $this->startParsing($file_contents);
        $stmts = static::phpParserNodeToAstNode($parser_node);
        // return static::normalizeNamespaces($stmts);
        return $stmts;
    }

    protected function startParsing(string $file_contents): void
    {
        self::$decl_id = 0;
        self::$should_add_placeholders = $this->instance_should_add_placeholders;
        self::$php_version_id_parsing = $this->instance_php_version_id_parsing;
        self::$file_position_map = new FilePositionMap($file_contents);
        // $file_contents required for looking up line numbers.
        // TODO: Other data structures?
        self::$file_contents = $file_contents;
    }

    /**
     * @param null|bool|int|string|PhpParser\Node|Token|(PhpParser\Node|Token)[] $n
     * @throws Exception if node is invalid
     * @internal
     */
    public static function debugDumpNodeOrToken($n): string
    {
        if (\is_scalar($n)) {
            return var_representation($n);
        }
        if (!\is_array($n)) {
            $n = [$n];
        }
        $result = [];
        foreach ($n as $e) {
            $dumper = new NodeDumper(self::$file_contents);
            $dumper->setIncludeTokenKind(true);
            $result[] = $dumper->dumpTreeAsString($e);
        }
        return implode("\n", $result);
    }

    /**
     * @param Token|PhpParser\Node[]|PhpParser\Node\StatementNode $parser_nodes
     *        This is represented as a single node for `if` with a colon (macro style)
     * @param ?int $lineno
     * @param bool $return_null_on_empty (return null if non-array (E.g. semicolon is seen))
     * @return ?ast\Node
     * Throws RuntimeException|Exception if the statement list is invalid
     * @suppress PhanThrowTypeAbsentForCall|PhanThrowTypeMismatchForCall
     */
    private static function phpParserStmtlistToAstNode($parser_nodes, ?int $lineno, bool $return_null_on_empty = false): ?\ast\Node
    {
        if ($parser_nodes instanceof PhpParser\Node\Statement\CompoundStatementNode) {
            $parser_nodes = $parser_nodes->statements;
        } elseif ($parser_nodes instanceof PhpParser\Node\StatementNode) {
            if ($parser_nodes instanceof PhpParser\Node\Statement\EmptyStatement) {
                $parser_nodes = [];
            } else {
                $parser_nodes = [$parser_nodes];
            }
        } elseif ($parser_nodes instanceof Token) {
            if ($parser_nodes->kind === TokenKind::SemicolonToken) {
                if ($return_null_on_empty) {
                    return null;
                }
                return new ast\Node(
                    ast\AST_STMT_LIST,
                    0,
                    [],
                    $lineno ?? 0
                );
            }
        }

        if (!\is_array($parser_nodes)) {
            throw new RuntimeException("Unexpected type for statements: " . static::debugDumpNodeOrToken($parser_nodes));
        }
        $children = [];
        foreach ($parser_nodes as $parser_node) {
            try {
                $child_node = static::phpParserNodeToAstNode($parser_node);
            } catch (InvalidNodeException $_) {
                continue;
            }
            if (\is_array($child_node)) {
                // EchoExpression returns multiple children.
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } elseif (!\is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        if (!\is_int($lineno)) {
            foreach ($parser_nodes as $parser_node) {
                $child_node_line = static::getStartLine($parser_node);
                if ($child_node_line > 0) {
                    $lineno = $child_node_line;
                    break;
                }
            }
        }
        return new ast\Node(ast\AST_STMT_LIST, 0, $children, $lineno ?? 0);
    }

    /**
     * @param ?PhpParser\Node\AttributeGroup[] $attribute_groups
     *        This is represented as a single node for `if` with a colon (macro style)
     * @return ?ast\Node a node of kind ast\AST_ATTRIBUTE_LIST, or null.
     */
    private static function phpParserAttributeGroupsToAstAttributeList(?array $attribute_groups): ?\ast\Node
    {
        if (!$attribute_groups) {
            return null;
        }
        $children = [];
        foreach ($attribute_groups as $attribute_group) {
            if (!$attribute_group instanceof PhpParser\Node\AttributeGroup) {
                continue;
            }
            $ast_group = self::phpParserAttributeGroupToAstAttributeGroup($attribute_group);
            if ($ast_group) {
                $children[] = $ast_group;
            }
        }
        if (!$children) {
            return null;
        }
        return new ast\Node(
            ast\AST_ATTRIBUTE_LIST,
            0,
            $children,
            $children[0]->lineno
        );
    }

    private static function phpParserAttributeGroupToAstAttributeGroup(PhpParser\Node\AttributeGroup $group): ?ast\Node
    {
        $children = [];
        foreach ($group->attributes->children ?? [] as $parser_attribute) {
            if (!$parser_attribute instanceof PhpParser\Node\Attribute) {
                continue;
            }
            $children[] = self::phpParserAttributeToAstAttribute($parser_attribute);
        }
        if (!$children) {
            return null;
        }
        $result = new ast\Node(
            ast\AST_ATTRIBUTE_GROUP,
            0,
            $children,
            self::getStartLine($group)
        );
        // Not part of php-ast, but useful as an indicator that the attribute group syntax is probably incompatible with php 7 and older
        // if it spans multiple lines.
        $result->endLineno = static::getEndLine($group);
        return $result;
    }

    private static function phpParserAttributeToAstAttribute(PhpParser\Node\Attribute $attribute): ast\Node
    {
        $args = $attribute->argumentExpressionList;
        $start_line = self::getStartLine($attribute);
        return new ast\Node(
            ast\AST_ATTRIBUTE,
            0,
            [
                'class' => static::phpParserNonValueNodeToAstNode($attribute->name),
                'args' => $args || $attribute->openParen || $attribute->closeParen ? static::phpParserArgListToAstArgList($args, $start_line) : null
            ],
            $start_line
        );
    }

    private static function phpParserExprListToExprList(PhpParser\Node\DelimitedList\ExpressionList $expressions_list, int $lineno): ast\Node
    {
        $children = [];
        $expressions_children = $expressions_list->children;
        foreach ($expressions_children as $expr) {
            if ($expr instanceof Token && $expr->kind === TokenKind::CommaToken) {
                continue;
            }
            $child_node = static::phpParserNodeToAstNode($expr);
            if (\is_array($child_node)) {
                // EchoExpression returns multiple children in php-ast
                foreach ($child_node as $child_node_part) {
                    $children[] = $child_node_part;
                }
            } elseif (!\is_null($child_node)) {
                $children[] = $child_node;
            }
        }
        foreach ($expressions_children as $parser_node) {
            $child_node_line = static::getEndLine($parser_node);
            if ($child_node_line > 0) {
                $lineno = $child_node_line;
                break;
            }
        }
        return new ast\Node(
            ast\AST_EXPR_LIST,
            0,
            $children,
            $lineno
        );
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     *                                                          Generates a valid placeholder for invalid nodes if $should_add_placeholders is true.
     * @throws InvalidNodeException when self::$should_add_placeholders is false, like many of these methods.
     */
    protected static function phpParserNodeToAstNodeOrPlaceholderExpr($n)
    {
        if (!self::$should_add_placeholders) {
            return static::phpParserNodeToAstNode($n);
        }
        try {
            return static::phpParserNodeToAstNode($n);
        } catch (InvalidNodeException $_) {
            return static::newPlaceholderExpression($n);
        }
    }

    /**
     * @param PhpParser\Node|Token $n
     * @throws InvalidNodeException if this was called on an unexpected type
     */
    final protected static function getStartLine($n): int
    {
        if (\is_object($n)) {
            return self::$file_position_map->getStartLine($n);
        }
        throw new InvalidNodeException();
    }

    /**
     * @param ?PhpParser\Node|?Token $n
     * @throws InvalidNodeException if this was called on an unexpected type
     */
    final protected static function getEndLine($n): int
    {
        if (!\is_object($n)) {
            if (\is_null($n)) {
                return 0;
            }
            throw new InvalidNodeException();
        }
        return self::$file_position_map->getEndLine($n);
    }

    /**
     * This returns an array of values mapping class names to the closures which converts them to a scalar or ast\Node
     *
     * Why not a switch? Switches are slow until php 7.2, and there are dozens of class names to handle.
     *
     * - In php <= 7.1, the interpreter would loop through all possible cases, and compare against the value one by one.
     * - There are a lot of local variables to look at.
     *
     * @return array<string,Closure(object,int):(\ast\Node|int|string|float|null)>
     *
     * NOTE: Make sure that the only caller of this is TolerantASTConverterTrait
     * @suppress PhanTypeMismatchReturn todo: why?
     */
    protected static function initHandleMap(): array
    {
        $closures = [
            /** @return ?ast\Node */
            'Microsoft\PhpParser\Node\SourceFileNode' => static function (PhpParser\Node\SourceFileNode $n, int $start_line): ?\ast\Node {
                return static::phpParserStmtlistToAstNode($n->statementList, $start_line, false);
            },
            /**
             * @return mixed
             */
            'Microsoft\PhpParser\Node\Expression\ArgumentExpression' => static function (PhpParser\Node\Expression\ArgumentExpression $n, int $start_line) {
                $expression = $n->expression;
                if ($expression === null) {
                    throw new InvalidNodeException();
                }
                $result = static::phpParserNodeToAstNode($expression);
                if ($n->dotDotDotToken !== null) {
                    return new ast\Node(ast\AST_UNPACK, 0, ['expr' => $result], $start_line);
                }
                return $result;
            },
            /**
             * @return ast\Node|string|int|float
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\Node\Expression\SubscriptExpression' => static function (PhpParser\Node\Expression\SubscriptExpression $n, int $start_line) {
                $expr = static::phpParserNodeToAstNode($n->postfixExpression);
                try {
                    return new ast\Node(
                        ast\AST_DIM,
                        ($n->openBracketOrBrace->kind ?? null) === TokenKind::OpenBraceToken ? ast\flags\DIM_ALTERNATIVE_SYNTAX : 0,
                        [
                            'expr' => $expr,
                            'dim' => $n->accessExpression !== null ? static::phpParserNodeToAstNode($n->accessExpression) : null,
                        ],
                        $start_line
                    );
                } catch (InvalidNodeException $_) {
                    return $expr;
                }
            },
            /** @return ?(ast\Node|float|int|string) */
            'Microsoft\PhpParser\Node\Expression\AssignmentExpression' => static function (PhpParser\Node\Expression\AssignmentExpression $n, int $start_line) {
                try {
                    $var_node = static::phpParserNodeToAstNode($n->leftOperand);
                } catch (InvalidNodeException $_) {
                    if (self::$should_add_placeholders) {
                        $var_node = new ast\Node(ast\AST_VAR, 0, ['name' => self::INCOMPLETE_VARIABLE], $start_line);
                    } else {
                        // convert `;= $b;` to `;$b;`
                        return static::phpParserNodeToAstNode($n->rightOperand);
                    }
                }
                $expr_node = static::phpParserNodeToAstNodeOrPlaceholderExpr($n->rightOperand);
                // FIXME switch on $n->kind
                return static::astNodeAssign(
                    $var_node,
                    $expr_node,
                    $start_line,
                    $n->byRef !== null
                );
            },
            /**
             * @return ast\Node|string|float|int (can return a non-Node if the left or right-hand side could not be parsed
             */
            'Microsoft\PhpParser\Node\Expression\BinaryExpression' => static function (PhpParser\Node\Expression\BinaryExpression $n, int $start_line) {
                $kind = $n->operator->kind;
                if ($kind === TokenKind::InstanceOfKeyword) {
                    return new ast\Node(ast\AST_INSTANCEOF, 0, [
                        'expr'  => static::phpParserNodeToAstNode($n->leftOperand),
                        'class' => static::phpParserNonValueNodeToAstNode($n->rightOperand),
                    ], $start_line);
                }
                $ast_kind = self::BINARY_EXPRESSION_LOOKUP[$kind] ?? null;
                if ($ast_kind === null) {
                    $ast_kind = self::BINARY_ASSIGN_EXPRESSION_LOOKUP[$kind] ?? null;
                    if ($ast_kind === null) {
                        throw new AssertionError("missing $kind (" . Token::getTokenKindNameFromValue($kind) . ")");
                    }
                    return static::astNodeAssignop($ast_kind, $n, $start_line);
                }
                return static::astNodeBinaryop($ast_kind, $n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\UnaryOpExpression' => static function (PhpParser\Node\Expression\UnaryOpExpression $n, int $start_line): ast\Node {
                $kind = $n->operator->kind;
                $ast_kind = self::UNARY_OP_EXPRESSION_LOOKUP[$kind] ?? null;
                if ($ast_kind === null) {
                    throw new AssertionError("missing $kind(" . Token::getTokenKindNameFromValue($kind) . ")");
                }
                return new ast\Node(
                    ast\AST_UNARY_OP,
                    $ast_kind,
                    ['expr' => static::phpParserNodeToAstNode($n->operand)],
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Expression\CastExpression' => static function (PhpParser\Node\Expression\CastExpression $n, int $start_line): ast\Node {
                $kind = $n->castType->kind;
                $ast_kind = self::CAST_EXPRESSION_TYPE_LOOKUP[$kind] ?? null;
                if ($ast_kind === null) {
                    throw new AssertionError("missing $kind");
                }
                return new ast\Node(
                    ast\AST_CAST,
                    $ast_kind,
                    ['expr' => static::phpParserNodeToAstNode($n->operand)],
                    static::getEndLine($n) ?: $start_line
                );
            },
            'Microsoft\PhpParser\Node\Expression\AnonymousFunctionCreationExpression' => static function (
                PhpParser\Node\Expression\AnonymousFunctionCreationExpression $n,
                int $start_line
            ): ast\Node {
                if ($n->functionKeyword) {
                    $start_line = self::getStartLine($n->functionKeyword);
                }
                $ast_return_type = static::phpParserUnionTypeToAstNode($n->returnTypeList, static::getEndLine($n->returnTypeList) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                $use_variable_name_list = $n->anonymousFunctionUseClause->useVariableNameList ?? null;
                if (!$use_variable_name_list instanceof PhpParser\Node\DelimitedList\UseVariableNameList) {
                    $use_variable_name_list = null;
                }
                return static::astDeclClosure(
                    $n->byRefToken !== null,
                    $n->staticModifier !== null,
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    static::phpParserParamsToAstParams($n->parameters, $start_line),
                    static::phpParserClosureUsesToAstClosureUses($use_variable_name_list, $start_line),
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable $return_null_on_empty is false
                    static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon->statements ?? [], self::getStartLine($n->compoundStatementOrSemicolon), false),
                    $ast_return_type,
                    $start_line,
                    static::getEndLine($n),
                    static::resolveDocCommentForClosure($n)
                );
            },
            'Microsoft\PhpParser\Node\Expression\ArrowFunctionCreationExpression' => static function (
                PhpParser\Node\Expression\ArrowFunctionCreationExpression $n,
                int $start_line
            ): ast\Node {
                if ($n->functionKeyword) {
                    $start_line = self::getStartLine($n->functionKeyword);
                }
                $ast_return_type = static::phpParserUnionTypeToAstNode($n->returnTypeList, static::getEndLine($n->returnTypeList) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                $return_line = self::getStartLine($n->resultExpression);
                return static::newASTDecl(
                    ast\AST_ARROW_FUNC,
                    ($n->byRefToken !== null ? flags\FUNC_RETURNS_REF : 0) | ($n->staticModifier !== null ? flags\MODIFIER_STATIC : null),
                    [
                        'params' => static::phpParserParamsToAstParams($n->parameters, $start_line),
                        'stmts' => new ast\Node(
                            ast\AST_RETURN,
                            0,
                            ['expr' => static::phpParserNodeToAstNode($n->resultExpression)],
                            $return_line
                        ),
                        'returnType' => $ast_return_type,
                        'attributes' => static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    ],
                    $start_line,
                    static::resolveDocCommentForClosure($n),
                    '{closure}',
                    static::getEndLine($n),
                    self::nextDeclId()
                );
            },
            /**
             * @throws InvalidNodeException if the resulting AST would not be analyzable by Phan
             */
            'Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression' => static function (PhpParser\Node\Expression\ScopedPropertyAccessExpression $n, int $start_line): ?\ast\Node {
                $member_name = $n->memberName;
                if ($member_name instanceof PhpParser\Node\Expression\Variable) {
                    try {
                        $prop_node = static::phpParserNodeToAstNode($member_name->name);
                    } catch (InvalidNodeException $e) {
                        if (self::$should_add_placeholders) {
                            $prop_node = '';
                        } else {
                            throw $e;
                        }
                    }
                    return new ast\Node(
                        ast\AST_STATIC_PROP,
                        0,
                        [
                            'class' => static::phpParserNonValueNodeToAstNode($n->scopeResolutionQualifier),
                            'prop' => $prop_node,
                        ],
                        $start_line
                    );
                } else {
                    if ($member_name instanceof Token) {
                        if (\get_class($member_name) !== Token::class) {
                            if (self::$should_add_placeholders) {
                                $member_name = self::INCOMPLETE_CLASS_CONST;
                            } else {
                                throw new InvalidNodeException();
                            }
                        } else {
                            $member_name = static::tokenToString($member_name);
                        }
                    } else {
                        // E.g. Node\Expression\BracedExpression
                        throw new InvalidNodeException();
                    }
                    return static::phpParserClassConstFetchToAstClassConstFetch($n->scopeResolutionQualifier, $member_name, $start_line);
                }
            },
            'Microsoft\PhpParser\Node\Expression\CloneExpression' => static function (PhpParser\Node\Expression\CloneExpression $n, int $start_line): ast\Node {
                return new ast\Node(ast\AST_CLONE, 0, ['expr' => static::phpParserNodeToAstNode($n->expression)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ErrorControlExpression' => static function (PhpParser\Node\Expression\ErrorControlExpression $n, int $start_line): ast\Node {
                return new ast\Node(
                    ast\AST_UNARY_OP,
                    flags\UNARY_SILENCE,
                    ['expr' => static::phpParserNodeToAstNode($n->operand)],
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Expression\EmptyIntrinsicExpression' => static function (PhpParser\Node\Expression\EmptyIntrinsicExpression $n, int $start_line): ast\Node {
                return new ast\Node(ast\AST_EMPTY, 0, ['expr' => static::phpParserNodeToAstNode($n->expression)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\EvalIntrinsicExpression' => static function (PhpParser\Node\Expression\EvalIntrinsicExpression $n, int $start_line): ast\Node {
                return new ast\Node(
                    ast\AST_INCLUDE_OR_EVAL,
                    flags\EXEC_EVAL,
                    ['expr' => static::phpParserNodeToAstNode($n->expression)],
                    $start_line
                );
            },
            /** @return string|ast\Node */
            'Microsoft\PhpParser\Token' => static function (PhpParser\Token $token, int $start_line) {
                $kind = $token->kind;
                $str = static::tokenToString($token);
                if ($kind === TokenKind::StaticKeyword) {
                    return new ast\Node(ast\AST_NAME, flags\NAME_NOT_FQ, ['name' => $str], $start_line);
                }
                return $str;
            },
            /**
             * @return never
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\MissingToken' => static function (PhpParser\MissingToken $unused_node, int $_): void {
                throw new InvalidNodeException();
            },
            /**
             * @return never
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\SkippedToken' => static function (PhpParser\SkippedToken $unused_node, int $_): void {
                throw new InvalidNodeException();
            },
            'Microsoft\PhpParser\Node\Expression\ExitIntrinsicExpression' => static function (PhpParser\Node\Expression\ExitIntrinsicExpression $n, int $start_line): ast\Node {
                $expression = $n->expression;
                $expr_node = $expression !== null ? static::phpParserNodeToAstNode($expression) : null;
                return new ast\Node(ast\AST_EXIT, 0, ['expr' => $expr_node], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\CallExpression' => static function (PhpParser\Node\Expression\CallExpression $n, int $start_line): ast\Node {
                $callable_expression = $n->callableExpression;
                $arg_list = static::phpParserArgListToAstArgList($n->argumentExpressionList, $start_line);
                if ($callable_expression instanceof PhpParser\Node\Expression\MemberAccessExpression) {  // $a->f()
                    return static::astNodeMethodCall(
                        $callable_expression->arrowToken->kind === TokenKind::QuestionArrowToken ? ast\AST_NULLSAFE_METHOD_CALL : ast\AST_METHOD_CALL,
                        static::phpParserNonValueNodeToAstNode($callable_expression->dereferencableExpression),
                        static::phpParserNodeToAstNode($callable_expression->memberName),
                        $arg_list,
                        $start_line
                    );
                } elseif ($callable_expression instanceof PhpParser\Node\Expression\ScopedPropertyAccessExpression) {  // a::f()
                    return static::astNodeStaticCall(
                        static::phpParserNonValueNodeToAstNode($callable_expression->scopeResolutionQualifier),
                        static::phpParserNodeToAstNode($callable_expression->memberName),
                        $arg_list,
                        $start_line
                    );
                } else {  // f()
                    return static::astNodeCall(
                        static::phpParserNonValueNodeToAstNode($callable_expression),
                        $arg_list,
                        $start_line
                    );
                }
            },
            'Microsoft\PhpParser\Node\Expression\ScriptInclusionExpression' => static function (PhpParser\Node\Expression\ScriptInclusionExpression $n, int $start_line): ast\Node {
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall should not happen
                $flags = static::phpParserIncludeTokenToAstIncludeFlags($n->requireOrIncludeKeyword);
                return new ast\Node(
                    ast\AST_INCLUDE_OR_EVAL,
                    $flags,
                    ['expr' => static::phpParserNodeToAstNode($n->expression)],
                    $start_line
                );
            },
            /**
             * @return ?ast\Node
             */
            'Microsoft\PhpParser\Node\Expression\IssetIntrinsicExpression' => static function (PhpParser\Node\Expression\IssetIntrinsicExpression $n, int $start_line): ?\ast\Node {
                $ast_issets = [];
                foreach ($n->expressions->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        if ($var->kind === TokenKind::CommaToken) {
                            continue;
                        } elseif ($var->length === 0) {
                            continue;
                        }
                    }
                    $ast_issets[] = new ast\Node(ast\AST_ISSET, 0, [
                        'var' => static::phpParserNodeToAstNode($var),
                    ], $start_line);
                }
                $e = $ast_issets[0] ?? null;
                for ($i = 1; $i < \count($ast_issets); $i++) {
                    $right = $ast_issets[$i];
                    $e = new ast\Node(
                        ast\AST_BINARY_OP,
                        flags\BINARY_BOOL_AND,
                        [
                            'left' => $e,
                            'right' => $right,
                        ],
                        // $e should always be set
                        $e->lineno ?? 0
                    );
                }
                return $e;
            },
            'Microsoft\PhpParser\Node\Expression\ArrayCreationExpression' => static function (PhpParser\Node\Expression\ArrayCreationExpression $n, int $start_line): ast\Node {
                return static::phpParserArrayToAstArray($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ListIntrinsicExpression' => static function (PhpParser\Node\Expression\ListIntrinsicExpression $n, int $start_line): ast\Node {
                return static::phpParserListToAstList($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\ObjectCreationExpression' => static function (PhpParser\Node\Expression\ObjectCreationExpression $n, int $start_line): ast\Node {
                $end_line = static::getEndLine($n);
                $class_type_designator = $n->classTypeDesignator;
                if ($class_type_designator instanceof Token && $class_type_designator->kind === TokenKind::ClassKeyword) {
                    // Node of type AST_CLASS
                    $base_class = $n->classBaseClause->baseClass ?? null;
                    $class_node = static::astStmtClass(
                        flags\CLASS_ANONYMOUS,
                        null,
                        static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                        $base_class !== null ? static::phpParserNonValueNodeToAstNode($base_class) : null,
                        $n->classInterfaceClause,
                        static::phpParserStmtlistToAstNode($n->classMembers->classMemberDeclarations ?? [], $start_line, false),
                        $start_line,
                        $end_line,
                        $n->getDocCommentText(),
                        null
                    );
                } else {
                    $class_node = static::phpParserNonValueNodeToAstNode($class_type_designator);
                }
                return new ast\Node(ast\AST_NEW, 0, [
                    'class' => $class_node,
                    'args' => static::phpParserArgListToAstArgList($n->argumentExpressionList, $start_line),
                ], $start_line);
            },
            /** @return mixed */
            'Microsoft\PhpParser\Node\Expression\ParenthesizedExpression' => static function (PhpParser\Node\Expression\ParenthesizedExpression $n, int $_) {
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Expression\PrefixUpdateExpression' => static function (PhpParser\Node\Expression\PrefixUpdateExpression $n, int $start_line): ast\Node {
                $type = $n->incrementOrDecrementOperator->kind === TokenKind::PlusPlusToken ? ast\AST_PRE_INC : ast\AST_PRE_DEC;

                return new ast\Node($type, 0, ['var' => static::phpParserNodeToAstNode($n->operand)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\PostfixUpdateExpression' => static function (PhpParser\Node\Expression\PostfixUpdateExpression $n, int $start_line): ast\Node {
                $type = $n->incrementOrDecrementOperator->kind === TokenKind::PlusPlusToken ? ast\AST_POST_INC : ast\AST_POST_DEC;

                return new ast\Node($type, 0, ['var' => static::phpParserNodeToAstNode($n->operand)], $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\PrintIntrinsicExpression' => static function (PhpParser\Node\Expression\PrintIntrinsicExpression $n, int $start_line): ast\Node {
                $expr_node = static::phpParserNodeToAstNode($n->expression);
                return new ast\Node(
                    ast\AST_PRINT,
                    0,
                    ['expr' => $expr_node],
                    $expr_node->lineno ?? (self::getStartLine($n->expression) ?: $start_line)
                );
            },
            /** @return ?ast\Node */
            'Microsoft\PhpParser\Node\Expression\MemberAccessExpression' => static function (PhpParser\Node\Expression\MemberAccessExpression $n, int $start_line): ?\ast\Node {
                return static::phpParserMemberAccessExpressionToAstProp($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\TernaryExpression' => static function (TernaryExpression $n, int $start_line): ast\Node {
                $n = self::normalizeTernaryExpression($n);
                $is_parenthesized = $n->parent instanceof PhpParser\Node\Expression\ParenthesizedExpression;
                $result = new ast\Node(
                    ast\AST_CONDITIONAL,
                    $is_parenthesized ? ast\flags\PARENTHESIZED_CONDITIONAL : 0,
                    [
                        'cond' => static::phpParserNodeToAstNode($n->condition),
                        'true' => $n->ifExpression !== null ? static::phpParserNodeToAstNode($n->ifExpression) : null,
                        'false' => static::phpParserNodeToAstNode($n->elseExpression),
                    ],
                    $start_line
                );
                if (PHP_VERSION_ID < 70400 && !$is_parenthesized) {
                    // This is a way to indicate that this AST is definitely unparenthesized in cases where the native parser would not provide this information.
                    // @phan-suppress-next-line PhanUndeclaredProperty
                    $result->is_not_parenthesized = true;
                }
                return $result;
            },
            /**
             * @throws InvalidNodeException if the variable would be unanalyzable
             * TODO: Consider ${''} as a placeholder instead?
             */
            'Microsoft\PhpParser\Node\Expression\Variable' => static function (PhpParser\Node\Expression\Variable $n, int $start_line): \ast\Node {
                $name_node = $n->name;
                // Note: there are 2 different ways to handle an Error. 1. Add a placeholder. 2. remove all of the statements in that tree.
                if ($name_node instanceof PhpParser\Node) {
                    $name_node = static::phpParserNodeToAstNode($name_node);
                } elseif ($name_node instanceof Token) {
                    if ($name_node instanceof PhpParser\MissingToken) {
                        if (self::$should_add_placeholders) {
                            $name_node = '__INCOMPLETE_VARIABLE__';
                        } else {
                            throw new InvalidNodeException();
                        }
                    } else {
                        if ($name_node->kind === TokenKind::VariableName) {
                            $name_node = static::variableTokenToString($name_node);
                        } else {
                            $name_node = static::tokenToString($name_node);
                        }
                    }
                }
                return new ast\Node(ast\AST_VAR, 0, ['name' => $name_node], $start_line);
            },
            /**
             * @return ast\Node|int|float|string
             */
            'Microsoft\PhpParser\Node\Expression\BracedExpression' => static function (PhpParser\Node\Expression\BracedExpression $n, int $_) {
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Expression\YieldExpression' => static function (PhpParser\Node\Expression\YieldExpression $n, int $start_line): ast\Node {
                $kind = $n->yieldOrYieldFromKeyword->kind === TokenKind::YieldFromKeyword ? ast\AST_YIELD_FROM : ast\AST_YIELD;

                $array_element = $n->arrayElement;
                $element_value = $array_element->elementValue ?? null;
                // Workaround for <= 0.0.5
                // TODO: Remove workaround?
                $ast_expr = ($element_value !== null && !($element_value instanceof MissingToken)) ? static::phpParserNodeToAstNode($element_value) : null;
                if ($kind === \ast\AST_YIELD) {
                    $element_key = $array_element->elementKey ?? null;
                    $key = $element_key !== null ? static::phpParserNodeToAstNode($element_key) : null;
                    $children = [
                        'value' => $ast_expr,
                        'key' => $key,
                    ];
                    $start_line = $key->lineno ?? $ast_expr->lineno ?? $start_line;
                } else {
                    $children = [
                        'expr' => $ast_expr,
                    ];
                    $start_line = $ast_expr->lineno ?? $start_line;
                }
                return new ast\Node(
                    $kind,
                    0,
                    $children,
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\ReservedWord' => static function (PhpParser\Node\ReservedWord $n, int $start_line): ast\Node {
                return new ast\Node(
                    ast\AST_NAME,
                    flags\NAME_NOT_FQ,
                    ['name' => static::tokenToString($n->children)],
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\QualifiedName' => static function (PhpParser\Node\QualifiedName $n, int $start_line): ast\Node {
                $name_parts = $n->nameParts;
                if (\count($name_parts) === 1) {
                    $part = $name_parts[0];
                    '@phan-var Token $part';
                    $imploded_parts = static::tokenToString($part);
                    if ($part->kind === TokenKind::Name) {
                        if (\preg_match('@^__(LINE|FILE|DIR|FUNCTION|CLASS|TRAIT|METHOD|NAMESPACE)__$@iD', $imploded_parts) > 0) {
                            return new ast\Node(
                                ast\AST_MAGIC_CONST,
                                self::MAGIC_CONST_LOOKUP[\strtoupper($imploded_parts)],
                                [],
                                self::getStartLine($part)
                            );
                        }
                    }
                } else {
                    $imploded_parts = static::phpParserNameToString($n);
                }
                if ($n->globalSpecifier !== null) {
                    $ast_kind = flags\NAME_FQ;
                } elseif (($n->relativeSpecifier->namespaceKeyword ?? null) !== null) {
                    $ast_kind = flags\NAME_RELATIVE;
                } else {
                    $ast_kind = flags\NAME_NOT_FQ;
                }
                return new ast\Node(ast\AST_NAME, $ast_kind, ['name' => $imploded_parts], $start_line);
            },
            'Microsoft\PhpParser\Node\Parameter' => static function (PhpParser\Node\Parameter $n, int $start_line): ast\Node {
                $start_line_token = $n->visibilityToken ?:
                    $n->questionToken ?:
                    $n->typeDeclarationList ?:
                    $n->byRefToken ?:
                    $n->variableName;
                if ($start_line_token) {
                    $start_line = static::getStartLine($start_line_token);
                }
                $type_declaration_list = $n->typeDeclarationList;
                $type_line = $type_declaration_list ? static::getStartLine($type_declaration_list) : $start_line;
                $default = $n->default;
                $default_node = $default !== null ? static::phpParserNodeToAstNode($default) : null;
                return self::astNodeParam(
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    $n->questionToken !== null,
                    self::getParamFlags($n),
                    static::phpParserUnionTypeToAstNode($type_declaration_list, $type_line),
                    static::variableTokenToString($n->variableName),
                    $default_node,
                    $start_line
                );
            },
            /** @return int|float */
            'Microsoft\PhpParser\Node\NumericLiteral' => static function (PhpParser\Node\NumericLiteral $n, int $_) {
                // Support php 7.4 numeric literal separators. Ignore `_`.
                $n = $n->children;
                $text = \str_replace('_', '', static::tokenToString($n));
                if (($n->kind ?? null) === TokenKind::IntegerLiteralToken) {
                    $as_int = \filter_var($text, FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL | FILTER_FLAG_ALLOW_HEX);
                    if ($as_int !== false) {
                        return $as_int;
                    }
                    if (\preg_match('/^0[0-7]+$/D', $text)) {
                        // this is octal - FILTER_VALIDATE_FLOAT would treat it like decimal
                        return \intval($text, 8);
                    }
                }
                if ($text[0] === '0' && !\preg_match('/[.eE]/', $text)) {
                    $c = $text[1];
                    if ($c === 'b' || $c === 'B') {
                        return \bindec($text);
                    }
                    if ($c === 'x' || $c === 'X') {
                        return \hexdec($text);
                    }
                    return \octdec(substr($text, 0, \strcspn($text, '89')));
                }
                return (float)$text;
            },
            /**
             * @return ast\Node|string
             * @throws Exception if the tokens of the string literal are invalid, etc.
             */
            'Microsoft\PhpParser\Node\StringLiteral' => static function (PhpParser\Node\StringLiteral $n, int $start_line) {
                $children = $n->children;
                if ($children instanceof Token) {
                    $inner_node = static::parseQuotedString($n);
                } elseif (\count($children) === 0) {
                    $inner_node = '';
                } elseif (\count($children) === 1 && $children[0] instanceof Token) {
                    $inner_node = static::parseQuotedString($n);
                } else {
                    $inner_node = self::parseMultiPartString($n, $children);
                }
                if ($n->startQuote !== null && $n->startQuote->kind === TokenKind::BacktickToken) {
                    return new ast\Node(ast\AST_SHELL_EXEC, 0, ['expr' => $inner_node], isset($children[0]) ? self::getStartLine($children[0]) : $start_line);
                    // TODO: verify match
                }
                return $inner_node;
            },
            /** @return list<ast\Node|float|int|string> - Can return a node or a scalar, depending on the settings */
            'Microsoft\PhpParser\Node\Statement\CompoundStatementNode' => static function (PhpParser\Node\Statement\CompoundStatementNode $n, int $_) {
                $children = [];
                foreach ($n->statements as $parser_node) {
                    $child_node = static::phpParserNodeToAstNode($parser_node);
                    if (\is_array($child_node)) {
                        // EchoExpression returns multiple children.
                        foreach ($child_node as $child_node_part) {
                            $children[] = $child_node_part;
                        }
                    } elseif (!\is_null($child_node)) {
                        $children[] = $child_node;
                    }
                }
                return $children;
            },
            /**
             * @return int|string|ast\Node|null
             * null if incomplete
             * int|string for no-op scalar statements like `;2;`
             */
            'Microsoft\PhpParser\Node\Statement\ExpressionStatement' => static function (PhpParser\Node\Statement\ExpressionStatement $n, int $_) {
                $expression = $n->expression;
                // tolerant-php-parser uses parseExpression(..., $force=true), which can return an array.
                // It is the only thing that uses $force=true at the time of writing.
                if (!\is_object($expression)) {
                    return null;
                }
                return static::phpParserNodeToAstNode($n->expression);
            },
            'Microsoft\PhpParser\Node\Statement\BreakOrContinueStatement' => static function (PhpParser\Node\Statement\BreakOrContinueStatement $n, int $start_line): ast\Node {
                $kind = $n->breakOrContinueKeyword->kind === TokenKind::ContinueKeyword ? ast\AST_CONTINUE : ast\AST_BREAK;
                $breakout_level = $n->breakoutLevel;
                if ($breakout_level !== null) {
                    $start_line = self::getStartLine($breakout_level);
                    $breakout_level = static::phpParserNodeToAstNode($breakout_level);
                    if (!\is_int($breakout_level)) {
                        $breakout_level = null;
                    }
                }
                return new ast\Node($kind, 0, ['depth' => $breakout_level], $start_line);
            },
            'Microsoft\PhpParser\Node\CatchClause' => static function (PhpParser\Node\CatchClause $n, int $start_line): ast\Node {
                $catch_inner_list = [];
                // @phan-suppress-next-line PhanUndeclaredProperty incorrect phpdoc in tolerant-php-parser 0.1.0
                foreach ($n->qualifiedNameList->children ?? [] as $other_qualified_name) {
                    if ($other_qualified_name instanceof PhpParser\Node\QualifiedName) {
                        $catch_inner_list[] = static::phpParserNonValueNodeToAstNode($other_qualified_name);
                    }
                }
                $catch_list_node = new ast\Node(ast\AST_NAME_LIST, 0, $catch_inner_list, $catch_inner_list[0]->lineno ?? $start_line);
                $variableName = $n->variableName;
                return static::astStmtCatch(
                    $catch_list_node,
                    $variableName !== null ? static::variableTokenToString($variableName) : null,
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
                    static::phpParserStmtlistToAstNode($n->compoundStatement, self::getStartLine($n->compoundStatement) ?: $start_line, false),
                    $variableName !== null ? self::getStartLine($variableName) : $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\HaltCompilerStatement' => static function (PhpParser\Node\Statement\HaltCompilerStatement $n, int $start_line): ast\Node {
                return new ast\Node(ast\AST_HALT_COMPILER, 0, ['offset' => $n->getHaltCompilerOffset()], $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\InterfaceDeclaration' => static function (PhpParser\Node\Statement\InterfaceDeclaration $n, int $start_line): ast\Node {
                if ($n->interfaceKeyword) {
                    $start_line = self::getStartLine($n->interfaceKeyword);
                }
                $end_line = static::getEndLine($n) ?: $start_line;
                return static::astStmtClass(
                    flags\CLASS_INTERFACE,
                    static::tokenToString($n->name),
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    static::interfaceBaseClauseToNode($n->interfaceBaseClause),
                    null,
                    static::phpParserStmtlistToAstNode($n->interfaceMembers->interfaceMemberDeclarations ?? [], $start_line, false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText(),
                    null
                );
            },
            /**
             * @unused-param $start_line
             */
            'Microsoft\PhpParser\Node\Statement\ClassDeclaration' => static function (PhpParser\Node\Statement\ClassDeclaration $n, int $start_line): ast\Node {
                $end_line = static::getEndLine($n);
                $base_class = $n->classBaseClause->baseClass ?? null;
                return static::astStmtClass(
                    static::phpParserClassModifiersToAstClassFlags($n->abstractOrFinalModifier, $n->modifiers),
                    static::tokenToString($n->name),
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    $base_class !== null ? static::phpParserNonValueNodeToAstNode($base_class) : null,
                    $n->classInterfaceClause,
                    static::phpParserStmtlistToAstNode($n->classMembers->classMemberDeclarations ?? [], self::getStartLine($n->classMembers), false),
                    static::getStartLine($n->classKeyword),
                    $end_line,
                    $n->getDocCommentText(),
                    null
                );
            },
            'Microsoft\PhpParser\Node\Statement\TraitDeclaration' => static function (PhpParser\Node\Statement\TraitDeclaration $n, int $start_line): ast\Node {
                if ($n->traitKeyword) {
                    $start_line = self::getStartLine($n->traitKeyword);
                }
                $end_line = static::getEndLine($n) ?: $start_line;
                return static::astStmtClass(
                    flags\CLASS_TRAIT,
                    static::tokenToString($n->name),
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    null,
                    null,
                    static::phpParserStmtlistToAstNode($n->traitMembers->traitMemberDeclarations ?? [], self::getStartLine($n->traitMembers), false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText(),
                    null
                );
            },
            /**
             * @unused-param $start_line
             */
            'Microsoft\PhpParser\Node\Statement\EnumDeclaration' => static function (PhpParser\Node\Statement\EnumDeclaration $n, int $start_line): ast\Node {
                $end_line = static::getEndLine($n);
                return static::astStmtClass(
                    flags\CLASS_ENUM | flags\CLASS_FINAL,
                    static::tokenToString($n->name),
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    null,
                    null,
                    static::phpParserStmtlistToAstNode($n->enumMembers->enumMemberDeclarations ?? [], self::getStartLine($n->enumMembers), false),
                    static::getStartLine($n->enumKeyword),
                    $end_line,
                    $n->getDocCommentText(),
                    self::phpParserTypeToAstNode($n->enumType, $start_line)
                );
            },
            'Microsoft\PhpParser\Node\EnumCaseDeclaration' => static function (PhpParser\Node\EnumCaseDeclaration $n, int $start_line): ast\Node {
                return static::phpParserEnumCaseDeclarationToAstNode($n, $start_line);
            },
            'Microsoft\PhpParser\Node\ClassConstDeclaration' => static function (PhpParser\Node\ClassConstDeclaration $n, int $start_line): ast\Node {
                return static::phpParserClassConstToAstNode($n, $start_line);
            },
            /** @return null - A stub that will be removed by the caller. */
            'Microsoft\PhpParser\Node\MissingMemberDeclaration' => static function (PhpParser\Node\MissingMemberDeclaration $unused_n, int $unused_start_line) {
                // This node type is generated for something that isn't a function/constant/property. e.g. "public example();"
                return null;
            },
            /** @return null - A stub that will be removed by the caller. */
            'Microsoft\PhpParser\Node\MissingDeclaration' => static function (PhpParser\Node\MissingDeclaration $unused_n, int $unused_start_line) {
                // This node type is generated for something that starts with an attribute but isn't a declaration.
                return null;
            },
            /**
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\Node\MethodDeclaration' => static function (PhpParser\Node\MethodDeclaration $n, int $start_line): ast\Node {
                $statements = $n->compoundStatementOrSemicolon;
                if (isset($n->modifiers[0])) {
                    $start_line = self::getStartLine($n->modifiers[0]);
                } elseif ($n->functionKeyword) {
                    $start_line = self::getStartLine($n->functionKeyword);
                }
                $ast_return_type = static::phpParserUnionTypeToAstNode($n->returnTypeList, static::getEndLine($n->returnTypeList) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                $original_method_name = $n->name;
                if (!($original_method_name instanceof Token)) {
                    throw new InvalidNodeException();
                }
                if ($original_method_name->kind === TokenKind::Name) {
                    $method_name = static::tokenToString($original_method_name);
                } else {
                    $method_name = 'placeholder_' . $original_method_name->fullStart;
                }

                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                return static::newAstDecl(
                    ast\AST_METHOD,
                    static::phpParserVisibilityToAstVisibility($n->modifiers) | ($n->byRefToken !== null ? flags\FUNC_RETURNS_REF : 0),
                    [
                        'params' => static::phpParserParamsToAstParams($n->parameters, $start_line),
                        'stmts' => static::phpParserStmtlistToAstNode($statements, self::getStartLine($statements), true),
                        'returnType' => $ast_return_type,
                        'attributes' => static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    ],
                    $start_line,
                    $n->getDocCommentText(),
                    $method_name,
                    static::getEndLine($n),
                    self::nextDeclId()
                );
            },
            'Microsoft\PhpParser\Node\Statement\ConstDeclaration' => static function (PhpParser\Node\Statement\ConstDeclaration $n, int $start_line): ast\Node {
                return static::phpParserConstToAstNode($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\DeclareStatement' => static function (PhpParser\Node\Statement\DeclareStatement $n, int $start_line): ast\Node {
                $doc_comment = $n->getDocCommentText();
                return static::astStmtDeclare(
                    static::phpParserDeclareListToAstDeclares($n, $start_line, $doc_comment),
                    $n->statements !== null ? static::phpParserStmtlistToAstNode($n->statements, $start_line, true) : null,
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\DoStatement' => static function (PhpParser\Node\Statement\DoStatement $n, int $start_line): ast\Node {
                return new ast\Node(
                    ast\AST_DO_WHILE,
                    0,
                    [
                        'stmts' => static::phpParserStmtlistToAstNode($n->statement, $start_line, false),
                        'cond' => static::phpParserNodeToAstNode($n->expression),
                    ],
                    $start_line
                );
            },
            /**
             * @return ast\Node|ast\Node[]
             */
            'Microsoft\PhpParser\Node\Statement\EchoStatement' => static function (PhpParser\Node\Statement\EchoStatement $n, int $start_line) {
                $ast_echos = [];
                foreach ($n->expressions->children ?? [] as $expr) {
                    if ($expr instanceof Token && $expr->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $expr_node = static::phpParserNodeToAstNode($expr);
                    $start_line = ($expr_node->lineno ?? self::getStartLine($expr)) ?: $start_line;
                    $ast_echos[] = new ast\Node(
                        ast\AST_ECHO,
                        0,
                        ['expr' => $expr_node],
                        $start_line
                    );
                }
                return \count($ast_echos) === 1 ? $ast_echos[0] : $ast_echos;
            },
            /**
             * @return ?ast\Node
             */
            'Microsoft\PhpParser\Node\ForeachKey' => static function (PhpParser\Node\ForeachKey $n, int $_): ?\ast\Node {
                $result = static::phpParserNodeToAstNode($n->expression);
                if (!$result instanceof ast\Node) {
                    return null;
                }
                return $result;
            },
            'Microsoft\PhpParser\Node\Statement\ForeachStatement' => static function (PhpParser\Node\Statement\ForeachStatement $n, int $start_line): ast\Node {
                $foreach_value = $n->foreachValue;
                $value = static::phpParserNodeToAstNode($foreach_value->expression);
                if ($foreach_value->ampersand) {
                    $value = new ast\Node(
                        ast\AST_REF,
                        0,
                        ['var' => $value],
                        $value->lineno ?? $start_line
                    );
                }
                $foreach_key = $n->foreachKey;
                return new ast\Node(
                    ast\AST_FOREACH,
                    0,
                    [
                        'expr' => static::phpParserNodeToAstNode($n->forEachCollectionName),
                        'value' => $value,
                        'key' => $foreach_key !== null ? static::phpParserNodeToAstNode($foreach_key) : null,
                        'stmts' => static::phpParserStmtlistToAstNode($n->statements, $start_line, true),
                    ],
                    $start_line
                );
                //return static::phpParserStmtlistToAstNode($n->statements, $start_line);
            },
            'Microsoft\PhpParser\Node\FinallyClause' => static function (PhpParser\Node\FinallyClause $n, int $start_line): ast\Node {
                // @phan-suppress-next-line PhanTypeMismatchReturnNullable return_null_on_empty is false.
                return static::phpParserStmtlistToAstNode($n->compoundStatement, $start_line, false);
            },
            /**
             * @throws InvalidNodeException
             */
            'Microsoft\PhpParser\Node\Statement\FunctionDeclaration' => static function (PhpParser\Node\Statement\FunctionDeclaration $n, int $start_line): ast\Node {
                if ($n->functionKeyword) {
                    $start_line = self::getStartLine($n->functionKeyword);
                }
                $end_line = static::getEndLine($n) ?: $start_line;
                $ast_return_type = static::phpParserUnionTypeToAstNode($n->returnTypeList, static::getEndLine($n->returnTypeList) ?: $start_line);
                if (($ast_return_type->children['name'] ?? null) === '') {
                    $ast_return_type = null;
                }
                if ($n->questionToken !== null && $ast_return_type !== null) {
                    $ast_return_type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $ast_return_type], $start_line);
                }
                $name = $n->name;
                if (!($name instanceof Token)) {
                    throw new InvalidNodeException();
                }

                return static::astDeclFunction(
                    $n->byRefToken !== null,
                    static::tokenToString($name),
                    static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
                    static::phpParserParamsToAstParams($n->parameters, $start_line),
                    $ast_return_type,
                    static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon, self::getStartLine($n->compoundStatementOrSemicolon), false),
                    $start_line,
                    $end_line,
                    $n->getDocCommentText()
                );
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\GlobalDeclaration' => static function (PhpParser\Node\Statement\GlobalDeclaration $n, int $start_line) {
                $global_nodes = [];
                foreach ($n->variableNameList->children ?? [] as $var) {
                    if ($var instanceof Token && $var->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $global_nodes[] = new ast\Node(ast\AST_GLOBAL, 0, ['var' => static::phpParserNodeToAstNode($var)], static::getEndLine($var) ?: $start_line);
                }
                return \count($global_nodes) === 1 ? $global_nodes[0] : $global_nodes;
            },
            'Microsoft\PhpParser\Node\Statement\IfStatementNode' => static function (PhpParser\Node\Statement\IfStatementNode $n, int $start_line): ast\Node {
                return static::phpParserIfStmtToAstIfStmt($n, $start_line);
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\InlineHtml' => static function (PhpParser\Node\Statement\InlineHtml $n, int $start_line) {
                $text = $n->text;
                if ($text === null) {
                    return [];  // For the beginning/end of files
                }
                return new ast\Node(
                    ast\AST_ECHO,
                    0,
                    ['expr' => static::tokenToRawString($n->text)],
                    self::getStartLine($n->text) ?: $start_line
                );
            },
            /** @suppress PhanTypeMismatchArgument TODO: Make ForStatement have more accurate docs? */
            'Microsoft\PhpParser\Node\Statement\ForStatement' => static function (PhpParser\Node\Statement\ForStatement $n, int $start_line): ast\Node {
                return new ast\Node(
                    ast\AST_FOR,
                    0,
                    [
                        'init' => $n->forInitializer !== null ? static::phpParserExprListToExprList($n->forInitializer, $start_line) : null,
                        'cond' => $n->forControl !== null     ? static::phpParserExprListToExprList($n->forControl, $start_line) : null,
                        'loop' => $n->forEndOfLoop !== null   ? static::phpParserExprListToExprList($n->forEndOfLoop, $start_line) : null,
                        'stmts' => static::phpParserStmtlistToAstNode($n->statements, $start_line, true),
                    ],
                    $start_line
                );
            },
            /** @return ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration' => static function (PhpParser\Node\Statement\NamespaceUseDeclaration $n, int $start_line): array {
                $use_clauses = $n->useClauses;
                $results = [];
                $parser_use_kind = $n->functionOrConst->kind ?? null;
                foreach ($use_clauses->children ?? [] as $use_clause) {
                    if (!($use_clause instanceof PhpParser\Node\NamespaceUseClause)) {
                        continue;
                    }
                    $results[] = static::astStmtUseOrGroupUseFromUseClause($use_clause, $parser_use_kind, $start_line);
                }
                return $results;
            },
            'Microsoft\PhpParser\Node\Statement\NamespaceDefinition' => static function (PhpParser\Node\Statement\NamespaceDefinition $n, int $start_line): ast\Node {
                $stmt = $n->compoundStatementOrSemicolon;
                $name_node = $n->name;
                if ($stmt instanceof PhpParser\Node) {
                    $stmts_start_line = self::getStartLine($stmt);
                    $ast_stmt = static::phpParserStmtlistToAstNode($n->compoundStatementOrSemicolon, $stmts_start_line, true);
                    $start_line = $name_node !== null ? self::getStartLine($name_node) : $stmts_start_line;  // imitate php-ast
                } else {
                    $ast_stmt = null;
                }
                return new ast\Node(
                    ast\AST_NAMESPACE,
                    0,
                    [
                        'name' => $name_node !== null ? static::phpParserNameToString($name_node) : null,
                        'stmts' => $ast_stmt,
                    ],
                    $start_line
                );
            },
            /** @return array{} */
            'Microsoft\PhpParser\Node\Statement\EmptyStatement' => static function (PhpParser\Node\Statement\EmptyStatement $unused_node, int $unused_start_line): array {
                // `;;`
                return [];
            },
            'Microsoft\PhpParser\Node\PropertyDeclaration' => static function (PhpParser\Node\PropertyDeclaration $n, int $start_line): ast\Node {
                return static::phpParserPropertyToAstNode($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\ReturnStatement' => static function (PhpParser\Node\Statement\ReturnStatement $n, int $start_line): ast\Node {
                $e = $n->expression;
                $expr_node = $e !== null ? static::phpParserNodeToAstNode($e) : null;
                return new ast\Node(ast\AST_RETURN, 0, ['expr' => $expr_node], $expr_node->lineno ?? $start_line);
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\FunctionStaticDeclaration' => static function (PhpParser\Node\Statement\FunctionStaticDeclaration $n, int $start_line) {
                $static_nodes = [];
                foreach ($n->staticVariableNameList->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        continue;
                    }
                    if (!($var instanceof PhpParser\Node\StaticVariableDeclaration)) {
                        // FIXME error tolerance
                        throw new AssertionError("Expected StaticVariableDeclaration");
                    }

                    $assignment = $var->assignment;
                    $static_nodes[] = new ast\Node(ast\AST_STATIC, 0, [
                        'var' => new ast\Node(ast\AST_VAR, 0, ['name' => static::phpParserNodeToAstNode($var->variableName)], static::getEndLine($var) ?: $start_line),
                        'default' => $assignment !== null ? static::phpParserNodeToAstNode($assignment) : null,
                    ], static::getEndLine($var) ?: $start_line);
                }
                return \count($static_nodes) === 1 ? $static_nodes[0] : $static_nodes;
            },
            'Microsoft\PhpParser\Node\Statement\SwitchStatementNode' => static function (PhpParser\Node\Statement\SwitchStatementNode $n, int $_): ast\Node {
                return static::phpParserSwitchListToAstSwitch($n);
            },
            'Microsoft\PhpParser\Node\Expression\ThrowExpression' => static function (PhpParser\Node\Expression\ThrowExpression $n, int $start_line): ast\Node {
                return static::phpParserThrowToASTThrow($n, $start_line);
            },
            'Microsoft\PhpParser\Node\Expression\MatchExpression' => static function (PhpParser\Node\Expression\MatchExpression $n, int $start_line): ast\Node {
                return self::phpParserMatchToAstMatch($n, $start_line);
            },

            'Microsoft\PhpParser\Node\TraitUseClause' => static function (PhpParser\Node\TraitUseClause $n, int $start_line): ast\Node {
                $clauses_list_node = $n->traitSelectAndAliasClauses;
                if ($clauses_list_node instanceof PhpParser\Node\DelimitedList\TraitSelectOrAliasClauseList) {
                    $adaptations_inner = [];
                    foreach ($clauses_list_node->children as $select_or_alias_clause) {
                        if ($select_or_alias_clause instanceof Token) {
                            continue;
                        }
                        if (!($select_or_alias_clause instanceof PhpParser\Node\TraitSelectOrAliasClause)) {
                            throw new AssertionError("Expected TraitSelectOrAliasClause");
                        }
                        $result = static::phpParserNodeToAstNode($select_or_alias_clause);
                        if ($result instanceof ast\Node) {
                            $adaptations_inner[] = $result;
                        }
                    }
                    $adaptations = new ast\Node(ast\AST_TRAIT_ADAPTATIONS, 0, $adaptations_inner, $adaptations_inner[0]->lineno ?? $start_line);
                } else {
                    $adaptations = null;
                }
                return new ast\Node(
                    ast\AST_USE_TRAIT,
                    0,
                    [
                        'traits' => static::phpParserNameListToAstNameList($n->traitNameList->children ?? [], $start_line),
                        'adaptations' => $adaptations,
                    ],
                    $start_line
                );
            },

            /**
             * @return ?ast\Node
             */
            'Microsoft\PhpParser\Node\TraitSelectOrAliasClause' => static function (PhpParser\Node\TraitSelectOrAliasClause $n, int $start_line): ?\ast\Node {
                // FIXME targetName phpdoc is wrong.
                $name = $n->name;
                if ($n->asOrInsteadOfKeyword->kind === TokenKind::InsteadOfKeyword) {
                    if (!$name instanceof ScopedPropertyAccessExpression) {
                        return null;
                    }
                    $member_name_list = $name->memberName;
                    if ($member_name_list === null) {
                        return null;
                    }

                    $target_name_list = $n->targetNameList->children ?? $n->targetNameList;
                    if (\is_object($member_name_list)) {
                        $member_name_list = [$member_name_list];
                    }
                    // Trait::y insteadof OtherTrait
                    $trait_node = static::phpParserNonValueNodeToAstNode($name->scopeResolutionQualifier);
                    $method_node = static::phpParserNameListToAstNameList($member_name_list, $start_line);
                    $target_node = static::phpParserNameListToAstNameList($target_name_list, $start_line);
                    $outer_method_node = new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                        'class' => $trait_node,
                        'method' => $method_node->children[0]
                    ], $start_line);

                    if (\count($member_name_list) !== 1) {
                        throw new AssertionError("Expected insteadof member_name_list length to be 1");
                    }
                    $children = [
                        'method' => $outer_method_node,
                        'insteadof' => $target_node,
                    ];
                    return new ast\Node(ast\AST_TRAIT_PRECEDENCE, 0, $children, $start_line);
                } else {
                    if ($name instanceof PhpParser\Node\Expression\ScopedPropertyAccessExpression) {
                        $class_node = static::phpParserNonValueNodeToAstNode($name->scopeResolutionQualifier);
                        $method_node = static::phpParserNodeToAstNode($name->memberName);
                    } else {
                        $class_node = null;
                        $method_node = static::phpParserNameToString($name);
                    }
                    $flags = static::phpParserVisibilityToAstVisibility($n->modifiers, false);
                    $target_name = $n->targetNameList;
                    $target_name = $target_name instanceof PhpParser\Node\QualifiedName ? static::phpParserNameToString($target_name) : null;
                    $children = [
                        'method' => new ast\Node(ast\AST_METHOD_REFERENCE, 0, [
                            'class' => $class_node,
                            'method' => $method_node,
                        ], $start_line),
                        'alias' => $target_name,
                    ];

                    return new ast\Node(ast\AST_TRAIT_ALIAS, $flags, $children, $start_line);
                }
            },
            'Microsoft\PhpParser\Node\Statement\TryStatement' => static function (PhpParser\Node\Statement\TryStatement $n, int $start_line): ast\Node {
                $finally_clause = $n->finallyClause;
                return static::astNodeTry(
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
                    static::phpParserStmtlistToAstNode($n->compoundStatement, $start_line, false), // $n->try
                    static::phpParserCatchlistToAstCatchlist($n->catchClauses ?? [], self::getEndLine($n->compoundStatement) ?: $start_line),
                    $finally_clause !== null ? static::phpParserStmtlistToAstNode($finally_clause->compoundStatement, self::getStartLine($finally_clause->compoundStatement), false) : null,
                    $start_line
                );
            },
            /** @return ast\Node|ast\Node[] */
            'Microsoft\PhpParser\Node\Statement\UnsetStatement' => static function (PhpParser\Node\Statement\UnsetStatement $n, int $start_line) {
                $stmts = [];
                foreach ($n->expressions->children ?? [] as $var) {
                    if ($var instanceof Token) {
                        // Skip over ',' and invalid tokens
                        continue;
                    }
                    $stmts[] = new ast\Node(ast\AST_UNSET, 0, ['var' => static::phpParserNodeToAstNode($var)], static::getEndLine($var) ?: $start_line);
                }
                return \count($stmts) === 1 ? $stmts[0] : $stmts;
            },
            'Microsoft\PhpParser\Node\Statement\WhileStatement' => static function (PhpParser\Node\Statement\WhileStatement $n, int $start_line): ast\Node {
                return static::astNodeWhile(
                    static::phpParserNodeToAstNode($n->expression),
                    // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
                    static::phpParserStmtlistToAstNode($n->statements, $start_line, false),
                    $start_line
                );
            },
            'Microsoft\PhpParser\Node\Statement\GotoStatement' => static function (PhpParser\Node\Statement\GotoStatement $n, int $start_line): ast\Node {
                return new ast\Node(ast\AST_GOTO, 0, ['label' => static::tokenToString($n->name)], $start_line);
            },
            'Microsoft\PhpParser\Node\Statement\NamedLabelStatement' => static function (PhpParser\Node\Statement\NamedLabelStatement $n, int $start_line): ast\Node {
                return new ast\Node(ast\AST_LABEL, 0, ['name' => static::tokenToString($n->name)], $start_line);
            },
        ];

        foreach ($closures as $key => $_) {
            if (!(\class_exists($key))) {
                throw new AssertionError("Class $key should exist");
            }
        }
        return $closures;
    }

    /**
     * Overridden in TolerantASTConverterWithNodeMapping
     *
     * @param PhpParser\Node\NamespaceUseClause $use_clause
     * @param ?int $parser_use_kind
     * @param int $start_line
     * @throws InvalidNodeException
     */
    protected static function astStmtUseOrGroupUseFromUseClause(PhpParser\Node\NamespaceUseClause $use_clause, ?int $parser_use_kind, int $start_line): ast\Node
    {
        $namespace_name_node = $use_clause->namespaceName;
        if ($namespace_name_node instanceof PhpParser\Node\QualifiedName) {
            $namespace_name = \rtrim(static::phpParserNameToString($namespace_name_node), '\\');
        } else {
            throw new InvalidNodeException();
        }
        if ($use_clause->groupClauses !== null) {
            return static::astStmtGroupUse(
                $parser_use_kind,  // E.g. kind is FunctionKeyword or ConstKeyword or null
                $namespace_name,
                static::phpParserNamespaceUseListToAstUseList($use_clause->groupClauses->children ?? []),
                $start_line
            );
        } else {
            $alias_token = $use_clause->namespaceAliasingClause->name ?? null;
            $alias = $alias_token !== null ? static::tokenToString($alias_token) : null;
            return static::astStmtUse($parser_use_kind, $namespace_name, $alias, $start_line);
        }
    }

    private static function astNodeTry(
        \ast\Node $try_node,
        ?\ast\Node $catches_node,
        ?\ast\Node $finally_node,
        int $start_line
    ): ast\Node {
        // Return fields of $node->children in the same order as php-ast
        $children = [
            'try' => $try_node,
        ];
        if ($catches_node !== null) {
            $children['catches'] = $catches_node;
        }
        $children['finally'] = $finally_node;
        return new ast\Node(ast\AST_TRY, 0, $children, $start_line);
    }

    private static function astStmtCatch(ast\Node $types, ?string $var, \ast\Node $stmts, int $lineno): ast\Node
    {
        return new ast\Node(
            ast\AST_CATCH,
            0,
            [
                'class' => $types,
                // php 8.0 allows catch statements without variables
                'var' => is_string($var) ? new ast\Node(ast\AST_VAR, 0, ['name' => $var], $lineno) : null,
                'stmts' => $stmts,
            ],
            $lineno
        );
    }

    /**
     * @param PhpParser\Node\CatchClause[] $catches
     */
    private static function phpParserCatchlistToAstCatchlist(array $catches, int $lineno): ast\Node
    {
        $children = [];
        foreach ($catches as $parser_catch) {
            $children[] = static::phpParserNonValueNodeToAstNode($parser_catch);
        }
        return new ast\Node(ast\AST_CATCH_LIST, 0, $children, $lineno);
    }

    /**
     * @param list<Token|PhpParser\Node> $types
     */
    private static function phpParserNameListToAstNameList(array $types, int $line): ast\Node
    {
        $ast_types = [];
        foreach ($types as $type) {
            if ($type instanceof Token && $type->kind === TokenKind::CommaToken) {
                continue;
            }
            $ast_types[] = static::phpParserNonValueNodeToAstNode($type);
        }
        return new ast\Node(ast\AST_NAME_LIST, 0, $ast_types, $line);
    }

    /**
     * @param ast\Node|string|int|float $cond
     */
    private static function astNodeWhile($cond, ast\Node $stmts, int $start_line): ast\Node
    {
        return new ast\Node(
            ast\AST_WHILE,
            0,
            [
                'cond' => $cond,
                'stmts' => $stmts,
            ],
            $start_line
        );
    }

    /**
     * @param ast\Node|string|int|float $var
     * @param ast\Node|string|int|float $expr
     */
    private static function astNodeAssign($var, $expr, int $line, bool $ref): ast\Node
    {
        return new ast\Node(
            $ref ? ast\AST_ASSIGN_REF : ast\AST_ASSIGN,
            0,
            [
                'var'  => $var,
                'expr' => $expr,
            ],
            $line
        );
    }

    /**
     * @throws Error if the kind could not be found
     */
    private static function phpParserIncludeTokenToAstIncludeFlags(Token $type): int
    {
        switch ($type->kind) {
            case TokenKind::IncludeKeyword:
                return flags\EXEC_INCLUDE;
            case TokenKind::IncludeOnceKeyword:
                return flags\EXEC_INCLUDE_ONCE;
            case TokenKind::RequireKeyword:
                return flags\EXEC_REQUIRE;
            case TokenKind::RequireOnceKeyword:
                return flags\EXEC_REQUIRE_ONCE;
            default:
                throw new \Error("Unrecognized PhpParser include/require type");
        }
    }

    /**
     * @param ?(PhpParser\Node\DelimitedList\QualifiedNameList|MissingToken) $types_node
     */
    protected static function phpParserUnionTypeToAstNode(?object $types_node, int $line): ?\ast\Node
    {
        $types = [];
        $is_intersection = false;
        if ($types_node instanceof PhpParser\Node\DelimitedList\QualifiedNameList) {
            foreach ($types_node->children as $child) {
                if ($child instanceof Token) {
                    if ($child->kind === TokenKind::BarToken) {
                        continue;
                    }
                    if ($child->kind === TokenKind::AmpersandToken) {
                        $is_intersection = true;
                        continue;
                    }
                }
                $result = static::phpParserTypeToAstNode($child, static::getEndLine($child) ?: $line);
                if ($result) {
                    $types[] = $result;
                }
            }
        }
        $n = \count($types);
        if ($n === 0) {
            return null;
        } elseif ($n === 1) {
            return $types[0];
        }
        return new ast\Node($is_intersection ? ast\AST_TYPE_INTERSECTION : ast\AST_TYPE_UNION, 0, $types, $types[0]->lineno);
    }

    protected static function phpParserParenthesizedIntersectionTypeToAstNode(PhpParser\Node\ParenthesizedIntersectionType $n, int $start_line): ?ast\Node {
        $children = [];
        foreach ($n->children->children ?? [] as $c) {
            if ($c instanceof Token && $c->kind === TokenKind::AmpersandToken) {
                continue;
            }
            $result = self::phpParserTypeToAstNode($c, $start_line);
            if ($result) {
                $children[] = $result;
            }
        }
        if (count($children) <= 1) {
            return $children[0] ?? null;
        }
        return new ast\Node(ast\AST_TYPE_INTERSECTION, 0, $children, $start_line);
    }

    /**
     * @param PhpParser\Node\QualifiedName|Token|null $type
     */
    protected static function phpParserTypeToAstNode($type, int $line): ?ast\Node
    {
        if (\is_null($type)) {
            return null;
        }
        $original_type = $type;
        if ($type instanceof PhpParser\Node\QualifiedName) {
            $type = static::phpParserNameToString($type);
        } elseif ($type instanceof PhpParser\Node\ParenthesizedIntersectionType) {
            return static::phpParserParenthesizedIntersectionTypeToAstNode($type, $line);
        } elseif ($type instanceof Token) {
            if (get_class($type) !== Token::class) {
                return null;
            }
            $type = static::tokenToString($type);
        }
        if (\is_string($type)) {
            switch (\strtolower($type)) {
                case 'null':
                    $flags = flags\TYPE_NULL;
                    break;
                case 'bool':
                    $flags = flags\TYPE_BOOL;
                    break;
                case 'int':
                    $flags = flags\TYPE_LONG;
                    break;
                case 'float':
                    $flags = flags\TYPE_DOUBLE;
                    break;
                case 'string':
                    $flags = flags\TYPE_STRING;
                    break;
                case 'array':
                    $flags = flags\TYPE_ARRAY;
                    break;
                case 'object':
                    $flags = flags\TYPE_OBJECT;
                    break;
                case 'callable':
                    $flags = flags\TYPE_CALLABLE;
                    break;
                case 'void':
                    $flags = flags\TYPE_VOID;
                    break;
                case 'iterable':
                    $flags = flags\TYPE_ITERABLE;
                    break;
                case 'false':
                    $flags = flags\TYPE_FALSE;
                    break;
                case 'true':
                    $flags = flags\TYPE_TRUE;
                    break;
                case 'static':
                    $flags = flags\TYPE_STATIC;
                    break;
                case 'never':
                    $flags = flags\TYPE_NEVER;
                    break;
                default:
                    // TODO: Refactor this into a function accepting a QualifiedName
                    if ($original_type instanceof PhpParser\Node\QualifiedName) {
                        if ($original_type->globalSpecifier !== null) {
                            $ast_kind = flags\NAME_FQ;
                        } elseif (($original_type->relativeSpecifier->namespaceKeyword ?? null) !== null) {
                            $ast_kind = flags\NAME_RELATIVE;
                        } else {
                            $ast_kind = flags\NAME_NOT_FQ;
                        }
                    } else {
                        $ast_kind = flags\NAME_NOT_FQ;
                    }
                    return new ast\Node(
                        ast\AST_NAME,
                        $ast_kind,
                        ['name' => $type],
                        $line
                    );
            }
            return new ast\Node(ast\AST_TYPE, $flags, [], $line);
        }
        return static::phpParserNodeToAstNode($type);
    }

    /**
     * @param ?ast\Node $type
     * @param string $name
     * @param ?ast\Node|?int|?string|?float $default
     */
    private static function astNodeParam(?ast\Node $attributes, bool $is_nullable, int $flags, ?\ast\Node $type, string $name, $default, int $line): ast\Node
    {
        if ($is_nullable) {
            $type = new ast\Node(
                ast\AST_NULLABLE_TYPE,
                0,
                ['type' => $type],
                $line
            );
        }
        return new ast\Node(
            ast\AST_PARAM,
            $flags,
            [
                'type' => $type,
                'name' => $name,
                'default' => $default,
                'attributes' => $attributes,
                'docComment' => null,
            ],
            $line
        );
    }

    private const VISIBILITY_FLAG_MAP = [
        TokenKind::PublicKeyword    => ast\flags\MODIFIER_PUBLIC,
        TokenKind::ProtectedKeyword => ast\flags\MODIFIER_PROTECTED,
        TokenKind::PrivateKeyword   => ast\flags\MODIFIER_PRIVATE,
        TokenKind::ReadonlyKeyword   => ast\flags\MODIFIER_READONLY,
    ];

    private static function getParamFlags(PhpParser\Node\Parameter $n): int
    {
        $flags = ($n->byRefToken ? flags\PARAM_REF : 0) | ($n->dotDotDotToken ? flags\PARAM_VARIADIC : 0);
        if ($visibilityToken = $n->visibilityToken) {
            $flags |= (self::VISIBILITY_FLAG_MAP[$visibilityToken->kind] ?? 0);
        }
        foreach ($n->modifiers ?? [] as $visibilityToken) {
            if ($visibilityToken instanceof Token) {
                $flags |= (self::VISIBILITY_FLAG_MAP[$visibilityToken->kind] ?? 0);
            }
        }
        return $flags;
    }

    private static function phpParserParamsToAstParams(?\Microsoft\PhpParser\Node\DelimitedList\ParameterDeclarationList $parser_params, int $line): ast\Node
    {
        $new_params = [];
        foreach ($parser_params->children ?? [] as $parser_node) {
            if ($parser_node instanceof Token) {
                continue;
            }
            $new_params[] = static::phpParserNodeToAstNode($parser_node);
        }
        $result = new ast\Node(
            ast\AST_PARAM_LIST,
            0,
            $new_params,
            $new_params[0]->lineno ?? $line
        );
        if (($parser_node->kind ?? null) === TokenKind::CommaToken) {
            // @phan-suppress-next-line PhanUndeclaredProperty
            $result->polyfill_has_trailing_comma = true;
        }
        return $result;
    }

    /**
     * @param PhpParser\Node|PhpParser\Token $parser_node
     * @suppress UnusedSuppression, TypeMismatchProperty
     * @internal
     */
    final public static function astStub(object $parser_node): ast\Node
    {
        // Debugging code.
        if (\getenv(self::ENV_AST_THROW_INVALID)) {
            // @phan-suppress-next-line PhanThrowTypeAbsent only throws for debugging
            throw new \Error("TODO:" . get_class($parser_node));
        }

        $node = new ast\Node();
        $node->kind = "TODO:" . get_class($parser_node);
        $node->flags = 0;
        $node->lineno = self::getStartLine($parser_node);
        $node->children = [];
        return $node;
    }

    private static function phpParserClosureUsesToAstClosureUses(
        ?\Microsoft\PhpParser\Node\DelimitedList\UseVariableNameList $uses,
        int $line
    ): ?\ast\Node {
        $children = $uses->children ?? [];
        if (count($children) === 0) {
            return null;
        }
        $ast_uses = [];
        foreach ($children as $use) {
            if ($use instanceof Token) {
                continue;
            }
            if (!($use instanceof PhpParser\Node\UseVariableName)) {
                throw new AssertionError("Expected UseVariableName");
            }
            $ast_uses[] = new ast\Node(ast\AST_CLOSURE_VAR, $use->byRef ? ast\flags\CLOSURE_USE_REF : 0, ['name' => static::tokenToString($use->variableName)], self::getStartLine($use));
        }
        $result = new ast\Node(ast\AST_CLOSURE_USES, 0, $ast_uses, $ast_uses[0]->lineno ?? $line);
        if (($use->kind ?? null) === TokenKind::CommaToken) {
            // @phan-suppress-next-line PhanUndeclaredProperty
            $result->polyfill_has_trailing_comma = true;
        }
        return $result;
    }

    private static function resolveDocCommentForClosure(PhpParser\Node\Expression $node): ?string
    {
        $doc_comment = $node->getDocCommentText();
        if (\Phan\Library\StringUtil::isNonZeroLengthString($doc_comment)) {
            return $doc_comment;
        }
        for ($prev_node = $node; $node = $node->parent; $prev_node = $node) {
            if ($node instanceof PhpParser\Node\Expression\AssignmentExpression ||
                $node instanceof PhpParser\Node\Expression\ParenthesizedExpression ||
                $node instanceof PhpParser\Node\ArrayElement ||
                $node instanceof PhpParser\Node\Statement\ReturnStatement) {
                $doc_comment = $node->getDocCommentText();
                if (\Phan\Library\StringUtil::isNonZeroLengthString($doc_comment)) {
                    return $doc_comment;
                }
                continue;
            }
            if ($node instanceof PhpParser\Node\Expression\ArgumentExpression) {
                // Skip ArgumentExpression and the PhpParser\Node\DelimitedList\ArgumentExpressionList
                // to get to the CallExpression
                // @phan-suppress-next-line PhanPossiblyUndeclaredProperty
                $node = $node->parent->parent;
                // fall through
            }
            if ($node instanceof PhpParser\Node\Expression\MemberAccessExpression) {
                // E.g. ((Closure)->bindTo())
                if ($prev_node !== $node->dereferencableExpression) {
                    return null;
                }
                $doc_comment = $node->getDocCommentText();
                if (is_string($doc_comment)) {
                    return $doc_comment;
                }
                continue;
            }
            if ($node instanceof PhpParser\Node\Expression\CallExpression) {
                if ($prev_node === $node->callableExpression) {
                    $doc_comment = $node->getDocCommentText();
                    if (is_string($doc_comment)) {
                        return $doc_comment;
                    }
                    continue;
                }
                if ($node->callableExpression instanceof PhpParser\Node\Expression\AnonymousFunctionCreationExpression) {
                    return null;
                }
                $found = false;
                foreach ($node->argumentExpressionList->children ?? [] as $argument_expression) {
                    if (!($argument_expression instanceof PhpParser\Node\Expression\ArgumentExpression)) {
                        continue;
                    }
                    $expression = $argument_expression->expression;
                    if ($expression === $prev_node) {
                        $found = true;
                        $doc_comment = $node->getDocCommentText();
                        if (is_string($doc_comment)) {
                            return $doc_comment;
                        }
                        break;
                    }
                    if (!($expression instanceof PhpParser\Node)) {
                        continue;
                    }
                    if ($expression instanceof PhpParser\Node\ConstElement || $expression instanceof PhpParser\Node\NumericLiteral || $expression instanceof PhpParser\Node\StringLiteral) {
                        continue;
                    }
                    return null;
                }

                if ($found) {
                    continue;
                }
            }
            break;
        }
        return null;
    }

    private static function astDeclClosure(
        bool $by_ref,
        bool $static,
        ?ast\Node $attributes,
        ast\Node $params,
        ?\ast\Node $uses,
        ast\Node $stmts,
        ?\ast\Node $return_type,
        int $start_line,
        int $end_line,
        ?string $doc_comment
    ): ast\Node {
        return static::newAstDecl(
            ast\AST_CLOSURE,
            ($by_ref ? flags\FUNC_RETURNS_REF : 0) | ($static ? flags\MODIFIER_STATIC : 0),
            [
                'params' => $params,
                'uses' => $uses,
                'stmts' => $stmts,
                'returnType' => $return_type,
                'attributes' => $attributes,  // TODO implement
            ],
            $start_line,
            $doc_comment,
            '{closure}',
            $end_line,
            self::nextDeclId()
        );
    }

    /**
     * @param ?ast\Node $return_type
     * @param ?ast\Node $stmts (TODO: create empty statement list instead of null)
     * @param ?string $doc_comment
     */
    private static function astDeclFunction(
        bool $by_ref,
        string $name,
        ?\ast\Node $attributes,
        ast\Node $params,
        ?\ast\Node $return_type,
        ?\ast\Node $stmts,
        int $line,
        int $end_line,
        ?string $doc_comment
    ): ast\Node {
        return static::newAstDecl(
            ast\AST_FUNC_DECL,
            $by_ref ? flags\FUNC_RETURNS_REF : 0,
            [
                'params' => $params,
                'stmts' => $stmts,
                'returnType' => $return_type,
                'attributes' => $attributes,
            ],
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    private static function phpParserClassModifierToAstClassFlags(?Token $modifier): int
    {
        if ($modifier === null) {
            return 0;
        }
        switch ($modifier->kind) {
            case TokenKind::AbstractKeyword:
                return flags\CLASS_ABSTRACT;
            case TokenKind::FinalKeyword:
                return flags\CLASS_FINAL;
            case TokenKind::ReadonlyKeyword:
                return flags\CLASS_READONLY;
            default:
                throw new InvalidArgumentException("Unexpected kind '" . Token::getTokenKindNameFromValue($modifier->kind) . "'");
        }
    }
    /**
     * @param ?Token $modifier
     * @param list<Token> $modifiers
     * @throws InvalidArgumentException if the class flags were unexpected
     */
    private static function phpParserClassModifiersToAstClassFlags(?Token $modifier, array $modifiers): int
    {
        $flags = self::phpParserClassModifierToAstClassFlags($modifier);
        foreach ($modifiers as $extra_modifier) {
            $flags |= self::phpParserClassModifierToAstClassFlags($extra_modifier);
        }
        return $flags;
    }

    private static function interfaceBaseClauseToNode(?\Microsoft\PhpParser\Node\InterfaceBaseClause $node): ?\ast\Node
    {
        if (!$node instanceof PhpParser\Node\InterfaceBaseClause) {
            // TODO: real placeholder?
            return null;
        }

        $interface_extends_name_list = [];
        foreach ($node->interfaceNameList->children ?? [] as $implement) {
            if ($implement instanceof Token && $implement->kind === TokenKind::CommaToken) {
                continue;
            }
            $interface_name_node = static::phpParserNonValueNodeToAstNode($implement);
            if (!$interface_name_node instanceof ast\Node) {
                throw new AssertionError("Expected valid node for interfaces inherited by class");
            }
            $interface_extends_name_list[] = $interface_name_node;
        }
        if (\count($interface_extends_name_list) === 0) {
            return null;
        }
        return new ast\Node(ast\AST_NAME_LIST, 0, $interface_extends_name_list, $interface_extends_name_list[0]->lineno);
    }

    private static function astStmtClass(
        int $flags,
        ?string $name,
        ?ast\Node $attributes,
        ?ast\Node $extends,
        ?PhpParser\Node\ClassInterfaceClause $implements,
        ?ast\Node $stmts,
        int $line,
        int $end_line,
        ?string $doc_comment,
        ?ast\Node $type
    ): ast\Node {

        // NOTE: `null` would be an anonymous class.
        // the empty string is a missing string we pretend is an anonymous class
        // so that Phan won't throw an UnanalyzableException during the analysis phase
        if ($name === null || $name === '') {
            $flags |= flags\CLASS_ANONYMOUS;
        }

        if (($flags & flags\CLASS_INTERFACE) > 0) {
            $children = [
                'extends'    => null,
                'implements' => $extends,
                'stmts'      => $stmts,
                'attributes' => $attributes,
                'type'       => null,
            ];
        } else {
            if ($implements !== null) {
                $ast_implements_inner = [];
                foreach ($implements->interfaceNameList->children ?? [] as $implement) {
                    // TODO: simplify?
                    if ($implement instanceof Token && $implement->kind === TokenKind::CommaToken) {
                        continue;
                    }
                    $implement_node = static::phpParserNonValueNodeToAstNode($implement);
                    if (!$implement_node instanceof ast\Node) {
                        continue;
                    }
                    $ast_implements_inner[] = $implement_node;
                }
                if (\count($ast_implements_inner) > 0) {
                    $ast_implements = new ast\Node(ast\AST_NAME_LIST, 0, $ast_implements_inner, $ast_implements_inner[0]->lineno);
                } else {
                    $ast_implements = null;
                }
            } else {
                $ast_implements = null;
            }
            $children = [
                'extends'    => $extends,
                'implements' => $ast_implements,
                'stmts'      => $stmts,
                'attributes' => $attributes,
                'type'       => $type,
            ];
        }

        return static::newAstDecl(
            ast\AST_CLASS,
            $flags,
            $children,
            $line,
            $doc_comment,
            $name,
            $end_line,
            self::nextDeclId()
        );
    }

    private static function phpParserArgListToAstArgList(?\Microsoft\PhpParser\Node\DelimitedList\ArgumentExpressionList $args, int $line): ast\Node
    {
        $ast_args = [];
        foreach ($args->children ?? [] as $arg) {
            if ($arg instanceof Token && $arg->kind === TokenKind::CommaToken) {
                continue;
            }
            $ast_args[] = static::phpParserNodeToAstNode($arg);
        }
        $result = new ast\Node(ast\AST_ARG_LIST, 0, $ast_args, $args ? self::getStartLine($args) : $line);
        if (($arg->kind ?? null) === TokenKind::CommaToken) {
            // NOTE: This is deliberately using a dynamic property instead of a flag because other applications may use flags
            // @phan-suppress-next-line PhanUndeclaredProperty
            $result->polyfill_has_trailing_comma = true;
        }
        return $result;
    }

    private static function phpParserThrowToASTThrow(PhpParser\Node\Expression\ThrowExpression $n, int $start_line): ast\Node
    {
        $expression = $n->expression;
        if (!$expression) {
            throw new InvalidNodeException();
        }
        $expr_node = static::phpParserNodeToAstNode($expression);
        return new ast\Node(
            ast\AST_THROW,
            0,
            ['expr' => $expr_node],
            $expr_node->lineno ?? $start_line
        );
    }

    protected static function phpParserMatchToAstMatch(PhpParser\Node\Expression\MatchExpression $n, int $start_line): ast\Node
    {
        $expression = $n->expression;
        if (!$expression) {
            throw new InvalidNodeException();
        }
        return new ast\Node(
            ast\AST_MATCH,
            0,
            [
                'cond' => static::phpParserNodeToAstNode($expression),
                'stmts' => static::phpParserMatchArmListToAstMatchArmList($n->arms, $start_line),
            ],
            $start_line
        );
    }

    protected static function phpParserMatchArmListToAstMatchArmList(?\Microsoft\PhpParser\Node\DelimitedList\MatchExpressionArmList $arms, int $start_line): ast\Node
    {
        $ast_arms = [];
        foreach ($arms->children ?? [] as $arm) {
            if (!$arm instanceof PhpParser\Node\MatchArm) {
                continue;
            }
            try {
                $ast_arms[] = static::phpParserMatchArmToAstMatchArm($arm);
            } catch (InvalidNodeException $_) {
                continue;
            }
        }
        return new ast\Node(ast\AST_MATCH_ARM_LIST, 0, $ast_arms, $arms ? self::getStartLine($arms) : $start_line);
    }

    private static function phpParserMatchConditionListToAstNode(?PhpParser\Node\DelimitedList\MatchArmConditionList $condition_list): ?ast\Node
    {
        if (!$condition_list) {
            throw new InvalidNodeException();
        }
        $conditions = [];
        foreach ($condition_list->children ?? [] as $phpparser_condition) {
            if ($phpparser_condition instanceof Token) {
                switch ($phpparser_condition->kind) {
                    case TokenKind::DefaultKeyword:
                        return null;
                    case TokenKind::CommaToken:
                        continue 2;
                }
            }
            $conditions[] = static::phpParserNodeToAstNode($phpparser_condition);
        }
        if (!$conditions) {
            throw new InvalidNodeException();
        }
        return new ast\Node(ast\AST_EXPR_LIST, 0, $conditions, self::getStartLine($condition_list));
    }

    private static function phpParserMatchArmToAstMatchArm(PhpParser\Node\MatchArm $arm): ast\Node
    {
        return new ast\Node(
            ast\AST_MATCH_ARM,
            0,
            [
                'cond' => static::phpParserMatchConditionListToAstNode($arm->conditionList),
                'expr' => static::phpParserNodeToAstNode($arm->body),
            ],
            self::getStartLine($arm)
        );
    }

    /**
     * @param ?int $kind
     * @throws InvalidArgumentException if the token kind was somehow invalid
     */
    private static function phpParserNamespaceUseKindToASTUseFlags(?int $kind): int
    {
        switch ($kind ?? 0) {
            case TokenKind::FunctionKeyword:
                return flags\USE_FUNCTION;
            case TokenKind::ConstKeyword:
                return flags\USE_CONST;
            case 0:
                return flags\USE_NORMAL;
            default:
                throw new \InvalidArgumentException("Unexpected kind '" . Token::getTokenKindNameFromValue($kind ?? 0) . "'");
        }
    }

    /**
     * @param Token[]|PhpParser\Node\NamespaceUseGroupClause[]|PhpParser\Node[] $uses
     * @return ast\Node[]
     */
    private static function phpParserNamespaceUseListToAstUseList(array $uses): array
    {
        $ast_uses = [];
        foreach ($uses as $use_clause) {
            if (!($use_clause instanceof PhpParser\Node\NamespaceUseGroupClause)) {
                continue;
            }
            $raw_namespace_name = $use_clause->namespaceName;
            if (!$raw_namespace_name instanceof PhpParser\Node\QualifiedName) {
                // Invalid AST, ignore. We should have already warned about the syntax
                continue;
            }
            // ast doesn't fill in an alias if it's identical to the real name,
            // but phpParser does?
            $namespace_name = \rtrim(static::phpParserNameToString($raw_namespace_name), '\\');
            $alias_token = $use_clause->namespaceAliasingClause->name ?? null;
            $alias = $alias_token !== null ? static::tokenToString($alias_token) : null;

            $ast_uses[] = new ast\Node(
                ast\AST_USE_ELEM,
                static::phpParserNamespaceUseKindToASTUseFlags($use_clause->functionOrConst->kind ?? 0),
                [
                    'name' => $namespace_name,
                    'alias' => $alias !== $namespace_name ? $alias : null,
                ],
                self::getStartLine($use_clause)
            );
        }
        return $ast_uses;
    }

    private static function astStmtUse(?int $type, string $name, ?string $alias, int $line): ast\Node
    {
        $use_inner = new ast\Node(ast\AST_USE_ELEM, 0, ['name' => $name, 'alias' => $alias], $line);
        return new ast\Node(
            ast\AST_USE,
            static::phpParserNamespaceUseKindToASTUseFlags($type),
            [$use_inner],
            $line
        );
    }

    /**
     * @param ?int $type
     * @param ?string $prefix
     * @param list<ast\Node> $uses
     * @suppress PhanPossiblyUndeclaredProperty $use should always be a node
     */
    private static function astStmtGroupUse(?int $type, ?string $prefix, array $uses, int $line): ast\Node
    {
        $flags = static::phpParserNamespaceUseKindToASTUseFlags($type);
        $uses = new ast\Node(ast\AST_USE, 0, $uses, $line);
        if ($flags === flags\USE_NORMAL) {
            foreach ($uses->children as $use) {
                if ($use->flags !== 0) {
                    $flags = 0;
                    break;
                }
            }
        } else {
            foreach ($uses->children as $use) {
                if ($use->flags === flags\USE_NORMAL) {
                    $use->flags = 0;
                }
            }
        }

        return new ast\Node(
            ast\AST_GROUP_USE,
            $flags,
            [
                'prefix' => $prefix,
                'uses' => $uses,
            ],
            $line
        );
    }

    /**
     * @param ast\Node|string|int|float|null $cond (null for else statements)
     * @param ast\Node $stmts
     * @param int $line
     */
    private static function astIfElem($cond, \ast\Node $stmts, int $line): ast\Node
    {
        return new ast\Node(ast\AST_IF_ELEM, 0, ['cond' => $cond, 'stmts' => $stmts], $line);
    }

    private static function phpParserSwitchListToAstSwitch(PhpParser\Node\Statement\SwitchStatementNode $node): ast\Node
    {
        $stmts = [];
        $node_line = static::getStartLine($node);
        foreach ($node->caseStatements as $case) {
            if (!($case instanceof PhpParser\Node\CaseStatementNode)) {
                continue;
            }
            $case_line = static::getStartLine($case);
            $stmts[] = new ast\Node(
                ast\AST_SWITCH_CASE,
                0,
                [
                    'cond' => $case->expression !== null ? static::phpParserNodeToAstNode($case->expression) : null,
                    'stmts' => static::phpParserStmtlistToAstNode($case->statementList, $case_line, false),
                ],
                $case_line
            );
        }
        return new ast\Node(ast\AST_SWITCH, 0, [
            'cond' => static::phpParserNodeToAstNode($node->expression),
            'stmts' => new ast\Node(ast\AST_SWITCH_LIST, 0, $stmts, $stmts[0]->lineno ?? $node_line),
        ], $node_line);
    }

    /**
     * @param PhpParser\Node[]|PhpParser\Node|Token $stmts
     */
    private static function getStartLineOfStatementOrStatements($stmts): int
    {
        if (is_array($stmts)) {
            return isset($stmts[0]) ? self::getStartLine($stmts[0]) : 0;
        }
        return self::getStartLine($stmts);
    }

    private static function phpParserIfStmtToAstIfStmt(PhpParser\Node\Statement\IfStatementNode $node, int $start_line): ast\Node
    {
        $if_elem_expr = static::phpParserNodeToAstNode($node->expression);
        $if_elem = static::astIfElem(
            $if_elem_expr,
            // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
            static::phpParserStmtlistToAstNode(
                $node->statements,
                self::getStartLineOfStatementOrStatements($node->statements) ?: $start_line,
                false
            ),
            $if_elem_expr->lineno ?? (self::getStartLine($node->expression) ?: $start_line)
        );
        $if_elems = [$if_elem];
        foreach ($node->elseIfClauses as $else_if) {
            $else_if_node = static::phpParserNodeToAstNode($else_if->expression);
            $if_elem_line = $else_if_node->lineno ?? (self::getStartLine($else_if->expression) ?: $start_line);
            $if_elem = static::astIfElem(
                $else_if_node,
                // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
                static::phpParserStmtlistToAstNode(
                    $else_if->statements,
                    self::getStartLineOfStatementOrStatements($else_if->statements)
                ),
                $if_elem_line
            );
            $if_elems[] = $if_elem;
        }
        $parser_else_node = $node->elseClause;
        if ($parser_else_node) {
            $parser_else_line = self::getStartLineOfStatementOrStatements($parser_else_node->statements);
            $if_elems[] = static::astIfElem(
                null,
                // @phan-suppress-next-line PhanTypeMismatchArgumentNullable return_null_on_empty is false.
                static::phpParserStmtlistToAstNode($parser_else_node->statements, $parser_else_line, false),
                $parser_else_line
            );
        }
        return new ast\Node(ast\AST_IF, 0, $if_elems, $if_elems[0]->lineno);
    }

    /**
     * @return ast\Node|string|int|float
     */
    private static function astNodeBinaryop(int $flags, PhpParser\Node\Expression\BinaryExpression $n, int $start_line)
    {
        try {
            $left_node = static::phpParserNodeToAstNode($n->leftOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $left_node = static::newPlaceholderExpression($n->leftOperand);
            } else {
                // convert `;$b ^;` to `;$b;`
                return static::phpParserNodeToAstNode($n->rightOperand);
            }
        }
        try {
            $right_node = static::phpParserNodeToAstNode($n->rightOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $right_node = static::newPlaceholderExpression($n->rightOperand);
            } else {
                // convert `;^ $b;` to `;$b;`
                return $left_node;
            }
        }
        if (PHP_VERSION_ID >= 80200) {
            if ($flags === ast\flags\BINARY_CONCAT && is_string($left_node) && is_string($right_node)) {
                return $left_node . $right_node;
            }
        }

        return new ast\Node(
            ast\AST_BINARY_OP,
            $flags,
            [
                'left' => $left_node,
                'right' => $right_node,
            ],
            $start_line
        );
    }

    /**
     * Binary assignment operation such as `+=`
     *
     * @return ast\Node|string|int|float
     * (Can return non-Node for an invalid AST if the right-hand is a scalar)
     */
    private static function astNodeAssignop(int $flags, PhpParser\Node\Expression\BinaryExpression $n, int $start_line)
    {
        try {
            $var_node = static::phpParserNodeToAstNode($n->leftOperand);
        } catch (InvalidNodeException $_) {
            if (self::$should_add_placeholders) {
                $var_node = new ast\Node(ast\AST_VAR, 0, ['name' => '__INCOMPLETE_VARIABLE__'], $start_line);
            } else {
                // convert `;= $b;` to `;$b;`
                return static::phpParserNodeToAstNode($n->rightOperand);
            }
        }
        $expr_node = static::phpParserNodeToAstNode($n->rightOperand);
        return new ast\Node(
            ast\AST_ASSIGN_OP,
            $flags,
            [
                'var' => $var_node,
                'expr' => $expr_node,
            ],
            $start_line
        );
    }

    /**
     * @param PhpParser\Node\Expression\AssignmentExpression|PhpParser\Node\Expression\Variable $n
     * @param ?string $doc_comment
     * @throws InvalidNodeException if the type can't be converted to a valid AST
     * @throws InvalidArgumentException if the passed in class is completely unexpected
     */
    private static function phpParserPropelemToAstPropelem($n, ?string $doc_comment): ast\Node
    {
        if ($n instanceof PhpParser\Node\Expression\AssignmentExpression) {
            $name_node = $n->leftOperand;
            if (!($name_node instanceof PhpParser\Node\Expression\Variable)) {
                throw new InvalidNodeException();
            }
            $children = [
                'name' => static::phpParserNodeToAstNode($name_node->name),
                'default' => $n->rightOperand ? static::phpParserNodeToAstNode($n->rightOperand) : null,
            ];
        } elseif ($n instanceof PhpParser\Node\Expression\Variable) {
            $name = $n->name;
            if (!($name instanceof Token) || !$name->length) {
                throw new InvalidNodeException();
            }
            $children = [
                'name' => static::tokenToString($name),
                'default' => null,
            ];
        } else {
            // @phan-suppress-next-line PhanThrowTypeMismatchForCall debugDumpNodeOrToken can throw
            throw new \InvalidArgumentException("Unexpected class for property element: Expected Variable or AssignmentExpression, got: " . static::debugDumpNodeOrToken($n));
        }

        $start_line = self::getStartLine($n);

        $children['docComment'] = static::extractPhpdocComment($n) ?? $doc_comment;
        return new ast\Node(ast\AST_PROP_ELEM, 0, $children, $start_line);
    }

    private static function phpParserConstelemToAstConstelem(PhpParser\Node\ConstElement $n, ?string $doc_comment): ast\Node
    {
        $start_line = self::getStartLine($n);
        $children = [
            'name' => static::variableTokenToString($n->name),
            'value' => static::phpParserNodeToAstNode($n->assignment),
        ];

        $children['docComment'] = static::extractPhpdocComment($n) ?? $doc_comment;
        return new ast\Node(ast\AST_CONST_ELEM, 0, $children, $start_line);
    }

    /**
     * @param Token[] $visibility
     * @throws RuntimeException if a visibility token was unexpected
     */
    private static function phpParserVisibilityToAstVisibility(array $visibility, bool $automatically_add_public = true): int
    {
        $ast_visibility = 0;
        foreach ($visibility as $token) {
            switch ($token->kind) {
                case TokenKind::VarKeyword:
                    $ast_visibility |= flags\MODIFIER_PUBLIC;
                    break;
                case TokenKind::PublicKeyword:
                    $ast_visibility |= flags\MODIFIER_PUBLIC;
                    break;
                case TokenKind::ProtectedKeyword:
                    $ast_visibility |= flags\MODIFIER_PROTECTED;
                    break;
                case TokenKind::PrivateKeyword:
                    $ast_visibility |= flags\MODIFIER_PRIVATE;
                    break;
                case TokenKind::StaticKeyword:
                    $ast_visibility |= flags\MODIFIER_STATIC;
                    break;
                case TokenKind::AbstractKeyword:
                    $ast_visibility |= flags\MODIFIER_ABSTRACT;
                    break;
                case TokenKind::FinalKeyword:
                    $ast_visibility |= flags\MODIFIER_FINAL;
                    break;
                case TokenKind::ReadonlyKeyword:
                    $ast_visibility |= flags\MODIFIER_READONLY;
                    break;
                default:
                    throw new \RuntimeException("Unexpected visibility modifier '" . Token::getTokenKindNameFromValue($token->kind) . "'");
            }
        }
        if ($automatically_add_public && !($ast_visibility & (flags\MODIFIER_PUBLIC | flags\MODIFIER_PROTECTED | flags\MODIFIER_PRIVATE))) {
            $ast_visibility |= flags\MODIFIER_PUBLIC;
        }
        return $ast_visibility;
    }

    private static function phpParserPropertyToAstNode(PhpParser\Node\PropertyDeclaration $n, int $start_line): ast\Node
    {
        $prop_elems = [];
        $doc_comment = $n->getDocCommentText();

        foreach ($n->propertyElements->children ?? [] as $i => $prop) {
            if ($prop instanceof Token) {
                continue;
            }
            // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType casting to a more specific node
            $prop_elems[] = static::phpParserPropelemToAstPropelem($prop, $i === 0 ? $doc_comment : null);
        }
        $flags = static::phpParserVisibilityToAstVisibility($n->modifiers, false);

        $line = $prop_elems[0]->lineno ?? (self::getStartLine($n) ?: $start_line);
        $prop_decl = new ast\Node(ast\AST_PROP_DECL, 0, $prop_elems, $line);
        $type_line = static::getEndLine($n->typeDeclarationList) ?: $start_line;

        $type = static::phpParserUnionTypeToAstNode($n->typeDeclarationList, $type_line);
        if ($n->questionToken !== null && $type !== null) {
            $type = new ast\Node(ast\AST_NULLABLE_TYPE, 0, ['type' => $type], $start_line);
        }
        return new ast\Node(ast\AST_PROP_GROUP, $flags, [
            'type' => $type,
            'props' => $prop_decl,
            'attributes' => static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
        ], $line);
    }

    private static function phpParserClassConstToAstNode(PhpParser\Node\ClassConstDeclaration $n, int $start_line): ast\Node
    {
        $const_elems = [];
        $doc_comment = $n->getDocCommentText();
        foreach ($n->constElements->children ?? [] as $i => $const_elem) {
            if ($const_elem instanceof Token) {
                continue;
            }
            // @phan-suppress-next-line PhanTypeMismatchArgumentSuperType casting to a more specific node
            $const_elems[] = static::phpParserConstelemToAstConstelem($const_elem, $i === 0 ? $doc_comment : null);
        }
        $flags = static::phpParserVisibilityToAstVisibility($n->modifiers);
        $const_start_line = $const_elems[0]->lineno ?? $start_line;
        $const_list_node = new ast\Node(ast\AST_CLASS_CONST_DECL, 0, $const_elems, $const_start_line);
        return new ast\Node(
            ast\AST_CLASS_CONST_GROUP,
            $flags,
            [
                'const' => $const_list_node,
                'attributes' => static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
            ],
            $const_start_line
        );
    }

    /**
     * @suppress PhanTypeMismatchArgument
     */
    private static function phpParserEnumCaseDeclarationToAstNode(PhpParser\Node\EnumCaseDeclaration $n, int $start_line): ast\Node
    {
        $assignment = $n->assignment;
        $children = [
            'name' => static::variableTokenToString($n->name),
            'expr' => $assignment !== null ? static::phpParserNodeToAstNode($assignment) : null,
            'docComment' => static::extractPhpdocComment($n),
            'attributes' => static::phpParserAttributeGroupsToAstAttributeList($n->attributes),
        ];

        return new ast\Node(ast\AST_ENUM_CASE, 0, $children, $start_line);
    }

    /**
     * @throws InvalidNodeException
     */
    private static function phpParserConstToAstNode(PhpParser\Node\Statement\ConstDeclaration $n, int $start_line): ast\Node
    {
        $const_elems = [];
        $doc_comment = $n->getDocCommentText();
        foreach ($n->constElements->children ?? [] as $i => $prop) {
            if ($prop instanceof Token) {
                continue;
            }
            if (!($prop instanceof PhpParser\Node\ConstElement)) {
                throw new InvalidNodeException();
            }
            $const_elems[] = static::phpParserConstelemToAstConstelem($prop, $i === 0 ? $doc_comment : null);
        }

        return new ast\Node(ast\AST_CONST_DECL, 0, $const_elems, $const_elems[0]->lineno ?? $start_line);
    }

    private static function phpParserDeclareListToAstDeclares(PhpParser\Node\Statement\DeclareStatement $declareStatement, int $start_line, ?string $first_doc_comment): ast\Node
    {
        $ast_declare_elements = [];
        foreach ($declareStatement->declareDirectiveList->children ?? [] as $other_declare) {
            if ($other_declare instanceof PhpParser\Node\DeclareDirective) {
                $ast_declare_elements[] = self::phpParserDeclareDirectiveToAstNode($other_declare, $first_doc_comment);
            }
        }
        if (!$ast_declare_elements) {
            throw new InvalidNodeException();
        }
        return new ast\Node(ast\AST_CONST_DECL, 0, $ast_declare_elements, $start_line);
    }

    private static function phpParserDeclareDirectiveToAstNode(PhpParser\Node\DeclareDirective $declare, ?string $first_doc_comment): ast\Node
    {
        $children = [
            'name' => static::tokenToString($declare->name),
            'value' => static::tokenToScalar($declare->literal),
        ];
        $doc_comment = static::extractPhpdocComment($declare) ?? $first_doc_comment;
        // $first_doc_comment = null;
        $children['docComment'] = $doc_comment;
        return new ast\Node(ast\AST_CONST_ELEM, 0, $children, self::getStartLine($declare));
    }

    private static function astStmtDeclare(ast\Node $declares, ?\ast\Node $stmts, int $start_line): ast\Node
    {
        $children = [
            'declares' => $declares,
            'stmts' => $stmts,
        ];
        return new ast\Node(ast\AST_DECLARE, 0, $children, $start_line);
    }

    /**
     * @param string|ast\Node $expr
     * @param ast\Node $args
     */
    private static function astNodeCall($expr, \ast\Node $args, int $start_line): ast\Node
    {
        if (\is_string($expr)) {
            if (substr($expr, 0, 1) === '\\') {
                $expr = substr($expr, 1);
            }
            $expr = new ast\Node(ast\AST_NAME, flags\NAME_FQ, ['name' => $expr], $start_line);
        }
        return new ast\Node(ast\AST_CALL, 0, ['expr' => $expr, 'args' => $args], $start_line);
    }

    /**
     * @param ast\Node|string $expr (can parse non-nodes, but they'd cause runtime errors)
     * @param ast\Node|string $method
     */
    private static function astNodeMethodCall(int $kind, $expr, $method, ast\Node $args, int $start_line): ast\Node
    {
        return new ast\Node($kind, 0, ['expr' => $expr, 'method' => $method, 'args' => $args], $start_line);
    }

    /**
     * @param ast\Node|string $class
     * @param ast\Node|string $method
     */
    private static function astNodeStaticCall($class, $method, ast\Node $args, int $start_line): ast\Node
    {
        // TODO: is this applicable?
        if (\is_string($class)) {
            if (substr($class, 0, 1) === '\\') {
                $class = substr($class, 1);
            }
            $class = new ast\Node(ast\AST_NAME, flags\NAME_FQ, ['name' => $class], $start_line);
        }
        return new ast\Node(ast\AST_STATIC_CALL, 0, ['class' => $class, 'method' => $method, 'args' => $args], $start_line);
    }

    /**
     * TODO: Get rid of this function?
     * @param string|PhpParser\Node|null|array $comments
     * @return ?string the doc comment, or null
     */
    private static function extractPhpdocComment($comments): ?string
    {
        if (\is_string($comments)) {
            return $comments;
        }
        if ($comments instanceof PhpParser\Node) {
            // TODO: Extract only the substring with doc comment text?
            return $comments->getDocCommentText() ?: null;
        }
        return null;
        // TODO: Could extract comments from elsewhere
        /*
        if ($comments === null) {
            return null;
        }
        if (!(\is_array($comments))) {
            throw new AssertionError("Expected an array of comments");
        }
        if (\count($comments) === 0) {
            return null;
        }
         */
    }

    private static function phpParserListToAstList(PhpParser\Node\Expression\ListIntrinsicExpression $n, int $start_line): ast\Node
    {
        $ast_items = [];
        $prev_was_element = false;
        foreach ($n->listElements->children ?? [] as $item) {
            if ($item instanceof Token) {
                if (!$prev_was_element) {
                    $ast_items[] = null;
                    continue;
                }
                $prev_was_element = false;
                continue;
            } else {
                $prev_was_element = true;
            }
            if (!($item instanceof PhpParser\Node\ArrayElement)) {
                throw new AssertionError("Expected ArrayElement");
            }
            $element_key = $item->elementKey;
            $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, 0, [
                'value' => static::phpParserNodeToAstNode($item->elementValue),
                'key' => $element_key !== null ? static::phpParserNodeToAstNode($element_key) : null,
            ], self::getStartLine($item));
        }
        if (self::$php_version_id_parsing < 70100 && \count($ast_items) === 0) {
            $ast_items[] = null;
        }
        return new ast\Node(ast\AST_ARRAY, flags\ARRAY_SYNTAX_LIST, $ast_items, $start_line);
    }

    private static function phpParserArrayToAstArray(PhpParser\Node\Expression\ArrayCreationExpression $n, int $start_line): ast\Node
    {
        $ast_items = [];
        $prev_was_element = false;
        foreach ($n->arrayElements->children ?? [] as $item) {
            if ($item instanceof Token) {
                if (!$prev_was_element) {
                    $ast_items[] = null;
                    continue;
                }
                $prev_was_element = false;
                continue;
            } else {
                $prev_was_element = true;
            }
            if (!($item instanceof PhpParser\Node\ArrayElement)) {
                throw new AssertionError("Expected ArrayElement");
            }
            if ($item->dotDotDot) {
                $ast_items[] = new ast\Node(ast\AST_UNPACK, 0, [
                    'expr' => static::phpParserNodeToAstNode($item->elementValue),
                ], self::getStartLine($item));
                continue;
            }
            $flags = $item->byRef ? flags\ARRAY_ELEM_REF : 0;
            $element_key = $item->elementKey;
            $ast_items[] = new ast\Node(ast\AST_ARRAY_ELEM, $flags, [
                'value' => static::phpParserNodeToAstNode($item->elementValue),
                'key' => $element_key !== null ? static::phpParserNodeToAstNode($element_key) : null,
            ], self::getStartLine($item));
        }
        if (self::$php_version_id_parsing < 70100) {
            $flags = 0;
        } else {
            $kind = $n->openParenOrBracket->kind;
            if ($kind === TokenKind::OpenBracketToken) {
                $flags = flags\ARRAY_SYNTAX_SHORT;
            } else {
                $flags = flags\ARRAY_SYNTAX_LONG;
            }
        }
        // Workaround for ast line choice
        return new ast\Node(ast\AST_ARRAY, $flags, $ast_items, $ast_items[0]->lineno ?? $start_line);
    }

    /**
     * @throws InvalidNodeException if the member name could not be converted
     *
     * (and various other exceptions)
     */
    private static function phpParserMemberAccessExpressionToAstProp(PhpParser\Node\Expression\MemberAccessExpression $n, int $start_line): \ast\Node
    {
        // TODO: Check for incomplete tokens?
        $member_name = $n->memberName;
        try {
            $name = static::phpParserNodeToAstNode($member_name);  // complex expression
        } catch (InvalidNodeException $e) {
            if (self::$should_add_placeholders) {
                $name = self::INCOMPLETE_PROPERTY;
            } else {
                throw $e;
            }
        }
        return new ast\Node(
            $n->arrowToken->kind === TokenKind::QuestionArrowToken ? ast\AST_NULLSAFE_PROP : ast\AST_PROP,
            0,
            [
                'expr'  => static::phpParserNodeToAstNode($n->dereferencableExpression),
                'prop'  => $name,  // ast\Node|string
            ],
            $start_line
        );
    }

    /**
     * @return int|string|float|bool|null
     */
    private static function tokenToScalar(Token $n)
    {
        $str = static::tokenToString($n);
        $int = \filter_var($str, FILTER_VALIDATE_INT);
        if ($int !== false) {
            return $int;
        }
        $float = \filter_var($str, FILTER_VALIDATE_FLOAT);
        if ($float !== false) {
            return $float;
        }

        return StringUtil::parse($str);
    }

    /**
     * @throws Exception if node is invalid
     */
    private static function parseQuotedString(PhpParser\Node\StringLiteral $n): string
    {
        $start = $n->getStartPosition();
        $text = (string)substr(self::$file_contents, $start, $n->getEndPosition() - $start);
        return StringUtil::parse($text);
    }

    /**
     * @suppress PhanPartialTypeMismatchArgumentInternal hopefully in range
     */
    private static function variableTokenToString(Token $n): string
    {
        return \ltrim(\trim($n->getText(self::$file_contents)), '$');
    }

    /**
     * @suppress PhanPartialTypeMismatchReturn this is in bounds and $file_contents is a string
     */
    private static function tokenToRawString(Token $n): string
    {
        return $n->getText(self::$file_contents);
    }

    /** @internal */
    private const MAGIC_CONST_LOOKUP = [
        '__LINE__' => flags\MAGIC_LINE,
        '__FILE__' => flags\MAGIC_FILE,
        '__DIR__' => flags\MAGIC_DIR,
        '__NAMESPACE__' => flags\MAGIC_NAMESPACE,
        '__FUNCTION__' => flags\MAGIC_FUNCTION,
        '__METHOD__' => flags\MAGIC_METHOD,
        '__CLASS__' => flags\MAGIC_CLASS,
        '__TRAIT__' => flags\MAGIC_TRAIT,
    ];

    // FIXME don't use in places expecting non-strings.
    /**
     * @phan-suppress PhanPartialTypeMismatchArgumentInternal hopefully in range
     */
    private static function tokenToString(Token $n): string
    {
        $result = \trim($n->getText(self::$file_contents));
        $kind = $n->kind;
        if ($kind === TokenKind::VariableName) {
            return \trim($result, '$');
        }
        return $result;
    }

    /**
     * @param PhpParser\Node\Expression|PhpParser\Node\QualifiedName|Token $scope_resolution_qualifier
     */
    private static function phpParserClassConstFetchToAstClassConstFetch($scope_resolution_qualifier, string $name, int $start_line): ast\Node
    {
        if (\strcasecmp($name, 'class') === 0) {
            $class_node = static::phpParserNonValueNodeToAstNode($scope_resolution_qualifier);
            if (!$class_node instanceof ast\Node) {
                // e.g. (0)::class
                $class_node = new ast\Node(ast\AST_NAME, ast\flags\NAME_FQ, ['name' => $class_node], $start_line);
            }
            return new ast\Node(ast\AST_CLASS_NAME, 0, [
                'class' => $class_node,
            ], $start_line);
        }
        return new ast\Node(ast\AST_CLASS_CONST, 0, [
            'class' => static::phpParserNonValueNodeToAstNode($scope_resolution_qualifier),
            'const' => $name,
        ], $start_line);
    }

    /**
     * @throws InvalidNodeException if the qualified type name could not be converted to a valid php-ast type name
     */
    private static function phpParserNameToString(PhpParser\Node\QualifiedName $name): string
    {
        $name_parts = $name->nameParts;
        // TODO: Handle error case (can there be missing parts?)
        $result = '';
        foreach ($name_parts as $part) {
            $part_as_string = static::tokenToString($part);
            if ($part_as_string !== '') {
                $result .= \trim($part_as_string);
            }
        }
        $result = \rtrim(\preg_replace('/\\\\{2,}/', '\\', $result), '\\');
        if ($result === '') {
            // Would lead to "The name cannot be empty" when parsing
            throw new InvalidNodeException();
        }
        return $result;
    }

    /**
     * @param array<mixed,ast\Node|string|int|float|null> $children
     */
    private static function newAstDecl(int $kind, int $flags, array $children, int $lineno, ?string $doc_comment = null, ?string $name = null, int $end_lineno = 0, int $decl_id = -1): ast\Node
    {
        $decl_children = [];
        $decl_children['name'] = $name;
        $decl_children['docComment'] = $doc_comment;
        $decl_children += $children;
        if ($decl_id >= 0) {
            $decl_children['__declId'] = $decl_id;
        }
        $node = new ast\Node($kind, $flags, $decl_children, $lineno);
        $node->endLineno = $end_lineno;
        return $node;
    }

    private static function nextDeclId(): int
    {
        return self::$decl_id++;
    }

    /** @param PhpParser\Node|Token $n the node or token to convert to a placeholder */
    private static function newPlaceholderExpression($n): ast\Node
    {
        $start_line = self::getStartLine($n);
        $name_node = new ast\Node(ast\AST_NAME, flags\NAME_FQ, ['name' => '__INCOMPLETE_EXPR__'], $start_line);
        return new ast\Node(ast\AST_CONST, 0, ['name' => $name_node], $start_line);
    }

    /**
     * @param PhpParser\Node[]|PhpParser\Token[] $children $children
     */
    private static function parseMultiPartString(PhpParser\Node\StringLiteral $n, array $children): ast\Node
    {
        if ($n->startQuote->length >= 3) {
            return self::parseMultiPartHeredoc($n, $children);
        }
        return self::parseMultiPartRegularString($n, $children);
    }

    /**
     * @param PhpParser\Node[]|PhpParser\Token[] $children $children
     */
    private static function parseMultiPartRegularString(PhpParser\Node\StringLiteral $n, array $children): ast\Node
    {
        $inner_node_parts = [];
        $start_quote_text = static::tokenToString($n->startQuote);
        $end_quote_text = $n->endQuote->getText(self::$file_contents);
        $deprecated = false;

        foreach ($children as $part) {
            if ($part instanceof PhpParser\Node) {
                $node = static::phpParserNodeToAstNode($part);
                if ($deprecated) {
                    self::setDeprecatedEncapsVar($node);
                    $deprecated = false;
                }
                $inner_node_parts[] = $node;
            } else {
                $kind = $part->kind;
                switch ($kind) {
                    case TokenKind::DollarOpenBraceToken:
                        $deprecated = true;
                    case TokenKind::OpenBraceDollarToken:
                    case TokenKind::OpenBraceToken:
                    case TokenKind::CloseBraceToken:
                        continue 2;
                }
                // ($part->kind === TokenKind::EncapsedAndWhitespace)
                $raw_string = static::tokenToRawString($part);
                if (\strlen($start_quote_text) > 1) {
                    // I guess it depends on what's before it.
                    // TODO: Use a correct heuristic instead
                    $raw_string = "\n$raw_string\n";
                }

                // Pass in '"\\n"' and get "\n" (somewhat inefficient)
                $represented_string = StringUtil::parse($start_quote_text . $raw_string . $end_quote_text);
                $inner_node_parts[] = $represented_string;
            }
        }
        return new ast\Node(ast\AST_ENCAPS_LIST, 0, $inner_node_parts, self::getStartLine($children[0]));
    }

    /**
     * @param ast\Node|string|int|float|null $node
     */
    private static function setDeprecatedEncapsVar($node): void
    {
        if ($node instanceof ast\Node && \in_array($node->kind, [ast\AST_VAR, ast\AST_DIM], true)) {
            if (PHP_VERSION_ID >= 80200) {
                // Make flags identical to native ast version for unit tests.
                // PHP 8.2 deprecated `"${...}"` string encapsulation syntax in favor of `"{$...}"`
                $node->flags |= $node->kind === ast\AST_VAR && ($node->children['var']->kind ?? null) === ast\AST_VAR
                    ? ast\flags\ENCAPS_VAR_DOLLAR_CURLY_VAR_VAR
                    : ast\flags\ENCAPS_VAR_DOLLAR_CURLY;
            }
            // @phan-suppress-next-line PhanUndeclaredProperty
            $node->is_deprecated_encaps_var = true;
        }
    }
    /**
     * @param PhpParser\Node[]|PhpParser\Token[] $children $children
     */
    private static function parseMultiPartHeredoc(PhpParser\Node\StringLiteral $n, array $children): ast\Node
    {
        $inner_node_parts = [];
        $end_of_start_quote = self::$file_contents[$n->startQuote->start + $n->startQuote->length - 1] ?? 0;
        $end_quote_text = $n->endQuote->getText(self::$file_contents);

        $spaces = \strspn($end_quote_text, " \t");
        $raw_spaces = substr($end_quote_text, 0, $spaces);
        $deprecated = false;

        foreach ($children as $i => $part) {
            if ($part instanceof PhpParser\Node) {
                $node = static::phpParserNodeToAstNode($part);
                if ($deprecated) {
                    self::setDeprecatedEncapsVar($node);
                    $deprecated = false;
                }
                $inner_node_parts[] = $node;
                continue;
            }
            $kind = $part->kind;
            switch ($kind) {
                case TokenKind::DollarOpenBraceToken:
                    $deprecated = true;
                case TokenKind::OpenBraceDollarToken:
                case TokenKind::OpenBraceToken:
                case TokenKind::CloseBraceToken:
                    continue 2;
            }

            // ($part->kind === TokenKind::EncapsedAndWhitespace)
            $raw_string = static::tokenToRawString($part);
            if ($i > 0) {
                $raw_string = $raw_spaces . $raw_string;
            }

            $represented_string = $spaces > 0 ? \preg_replace("/^" . $raw_spaces . "/m", '', $raw_string) : $raw_string;
            if ($end_of_start_quote !== "'") {
                $represented_string = StringUtil::parseEscapeSequences($represented_string, null);
            }
            $inner_node_parts[] = $represented_string;
        }
        $i = \count($inner_node_parts) - 1;
        $s = $inner_node_parts[$i];
        if (\is_string($s)) {
            $s = substr($s, 0, -1);
            // On Windows, the "\r" must also be removed from the last line of the heredoc
            if (substr($s, -1) === "\r") {
                $s = substr($s, 0, -1);
            }
            $inner_node_parts[$i] = $s;
        }

        return new ast\Node(ast\AST_ENCAPS_LIST, 0, $inner_node_parts, self::getStartLine($children[0]));
    }

    /**
     * Gets a string based on environment details that could affect parsing
     */
    private static function getEnvironmentDetails(): string
    {
        static $details = null;
        if ($details === null) {
            $details = \sha1(var_export([
                \PHP_VERSION,
                \PHP_BINARY,
                self::getDevelopmentBuildDate(),
                \phpversion('ast'),
                \ini_get('short_open_tag'),
                \sha1((string)\file_get_contents(__DIR__ . '/ast_shim.php')),
                class_exists(CLI::class) ? CLI::getDevelopmentVersionId() : 'unknown'
            ], true));
        }
        return $details;
    }

    /**
     * For development PHP versions such as 8.0.0-dev, use the build date as part of the cache key to invalidate cached ASTs when this gets rebuilt.
     * @suppress PhanImpossibleTypeComparison, PhanRedundantCondition, PhanImpossibleCondition, PhanSuspiciousValueComparison Phan evaluates the strpos to a constant, so this is either impossible or redundant
     */
    private static function getDevelopmentBuildDate(): ?string
    {
        if (\strpos(\PHP_VERSION, '-dev') === false) {
            return null;
        }
        \ob_start();
        \phpinfo(\INFO_GENERAL);
        $contents = (string)\ob_get_clean();
        \preg_match('/^Build Date.*=>\s*(.+)$/m', $contents, $matches);
        return $matches[1] ?? 'unknown';
    }

    /**
     * @return ?string - null if this should not be cached
     */
    public function generateCacheKey(string $file_contents, int $version): ?string
    {
        $details = var_export([
            \sha1($file_contents),
            $version,
            self::getEnvironmentDetails(),
            $this->instance_should_add_placeholders,
        ], true);
        return \sha1($details);
    }

    private static function normalizeTernaryExpression(TernaryExpression $n): TernaryExpression
    {
        $else = $n->elseExpression;
        if (!($else instanceof TernaryExpression)) {
            return $n;
        }
        // The else expression is an unparenthesized ternary expression. Rearrange the parts.
        // (Convert a ? b : (c ? d : e) to (a ? b : c) ? d : e)
        $inner_left = clone($n);
        // @phan-suppress-next-line PhanPartialTypeMismatchProperty pretty much all expressions can be tokens, type is incorrect
        $inner_left->elseExpression = $else->condition;
        $outer = clone($else);
        $outer->condition = $inner_left;
        return $outer;
    }
}
