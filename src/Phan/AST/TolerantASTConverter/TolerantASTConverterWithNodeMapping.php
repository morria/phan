<?php

declare(strict_types=1);

namespace Phan\AST\TolerantASTConverter;

use AssertionError;
use ast;
use Closure;
use InvalidArgumentException;
use Microsoft\PhpParser;
use Microsoft\PhpParser\Diagnostic;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phan\Library\Cache;
use Throwable;

use function is_string;
use function preg_match;

/**
 * This is a subclass of TolerantASTConverter
 * that maps the original AST to the corresponding generated ast\Node for a single selected location.
 *
 * This is used with "Go to definition" requests, completion requests, hover requests, etc.
 *
 * (This lets you know the byte offset of a given node and how long that node is)
 *
 * Workflow:
 *
 * 1. A request is received for finding the type definition of the ast\Node at byte offset 100 in a given file
 * 2. Phan will figure out which PhpParser\Token that is referring to.
 *
 *    If this is a property, method invocation, constant, etc.,
 *    this will refer to the property access (Not the name), constant access, etc.
 *
 *    This is done via iterating over the tokens, finding the token that contains the offset,
 *    then walking back up to the parent
 * 3. Then, Phan will use $node_mapping to update the corresponding AST node
 *
 * @phan-file-suppress PhanUndeclaredProperty deliberately adding dynamic property
 *
 * The logging to STDERR can be uncommented if you have issues debugging why
 * Phan can't locate a given node's definition.
 */
final class TolerantASTConverterWithNodeMapping extends TolerantASTConverter
{
    use TolerantASTConverterTrait;

    /**
     * @var PhpParser\Node|Token|null
     * This is the closest node or token from tolerant-php-parser
     * (among the nodes being parsed **that will have a corresponding ast\Node be created**)
     *
     * TODO: If this is null, then just use TolerantASTConverter's node generation logic to be a bit faster
     */
    private static $closest_node_or_token;

    /**
     * @var ?Token
     * This is the closest node or token from tolerant-php-parser
     * (among the nodes being parsed **that will have a corresponding ast\Node be created**)
     *
     * (duplicated to be accessed by static methods, for performance)
     */
    private static $closest_node_or_token_symbol;

    /**
     * @var int the byte offset we are looking for, to mark the corresponding Node as within the selected location.
     * (duplicated to be accessed by static methods, for performance)
     */
    private static $desired_byte_offset;

    /** @var int the byte offset we are looking for, to mark the corresponding Node as within the selected location */
    private $instance_desired_byte_offset;

    /**
     * @var ?Closure(ast\Node):void This is optional. If it is set, this is invoked on the Node we marked.
     * Currently, this is used to add plugin methods at runtime (limited to what is needed to handle that node's kind)
     *
     * (duplicated to be accessed by static methods, for performance)
     */
    private static $handle_selected_node;

    /**
     * @var ?Closure(ast\Node):void This is optional. If it is set, this is invoked on the Node we marked.
     * Currently, this is used to add plugin methods at runtime (limited to what is needed to handle that node's kind)
     */
    private $instance_handle_selected_node;

    /**
     * @param int $desired_byte_offset the byte offset of the cursor
     * @param ?Closure(ast\Node):void $handle_selected_node this can be passed in.
     *                      If a node corresponding to a reference was found, then this closure will be invoked once with that node.
     */
    public function __construct(int $desired_byte_offset, ?Closure $handle_selected_node = null)
    {
        $this->instance_desired_byte_offset = $desired_byte_offset;
        $this->instance_handle_selected_node = $handle_selected_node;
    }

    /**
     * @param Diagnostic[] &$errors @phan-output-reference
     * @unused-param $cache
     * @throws InvalidArgumentException for invalid $version
     * @throws Throwable (after logging) if anything is thrown by the parser
     */
    public function parseCodeAsPHPAST(string $file_contents, int $version, array &$errors = [], ?Cache $cache = null): \ast\Node
    {
        // Force the byte offset to be within the
        $byte_offset = \max(0, \min(\strlen($file_contents), $this->instance_desired_byte_offset));
        self::$desired_byte_offset = $byte_offset;
        self::$handle_selected_node = $this->instance_handle_selected_node;

        if (!\in_array($version, self::SUPPORTED_AST_VERSIONS, true)) {
            throw new InvalidArgumentException(\sprintf("Unexpected version: want %s, got %d", \implode(', ', self::SUPPORTED_AST_VERSIONS), $version));
        }

        // Aside: this can be implemented as a stub.
        try {
            $parser_node = static::phpParserParse($file_contents, $errors);
            self::findNodeAtOffset($parser_node, $byte_offset);
            // phpcs:ignore Generic.Files.LineLength.MaxExceeded
            // fwrite(STDERR, "Seeking node: " . json_encode(self::$closest_node_or_token, JSON_PRETTY_PRINT) . "nearby: " . json_encode(self::$closest_node_or_token_symbol, JSON_PRETTY_PRINT) . "\n");
            return $this->phpParserToPhpast($parser_node, $version, $file_contents);
        } catch (Throwable $e) {
            // fprintf(STDERR, "saw exception: %s\n", $e->getMessage());
            throw $e;
        } finally {
            self::$closest_node_or_token = null;
            self::$closest_node_or_token_symbol = null;
        }
    }

