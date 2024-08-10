<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal\IssueFixingPlugin;

use Closure;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Node\NamespaceUseClause;
use Microsoft\PhpParser\Node\QualifiedName;
use Microsoft\PhpParser\Node\Parameter;
use Microsoft\PhpParser\Node\Statement\NamespaceUseDeclaration;
use Microsoft\PhpParser\Token;
use Microsoft\PhpParser\TokenKind;
use Phan\AST\TolerantASTConverter\NodeUtils;
use Phan\CodeBase;
use Phan\Config;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Library\FileCache;
use Phan\Library\FileCacheEntry;
use Phan\Library\StringUtil;
use RuntimeException;

/**
 * Represents a set of changes to be made to file contents.
 * The structure of this will change.
 */
class IssueFixer
{

    private static function isMatchingNamespaceUseDeclaration(
        string $file_contents,
        NamespaceUseDeclaration $declaration,
        IssueInstance $issue_instance
    ): bool {
        $type = $issue_instance->getIssue()->getType();

        switch ($type) {
            case Issue::UnreferencedUseNormal:
                $expected_token_kind = null;
                break;
            case Issue::UnreferencedUseFunction:
                $expected_token_kind = TokenKind::FunctionKeyword;
                break;
            case Issue::UnreferencedUseConstant:
                $expected_token_kind = TokenKind::ConstKeyword;
                break;
            default:
                self::debug(\sprintf("Unexpected kind %s in %s\n", $type, __METHOD__));
                return false;
        }

        $actual_token_kind = $declaration->functionOrConst->kind ?? null;
        if ($expected_token_kind !== $actual_token_kind) {
            self::debug(\sprintf("DEBUG: Unexpected type %s in %s\n", $actual_token_kind ?? 'null', __METHOD__));
            return false;
        }
        $list = $declaration->useClauses->children ?? [];
        if (\count($list) !== 1) {
            self::debug(\sprintf("DEBUG: Unexpected count %d in %s\n", \count($list), __METHOD__));
            return false;
        }
        $element = $list[0];
        // $dumper = new \Phan\AST\TolerantASTConverter\NodeDumper($file_contents);
        // $dumper->setIncludeTokenKind(true);
        // $dumper->dumpTree($element);
        if (!($element instanceof NamespaceUseClause)) {
            return false;
        }
        if ($element->openBrace || $element->groupClauses || $element->closeBrace) {
            // Not supported
            return false;
        }
        // $element->namespaceAliasingClause doesn't matter for the the subsequent checks

        $namespace_name = $element->namespaceName;
        if (!($namespace_name instanceof QualifiedName)) {
            return false;
        }
        $actual_use_name = (new NodeUtils($file_contents))->phpParserNameToString($namespace_name);
        // Get the last argument from
        // Possibly zero references to use statement for classlike/namespace {CLASSLIKE} ({CLASSLIKE})
        $expected_use_name = $issue_instance->getTemplateParameters()[1];

        if (\strcasecmp(\ltrim((string)$expected_use_name, "\\"), \ltrim($actual_use_name, "\\")) !== 0) {
            // Not the same fully qualified name.
            return false;
        }
        // This is the same fully qualified name.
        return true;
    }

    private static function maybeAddNullableToParam(
        string $file_contents,
        Parameter $param,
        IssueInstance $issue_instance
    ): ?FileEdit {
        if ($param->questionToken !== null) {
            self::debug(\sprintf("maybeAddNullableToParam: already nullable %s for %s\n", $param->getText(), (string)$issue_instance));
            return null;
        }
        if ($param->default === null) {
            self::debug(\sprintf("maybeAddNullableToParam: %s does not have a default so this cannot be implicitly nullable %s\n", $param->getText(), (string)$issue_instance));
            return null;
        }

        // Both of these start with '$'
        $variable_name = $param->variableName;
        $variable_name_text = $variable_name instanceof Token ? $variable_name->getText($file_contents) : null;
        $param_name = $issue_instance->getTemplateParameters()[1];
        if ($variable_name_text !== $param_name) {
            self::debug(\sprintf("maybeAddNullableToParam: variable_name mismatch for %s: %s != %s\n", (string)$issue_instance, \var_export($variable_name_text, true), \var_export($param_name, true)));
            return null;
        }
        $typeDeclarationList = $param->typeDeclarationList;
        if (!$typeDeclarationList instanceof QualifiedNameList) {
            self::debug(\sprintf("maybeAddNullableToParam: got %s instead of QualifiedNameList for %s\n", \get_debug_type($typeDeclarationList), (string)$issue_instance));
            return null;
        }
        if (\count($typeDeclarationList->children) === 1) {
            $position = $typeDeclarationList->children[0]->getStartPosition();
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall
            return new FileEdit($position, $position, '?');
        }
        // TODO optionally add automatic fix for "null|" for null|A|B for union types containing TokenKind::BarToken
        self::debug(\sprintf("unsupported number of type declarations %d for %s\n", \count($typeDeclarationList->children), (string)$issue_instance));
        return null;
    }

