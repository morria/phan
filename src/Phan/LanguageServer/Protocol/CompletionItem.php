<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * A suggestion item for a completion suggested by the language server.
 * @phan-file-suppress PhanWriteOnlyPublicProperty this is sent to the language client
 */
class CompletionItem
{
    /**
     * The label of this completion item. By default
     * also the text that is inserted when selecting
     * this completion.
     *
     * @var string
     */
    public $label;

    /**
     * The kind of this completion item. Based of the kind
     * an icon is chosen by the editor.
     *
     * @var int|null
     */
    public $kind;

    /**
     * A human-readable string with additional information
     * about this item, like type or symbol information.
     *
     * @var string|null
     */
    public $detail;

    /**
     * A human-readable string that represents a doc-comment.
     *
     * @var string|null
     */
    public $documentation;

    /**
     * A string that should be used when comparing this item
     * with other items. When `falsy` the label is used.
     *
     * @var string|null
     */
    public $sortText;

    /**
     * A string that should be used when filtering a set of
     * completion items. When `falsy` the label is used.
     *
     * @var string|null
     */
    public $filterText;

    /**
     * A string that should be inserted in a document when selecting
     * this completion. When `falsy` the label is used.
     *
     * @var string|null
     *
     * TODO: Switch to textEdit once the column is tracked, this is deprecated
     */
    public $insertText;

    /**
     * @param string|null     $label
     * @param int|null        $kind
     * @param string|null     $detail
     * @param string|null     $documentation
     * @param string|null     $sortText
     * @param string|null     $filterText
     * @param string|null     $insertText
     */
    public function __construct(
        ?string $label = null,
        ?int $kind = null,
        ?string $detail = null,
        ?string $documentation = null,
        ?string $sortText = null,
        ?string $filterText = null,
        ?string $insertText = null
    ) {
        // @phan-suppress-next-line PhanPossiblyNullTypeMismatchProperty the '(at)var string' annotation is used by the RPC library
        $this->label = $label;
        $this->kind = $kind;
        $this->detail = $detail;
        $this->documentation = $documentation;
        $this->sortText = $sortText;
        $this->filterText = $filterText;
        $this->insertText = $insertText;
    }

    /**
     * Create a CompletionItem from a serialized array $data
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): CompletionItem
    {
        return new self(
            $data['label'],
            $data['kind'],
            $data['detail'],
            $data['documentation'],
            $data['sortText'],
            $data['filterText'],
            $data['insertText']
        );
    }
}
