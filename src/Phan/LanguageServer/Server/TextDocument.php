<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Server;

use InvalidArgumentException;
use Phan\Config;
use Phan\LanguageServer\FileMapping;
use Phan\LanguageServer\LanguageClient;
use Phan\LanguageServer\LanguageServer;
use Phan\LanguageServer\Logger;
use Phan\LanguageServer\Protocol\CompletionContext;
use Phan\LanguageServer\Protocol\Position;
use Phan\LanguageServer\Protocol\TextDocumentContentChangeEvent;
use Phan\LanguageServer\Protocol\TextDocumentIdentifier;
use Phan\LanguageServer\Protocol\TextDocumentItem;
use Phan\LanguageServer\Protocol\VersionedTextDocumentIdentifier;
use Phan\LanguageServer\Utils;
use Sabre\Event\Promise;

/**
 * Provides method handlers for all textDocument/* methods
 * Source: Based on https://github.com/felixfbecker/php-language-server/blob/master/src/Server/TextDocument.php
 * @phan-file-suppress PhanPossiblyNullTypeArgument, PhanPossiblyNullTypeArgumentInternal
 */
class TextDocument
{
    /**
     * The language client object to call methods on the client
     *
     * @var LanguageClient
     */
    protected $client;

    /**
     * The language client object to call methods on the server
     *
     * @var LanguageServer
     */
    protected $server;

    /**
     * Maps paths of files on disk to overrides.
     * @var FileMapping
     */
    protected $file_mapping;

    /**
     * @param LanguageClient $client
     * @param FileMapping $file_mapping
     */
    public function __construct(
        LanguageClient $client,
        LanguageServer $server,
        FileMapping $file_mapping
    ) {
        $this->client = $client;
        $this->server = $server;
        $this->file_mapping = $file_mapping;
    }


    /**
     * The document symbol request is sent from the client to the server to list all symbols found in a given text
     * document.
     * FIXME: reintroduce when support is added back
     *
     * @param TextDocumentIdentifier $textDocument
     * @return Promise <SymbolInformation[]>
     */
    /*
    public function documentSymbol(TextDocumentIdentifier $textDocument): Promise
    {
        return $this->documentLoader->getOrLoad($textDocument->uri)->then(function (PhpDocument $document) {
            $symbols = [];
            foreach ($document->getDefinitions() as $fqn => $definition) {
                $symbols[] = $definition->symbolInformation;
            }
            return $symbols;
        });
    }
     */