    private static function maybeRemoveNamespaceUseDeclaration(
        string $file_contents,
        NamespaceUseDeclaration $declaration,
        IssueInstance $issue_instance
    ): ?FileEdit {
        if (!self::isMatchingNamespaceUseDeclaration($file_contents, $declaration, $issue_instance)) {
            return null;
        }

        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        $end = $declaration->getEndPosition();
        $end = self::skipTrailingWhitespaceAndNewlines($file_contents, $end);
        // @phan-suppress-next-line PhanThrowTypeAbsentForCall
        return new FileEdit($declaration->getStartPosition(), $end);
    }

    private static function skipTrailingWhitespaceAndNewlines(string $file_contents, int $end): int
    {
        // Handles \r\n and \n, but doesn't bother handling \r
        $next = \strpos($file_contents, "\n", $end);
        if ($next === false) {
            return $end;
        }
        $remaining = (string)\substr($file_contents, $end, $next - $end);
        if (\trim($remaining) === '') {
            return $next + 1;
        }
        return $end;
    }

    /**
     * @var array<string,callable(CodeBase,FileCacheEntry,IssueInstance):(?FileEditSet)>
     */
    private static $fixer_closures = [];

    /**
     * Registers a fixer that can be used to generate a fix for $issue_name
     *
     * @param callable(CodeBase,FileCacheEntry,IssueInstance):(?FileEditSet) $fixer
     *        this is neither a real type hint nor a real closure so that the implementation can optionally be moved to classes that aren't loaded by the PHP interpreter yet.
     */
    public static function registerFixerClosure(string $issue_name, callable $fixer): void
    {
        self::$fixer_closures[$issue_name] = $fixer;
    }

    /**
     * @return array<string,callable(CodeBase,FileCacheEntry,IssueInstance):(?FileEditSet)>
     */
    private static function createClosures(): array
    {
        /**
         * @return ?FileEditSet
         */
        $handle_unreferenced_use = static function (
            CodeBase $unused_code_base,
            FileCacheEntry $file_contents,
            IssueInstance $issue_instance
        ): ?FileEditSet {
            // 1-based line
            $line = $issue_instance->getLine();
            $edits = [];
            foreach ($file_contents->getNodesAtLine($line) as $candidate_node) {
                self::debug(\sprintf("Handling %s for %s\n", \get_class($candidate_node), (string)$issue_instance));
                if ($candidate_node instanceof NamespaceUseDeclaration) {
                    $edit = self::maybeRemoveNamespaceUseDeclaration($file_contents->getContents(), $candidate_node, $issue_instance);
                    if ($edit) {
                        $edits[] = $edit;
                    }
                    // This assumes a coding style of one namespace use per line.
                    break;
                }
            }
            if ($edits) {
                return new FileEditSet($edits);
            }
            return null;
        };
        $handle_deprecated_implicit_nullable = static function (
            CodeBase $unused_code_base,
            FileCacheEntry $file_contents,
            IssueInstance $issue_instance
        ): ?FileEditSet {
            // 1-based line
            $line = $issue_instance->getLine();
            $edits = [];
            foreach ($file_contents->getNodesAtLine($line) as $candidate_node) {
                self::debug(\sprintf("Handling %s for %s\n", \get_class($candidate_node), (string)$issue_instance));
                if ($candidate_node instanceof Parameter) {
                    $edit = self::maybeAddNullableToParam($file_contents->getContents(), $candidate_node, $issue_instance);
                    if ($edit) {
                        $edits[] = $edit;
                        break;
                    }
                }
            }
            if ($edits) {
                return new FileEditSet($edits);
            }
            return null;
        };

        return \array_merge(self::$fixer_closures, [
            Issue::UnreferencedUseNormal => $handle_unreferenced_use,
            Issue::UnreferencedUseConstant => $handle_unreferenced_use,
            Issue::UnreferencedUseFunction => $handle_unreferenced_use,
            Issue::DeprecatedImplicitNullableParam => $handle_deprecated_implicit_nullable,
        ]);
    }

    /**
     * Apply fixes where possible for any issues in $instances.
     *
     * @param IssueInstance[] $instances
     */
    public static function applyFixes(CodeBase $code_base, array $instances): void
    {
        $fixers_for_files = self::computeFixersForInstances($instances);
        foreach ($fixers_for_files as $file => $fixers) {
            self::attemptFixForIssues($code_base, (string)$file, $fixers);
        }
    }

    /**
     * Given a list of issue instances,
     * return arrays of Closures to fix fixable instances in their corresponding files.
     *
     * @param IssueInstance[] $instances
     * @return array<string,list<Closure(CodeBase,FileCacheEntry):(?FileEditSet)>>
     */
    public static function computeFixersForInstances(array $instances): array
    {
        $closures = self::createClosures();
        $fixers_for_files = [];
        foreach ($instances as $instance) {
            $issue = $instance->getIssue();
            $type = $issue->getType();
            $closure = $closures[$type] ?? null;
            // self::debug("Found closure for $type: " . \json_encode((bool)$closure) . "\n");
            if ($closure) {
                /**
                 * @return ?FileEditSet
                 */
                $fixers_for_files[$instance->getFile()][] = static function (
                    CodeBase $code_base,
                    FileCacheEntry $file_contents
                ) use (
                    $closure,
                    $instance
                ): ?FileEditSet {
                    self::debug("Calling for $instance\n");
                    return $closure($code_base, $file_contents, $instance);
                };
            }
        }
        return $fixers_for_files;
    }

