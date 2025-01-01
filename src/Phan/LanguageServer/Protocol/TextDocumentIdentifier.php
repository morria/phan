<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/TextDocumentIdentifier.php
 * See ../../../../LICENSE.LANGUAGE_SERVER
 * @phan-immutable
 */
class TextDocumentIdentifier
{
    /**
     * The text document's URI.
     *
     * @var string|null
     */
    public $uri;

    /**
     * @param string|null $uri The text document's URI.
     */
    public function __construct(?string $uri = null)
    {
        $this->uri = $uri;
    }
}
