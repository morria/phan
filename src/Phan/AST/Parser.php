<?php

declare(strict_types=1);

namespace Phan\AST;

use ast\Node;
use CompileError;
use Error;
use Microsoft\PhpParser\Diagnostic;
use Microsoft\PhpParser\FilePositionMap;
use ParseError;
use Phan\AST\TolerantASTConverter\CachingTolerantASTConverter;
use Phan\AST\TolerantASTConverter\ParseException;
use Phan\AST\TolerantASTConverter\ParseResult;
use Phan\AST\TolerantASTConverter\ShimFunctions;
use Phan\AST\TolerantASTConverter\TolerantASTConverter;
use Phan\AST\TolerantASTConverter\TolerantASTConverterPreservingOriginal;
use Phan\AST\TolerantASTConverter\TolerantASTConverterWithNodeMapping;
use Phan\CodeBase;
use Phan\Config;
use Phan\Daemon\Request;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Library\Cache;
use Phan\Library\DiskCache;
use Phan\Library\FileCacheEntry;
use Phan\Library\StringUtil;
use Phan\Phan;
use Phan\Plugin\ConfigPluginSet;
use Throwable;

use function error_clear_last;
use function error_get_last;
use function error_reporting;

/**
 * Parser parses the passed in PHP code based on configuration settings.
 *
 * It has options for error-tolerant parsing,
 * annotating \ast\Nodes with additional information used by the language server
 */
class Parser
{
    /** @var ?DiskCache<ParseResult> */
    private static $cache = null;

    /**
     * Creates a cache if Phan is configured to use caching in the current phase.
     *
     * @return ?Cache<ParseResult>
     */
    private static function maybeGetCache(CodeBase $code_base): ?Cache
    {
        if ($code_base->getExpectChangesToFileContents()) {
            return null;
        }
        if (!Config::getValue('cache_polyfill_asts')) {
            return null;
        }
        return self::getCache();
    }

    /**
     * @return DiskCache<ParseResult>
     */
    private static function getCache(): DiskCache
    {
        return self::$cache ?? self::$cache = self::makeNewCache();
    }

    /**
     * @return DiskCache<ParseResult>
     */
    private static function makeNewCache(): DiskCache
    {
        $igbinary_version = \phpversion('igbinary') ?: '';
        $use_igbinary = \version_compare($igbinary_version, '2.0.5') >= 0;

        $user = \getenv('USERNAME') ?: \getenv('USER');
        $directory = \sys_get_temp_dir() . '/phan';
        if (StringUtil::isNonZeroLengthString($user)) {
            $directory .= "-$user";
        }
        return new DiskCache($directory, '-ast', ParseResult::class, $use_igbinary);
    }

    /**
     * Parses the code with the native parser or the polyfill.
     * If $suppress_parse_errors is false, this also emits SyntaxError.
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param ?Request $request (A daemon mode request if in daemon mode. May affect the parser used for $file_path)
     * @param string $file_path file path for error reporting
     * @param string $file_contents file contents to pass to parser. This may deliberately differ from what is currently on disk (e.g. for the language server mode or daemon mode)
     * @param bool $suppress_parse_errors (If true, don't emit SyntaxError)
     * @throws ParseError
     * @throws CompileError (possible in php 7.3)
     * @throws ParseException
     */
    public static function parseCode(
        CodeBase $code_base,
        Context $context,
        ?Request $request,
        string $file_path,
        string $file_contents,
        bool $suppress_parse_errors
    ): Node {
        try {
            // This will choose the parser to use based on the config and $file_path
            // (For "Go To Definition", one of the files will have a slower parser which records the requested AST node)

            if (self::shouldUsePolyfill($file_path, $request)) {
                // This helper method has its own exception handling.
                // It may throw a ParseException, which is unintentionally not caught here.
                return self::parseCodePolyfill($code_base, $context, $file_path, $file_contents, $suppress_parse_errors, $request);
            }
            return self::parseCodeHandlingDeprecation($code_base, $context, $file_contents, $file_path);
        } catch (CompileError | ParseError $native_parse_error) {
            return self::handleParseError($code_base, $context, $file_path, $file_contents, $suppress_parse_errors, $native_parse_error, $request);
        }
    }