    /**
     * The document open notification is sent from the client to the server to signal newly opened text documents. The
     * document's truth is now managed by the client and the server must not try to read the document's truth using the
     * document's uri.
     *
     * @param TextDocumentItem $textDocument The document that was opened.
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function didOpen(TextDocumentItem $textDocument): void
    {
        Logger::logInfo("Called textDocument/didOpen, uri={$textDocument->uri}");
        try {
            Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return;
        }
        // TODO: Look into replacing this call with the normalized URI.
        $this->file_mapping->addOverrideURI($textDocument->uri, $textDocument->text);
        $this->server->analyzeURIAsync($textDocument->uri);

        //$document = $this->documentLoader->open($textDocument->uri, $textDocument->text);
        // TODO: make this trigger re-analysis
        // TODO: Check based on parse and analyze directories and Phan supported file extensions if this file affects Phan's analysis.
        // TODO:   Add functions to quickly check if a relative/absolute path is within the parse or analysis list of a project
        // TODO:   Maybe allow reloading .phan/config, at least the files and directories to parse/analyze
    }

    /**
     * The document save notification is sent from the client to the server when the document was saved in the client.
     * TODO: Should this use willSave instead
     * TODO: Why is this not triggering on Ctrl+S
     *
     * @param VersionedTextDocumentIdentifier $textDocument
     * @param string|null $text (NOTE: can't use ?T here)
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function didSave(TextDocumentIdentifier $textDocument, ?string $text = null): void
    {
        Logger::logInfo("Called textDocument/didSave, uri={$textDocument->uri} len(text)=" . \strlen($text ?? ''));
        try {
            Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return;
        }
        // TODO: Look into replacing this with the normalized URI
        $this->file_mapping->addOverrideURI($textDocument->uri, $text);
        $this->server->analyzeURIAsync($textDocument->uri);
    }

    /**
     * The document change notification is sent from the client to the server to signal changes to a text document.
     *
     * @param VersionedTextDocumentIdentifier $textDocument
     * @param TextDocumentContentChangeEvent[] $contentChanges
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function didChange(VersionedTextDocumentIdentifier $textDocument, array $contentChanges): void
    {
        foreach ($contentChanges as $change) {
            $this->file_mapping->addOverrideURI($textDocument->uri, $change->text);
        }
        Logger::logInfo("Called textDocument/didChange, uri={$textDocument->uri} version={$textDocument->version}");
        if (Config::getValue('language_server_analyze_only_on_save')) {
            // Track the change to the file, but don't trigger analysis.
            return;
        }
        $this->server->analyzeURIAsync($textDocument->uri);
        // TODO:   Maybe allow reloading .phan/config, at least the files and directories to parse/analyze
    }

    /**
     * The document close notification is sent from the client to the server when the document got closed in the client.
     * The document's truth now exists where the document's uri points to (e.g. if the document's uri is a file uri the
     * truth now exists on disk).
     *
     * @param TextDocumentIdentifier $textDocument The document that was closed
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function didClose(TextDocumentIdentifier $textDocument): void
    {
        Logger::logInfo("Called textDocument/didClose, uri={$textDocument->uri}");
        try {
            $uri = Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return;
        }
        $this->client->textDocument->publishDiagnostics($uri, []);
        // After publishing diagnostics, remove the override
        $this->file_mapping->removeOverrideURI($textDocument->uri);
    }

    /**
     * The "go to definition" request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return ?Promise <Location|Location[]|null>
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function definition(TextDocumentIdentifier $textDocument, Position $position): ?Promise
    {
        Logger::logInfo("Called textDocument/definition, uri={$textDocument->uri} position={$position->line}:{$position->character}");
        try {
            $uri = Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return null;
        }
        return $this->server->awaitDefinition($uri, $position, false);
    }

    /**
     * The "go to type definition" request is sent from the client to the server to resolve the definition location of a symbol
     * at a given text document position.
     *
     * @param TextDocumentIdentifier $textDocument The text document
     * @param Position $position The position inside the text document
     * @return ?Promise <Location|Location[]|null>
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function typeDefinition(TextDocumentIdentifier $textDocument, Position $position): ?Promise
    {
        Logger::logInfo("Called textDocument/typeDefinition, uri={$textDocument->uri} position={$position->line}:{$position->character}");
        try {
            $uri = Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return null;
        }
        return $this->server->awaitDefinition($uri, $position, true);
    }

    /**
     * Implements textDocument/hover, to show a preview of the element being hovered over.
     *
     * TODO: This can probably be optimized for references to constants, static methods, or tokens that obviously have no corresponding element.
     * TODO: Implement support for the cancel request LSP operation?
     *
     * @param TextDocumentIdentifier $textDocument @phan-unused-param
     * @param Position $position @phan-unused-param
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     */
    public function hover(TextDocumentIdentifier $textDocument, Position $position): ?Promise
    {
        // Some clients (e.g. emacs-lsp, the last time I checked)
        // don't respect the server's reported hover capability, and send this unconditionally.
        if (!Config::getValue('language_server_enable_hover')) {
            // Placeholder to avoid a performance degradation on clients
            // that aren't respecting the configuration.
            //
            // (computing hover response may or may not slow down those clients)
            return null;
        }
        try {
            $uri = Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return null;
        }
        return $this->server->awaitHover($uri, $position);
    }

    /**
     * Implements textDocument/completion, to compute completion items at a given cursor position.
     *
     * TODO: Implement support for the cancel request LSP operation?
     *
     * @param TextDocumentIdentifier $textDocument @phan-unused-param
     * @param Position $position @phan-unused-param
     * @suppress PhanUnreferencedPublicMethod called by client via AdvancedJsonRpc
     * @return ?Promise <CompletionItem[]|CompletionList>
     */
    public function completion(TextDocumentIdentifier $textDocument, Position $position, ?CompletionContext $context = null): ?Promise
    {
        if (!Config::getValue('language_server_enable_completion')) {
            // Placeholder to avoid a performance degradation on clients
            // that aren't respecting the configuration.
            //
            // (computing completion response may or may not slow down those clients)
            return null;
        }
        try {
            $uri = Utils::pathToUri(Utils::uriToPath($textDocument->uri));
        } catch (InvalidArgumentException $e) {
            Logger::logError(\sprintf("Language server could not understand uri %s in %s: %s\n", $textDocument->uri, __METHOD__, $e->getMessage()));
            return null;
        }
        // Workaround: Phan needs the cursor to be on the character that's within the expression in order to select it.
        // So shift the cursor left by one.
        $position->character = \max(0, $position->character - 1);
        return $this->server->awaitCompletion($uri, $position, $context);
    }
}
