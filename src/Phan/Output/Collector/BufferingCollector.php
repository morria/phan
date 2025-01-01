<?php

declare(strict_types=1);

namespace Phan\Output\Collector;

use Phan\CLI;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Output\Filter\AnyFilter;
use Phan\Output\IssueCollectorInterface;
use Phan\Output\IssueFilterInterface;

/**
 * BufferingCollector represents an issue collector that stores issues for use later.
 */
final class BufferingCollector implements IssueCollectorInterface
{

    /** @var array<string,IssueInstance> the issues that were collected */
    private $issues = [];

    /** @var IssueFilterInterface used to prevent some issues from being output (based on config and CLI options) */
    private $filter;

    /**
     * BufferingCollector constructor.
     * @param ?IssueFilterInterface $filter
     */
    public function __construct(?IssueFilterInterface $filter = null)
    {
        $this->filter = $filter ?? (new AnyFilter());
    }

    /**
     * @var ?string - This is null unless debugging.
     */
    private static $trace_issues = null;

    /**
     * Ensure that backtraces with the cause of the emitted issue are printed to stderr.
     * If null, stop emitting backtraces.
     */
    public static function setTraceIssues(?string $level): void
    {
        self::$trace_issues = \is_string($level) ? \strtolower($level) : null;
    }

    /**
     * Collect issue
     * @param IssueInstance $issue
     * @suppress PhanPluginRemoveDebugCall
     */
    public function collectIssue(IssueInstance $issue): void
    {
        if (!$this->filter->supports($issue)) {
            return;
        }
        if (self::$trace_issues) {
            CLI::printToStderr("Backtrace of {$issue->getIssue()->getType()} $issue is:\n");
            if (self::$trace_issues === Issue::TRACE_VERBOSE) {
                \phan_print_backtrace();
            } else {
                \ob_start();
                \debug_print_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
                CLI::printToStderr((\ob_get_clean() ?: "failed to dump backtrace") . \PHP_EOL);
            }
        }

        $this->issues[self::formatSortableKey($issue)] = $issue;
    }

    private static function formatSortableKey(IssueInstance $issue): string
    {
        // This needs to be a sortable key so that output
        // is in the expected order
        return \implode('|', [
            $issue->getFile(),
            \str_pad((string)$issue->getLine(), 5, '0', \STR_PAD_LEFT),
            $issue->getIssue()->getType(),
            $issue->getMessage()
        ]);
    }

    /**
     * @return list<IssueInstance>
     */
    public function getCollectedIssues(): array
    {
        \ksort($this->issues);
        return \array_values($this->issues);
    }

    /**
     * Clear the array of issues without outputting anything.
     *
     * Called after analysis ends.
     */
    public function flush(): void
    {
        $this->issues = [];
    }

    /**
     * Remove all collected issues (from the parse phase) for the given file paths.
     * Called from daemon mode.
     *
     * @param list<string> $files - the relative paths to those files
     */
    public function removeIssuesForFiles(array $files): void
    {
        $file_set = \array_flip($files);
        foreach ($this->issues as $key => $issue) {
            if (\array_key_exists($issue->getFile(), $file_set)) {
                unset($this->issues[$key]);
            }
        }
    }

    /**
     * Removes all collected issues.
     */
    public function reset(): void
    {
        $this->issues = [];
    }
}