    private static function parseCodeHandlingDeprecation(CodeBase $code_base, Context $context, string $file_contents, string $file_path): Node
    {
        global $__no_echo_phan_errors;
        // Suppress errors such as "declare(encoding=...) ignored because Zend multibyte feature is turned off by settings" (#1076)
        // E_COMPILE_WARNING can't be caught by a PHP error handler,
        // the errors are printed to stderr by default (can't be captured),
        // and those errors might mess up language servers, etc. if ever printed to stdout
        $original_error_reporting = error_reporting();
        error_reporting($original_error_reporting & ~\E_COMPILE_WARNING);
        $__no_echo_phan_errors = static function (int $errno, string $errstr, string $unused_errfile, int $errline) use ($code_base, $context): bool {
            if ($errno === \E_DEPRECATED && \preg_match('/Version.*is deprecated/i', $errstr)) {
                return false;
            }
            // Catch errors such as E_DEPRECATED in php 7.4 for the (real) cast.
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::CompatibleSyntaxNotice,
                $errline,
                $errstr
            );
            // Return true to prevent printing to stderr
            return true;
        };
        try {
            error_clear_last();
            $root_node = \ast\parse_code(
                $file_contents,
                Config::AST_VERSION,
                $file_path
            );
            $error = error_get_last();
            if ($error && $error['type'] === \E_COMPILE_WARNING) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::SyntaxCompileWarning,
                    $error['line'],
                    $error['message']
                );
            }
            return $root_node;
        } finally {
            $__no_echo_phan_errors = false;
            error_reporting($original_error_reporting);
        }
    }

    /**
     * Handles ParseError|CompileError from the native parser.
     * This will return a Node or re-throw an error, depending on the configuration and parameters.
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param string $file_path file path for error reporting
     * @param string $file_contents file contents to pass to parser. May be overridden to ignore what is currently on disk.
     * @param ParseError|CompileError $native_parse_error (can be CompileError in 7.3+, will be ParseError in most cases)
     * @param ?Request $request used to check if caching should be enabled to save time.
     * @throws ParseError most of the time
     * @throws CompileError in PHP 7.3+
     */
    public static function handleParseError(
        CodeBase $code_base,
        Context $context,
        string $file_path,
        string $file_contents,
        bool $suppress_parse_errors,
        Error $native_parse_error,
        ?Request $request = null
    ): Node {
        if ($file_path !== 'internal') {
            if (!$suppress_parse_errors) {
                self::emitSyntaxErrorForNativeParseError($code_base, $context, $file_path, new FileCacheEntry($file_contents), $native_parse_error, $request);
            }
            if (!Config::getValue('use_fallback_parser')) {
                // By default, don't try to re-parse files with syntax errors.
                throw $native_parse_error;
            }
        }

        // If there's a parse error in a file that's excluded from analysis, give up on parsing it.
        // Users might not see the parse error, and ignoring it (e.g. acting as though a file in vendor/ or ext/
        // that can't be parsed has class and function definitions)
        // may lead to users not noticing bugs.
        if (Phan::isExcludedAnalysisFile($file_path)) {
            throw $native_parse_error;
        }
        // But if the user would see the syntax error, go ahead and retry.

        if ($request) {
            $converter = new CachingTolerantASTConverter();
        } else {
            $converter = new TolerantASTConverter();
        }
        $converter->setPHPVersionId(Config::get_closest_target_php_version_id());
        $errors = [];
        try {
            $node = $converter->parseCodeAsPHPAST($file_contents, Config::AST_VERSION, $errors);
        } catch (\Exception $_) {
            // Generic fallback. TODO: log.
            throw $native_parse_error;
        }
        // TODO: loop over $errors?
        return $node;
    }

    /**
     * Emit PhanSyntaxError for ParseError|CompileError from the native parser.
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param string $file_path file path for error reporting
     * @param FileCacheEntry $file_cache_entry for file contents that were passed to the polyfill parser. May be overridden to ignore what is currently on disk.
     * @param ParseError|CompileError $native_parse_error (can be CompileError in 7.3+, will be ParseError in most cases)
     */
    public static function emitSyntaxErrorForNativeParseError(
        CodeBase $code_base,
        Context $context,
        string $file_path,
        FileCacheEntry $file_cache_entry,
        Error $native_parse_error,
        ?Request $request = null
    ): void {
        // Try to get the raw diagnostics by reference.
        // For efficiency, reuse the last result if this was called multiple times in a row.
        $line = $native_parse_error->getLine();
        $message = $native_parse_error->getMessage();
        $diagnostic_error_column = self::guessErrorColumnUsingTokens($file_cache_entry, $native_parse_error) ?:
            self::guessErrorColumnUsingPolyfill($code_base, $context, $file_path, $file_cache_entry, $native_parse_error, $request);

        Issue::maybeEmitWithParameters(
            $code_base,
            $context,
            Issue::SyntaxError,
            $line,
            [$message],
            null,
            $diagnostic_error_column
        );
    }

    /**
     * Returns the 1-based error column, or 0 if unknown.
     *
     * This will return the corresponding unexpected token only when there's exactly one token with that value on the line with the error.
     */
    private static function guessErrorColumnUsingTokens(
        FileCacheEntry $file_cache_entry,
        Error $native_parse_error
    ): int {
        if (!\function_exists('token_get_all')) {
            return 0;
        }
        $message = $native_parse_error->getMessage();
        $prefix = "unexpected (?:token )?('(?:.+)'|\"(?:.+)\")";
        if (!\preg_match("/$prefix \((T_\w+)\)/", $message, $matches)) {
            if (!\preg_match("/$prefix, expecting/", $message, $matches)) {
                if (!\preg_match("/$prefix$/D", $message, $matches)) {
                    return 0;
                }
            }
        }
        $token_name = $matches[2] ?? null;
        if (\is_string($token_name)) {
            if (!\defined($token_name)) {
                return 0;
            }
            $token_kind = \constant($token_name);
        } else {
            $token_kind = null;
        }
        $token_str = \substr($matches[1], 1, -1);
        $tokens = \token_get_all($file_cache_entry->getContents());
        $candidates = [];
        $desired_line = $native_parse_error->getLine();
        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                if ($token_str === $token) {
                    $candidates[] = $i;
                }
                continue;
            }
            $line = $token[2];
            if ($line < $desired_line) {
                continue;
            } elseif ($line > $desired_line) {
                break;
            }
            if ($token_kind !== $token[0]) {
                continue;
            }
            if ($token_str !== $token[1]) {
                continue;
            }
            $candidates[] = $i;
        }
        if (\count($candidates) !== 1) {
            return 0;
        }
        return self::computeColumnForTokenAtIndex($tokens, $candidates[0], $desired_line);
    }

    /**
     * @param list<array{0:int,1:string,2:int}|string> $tokens
     * @return int the 1-based line number, or 0 on failure
     */
    private static function computeColumnForTokenAtIndex(array $tokens, int $i, int $desired_line): int
    {
        if ($i <= 0) {
            return 1;
        }
        $column = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];
            if (!\is_array($token)) {
                $column += \strlen($token);
                continue;
            }
            $token_str = $token[1];
            if ($token[2] >= $desired_line) {
                $column += \strlen($token_str);
                continue;
            }
            $last_newline = \strrpos($token_str, "\n");
            if ($last_newline !== false) {
                $column += \strlen($token_str) - $last_newline;
            }
            break;
        }
        return $column;
    }

    /**
     * Returns the 1-based error column, or 0 if unknown.
     */
    private static function guessErrorColumnUsingPolyfill(
        CodeBase $code_base,
        Context $context,
        string $file_path,
        FileCacheEntry $file_cache_entry,
        Error $native_parse_error,
        ?Request $request
    ): int {
        $file_contents = $file_cache_entry->getContents();
        static $last_file_contents = null;
        static $errors = [];

        if ($last_file_contents !== $file_contents) {
            // Create a brand new reference group
            $new_errors = [];
            $errors = & $new_errors;
            try {
                self::parseCodePolyfill($code_base, $context, $file_path, $file_contents, true, $request, $errors);
            } catch (Throwable $_) {
                // ignore this exception
            }
        }
        // If the polyfill parser emits the first error on the same line as the native parser,
        // mention the column that the polyfill parser found for the error.
        $diagnostic = $errors[0] ?? null;
        // $diagnostic_error_column is either 0 or the column of the error determined by the polyfill parser
        if (!$diagnostic) {
            return 0;
        }
        // Using FilePositionMap is much faster than substr_count to count lines if you have more than one diagnostic to report (e.g. a string has an unmatched quote).
        $file_position_map = $file_cache_entry->getFilePositionMap();
        $start = (int) $diagnostic->start;
        $diagnostic_error_start_line = $file_position_map->getLineNumberForOffset($start);
        if ($diagnostic_error_start_line > $native_parse_error->getLine()) {
            return 0;
        }
        // If the current character is whitespace, keep searching forward for the next non-whitespace character
        $file_length = \strlen($file_contents);
        while ($start + 1 < $file_length && \ctype_space($file_contents[$start])) {
            $start++;
        }
        $diagnostic_error_start_line = $file_position_map->getLineNumberForOffset($start);
        if ($diagnostic_error_start_line !== $native_parse_error->getLine()) {
            return 0;
        }
        return $start - (\strrpos($file_contents, "\n", $start - \strlen($file_contents) - 1) ?: 0);
    }

    /** Set an arbitrary limit on the number of warnings for the polyfill diagnostics to prevent excessively large errors for unmatched string quotes, etc. */
    private const MAX_POLYFILL_WARNINGS = 1000;

    /**
     * Parses the code with the polyfill. If $suppress_parse_errors is false, this also emits SyntaxError.
     *
     * @param CodeBase $code_base
     * @param Context $context
     * @param string $file_path file path for error reporting
     * @param string $file_contents file contents to pass to parser. May be overridden to ignore what is currently on disk.
     * @param bool $suppress_parse_errors (If true, don't emit SyntaxError)
     * @param ?Request $request - May affect the parser used for $file_path
     * @param list<Diagnostic> &$errors @phan-output-reference
     * @throws ParseException
     * @suppress PhanThrowTypeMismatch
     */
    public static function parseCodePolyfill(CodeBase $code_base, Context $context, string $file_path, string $file_contents, bool $suppress_parse_errors, ?Request $request, array &$errors = []): Node
    {
        // @phan-suppress-next-line PhanRedundantCondition
        if (!\in_array(Config::AST_VERSION, TolerantASTConverter::SUPPORTED_AST_VERSIONS, true)) {
            throw new \Error(\sprintf("Unexpected polyfill version: want %s, got %d", \implode(', ', TolerantASTConverter::SUPPORTED_AST_VERSIONS), Config::AST_VERSION));
        }
        $converter = self::createConverter($file_path, $file_contents, $request);
        $converter->setPHPVersionId(Config::get_closest_target_php_version_id());
        $errors = [];
        error_clear_last();
        try {
            $node = $converter->parseCodeAsPHPAST($file_contents, Config::AST_VERSION, $errors, self::maybeGetCache($code_base));
        } catch (\Exception $e) {
            // Generic fallback. TODO: log.
            throw new ParseException('Unexpected Exception of type ' . \get_class($e) . ': ' . $e->getMessage(), 0);
        }
        if (!$suppress_parse_errors) {
            $error = error_get_last();
            if ($error) {
                self::handleWarningFromPolyfill($code_base, $context, $error);
            }
        }
        if (!$errors) {
            return $node;
        }
        $file_position_map = new FilePositionMap($file_contents);
        $emitted_warning_count = 0;
        foreach ($errors as $diagnostic) {
            if ($diagnostic->kind === 0) {
                $start = (int)$diagnostic->start;
                $diagnostic_error_message = 'Fallback parser diagnostic error: ' . $diagnostic->message;
                $len = \strlen($file_contents);
                $diagnostic_error_start_line = $file_position_map->getLineNumberForOffset($start);
                $diagnostic_error_column = $start - (\strrpos($file_contents, "\n", $start - $len - 1) ?: 0);

                if (!$suppress_parse_errors) {
                    $emitted_warning_count++;
                    if ($emitted_warning_count <= self::MAX_POLYFILL_WARNINGS) {
                        Issue::maybeEmitWithParameters(
                            $code_base,
                            $context,
                            Issue::SyntaxError,
                            $diagnostic_error_start_line,
                            [$diagnostic_error_message],
                            null,
                            $diagnostic_error_column
                        );
                    }
                }
                if (!Config::getValue('use_fallback_parser')) {
                    // By default, don't try to re-parse files with syntax errors.
                    throw new ParseException($diagnostic_error_message, $diagnostic_error_start_line);
                }

                // If there's a parse error in a file that's excluded from analysis, give up on parsing it.
                // Users might not see the parse error, and ignoring it (e.g. acting as though a file in vendor/ or ext/
                // that can't be parsed has class and function definitions)
                // may lead to users not noticing bugs.
                if (Phan::isExcludedAnalysisFile($file_path)) {
                    throw new ParseException($diagnostic_error_message, $diagnostic_error_start_line);
                }
            }
        }
        return $node;
    }

    /**
     * @param array<string,mixed> $error
     */
    private static function handleWarningFromPolyfill(CodeBase $code_base, Context $context, array $error): void
    {
        if (\in_array($error['type'], [\E_DEPRECATED, \E_COMPILE_WARNING], true) &&
            \basename($error['file']) === 'PhpTokenizer.php') {
            $line = $error['line'];
            if (\preg_match('/line ([0-9]+)$/D', $error['message'], $matches)) {
                $line = (int)$matches[1];
            }


            Issue::maybeEmit(
                $code_base,
                $context,
                $error['type'] === \E_COMPILE_WARNING ? Issue::SyntaxCompileWarning : Issue::CompatibleSyntaxNotice,
                $line,
                $error['message']
            );
        }
    }

    /**
     * Remove the leading #!/path/to/interpreter/of/php from a CLI script, if any was found.
     */
    public static function removeShebang(string $file_contents): string
    {
        if (\substr($file_contents, 0, 2) !== "#!") {
            return $file_contents;
        }
        for ($i = 2; $i < \strlen($file_contents); $i++) {
            $c = $file_contents[$i];
            if ($c === "\r") {
                if (($file_contents[$i + 1] ?? '') === "\n") {
                    $i++;
                    break;
                }
            } elseif ($c === "\n") {
                break;
            }
        }
        if ($i >= \strlen($file_contents)) {
            return '';
        }
        $rest = (string)\substr($file_contents, $i + 1);
        if (\strcasecmp(\substr($rest, 0, 5), "<?php") === 0) {
            // declare(strict_types=1) must be the first part of the script.
            // Even empty php tags aren't allowed prior to it, so avoid adding empty tags if possible.
            return "<?php\n" . \substr($rest, 5);
        }
        // Preserve the line numbers by adding a no-op newline instead of the removed shebang
        return "<?php\n?>" . $rest;
    }

    private static function shouldUsePolyfill(string $file_path, ?Request $request = null): bool
    {
        if (Config::getValue('use_polyfill_parser')) {
            return true;
        }
        if ($request) {
            return $request->shouldUseMappingPolyfill($file_path);
        }
        return false;
    }


    private static function createConverter(string $file_path, string $file_contents, ?Request $request = null): TolerantASTConverter
    {
        if ($request) {
            if ($request->shouldUseMappingPolyfill($file_path)) {
                // TODO: Rename to something better
                $converter = new TolerantASTConverterWithNodeMapping(
                    $request->getTargetByteOffset($file_contents),
                    static function (Node $node): void {
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        ConfigPluginSet::instance()->prepareNodeSelectionPluginForNode($node);
                    }
                );
                if ($request->shouldAddPlaceholdersForPath($file_path)) {
                    $converter->setShouldAddPlaceholders(true);
                }
                return $converter;
            }
            return new CachingTolerantASTConverter();
        }
        if (Config::getValue('__parser_keep_original_node')) {
            return new TolerantASTConverterPreservingOriginal();
        }

        return new TolerantASTConverter();
    }

    /**
     * Get a string representation of the AST kind value.
     * @suppress PhanAccessMethodInternal
     */
    public static function getKindName(int $kind): string
    {
        static $use_native = null;
        $use_native = ($use_native ?? self::shouldUseNativeAST());
        if ($use_native) {
            return \ast\get_kind_name($kind);
        }
        // The native function doesn't exist or is missing some constants Phan would use.
        return ShimFunctions::getKindName($kind);
    }

    // TODO: Refactor and make more code use this check
    private static function shouldUseNativeAST(): bool
    {
        if (\PHP_VERSION_ID >= 80100) {
            $min_version = '1.0.14';
        } elseif (\PHP_VERSION_ID >= 80000) {
            $min_version = '1.0.10';
        } elseif (\PHP_VERSION_ID >= 70400) {
            $min_version = '1.0.2';
        } else {
            $min_version = Config::MINIMUM_AST_EXTENSION_VERSION;
        }
        return \version_compare(\phpversion('ast') ?: '0.0.0', $min_version) >= 0;
    }
}