    /**
     * @unused-param $file_contents
     * @unused-param $version
     * @return ?string - null if this should not be cached
     */
    public function generateCacheKey(string $file_contents, int $version): ?string
    {
        return null;
    }

    /**
     * Records the closest node or token to the given offset.
     * Heuristics are used to ensure that this can map to an ast\Node.
     * TODO: Finish implementing
     */
    private static function findNodeAtOffset(PhpParser\Node $parser_node, int $offset): void
    {
        self::$closest_node_or_token = null;
        self::$closest_node_or_token_symbol = null;
        // fprintf(STDERR, "Seeking offset %d\n", $offset);
        self::findNodeAtOffsetRecursive($parser_node, $offset);
    }

    /**
     * We use a blacklist because there are more many more tokens we want to use the parent for.
     * For example, when navigating to class names in comments, the comment can be prior to pretty much any token (e.g. AmpersandToken, PublicKeyword, etc.)
     */
    private const KINDS_TO_NOT_RETURN_PARENT = [
        TokenKind::QualifiedName => true,
    ];

    /**
     * @param PhpParser\Node $parser_node
     * @return bool|PhpParser\Node|PhpParser\Token (Returns $parser_node if that node was what the cursor is pointing directly to)
     */
    private static function findNodeAtOffsetRecursive(\Microsoft\PhpParser\Node $parser_node, int $offset)
    {
        foreach ($parser_node->getChildNodesAndTokens() as $key => $node_or_token) {
            if ($node_or_token instanceof Token) {
                // fprintf(
                //     STDERR,
                //     "Scanning over Token %s (fullStart=%d) %d-%d for offset=%d\n",
                //     Token::getTokenKindNameFromValue($node_or_token->kind),
                //     $node_or_token->fullStart,
                //     $node_or_token->start,
                //     $node_or_token->getEndPosition(),
                //     $offset
                // );
                if ($node_or_token->getEndPosition() > $offset) {
                    if ($node_or_token->start > $offset) {
                        if ($node_or_token->fullStart <= $offset) {
                            // The cursor falls within the leading comments (doc comment or otherwise)
                            // of this token.
                            self::$closest_node_or_token_symbol = $node_or_token;
                        } elseif (self::$closest_node_or_token_symbol === null) {
                            // The cursor is hovering over whitespace.
                            // Give up.
                            return true;
                        }
                    }
                    if (!\in_array($node_or_token->kind, self::KINDS_TO_NOT_RETURN_PARENT, true)) {
                        // We want the parent of a Name, e.g. a class
                        self::$closest_node_or_token = $parser_node;
                        // fwrite(STDERR, "Found node: " . json_encode($parser_node) . "\n");
                        return $parser_node;
                    }
                    // fwrite(STDERR, "Found token (parent " . get_class($parser_node) . "): " . json_encode($node_or_token));
                    self::$closest_node_or_token = $node_or_token;
                    // TODO: Handle other cases
                    return $node_or_token;
                }
            }
            if ($node_or_token instanceof PhpParser\Node) {
                // @phan-suppress-next-line PhanThrowTypeAbsentForCall shouldn't happen for generated ASTs
                $end_position = $node_or_token->getEndPosition();
                // fprintf(STDERR, "Scanning over Node %s %d-%d\n", get_class($node_or_token), $node_or_token->getStart(), $end_position);
                if ($end_position < $offset) {
                    // End this early if this token ends before the cursor even starts
                    continue;
                }
                // Either the node, or true if a the node was found as a descendant, or false.
                $state = self::findNodeAtOffsetRecursive($node_or_token, $offset);
                if (\is_object($state)) {
                    // fwrite(STDERR, "Found parent node for $key: " . get_class($parser_node) . "\n");
                    // fwrite(STDERR, "Found parent node for $key: " . json_encode($parser_node) . "\n");
                    // $state is either a Node or a Token
                    if (!is_string($key)) {
                        throw new AssertionError("Expected key to be a string");
                    }
                    return self::adjustClosestNodeOrToken($parser_node, $key);
                } elseif ($state) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * This optionally adjusts the closest_node_or_token to a more useful value.
     * (so that functionality such as "go to definition" for classes, properties, etc. will work as expected)
     *
     * @param PhpParser\Node $node the parent node of the old value of
     * @param string $key
     * @return PhpParser\Node|true
     */
    private static function adjustClosestNodeOrToken(PhpParser\Node $node, string $key)
    {
        switch ($key) {
            case 'memberName':
            case 'callableExpression':
            case 'namespaceName':
            case 'namespaceAliasingClause':
                // fwrite(STDERR, "Adjusted node: " . json_encode($node) . "\n");
                self::$closest_node_or_token = $node;
                return $node;
        }
        return true;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     * @throws InvalidNodeException when self::$should_add_placeholders is false, like many of these methods.
     * @override
     */
    protected static function phpParserNodeToAstNodeOrPlaceholderExpr($n)
    {
        // fprintf(STDERR, "Comparing %s to %s\n", get_class($n), get_class(self::$closest_node_or_token));
        $ast_node = parent::phpParserNodeToAstNodeOrPlaceholderExpr($n);
        if ($n === self::$closest_node_or_token) {
            self::markNodeAsSelected($n, $ast_node);
        }
        return $ast_node;
    }

    /**
     * This marks the tolerant-php-parser Node as being selected,
     * and adds any information that will be useful to code handling the corresponding
     *
     * @param PhpParser\Node|Token $n @phan-unused-param the tolerant-php-parser node that generated the $ast_node
     * @param mixed $ast_node the node that was selected because it was under the cursor
     */
    private static function markNodeAsSelected($n, $ast_node): void
    {
        // fwrite(STDERR, "Marking corresponding node as flagged: " . json_encode($n) . "\n" . \Phan\Debug::nodeToString($ast_node) . "\n");
        // fflush(STDERR);
        if ($ast_node instanceof ast\Node) {
            if (self::$closest_node_or_token_symbol !== null) {
                // fwrite(STDERR, "Marking corresponding node as flagged: " . json_encode($n) . "\n" . json_encode($ast_node) . "\n");
                // fflush(STDERR);

                // TODO: This won't work if the comment is at the end of the file. Add a dummy statement or something to associate it with.
                //
                // TODO: Extract the longest class name or method name from the doc comment
                $fragment = self::extractFragmentFromCommentLike();
                if ($fragment === null) {
                    // We're inside of a string or doc comment but failed to extract a class name
                    return;
                }
                // fwrite(STDERR, "Marking selectedFragment = $fragment\n");
                $ast_node->isSelectedApproximate = self::$closest_node_or_token_symbol;
                $ast_node->selectedFragment = $fragment;
            }
            // fwrite(STDERR, "Marking node with kind " . ast\get_kind_name($ast_node->kind) . " as selected\n");
            $ast_node->isSelected = true;
            $closure = self::$handle_selected_node;
            if ($closure) {
                $closure($ast_node);
            }
        }
    }

    private const VALID_FRAGMENT_CHARACTER_REGEX = '/[\\\\a-z0-9_\x7f-\xff]/i';

    /**
     * @return ?string A fragment that is a potentially valid class or function identifier (e.g. 'MyNs\MyClass', '\MyClass')
     *                 for the comment or string under the cursor
     *
     * TODO: Support method identifiers?
     * TODO: Support variables?
     * TODO: Implement support for going to function definitions if no class could be found
     */
    private static function extractFragmentFromCommentLike(): ?string
    {
        $offset = self::$desired_byte_offset;
        $contents = self::$file_contents;

        // fwrite(STDERR, __METHOD__ . " looking for $offset\n");
        if (!preg_match(self::VALID_FRAGMENT_CHARACTER_REGEX, $contents[$offset] ?? '')) {
            // fwrite(STDERR, "Giving up, invalid character at $offset\n");
            // Give up if the character under the cursor is an invalid character for a token
            return null;
        }
        // Iterate backwards to find the start of this class identifier
        while ($offset > 0 && preg_match(self::VALID_FRAGMENT_CHARACTER_REGEX, $contents[$offset - 1])) {
            $offset--;
        }
        // fwrite(STDERR, "Moved back to $offset, searching at " . json_encode(substr($contents, $offset, 20)) . "\n");

        if (preg_match('/\\\\?[a-z_\x7f-\xff][a-z0-9_\x7f-\xff]*(\\\\[a-z_\x7f-\xff][a-z0-9_\x7f-\xff]*)*/i', $contents, $matches, 0, $offset) > 0) {
            // fwrite(STDERR, "Returning $matches[0]\n");
            return $matches[0];
        }
        return null;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     * @override
     */
    protected static function phpParserNodeToAstNode($n)
    {
        static $callback_map;
        static $fallback_closure;
        if (\is_null($callback_map)) {
            // XXX: If initHandleMap is called on TolerantASTConverter in the parent implementation before TolerantASTConverterWithNodeMapping,
            // then static:: in the callbacks would point to TolerantASTConverter, not this subclass.
            //
            // This is worked around by copying and pasting the parent implementation
            $callback_map = static::initHandleMap();
            /**
             * @param PhpParser\Node|Token $n
             * @throws InvalidArgumentException for invalid token classes
             * @suppress PhanThrowTypeMismatchForCall can throw if debugDumpNodeOrToken fails
             */
            $fallback_closure = static function ($n, int $unused_start_line): ast\Node {
                if (!($n instanceof PhpParser\Node) && !($n instanceof Token)) {
                    throw new InvalidArgumentException("Invalid type for node: " . (\is_object($n) ? \get_class($n) : \gettype($n)) . ": " . static::debugDumpNodeOrToken($n));
                }

                return static::astStub($n);
            };
        }
        $callback = $callback_map[\get_class($n)] ?? $fallback_closure;
        $result = $callback($n, self::$file_position_map->getStartLine($n));
        if (($result instanceof ast\Node) && $result->kind === ast\AST_NAME) {
            $result = new ast\Node(ast\AST_CONST, 0, ['name' => $result], $result->lineno);
        }
        if ($n === self::$closest_node_or_token) {
            self::markNodeAsSelected($n, $result);
        }
        return $result;
    }

    /**
     * @param PhpParser\Node|Token $n - The node from PHP-Parser
     * @return ast\Node|ast\Node[]|string|int|float|bool|null - whatever ast\parse_code would return as the equivalent.
     * @override
     */
    protected static function phpParserNonValueNodeToAstNode($n)
    {
        // fprintf(STDERR, "Comparing %s to %s\n", get_class($n), get_class(self::$closest_node_or_token));
        static $callback_map;
        static $fallback_closure;
        if (\is_null($callback_map)) {
            // XXX: If initHandleMap is called on TolerantASTConverter in the parent implementation before TolerantASTConverterWithNodeMapping,
            // then static:: in the callbacks would point to TolerantASTConverter, not this subclass.
            //
            // This is worked around by copying and pasting the parent implementation
            $callback_map = static::initHandleMap();
            /**
             * @param PhpParser\Node|Token $n
             * @throws InvalidArgumentException for invalid token classes
             */
            $fallback_closure = static function ($n, int $unused_start_line): ast\Node {
                if (!($n instanceof PhpParser\Node) && !($n instanceof Token)) {
                    // @phan-suppress-next-line PhanThrowTypeMismatchForCall debugDumpNodeOrToken can throw
                    throw new InvalidArgumentException("Invalid type for node: " . (\is_object($n) ? \get_class($n) : \gettype($n)) . ": " . static::debugDumpNodeOrToken($n));
                }
                return static::astStub($n);
            };
        }
        $callback = $callback_map[\get_class($n)] ?? $fallback_closure;
        $ast_node = $callback($n, self::getStartLine($n));
        if ($n === self::$closest_node_or_token) {
            self::markNodeAsSelected($n, $ast_node);
        }
        return $ast_node;
    }

    /**
     * @override
     */
    protected static function astStmtUseOrGroupUseFromUseClause(
        PhpParser\Node\NamespaceUseClause $use_clause,
        ?int $parser_use_kind,
        int $start_line
    ): ast\Node {
        // fwrite(STDERR, "Calling astStmtUseOrGroupUseFromUseClause for " . json_encode($use_clause) . "\n");
        $ast_node = parent::astStmtUseOrGroupUseFromUseClause($use_clause, $parser_use_kind, $start_line);
        if ($use_clause === self::$closest_node_or_token) {
            // NOTE: This selects AST_USE instead of AST_USE_ELEM so that we have
            // full information on whether it is a function, constant, or class/namespace
            // fwrite(STDERR, "Marking corresponding node as flagged: " . json_encode($use_clause) . "\n" . json_encode($ast_node) . "\n");
            self::markNodeAsSelected($use_clause, $ast_node);
        }
        return $ast_node;
    }

    /**
     * @param PhpParser\Node\QualifiedName|Token|null $type
     * @override
     */
    protected static function phpParserTypeToAstNode($type, int $line): ?\ast\Node
    {
        $ast_node = parent::phpParserTypeToAstNode($type, $line);
        if ($type === self::$closest_node_or_token && $type !== null) {
            self::markNodeAsSelected($type, $ast_node);
        }
        return $ast_node;
    }
}
