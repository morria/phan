<?php

declare(strict_types=1);

namespace Phan\CodeBase;

use Closure;
use Phan\CodeBase;
use Phan\Daemon;
use Phan\Phan;

use function count;
use function in_array;

/**
 * UndoTracker maps a file path to a list of operations(e.g. Closures) that must be executed to
 * remove all traces of a file from the CodeBase, etc. if a file was removed or edited.
 * This is done to support running phan in daemon mode.
 * - Files will have to be re-parsed to get the new function signatures, check for new parse/analysis errors,
 *   and to update the class/function/method/property/constant/etc. definitions that would have to be created.
 *
 * If a file is edited, its contributions are undone, then it is parsed yet again.
 *
 * (We don't garbage collect reference cycles, so this attempts to work in a way that avoids cycles.
 *  Haven't verified that it does that as expected, yet)
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
class UndoTracker
{

    /**
     * @var ?string absolute path to currently parsed file, when in parse phase.
     * TODO: Does the Context->getFile() make keeping this redundant?
     */
    private $current_parsed_file;

    /**
     * @var array<string,list<Closure(CodeBase):void>>
     */
    private $undo_operations_for_path = [];

    /**
     * @var array<string,?string> Maps file paths to the modification dates and file size of the paths. - On ext4, milliseconds are available, but php APIs all return seconds.
     */
    private $file_modification_state = [];

    public function __construct()
    {
    }

    /**
     * @return list<string> - The list of files which are successfully parsed.
     * This changes whenever the file list is reloaded from disk.
     * This also includes files which don't declare classes or functions or globals,
     * because those files use classes/functions/constants.
     *
     * (This is the list prior to any analysis exclusion or whitelisting steps)
     */
    public function getParsedFilePathList(): array
    {
        return \array_keys($this->file_modification_state);
    }

    /**
     * @return int The size of $this->getParsedFilePathList()
     */
    public function getParsedFilePathCount(): int
    {
        return count($this->file_modification_state);
    }

    /**
     * Record that Phan has started parsing $current_parsed_file.
     *
     * This allows us to track which changes need to be undone when that file's contents change or the file gets removed.
     */
    public function setCurrentParsedFile(?string $current_parsed_file): void
    {
        if (\is_string($current_parsed_file)) {
            Daemon::debugf("Recording file modification state for %s", $current_parsed_file);
            // This shouldn't be null. TODO: Figure out what to do if it is.
            $this->file_modification_state[$current_parsed_file] = self::getFileState($current_parsed_file);
        }
        $this->current_parsed_file = $current_parsed_file;
    }


    /**
     * @return ?string - This string should change when the file is modified. Returns null if the file somehow doesn't exist
     */
    public static function getFileState(string $path): ?string
    {
        \clearstatcache(true, $path);  // TODO: does this work properly with symlinks? seems to.
        $real = \realpath($path);
        if (!\is_string($real)) {
            return null;
        }
        if (!\file_exists($real)) {
            return null;
        }
        $stat = @\stat($real);  // Double check: suppress to prevent Phan's error_handler from terminating on error.
        if (!$stat) {
            return null;  // It was missing or unreadable.
        }
        return \sprintf('%d_%d', $stat['mtime'], $stat['size']);
    }

    /**
     * Called when a file is unparsable.
     * Removes the classes and functions, etc. from an older version of the file, if one exists.
     */
    public function recordUnparsableFile(CodeBase $code_base, string $current_parsed_file): void
    {
        Daemon::debugf("%s was unparsable, had a syntax error", $current_parsed_file);
        Phan::getIssueCollector()->removeIssuesForFiles([$current_parsed_file]);
        $this->undoFileChanges($code_base, $current_parsed_file);
        unset($this->file_modification_state[$current_parsed_file]);
    }

    /**
     * Undoes all of the changes for the relative path at $path
     */
    private function undoFileChanges(CodeBase $code_base, string $path): void
    {
        Daemon::debugf("Undoing file changes for $path");
        foreach ($this->undo_operations_for_path[$path] ?? [] as $undo_operation) {
            $undo_operation($code_base);
        }
        unset($this->undo_operations_for_path[$path]);
    }

    /**
     * @param \Closure $undo_operation - a closure expecting 1 param - inner. It undoes a change caused by a parsed file.
     * Ideally, this would extend to all changes. (e.g. including dead code detection)
     */
    public function recordUndo(Closure $undo_operation): void
    {
        $file = $this->current_parsed_file;
        if (!\is_string($file)) {
            throw new \RuntimeException("Called recordUndo in CodeBaseMutable, but not parsing a file");
        }
        if (!isset($this->undo_operations_for_path[$file])) {
            $this->undo_operations_for_path[$file] = [];
        }
        $this->undo_operations_for_path[$file][] = $undo_operation;
    }

    /**
     * @param CodeBase $code_base - code base owning this tracker
     * @param list<string> $new_file_list
     * @param array<string,string> $file_mapping_contents
     * @param ?(string[]) $reanalyze_files files to re-parse before re-running analysis.
     *                    This fixes #1921
     * @return list<string> - Subset of $new_file_list which changed on disk and has to be parsed again. Automatically unparses the old versions of files which were modified.
     */
    public function updateFileList(CodeBase $code_base, array $new_file_list, array $file_mapping_contents, ?array $reanalyze_files = null): array
    {
        $new_file_set = [];
        foreach ($new_file_list as $path) {
            $new_file_set[$path] = true;
        }
        foreach ($file_mapping_contents as $path => $_) {
            $new_file_set[$path] = true;
        }
        unset($new_file_list);
        $removed_file_list = [];
        $changed_or_added_file_list = [];
        foreach ($new_file_set as $path => $_) {
            if (!isset($this->file_modification_state[$path])) {
                $changed_or_added_file_list[] = $path;
            }
        }
        foreach ($this->file_modification_state as $path => $state) {
            if (!isset($new_file_set[$path])) {
                $this->undoFileChanges($code_base, $path);
                $removed_file_list[] = $path;
                unset($this->file_modification_state[$path]);
                continue;
            }
            // TODO: Always invalidate the parsed file if we're about to analyze it?
            if (isset($file_mapping_contents[$path])) {
                // TODO: Move updateFileList to be called before fork()?
                $new_state = 'daemon:' . \sha1($file_mapping_contents[$path]);
            } else {
                $new_state = self::getFileState($path);
            }
            if ($new_state !== $state || in_array($path, $reanalyze_files ?? [], true)) {
                $removed_file_list[] = $path;
                $this->undoFileChanges($code_base, $path);
                // TODO: This will call stat() twice as much as necessary for the modified files. Not important.
                unset($this->file_modification_state[$path]);
                if ($new_state !== null) {
                    $changed_or_added_file_list[] = $path;
                }
            }
        }
        if (count($removed_file_list) > 0) {
            Phan::getIssueCollector()->removeIssuesForFiles($removed_file_list);
        }
        return $changed_or_added_file_list;
    }

    /**
     * @param CodeBase $code_base - code base owning this tracker
     * @param string $file_path
     * @return bool - true if the file existed
     */
    public function beforeReplaceFileContents(CodeBase $code_base, string $file_path): bool
    {
        if (!isset($this->file_modification_state[$file_path])) {
            Daemon::debugf("Tried to replace contents of '$file_path', but that path does not yet exist");
            return false;
        }
        Phan::getIssueCollector()->removeIssuesForFiles([$file_path]);
        $this->undoFileChanges($code_base, $file_path);
        unset($this->file_modification_state[$file_path]);
        return true;
    }
}
