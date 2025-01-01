<?php

declare(strict_types=1);

namespace Phan;

use Phan\Language\Element\TypedElementInterface;
use Phan\Language\Element\UnaddressableTypedElement;
use Phan\Language\FQSEN;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;
use Phan\Output\Colorizing;

/**
 * Represents an instance of an issue at a given file and line for the given template parameters.
 *
 * Some IssueInstances are created with suggestions for how they can be fixed
 *
 * @see Issue for how these are emitted. Visitors and plugins often have helper methods to emit issues.
 * @see OutputPrinter for how this is converted to various output formats
 * @phan-file-suppress PhanPluginNoCommentOnPublicMethod TODO: Add comments
 */
class IssueInstance
{
    /** @var Issue the generic category of issues */
    private $issue;

    /** @var string the file in which this issue was emitted. */
    private $file;

    /** @var int the line in which this issue was emitted. */
    private $line;

    /** @var int the 1-based column in which this issue was emitted. 0 means the column is unknown (php-ast cannot provide it). */
    private $column = 0;

    /** @var string the formatted issue message */
    private $message;

    /** @var ?Suggestion If this is non-null, this contains suggestions on how to resolve the error. */
    private $suggestion;

    /** @var list<string|int|float> $template_parameters If this is non-null, this contains the arguments emitted for this instance of the issue. */
    private $template_parameters;

    /**
     * @param Issue $issue
     * @param string $file
     * @param int $line
     * @param list<string|int|float|FQSEN|Type|UnionType|TypedElementInterface|UnaddressableTypedElement> $template_parameters
     * @param ?Suggestion $suggestion
     * @param int $column
     * @suppress PhanPluginRemoveDebugAny
     */
    public function __construct(
        Issue $issue,
        string $file,
        int $line,
        array $template_parameters,
        ?Suggestion $suggestion = null,
        int $column = 0
    ) {
        $this->issue = $issue;
        $this->file = $file;
        $this->line = $line;
        $this->column = $column;
        $this->suggestion = $suggestion;

        if ($issue->getExpectedArgumentCount() !== \count($template_parameters)) {
            CLI::printWarningToStderr(
                \sprintf("Unexpected argument count for %s('%s'): Expected %d args, got %d\n", $issue->getType(), $issue->getTemplate(), $issue->getExpectedArgumentCount(), \count($template_parameters))
            );
            \ob_start();
            \debug_print_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS);
            \fwrite(\STDERR, (string)\ob_get_clean());
        }
        // color_issue_message will interfere with some formatters, such as xml.
        if (Config::getValue('color_issue_messages')) {
            $this->message = self::generateColorizedMessage($issue, $template_parameters);
        } else {
            $this->message = self::generatePlainMessage($issue, $template_parameters);
        }
        // The terminal color codes are valid utf-8 (all control code bytes <= 127)
        $this->message = StringUtil::asSingleLineUtf8($this->message);

        // Fixes #1754 : Some issue template parameters might not be serializable (for passing to ForkPool)

        /**
         * @param string|float|int|FQSEN|Type|UnionType|TypedElementInterface|UnaddressableTypedElement $parameter
         * @return string|float|int
         */
        $this->template_parameters = \array_map(static function ($parameter) {
            if (\is_object($parameter)) {
                return (string)$parameter;
            }
            return $parameter;
        }, $template_parameters);
    }

    /**
     * @param list<string|int|float|object> $template_parameters
     */
    private static function generatePlainMessage(
        Issue $issue,
        array $template_parameters
    ): string {
        $template = $issue->getTemplate();

        // markdown_issue_messages doesn't make sense with color, unless you add <span style="color:red">msg</span>
        // Not sure if codeclimate supports that.
        if (Config::getValue('markdown_issue_messages')) {
            $template = \preg_replace(
                '/([^ ]*%s[^ ]*)/',
                '`\1`',
                $template
            );
        }
        // @phan-suppress-next-line PhanPluginPrintfVariableFormatString the template is provided by Phan/its plugins
        return \vsprintf(
            $template,
            self::normalizeTemplateParameters($template_parameters)
        );
    }

    /**
     * @param list<string|int|float|FQSEN|Type|UnionType|TypedElementInterface|UnaddressableTypedElement> $template_parameters
     */
    private static function generateColorizedMessage(
        Issue $issue,
        array $template_parameters
    ): string {
        $template = $issue->getTemplateRaw();

        $result = Colorizing::colorizeTemplate($template, self::normalizeTemplateParameters($template_parameters));
        return $result;
    }

    /**
     * @param list<string|int|float|object> $template_parameters
     * @return list<string|int|float|object>
     */
    private static function normalizeTemplateParameters(array $template_parameters): array
    {
        foreach ($template_parameters as $i => $parameter) {
            if ($parameter instanceof UnionType) {
                $parameter = $parameter->__toString();
                if ($parameter === '') {
                    $parameter = '(empty union type)';
                }
                $template_parameters[$i] = $parameter;
            }
        }
        return $template_parameters;
    }

    /**
     * @return ?Suggestion If this is non-null, this contains suggestions on how to resolve the error.
     */
    public function getSuggestion(): ?Suggestion
    {
        return $this->suggestion;
    }

    /** @return list<string|int|float|FQSEN|Type|UnionType> $template_parameters */
    public function getTemplateParameters(): array
    {
        return $this->template_parameters;
    }

    public function getSuggestionMessage(): ?string
    {
        if (!$this->suggestion) {
            return null;
        }
        $text = $this->suggestion->getMessage();
        if (!StringUtil::isNonZeroLengthString($text)) {
            return null;
        }
        return StringUtil::asSingleLineUtf8($text);
    }

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getDisplayedFile(): string
    {
        if (Config::getValue('absolute_path_issue_messages')) {
            return Config::projectPath($this->file);
        }
        return $this->file;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    /**
     * @return int the 1-based column, or 0 if the column is unknown.
     */
    public function getColumn(): int
    {
        return $this->column;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMessageAndMaybeSuggestion(): string
    {
        $message = $this->message;
        $suggestion = $this->getSuggestionMessage();
        if (StringUtil::isNonZeroLengthString($suggestion)) {
            return $message . ' (' . $suggestion . ')';
        }
        return $message;
    }

    public function __toString(): string
    {
        return "{$this->file}:{$this->line} {$this->getMessageAndMaybeSuggestion()}";
    }
}
