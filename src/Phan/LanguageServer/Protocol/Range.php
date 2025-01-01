<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

use Phan\Language\FileRef;

/**
 * A range in a text document expressed as (zero-based) start and end positions.
 *
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/Range.php
 * See ../../../../LICENSE.LANGUAGE_SERVER
 * @phan-pure
 */
class Range
{
    /**
     * The range's start position.
     *
     * @var Position
     */
    public $start;

    /**
     * The range's end position.
     *
     * @var Position
     */
    public $end;

    /** @suppress PhanPossiblyNullTypeMismatchProperty anything useful will be non-null */
    public function __construct(?Position $start = null, ?Position $end = null)
    {
        $this->start = $start;
        $this->end = $end;
    }

    /**
     * Checks if a position is within the range
     *
     * @param Position $position
     * @suppress PhanUnreferencedPublicMethod
     */
    public function includes(Position $position): bool
    {
        return $this->start->compare($position) <= 0 && $this->end->compare($position) >= 0;
    }

    /**
     * Creates a placeholder Range which spans the entire line of source code of $context.
     */
    public static function fromContextOnSingleLine(FileRef $context): Range
    {
        $lineno = $context->getLineNumberStart();
        return new Range(new Position($lineno - 1, 0), new Position($lineno, 0));
    }

    /**
     * Create a range from a serialized array
     * @param array{start:array,end:array} $data
     */
    public static function fromArray(array $data): Range
    {
        return new self(
            Position::fromArray($data['start']),
            Position::fromArray($data['end'])
        );
    }
}
