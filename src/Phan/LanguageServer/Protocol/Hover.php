<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * The result of a hover request.
 * @phan-file-suppress PhanWriteOnlyPublicProperty
 * @phan-immutable
 */
class Hover
{
    /**
     * @var MarkupContent The hover's content
     */
    public $contents;

    /**
     * @var Range|null an optional range inside a text document
     * that is used to visualize a hover, e.g. by changing the background color.
     */
    public $range;

    public function __construct(MarkupContent $contents, ?Range $range = null)
    {
        $this->contents = $contents;
        $this->range = $range;
    }

    /**
     * Creates a Hover object from a serialized array
     *
     * @param array{contents:array,range?:array} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            MarkupContent::fromArray($data['contents']),
            isset($data['range']) ? Range::fromArray($data['range']) : null
        );
    }
}
