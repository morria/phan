<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/InitializeResult.php
 * See ../../../../LICENSE.LANGUAGE_SERVER
 */
class InitializeResult
{
    /**
     * The capabilities the language server provides.
     *
     * @var ServerCapabilities
     * @suppress PhanWriteOnlyPublicProperty (Used by AdvancedJsonRpc)
     */
    public $capabilities;

    /**
     * @param ?ServerCapabilities $capabilities
     */
    public function __construct(?ServerCapabilities $capabilities = null)
    {
        $this->capabilities = $capabilities ?? new ServerCapabilities();
    }
}