    /**
     * @param string $file the file name, for debugging
     * @param list<Closure(CodeBase,FileCacheEntry):(?FileEditSet)> $fixers one or more fixers. These return 0 edits if nothing works.
     * @return ?string the new contents, if fixes could be applied
     */
    public static function computeNewContentForFixers(
        CodeBase $code_base,
        string $file,
        string $raw_contents,
        array $fixers
    ): ?string {
        // A tolerantparser ast node

        $contents = new FileCacheEntry($raw_contents);

        // $dumper = new \Phan\AST\TolerantASTConverter\NodeDumper($contents);
        // $dumper->setIncludeTokenKind(true);
        // $dumper->dumpTree($ast);

        $all_edits = [];
        foreach ($fixers as $fix) {
            $edit_set = $fix($code_base, $contents);
            foreach ($edit_set->edits ?? [] as $edit) {
                $all_edits[] = $edit;
            }
        }
        if (!$all_edits) {
            self::debug("Phan cannot create any automatic fixes for $file\n");
            return null;
        }
        return self::computeNewContents($file, $contents->getContents(), $all_edits);
    }

    /**
     * @param list<Closure(CodeBase,FileCacheEntry):(?FileEditSet)>  $fixers one or more fixers. These return 0 edits if nothing works.
     */
    private static function attemptFixForIssues(
        CodeBase $code_base,
        string $file,
        array $fixers
    ): void {
        try {
            $entry = FileCache::getOrReadEntry($file);
        } catch (RuntimeException $e) {
            self::error("Could not automatically fix $file: could not read contents: " . $e->getMessage() . "\n");
            return;
        }
        $contents = $entry->getContents();
        $new_contents = self::computeNewContentForFixers($code_base, $file, $contents, $fixers);
        if ($new_contents === null) {
            return;
        }
        // Sort file edits in order of start position
        $absolute_path = Config::projectPath($file);
        if (!\file_exists($absolute_path)) {
            // This file should exist - always warn
            self::error("Giving up on saving changes to $file: expected $absolute_path to exist already\n");
            return;
        }
        \file_put_contents($absolute_path, $new_contents);
    }

    /**
     * Compute the new contents for a file, given the original contents and a list of edits to apply to that file
     * @param string $file the path to the file, for logging.
     * @param string $contents the original contents of the file. This will be modified
     * @param FileEdit[] $all_edits
     * @return ?string - the new contents, if successful.
     */
    public static function computeNewContents(string $file, string $contents, array $all_edits): ?string
    {
        \usort($all_edits, static function (FileEdit $a, FileEdit $b): int {
            return ($a->replace_start <=> $b->replace_start)
                ?: ($a->replace_end <=> $b->replace_end)
                ?: \strcmp($a->new_text, $b->new_text);
        });
        self::debug("Going to apply these fixes for $file: " . StringUtil::jsonEncode($all_edits) . "\n");
        $last_end = 0;
        $last_replace_start = -1;
        $new_contents = '';
        $prev_edit = null;
        foreach ($all_edits as $edit) {
            if ($prev_edit && $edit->isEqualTo($prev_edit)) {
                continue;
            }
            $prev_edit = $edit;
            if ($edit->replace_start < $last_end) {
                self::debug("Giving up on $file: replacement starts before end of another replacement\n");
                return null;
            }
            if ($edit->new_text !== '') {
                if ($edit->replace_start <= $last_replace_start) {
                    self::debug("Giving up on $file: replacement conflicts with another replacement at $last_replace_start\n");
                    return null;
                }
                $last_replace_start = $edit->replace_start;
            }

            $new_contents .= \substr($contents, $last_end, $edit->replace_start - $last_end);
            // Append the empty string if this is a deletion, or a non-empty string for an insertion/replacement.
            $new_contents .= $edit->new_text;
            $last_end = $edit->replace_end;
        }
        $new_contents .= \substr($contents, $last_end);
        return $new_contents;
    }

    /**
     * Log an error message to be shown to users for unexpected errors.
     */
    public static function error(string $message): void
    {
        // @phan-suppress-next-line PhanPluginRemoveDebugCall
        \fwrite(\STDERR, $message);
    }

    /**
     * Log an extremely verbose message - used for debugging why automatic fixing doesn't work.
     */
    public static function debug(string $message): void
    {
        if (\getenv('PHAN_DEBUG_AUTOMATIC_FIX')) {
            // @phan-suppress-next-line PhanPluginRemoveDebugCall
            \fwrite(\STDERR, $message);
        }
    }
}
