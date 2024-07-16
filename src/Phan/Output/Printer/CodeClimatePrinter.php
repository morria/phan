<?php

declare(strict_types=1);

namespace Phan\Output\Printer;

use Phan\Issue;
use Phan\IssueInstance;
use Phan\Library\StringUtil;
use Phan\Output\BufferedPrinterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This prints issues in the CodeClimate zero byte separated JSON format to the configured OutputInterface.
 */
final class CodeClimatePrinter implements BufferedPrinterInterface
{

    private const CODECLIMATE_SEVERITY_INFO = 'info';
    private const CODECLIMATE_SEVERITY_CRITICAL = 'critical';
    private const CODECLIMATE_SEVERITY_NORMAL = 'normal';

    /** @var OutputInterface an output that zero byte separated JSON can be written to.  */
    private $output;

    /** @var list<array> a list of associative arrays with codeclimate issue fields. */
    private $messages = [];

    public function print(IssueInstance $instance): void
    {
        $message = [
            'type' => 'issue',
            'check_name' => $instance->getIssue()->getType(),
            'description' => $instance->getMessageAndMaybeSuggestion(),
            'categories' => ['Bug Risk'],
            'severity' => self::mapSeverity($instance->getIssue()->getSeverity()),
            'location' => [
                // XXX this puts the docker volume in /code/ when running on codeclimate.
                'path' => \preg_replace('/^\/code\//', '', $instance->getFile()),
                'lines' => [
                    'begin' => $instance->getLine(),
                    'end' => $instance->getLine(),
                ],
            ],
        ];
        if ($instance->getColumn() !== 0) {
            $message['location']['lines']['column'] = $instance->getColumn();
        }

        $this->messages[] = $message;
    }

    private static function mapSeverity(int $raw_severity): string
    {
        $severity = self::CODECLIMATE_SEVERITY_INFO;
        switch ($raw_severity) {
            case Issue::SEVERITY_CRITICAL:
                $severity = self::CODECLIMATE_SEVERITY_CRITICAL;
                break;
            case Issue::SEVERITY_NORMAL:
                $severity = self::CODECLIMATE_SEVERITY_NORMAL;
                break;
        }

        return $severity;
    }

    /** flush printer buffer */
    public function flush(): void
    {

        // See https://github.com/codeclimate/spec/blob/master/SPEC.md#output
        // for details on the CodeClimate output format
        foreach ($this->messages as $message) {
            $encoded_message = StringUtil::jsonEncode($message) . "\0";
            $this->output->write($encoded_message, false, OutputInterface::OUTPUT_RAW);
        }
        $this->messages = [];
    }

    public function configureOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }
}
