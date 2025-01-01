<?php

declare(strict_types=1);

namespace Phan\AST\TolerantASTConverter;

use Exception;
use Throwable;

/**
 * An error in the polyfill PHP parser used for unparseable code.
 *
 * Should be handled similarly to parseError.
 * Note that getLine() can't be overridden
 */
class ParseException extends Exception
{
    /** @var int the line number of the unparsable file where parsing failed. */
    private $line_number_start;

    public function __construct(string $message, int $line_number_start, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->line_number_start = $line_number_start;
    }

    /**
     * Returns the line of the file being parsed that caused this ParseException.
     * @suppress PhanUnreferencedPublicMethod added for API completeness.
     */
    public function getLineNumberStart(): int
    {
        return $this->line_number_start;
    }
}
