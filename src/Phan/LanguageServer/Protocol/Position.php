<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * Position in a text document expressed as zero-based line and character offset.
 *
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/Position.php
 * See ../../../../LICENSE.LANGUAGE_SERVER
 */
class Position
{
    /**
     * Line position in a document (zero-based).
     *
     * @var int
     */
    public $line;

    /**
     * Character offset on a line in a document (zero-based).
     *
     * @var int
     */
    public $character;

    /**
     * @suppress PhanPossiblyNullTypeMismatchProperty
     */
    public function __construct(?int $line = null, ?int $character = null)
    {
        $this->line = $line;
        $this->character = $character;
    }

    /**
     * Compares this position to another position
     * Returns
     *  - 0 if the positions match
     *  - a negative number if $this is before $position
     *  - a positive number otherwise
     *
     * @param Position $position
     */
    public function compare(Position $position): int
    {
        if ($this->line !== $position->line) {
            return $this->line - $position->line;
        }

        return $this->character - $position->character;
    }

    /**
     * Returns the offset of the position in a string
     *
     * @param string $content
     * @suppress PhanUnreferencedPublicMethod
     */
    public function toOffset(string $content): int
    {
        $lines = \explode("\n", $content);
        $slice = \array_slice($lines, 0, $this->line);
        // TODO: array_sum should infer sum of ints is typically an int
        return ((int)\array_sum(\array_map('strlen', $slice))) + \count($slice) + $this->character;
    }

    /**
     * Creates a Position from a serialized array $data
     * @param array{line:int,character?:?int} $data
     */
    public static function fromArray(array $data): Position
    {
        return new self(
            $data['line'],
            $data['character'] ?? null
        );
    }

    /**
     * Used for debugging
     */
    public function __toString(): string
    {
        return "$this->line:$this->character";
    }
}
