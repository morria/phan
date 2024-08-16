<?php

declare(strict_types=1);

namespace Phan\Output\Printer;

use AssertionError;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Library\StringUtil;
use Phan\Output\BufferedPrinterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This printer prints issues intended for use by Gitlab and the Code Quality Widget.
 *
 * @see https://docs.gitlab.com/ee/ci/testing/code_quality.html#implement-a-custom-tool
 */
class GitlabPrinter implements BufferedPrinterInterface
{
    private const GITLAB_SEVERITY_INFO = 'info';
    private const GITLAB_SEVERITY_MINOR = 'minor';

    /** @var OutputInterface an output that JSON encoded can be written to. */
    protected OutputInterface $output;

    /** @var list<array<string,mixed>> the issue data to be JSON encoded. */
    protected array $messages = [];

    public function print(IssueInstance $instance): void
    {
        $message = [
            'description' => $instance->getIssue()->getType() . ' ' . $instance->getMessage(),
            'check_name' => $instance->getIssue()->getType(),
            'fingerprint' => $this->generateFingerprint($instance),
            'severity' => $this->getSeverityName($instance->getIssue()),
            'location' => [
                'path' => $instance->getDisplayedFile(),
                'lines' => [
                    'begin' => $instance->getLine()
                ],
            ],
        ];

        if ($instance->getColumn() > 0) {
            $message['location']['lines']['begin_column'] = $instance->getColumn();
        }

        $suggestion = $instance->getSuggestionMessage();

        if (StringUtil::isNonZeroLengthString($suggestion)) {
            $message['suggestion'] = $suggestion;
        }

        $this->messages[] = $message;
    }

    private function generateFingerprint(IssueInstance $instance): string
    {
        return md5($instance->getDisplayedFile() . ':' . $instance->getLine() . ':' . $instance->getIssue()->getType());
    }

    private function getSeverityName(Issue $issue): string
    {
        switch ($issue->getSeverity()) {
            case Issue::SEVERITY_LOW:
                return self::GITLAB_SEVERITY_INFO;
            case Issue::SEVERITY_NORMAL:
                return self::GITLAB_SEVERITY_MINOR;
            default:
                return $issue->getSeverityName();
        }
    }

    /** flush printer buffer */
    public function flush(): void
    {
        // NOTE: Need to use OUTPUT_RAW for JSON.
        // Otherwise, error messages such as "...Unexpected << (T_SL)" don't get formatted properly (They get escaped into unparsable JSON)
        $encoded_message = json_encode(
            $this->messages,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if (!is_string($encoded_message)) {
            throw new AssertionError('Failed to encode anything for what should be an array');
        }

        $this->output->write($encoded_message . "\n", false, OutputInterface::OUTPUT_RAW);

        $this->messages = [];
    }

    public function configureOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }
}
