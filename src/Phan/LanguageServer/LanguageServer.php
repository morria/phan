<?php

declare(strict_types=1);

namespace Phan\LanguageServer;

use AdvancedJsonRpc;
use AssertionError;
use Closure;
use Exception;
use Phan\CLI;
use Phan\CodeBase;
use Phan\Config;
use Phan\Daemon\ExitException;
use Phan\Daemon\Request;
use Phan\Daemon\Transport\CapturerResponder;
use Phan\Daemon\Transport\StreamResponder;
use Phan\Issue;
use Phan\Language\Element\MarkupDescription;
use Phan\Language\FileRef;
use Phan\LanguageServer\Protocol\ClientCapabilities;
use Phan\LanguageServer\Protocol\CompletionContext;
use Phan\LanguageServer\Protocol\CompletionItem;
use Phan\LanguageServer\Protocol\CompletionOptions;
use Phan\LanguageServer\Protocol\Diagnostic;
use Phan\LanguageServer\Protocol\DiagnosticSeverity;
use Phan\LanguageServer\Protocol\Hover;
use Phan\LanguageServer\Protocol\InitializeResult;
use Phan\LanguageServer\Protocol\Location;
use Phan\LanguageServer\Protocol\Message;
use Phan\LanguageServer\Protocol\Position;
use Phan\LanguageServer\Protocol\Range;
use Phan\LanguageServer\Protocol\SaveOptions;
use Phan\LanguageServer\Protocol\ServerCapabilities;
use Phan\LanguageServer\Protocol\TextDocumentSyncKind;
use Phan\LanguageServer\Protocol\TextDocumentSyncOptions;
use Phan\LanguageServer\Server\TextDocument;
use Phan\Library\StringUtil;
use Phan\Phan;
use Sabre\Event\Loop;
use Sabre\Event\Promise;
use Throwable;

use function count;
use function get_class;
use function is_array;
use function is_string;
use function Sabre\Event\coroutine;
use function strlen;

use const EXIT_FAILURE;
use const SIGCHLD;
use const STDERR;
use const STDIN;
use const STDOUT;

/**
 * Based on https://github.com/felixfbecker/php-language-server/blob/master/bin/php-language-server.php
 * and https://github.com/felixfbecker/php-language-server/blob/master/src/LanguageServer.php (for language server protocol implementation)
 *
 * This is similar to Phan daemon mode, but it's possible for it to receive concurrent events, or more than one type of event.
 * (in addition to file notifications, etc.)
 *
 * What will do in the most common case (checking for errors in a file):
 *
 * 0. Phan completes the parse phase, on the initial version of the codebase
 * 1. Phan starts a Sabre event loop and listens for requests and responses: see http://sabre.io/event/loop/
 *    It registers addReadStream and addWriteStream (See ProtocolStreamReader and ProtocolStreamWriter)
 * 2. Phan receives a notification that a file changed/was added/was removed
 * 3. If the files are within the phan project (contains .phan directory),
 *    then phan removes the old parse state of removed/changed files, then adds the new parse state of changed/added files.
 * 4. The main phan process (managing the event loop) fork, the fork shuts down the event loop without receiving any more events or touching the stream(will this work?)
 * 5. The forked process runs the analysis.
 * 6. The forked process reports the analysis result to the main process via IPC (similar to what \Phan\ForkPool does), along with the id of the request
 * 7. Phan notifies the client of the new issues ("diagnostic" in the open Language Server Protocol)
 *    See https://github.com/Microsoft/language-server-protocol#language-server-protocol
 *
 * TODO: support textDocument rename
 */
class LanguageServer extends AdvancedJsonRpc\Dispatcher
{
    /**
     * Handles workspace/* method calls
     *
     * @var Server\Workspace
     * @suppress PhanWriteOnlyPublicProperty used by AdvancedJsonRpc\Dispatcher via reflection
     */
    public $workspace;

    /**
     * @var ProtocolReader
     * This reads and unserializes requests(or responses) and notifications from the language server client.
     */
    protected $protocolReader;

    /**
     * @var ProtocolWriter
     * This serializes and sends responses(or requests) and notifications to the language server client.
     */
    protected $protocolWriter;

    /**
     * @var LanguageClient
     * Used to interact with the remote language server client.
     */
    protected $client;

    /**
     * Handles textDocument/* method calls
     * (e.g. whenever a text document is opened, saved, or closed)
     *
     * @var TextDocument
     */
    public $textDocument;

    /**
     * This contains the most recent daemon request to analyze a set of files (and optionally return information).
     *
     * Phan's support for the language server protocol is based on an earlier asynchronous mode called "Daemon mode"
     *
     * The most recent request is temporarily saved in this property so that Phan knows which forked process to communicate with.
     *
     * @var Request|null
     */
    protected $most_recent_request;

    /**
     * @var CodeBase The code base within which we're operating
     */
    protected $code_base;

    /**
     * Lister of files that Phan would parse - many of these won't change and won't require re-analysis
     * @var Closure
     */
    protected $file_path_lister;

    /**
     * This maps file URIs from the language client to/from absolute paths of files on disk.
     *
     * This is useful to send the language client the same URI that it sent us in requests back in our responses.
     *
     * @var FileMapping
     */
    protected $file_mapping;

    /**
     * Is the language server still accepting new requests from clients?
     *
     * @var bool
     */
    private $is_accepting_new_requests = true;

    /**
     * @var array<string,string> maps Paths to URIs, for URIs which have pending analysis requests.
     * Requests are buffered because the language server may otherwise send requests faster than Phan can respond to them.
     * (`$reader->on('readMessageGroup')` notifies Phan that a group of 1 or more messages was read)
     */
    protected $analyze_request_set = [];

    /**
     * @var ?NodeInfoRequest
     *
     * Contains the promise for the most recent "Go to definition" request.
     * If more than one such request exists, the earlier requests will be discarded.
     *
     * TODO: Will need to Resolve(null) for the older requests.
     */
    protected $most_recent_node_info_request = null;
    /**
     * @var ?CachedHoverResponse
     *
     * Contains the result of the previous hover response and information used to validate the cached information
     */
    protected $cached_hover_response = null;

    /**
     * Constructs the only instance of the language server
     */
    public function __construct(ProtocolReader $reader, ProtocolWriter $writer, CodeBase $code_base, Closure $file_path_lister)
    {
        parent::__construct($this, '/');
        $this->protocolReader = $reader;
        $this->file_mapping = new FileMapping();
        $reader->on('close', function (): void {
            if (!$this->is_accepting_new_requests) {
                // This is the forked process, which forced the ProtocolReader to close. Don't exit().
                // Instead, carry on and analyze the input files.
                return;
            }
            $this->shutdown();
            $this->exit();
        });
        $reader->on('message', function (Message $msg): void {
            /** @suppress PhanUndeclaredProperty Request->body->id is a request with an id */
            coroutine(function () use ($msg): \Generator {
                $body = $msg->body;
                if (!$body) {
                    return;
                }
                // Ignore responses, this is the handler for requests and notifications
                if (AdvancedJsonRpc\Response::isResponse($body)) {
                    return;
                }
                Logger::logInfo('Received message in coroutine: ' . (string)$body);
                $result = null;
                $error = null;
                try {
                    // Invoke the method handler to get a result
                    $result = yield $this->dispatch($body);
                } catch (AdvancedJsonRpc\Error $e) {
                    Logger::logInfo('Saw error: ' . $e->getMessage());
                    // If a ResponseError is thrown, send it back in the Response
                    $error = $e;
                } catch (Throwable $e) {
                    Logger::logInfo('Saw Throwable: ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString());
                    // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                    $error = new AdvancedJsonRpc\Error(
                        (string)$e,
                        AdvancedJsonRpc\ErrorCode::INTERNAL_ERROR,
                        null,
                        $e
                    );
                }
                // Only send a Response for a Request
                // Notifications do not send Responses
                if (AdvancedJsonRpc\Request::isRequest($body)) {
                    if ($error !== null) {
                        $responseBody = new AdvancedJsonRpc\ErrorResponse($body->id, $error);
                    } else {
                        $responseBody = new AdvancedJsonRpc\SuccessResponse($body->id, $result);
                    }
                    $this->protocolWriter->write(new Message($responseBody));
                }
            })->otherwise('\\Phan\\LanguageServer\\Utils::crash');
        });

        $reader->on('readMessageGroup', function (): void {
            $this->finalizeAnalyzingURIs();
        });

        $this->protocolWriter = $writer;
        // We create a client to send diagnostics, etc. to the IDE
        $this->client = new LanguageClient($reader, $writer);
        // We create a workspace to receive change notifications.
        $this->workspace = new Server\Workspace($this->client, $this, $this->file_mapping);

        // Phan specific code
        $this->code_base = $code_base;
        $this->file_path_lister = $file_path_lister;
    }

    /**
     * This creates an analyzing daemon, to be used by IDEs.
     * Format:
     *
     * - Read over TCP socket with JSONRPC 2
     * - Respond over TCP socket with JSONRPC 2
     *
     * @param CodeBase $code_base (Must have undo tracker enabled)
     *
     * @param \Closure $file_path_lister
     * Returns string[] - A list of files to scan. This may be different from the previous contents.
     *
     * @param array<string,mixed> $options (leave empty for stdout)
     *
     * @return Request|null - A writeable request, which has been fully read from.
     * Callers should close after they are finished writing.
     *
     * @suppress PhanUndeclaredConstant, UnusedSuppression (pcntl unavailable on Windows)
     */
    public static function run(CodeBase $code_base, Closure $file_path_lister, array $options): ?Request
    {
        if (!$code_base->isUndoTrackingEnabled()) {
            throw new AssertionError("Expected undo tracking to be enabled");
        }
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(
                SIGCHLD,
                /**
                 * @param ?(int|array) $status
                 */
                static function (int $signo, $status = null, ?int $pid = null): void {
                    Request::childSignalHandler($signo, $status, $pid);
                }
            );
        }

        $make_language_server = static function (ProtocolStreamReader $in, ProtocolStreamWriter $out) use ($code_base, $file_path_lister): LanguageServer {
            return new LanguageServer(
                $in,
                $out,
                $code_base,
                $file_path_lister
            );
        };
        // example requests over TCP
        // Assumes that clients send and close the their requests quickly, then wait for a response.

        // {"method":"analyze","files":["/path/to/file1.php","/path/to/file2.php"]}

        // FIXME add re-parsing files to actions taken during loop
        /*
        $socket_server = self::createDaemonStreamSocketServer();
        // TODO: Limit the maximum number of active processes to a small number(4?)
        // TODO: accept SIGCHLD when child terminates, somehow?
        try {
            $gotSignal = false;
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGCHLD, function(...$args) use(&$gotSignal) {
                    $gotSignal = true;
                    Request::childSignalHandler(...$args);
                });
            }
            while (true) {
                $gotSignal = false;  // reset this.
                // We get an error from stream_socket_accept. After the RuntimeException is thrown, pcntl_signal is called.
                $previousErrorHandler = set_error_handler(function ($severity, $message, $file, $line) use (&$previousErrorHandler) {
                    self::debugf("In new error handler '$message'");
                    if (!preg_match('/stream_socket_accept/i', $message)) {
                        return $previousErrorHandler($severity, $message, $file, $line);
                    }
                    throw new \RuntimeException("Got signal");
                });

                $conn = false;
                try {
                    $conn = stream_socket_accept($socket_server, -1);
                } catch(\RuntimeException $e) {
                    self::debugf("Got signal");
                    pcntl_signal_dispatch();
                    self::debugf("done processing signals");
                    if ($gotSignal) {
                        continue;  // Ignore notices from stream_socket_accept if it's due to being interrupted by a child process terminating.
                    }
                } finally {
                    restore_error_handler();
                }

                if (!$conn) {
                    // If we didn't get a connection, and it wasn't due to a signal from a child process, then stop the daemon.
                    break;
                }
                $request = Request::accept($code_base, $file_path_lister, $conn);
                if ($request instanceof Request) {
                    return $request;  // We forked off a worker process successfully, and this is the worker process
                }
            }
            error_log("Stopped accepting connections");
        } finally {
            restore_error_handler();
        }
        return null;
         */
        if (isset($options['tcp'])) {
            // Connect to a TCP server
            $address = $options['tcp'];
            $socket = \stream_socket_client('tcp://' . $address, $errno, $errstr);
            if ($socket === false) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, "Could not connect to language client. Error $errno\n$errstr");
                exit(1);
            }
            \stream_set_blocking($socket, false);
            $ls = $make_language_server(new ProtocolStreamReader($socket), new ProtocolStreamWriter($socket));
            Logger::logInfo("Connected to $address to receive requests");
            Loop\run();
            Logger::logInfo("Finished connecting to $address to receive requests");
            $most_recent_request = $ls->most_recent_request;
            $ls->most_recent_request = null;
            return $most_recent_request;
        } elseif (isset($options['tcp-server'])) {
            // Run a TCP Server
            $address = $options['tcp-server'];
            $tcpServer = \stream_socket_server('tcp://' . $address, $errno, $errstr);
            if ($tcpServer === false) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, "Could not listen on $address. Error $errno\n$errstr");
                exit(1);
            }
            // @phan-suppress-next-line PhanPluginRemoveDebugCall stdout is deliberate
            \fwrite(STDOUT, "Server listening on $address\n");
            if (!\extension_loaded('pcntl')) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, "PCNTL is not available. Only a single connection will be accepted\n");
            }
            while ($socket = \stream_socket_accept($tcpServer, -1)) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall stdout is deliberate
                \fwrite(STDOUT, "Connection accepted\n");
                \stream_set_blocking($socket, false);
                /**if (false && extension_loaded('pcntl')) {  // FIXME re-enable, this was disabled to simplify testing
                    // TODO: This will work, but does it make sense?

                    // If PCNTL is available, fork a child process for the connection
                    // An exit notification will only terminate the child process
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        fwrite(STDERR, "Could not fork\n");
                        exit(1);
                    } else if ($pid === 0) {
                        // Child process
                        $reader = new ProtocolStreamReader($socket);
                        $writer = new ProtocolStreamWriter($socket);
                        $reader->on('close', function () {
                            fwrite(STDOUT, "Connection closed\n");
                        });
                        $ls = $make_language_server($reader, $writer);
                        Logger::logInfo("Worker started accepting requests on $address");
                        Loop\run();
                        Logger::logInfo("Worker finished accepting requests on $address");
                        $most_recent_request = $ls->most_recent_request;
                        $ls->most_recent_request = null;
                        return $most_recent_request;;
                    }
                } else {*/
                    // To avoid edge cases, we only accept one connection.
                    // If PCNTL is not available, we only accept one connection.
                    // An exit notification will terminate the server
                    $ls = $make_language_server(
                        new ProtocolStreamReader($socket),
                        new ProtocolStreamWriter($socket)
                    );
                    Logger::logInfo("Started listening on tcp");
                    Loop\run();
                    Logger::logInfo("Finished listening on tcp");
                    $most_recent_request = $ls->most_recent_request;
                    $ls->most_recent_request = null;
                    return $most_recent_request;
                /* } */
            }
            return null;
        } else {
            if ($options['stdin'] !== true) {
                throw new AssertionError("Expected either 'stdin', 'tcp-server', or 'tcp' as the language server communication option");
            }
            // Use STDIO
            \stream_set_blocking(STDIN, false);
            $ls = $make_language_server(
                new ProtocolStreamReader(STDIN),
                new ProtocolStreamWriter(STDOUT)
            );
            Logger::logInfo("Started listening on stdin");
            Loop\run();
            Logger::logInfo("Finished listening on stdin");
            $most_recent_request = $ls->most_recent_request;
            $ls->most_recent_request = null;
            return $most_recent_request;
        }
    }

    /**
     * Asynchronously analyze the given URI.
     */
    public function analyzeURIAsync(string $uri): void
    {
        $path_to_analyze = Utils::uriToPath($uri);
        Logger::logInfo("Called analyzeURIAsync, uri=$uri, path=$path_to_analyze");
        $this->analyze_request_set[$path_to_analyze] = $uri;
        // Clear the cached hover response - a file was probably opened, modified, or saved/closed.
        $this->cached_hover_response = null;
        // Don't call file_path_lister immediately -
        // That has to walk the directories in .phan/config.php to see if the requested path is included and not excluded.
    }

    /**
     * Asynchronously generates the definition for a given URL and position.
     * @return Promise <Location|Location[]|null>
     */
    public function awaitDefinition(
        string $uri,
        Position $position,
        bool $is_type_definition_request
    ): Promise {
        // TODO: Add a way to "go to definition" (etc.) without emitting analysis results as a side effect
        $path_to_analyze = Utils::uriToPath($uri);
        $logType = $is_type_definition_request ? 'awaitTypeDefinition' : 'awaitDefinition';
        Logger::logInfo("Called LanguageServer->$logType, uri=$uri, position=" . StringUtil::jsonEncode($position));
        $type = $is_type_definition_request ? GoToDefinitionRequest::REQUEST_TYPE_DEFINITION : GoToDefinitionRequest::REQUEST_DEFINITION;
        $this->discardPreviousNodeInfoRequest();
        $request = new GoToDefinitionRequest($uri, $position, $type);
        $this->most_recent_node_info_request = $request;

        // We analyze this url so that Phan is aware enough of the types and namespace maps to trigger "Go to definition"
        // E.g. going to the definition of `Bar` in `use Foo as Bar; Bar::method();` requires parsing other statements in this file, not just the name in question.
        //
        // NOTE: This also ensures that we will run analysis, because of the check for analyze_request_set being non-empty
        $this->analyze_request_set[$path_to_analyze] = $uri;
        return $request->getPromise();
    }

    /**
     * Asynchronously generates the hover text for a given URL and position.
     *
     * @return Promise <Hover|null>
     */
    public function awaitHover(
        string $uri,
        Position $position
    ): Promise {
        MarkupDescription::eagerlyLoadAllDescriptionMaps();
        // TODO: Add a way to "go to definition" without emitting analysis results as a side effect
        $path_to_analyze = Utils::uriToPath($uri);
        Logger::logInfo("Called LanguageServer->awaitHover, uri=$uri, position=" . StringUtil::jsonEncode($position));
        $request = new GoToDefinitionRequest($uri, $position, GoToDefinitionRequest::REQUEST_HOVER);
        $this->discardPreviousNodeInfoRequest();
        $this->most_recent_node_info_request = $request;
        $early_response = $this->generateQuickHoverResponse($request);
        if ($early_response) {
            return $early_response;
        }
        $this->cached_hover_response = null;

        // We analyze this url so that Phan is aware enough of the types and namespace maps to trigger "Go to definition"
        // E.g. going to the definition of `Bar` in `use Foo as Bar; Bar::method();` requires parsing other statements in this file, not just the name in question.
        //
        // NOTE: This also ensures that we will run analysis, because of the check for analyze_request_set being non-empty
        $this->analyze_request_set[$path_to_analyze] = $uri;
        return $request->getPromise();
    }

    /**
     * Immediately generates the hover text for a given URL and position.
     *
     * @return ?Promise <?Hover>
     */
    private function generateQuickHoverResponse(GoToDefinitionRequest $request): ?Promise
    {
        $description = "uri={$request->getUrl()}, position=" . StringUtil::jsonEncode($request->getPosition());
        Logger::logInfo("Looking for quick hover response for $description");
        if (!$this->cached_hover_response) {
            return null;
        }
        if (!$this->cached_hover_response->isSameRequest($request->getUrl(), $request->getPosition(), $this->file_mapping)) {
            return null;
        }
        $promise = new Promise();
        $cached_response = $this->cached_hover_response->getHoverResponse();
        Logger::logInfo("Returning cached hover response for $description");

        $promise->fulfill($cached_response);
        return $promise;
    }

    /**
     * Asynchronously generates the definition for a given URL
     * @return Promise <Location|Location[]|null>
     */
    public function awaitCompletion(
        string $uri,
        Position $position,
        ?CompletionContext $completion_context = null
    ): Promise {
        // TODO: Add a way to "go to definition" without emitting analysis results as a side effect
        $path_to_analyze = Utils::uriToPath($uri);
        Logger::logInfo("Called LanguageServer->awaitCompletion, uri=$uri, position=" . StringUtil::jsonEncode($position));
        $this->discardPreviousNodeInfoRequest();
        $request = new CompletionRequest($uri, $position, $completion_context);
        $this->most_recent_node_info_request = $request;

        // We analyze this url so that Phan is aware enough of the types and namespace maps to trigger "Go to definition"
        // E.g. going to the definition of `Bar` in `use Foo as Bar; Bar::method();` requires parsing other statements in this file, not just the name in question.
        //
        // NOTE: This also ensures that we will run analysis, because of the check for analyze_request_set being non-empty
        $this->analyze_request_set[$path_to_analyze] = $uri;
        return $request->getPromise();
    }

    private function discardPreviousNodeInfoRequest(): void
    {
        $prev_node_info_request = $this->most_recent_node_info_request;
        if ($prev_node_info_request) {
            // Discard the previous request silently
            $prev_node_info_request->finalize();
            $this->most_recent_node_info_request = null;
        }
    }

    /**
     * Gets URIs (and corresponding paths) which the language server client needs Phan to re-analyze.
     * This excludes any files that aren't in files and directories of .phan/config.php
     *
     * @return array{0:array<string,string>,1:list<string>}
     * First element maps relative path to the file URI.
     * Second element is the result of file_path_lister (unless there's nothing to analyze)
     */
    private function getFilteredURIsToAnalyze(): array
    {
        $uris_to_analyze = $this->analyze_request_set;
        if (\count($uris_to_analyze) === 0) {
            return [[], []];
        }
        $this->analyze_request_set = [];

        // Always recompute the file list from the directory list : see src/phan.php
        // The caller will reuse the cached file list.
        $file_path_list = ($this->file_path_lister)(true);
        $filtered_uris_to_analyze = [];
        foreach ($uris_to_analyze as $path_to_analyze => $uri) {
            if (!is_string($uri)) {
                Logger::logInfo("Uri for path '$path_to_analyze' is not a string, should not happen");
                continue;
            }
            $relative_path_to_analyze = FileRef::getProjectRelativePathForPath($path_to_analyze);
            if (!\in_array($uri, $file_path_list, true) && !\in_array($relative_path_to_analyze, $file_path_list, true)) {
                // fwrite(STDERR, "Checking if should parse missing $relative_path_to_analyze for $uri\n");
                if (CLI::shouldParse($relative_path_to_analyze)) {
                    $file_path_list[] = $relative_path_to_analyze;
                    Logger::logInfo("Path '$relative_path_to_analyze' (URI '$uri') was not in list - adding it");
                } else {
                    Logger::logInfo("Path '$relative_path_to_analyze' (URI '$uri') not in parse list, skipping");
                    continue;
                }
            }
            $filtered_uris_to_analyze[$relative_path_to_analyze] = $uri;
        }
        return [$filtered_uris_to_analyze, $file_path_list];
    }

    private function finalizeAnalyzingURIs(): void
    {
        [$uris_to_analyze, $file_path_list] = $this->getFilteredURIsToAnalyze();
        // TODO: Add a better abstraction of
        if (\count($uris_to_analyze) === 0) {
            // Discard any node info requests, we haven't created a request yet.
            $this->discardPreviousNodeInfoRequest();
            return;
        }

        // Add anything that's open in the IDE to the URIs to analyze.
        // In the future, this behavior may be configurable.
        foreach ($this->file_mapping->getOverrides() as $path => $_) {
            if (!isset($uris_to_analyze[$path])) {
                $uris_to_analyze[$path] = $this->file_mapping->getURIForPath($path);
            }
        }

        if (Config::getValue('language_server_use_pcntl_fallback')) {
            // @phan-suppress-next-line PhanThrowTypeAbsentForCall
            $this->finishAnalyzingURIsWithoutPcntl($uris_to_analyze);
            return;
        }

        // TODO: check if $path_to_analyze can be analyzed first.
        $sockets = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        if (!$sockets) {
            \error_log("unable to create stream socket pair");
            exit(EXIT_FAILURE);
        }

        $this->most_recent_request = null;

        // Give our signal handler time to collect the status of any zombie processes
        // so that they don't accumulate.
        \pcntl_signal_dispatch();

        // Fork a new process to handle the analysis request
        $pid = \pcntl_fork();
        if ($pid < 0) {
            \error_log(\posix_strerror(\posix_get_last_error()));
            exit(EXIT_FAILURE);
        }

        // FIXME: make this async as well, and rate limit it.
        if ($pid > 0) {
            // This is the parent - The worker process that was forked has pid $pid
            Request::handleBecomingParentOfChildAnalysisProcess($pid);
            $read_stream = self::streamForParent($sockets);
            $concatenated = '';
            while (!\feof($read_stream)) {
                $buffer = \fread($read_stream, 8096);
                if ($buffer === false) {
                    Logger::logError("fread from language client failed");
                    break;
                }
                if (strlen($buffer) > 0) {
                    $concatenated .= $buffer;
                } else {
                    self::waitForDataOnReadSocket($read_stream);
                }
            }
            $json_contents = \json_decode($concatenated, true);
            if (!\is_array($json_contents)) {
                Logger::logInfo("Fetched non-json: " . $concatenated);
                return;
            }
            $this->handleJSONResponseFromWorker($uris_to_analyze, $json_contents);
            return;
        }
        // This is the worker process.
        Request::handleBecomingChildAnalysisProcess();

        $child_stream = self::streamForChild($sockets);
        $paths_to_analyze = \array_keys($uris_to_analyze);
        $this->most_recent_request = Request::makeLanguageServerAnalysisRequest(
            new StreamResponder($child_stream, false),
            $paths_to_analyze,
            $this->code_base,
            /** @return list<string> */
            static function (bool $unused_recompute_file_list = false) use ($file_path_list): array {
                return $file_path_list;
            },
            $this->file_mapping,
            $this->most_recent_node_info_request,
            true  // We are the fork. Call exit() instead of throwing ExitException
        );
        // FIXME update the parsed file lists before and after (e.g. add to analyzeURI). See Daemon\Request::accept()
        //    TODO: refactor accept() to make it easier to work with.
        // TODO: add unit tests

        $this->protocolReader->stopAcceptingNewRequests();
        $this->is_accepting_new_requests = false;
        Loop\stop();  // abort the loop (without closing streams?)
    }

    /**
     * Calls stream_select to avoid a busy loop to read from the worker when pcntl is used to fork a worker analysis process.
     *
     * @param resource $read_stream
     */
    private static function waitForDataOnReadSocket($read_stream): void
    {
        $read = [$read_stream];
        $write = [];
        $except = [];
        // > Remember that the timeout value is the maximum time that will elapse;
        // > stream_select() will return as soon as the requested streams are ready for use.
        \stream_select($read, $write, $except, 1);
    }

    /**
     * @param array<string,string> $uris_to_analyze
     * @throws Exception if analysis throws an exception
     *
     * @suppress PhanAccessMethodInternal
     */
    private function finishAnalyzingURIsWithoutPcntl(array $uris_to_analyze): void
    {
        $paths_to_analyze = \array_keys($uris_to_analyze);
        Logger::logInfo('in ' . __METHOD__ . ' paths: ' . StringUtil::jsonEncode($paths_to_analyze));
        // When there is no pcntl:
        // Create a fake request object.
        // Instead of stopping the loop, keep going with the loop and keep accepting the requests
        $responder = new CapturerResponder([]);
        $code_base = $this->code_base;

        $analysis_request = Request::makeLanguageServerAnalysisRequest(
            $responder,
            $paths_to_analyze,
            $code_base,
            $this->file_path_lister,
            $this->file_mapping,
            $this->most_recent_node_info_request,
            false  // We aren't forking. Throw ExitException instead of calling exit()
        );

        $analyze_file_path_list = $analysis_request->filterFilesToAnalyze($this->code_base->getParsedFilePathList());
        if (count($analyze_file_path_list) === 0) {
            // Nothing to do, don't start analysis
            $analysis_request->rejectLanguageServerRequestsRequiringAnalysis();
            return;
        }

        // Do this before we stop tracking undo operations.
        $temporary_file_mapping = $analysis_request->getTemporaryFileMapping();

        $restore_point = $code_base->createRestorePoint();

        // Stop tracking undo operations, now that the parse phase is done.
        // This is re-enabled in restoreFromRestorePoint
        $code_base->disableUndoTracking();

        Phan::setPrinter($analysis_request->getPrinter());

        try {
            Phan::finishAnalyzingRemainingStatements($this->code_base, $analysis_request, $analyze_file_path_list, $temporary_file_mapping);
        } catch (ExitException $_) {
            // This is normal, do nothing
        }

        $response_data = $responder->getResponseData();
        if (!$response_data) {
            // Something is probably broken if we don't get response data
            // But just in case we can recover, restore this.
            $code_base->restoreFromRestorePoint($restore_point);
            throw new \RuntimeException("Failed to get a response from a worker");
        }

        // Send a response with diagnostics to the language server client.
        // It should be slightly faster to send a response
        // if the language server sends data before restoring the state of the codebase.
        // (Transforming the JSON response does not depend on the $code_base object)
        $this->handleJSONResponseFromWorker($uris_to_analyze, $response_data);

        $code_base->restoreFromRestorePoint($restore_point);

        Logger::logInfo("Response from non-pcntl server: " . StringUtil::jsonEncode($response_data));
    }

    /**
     * @param array<string,string> $uris_to_analyze
     * @param array{issues:array[],definitions?:?Location|?(Location[]),completions?:?(CompletionItem[]),hover_response?:Hover} $response_data
     * @see Request::respondWithIssues() for where $response_data is serialized
     */
    private function handleJSONResponseFromWorker(array $uris_to_analyze, array $response_data): void
    {
        $most_recent_node_info_request = $this->most_recent_node_info_request;
        if ($most_recent_node_info_request) {
            if ($most_recent_node_info_request instanceof GoToDefinitionRequest) {
                // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                $most_recent_node_info_request->recordDefinitionLocationList($response_data['definitions'] ?? null);
                if ($most_recent_node_info_request->isHoverRequest()) {
                    $normalized_hover = $most_recent_node_info_request->setHoverResponse($response_data['hover_response'] ?? null);
                    // \fwrite(\STDERR, "Creating cached_hover_response\n");
                    $this->cached_hover_response = new CachedHoverResponse(
                        $most_recent_node_info_request->getUrl(),
                        $most_recent_node_info_request->getPosition(),
                        $this->file_mapping,
                        $normalized_hover
                    );
                }
            } elseif ($most_recent_node_info_request instanceof CompletionRequest) {
                $most_recent_node_info_request->recordCompletionList($response_data['completions'] ?? null);
            }
            $most_recent_node_info_request->finalize();
        }

        $this->most_recent_node_info_request = null;
        if (!\array_key_exists('issues', $response_data)) {
            Logger::logInfo("Failed to fetch 'issues' from JSON: " . StringUtil::jsonEncode($response_data));
            return;
        }
        $diagnostics = [];
        // Normalize the uri so that it will be the same as URIs phan would send for diagnostics.
        // E.g. "file:///path/path%.php" will be normalized to "file:///path/path%25.php"
        foreach ($uris_to_analyze as $uri) {
            $normalized_requested_uri = Utils::pathToUri(Utils::uriToPath($uri));
            $diagnostics[$normalized_requested_uri] = [];  // send an empty diagnostic list on failure.
        }

        $issues = $response_data['issues'] ?? [];
        if (!is_array($issues)) {
            Logger::logInfo("Failed to fetch 'issues' from JSON: " . StringUtil::jsonEncode($response_data));
            return;
        }
        foreach ($issues as $issue) {
            [$issue_uri, $diagnostic] = self::generateDiagnostic($issue);
            if ($diagnostic instanceof Diagnostic) {
                $diagnostics[$issue_uri][] = $diagnostic;
            }
        }

        $this->publishDiagnosticsListsForURIs($diagnostics);
    }

    /**
     * @param array<string,list<Diagnostic>> $diagnostics
     */
    private function publishDiagnosticsListsForURIs(array $diagnostics): void
    {
        if (count($diagnostics) === 0) {
            return;
        }
        foreach ($diagnostics as $diagnostics_uri => $diagnostics_list) {
            $this->client->textDocument->publishDiagnostics($diagnostics_uri, $diagnostics_list);
        }
    }

    /**
     * @param array{type:string,description:string,suggestion?:string,severity:int,location:array{path:string,lines:array{begin:int,begin_column?:int,end:int}}} $issue
     * @return null[]|string[]|Diagnostic[] - On success, returns [string $uri, Diagnostic $diagnostic]
     */
    private static function generateDiagnostic(array $issue): array
    {
        if ($issue['type'] !== 'issue') {
            return [null, null];
        }
        //$check_name = $issue['check_name'];
        $description = $issue['description'];
        if (Config::getValue('language_server_hide_category_of_issues')) {
            // See JSONPrinter.php for how $description is built
            $description = \explode(' ', $description, 2)[1];
        }
        if (isset($issue['suggestion'])) {
            $description .= ' (' . $issue['suggestion'] . ')';
        }

        $severity = $issue['severity'];
        $path = Config::projectPath($issue['location']['path']);
        $issue_uri = Utils::pathToUri($path);
        $start_line = $issue['location']['lines']['begin'];
        $column = $issue['location']['lines']['begin_column'] ?? 0;

        $start_line = (int)\max($start_line, 1);
        // If we ever supported end_line:
        // $end_line = $issue['location']['lines']['end'] ?? $start_line;
        // $end_line = max($end_line, 1);
        // Language server has 0 based lines and columns, phan has 1-based lines and columns.
        $range = new Range(new Position($start_line - 1, \max($column - 1, 0)), new Position($start_line, 0));
        $diagnostic_severity = self::diagnosticSeverityFromPhanSeverity($severity);
        // TODO: copy issue code in 'json' format
        return [$issue_uri, new Diagnostic($description, $range, null, $diagnostic_severity, 'Phan')];
    }

    /**
     * @param int $severity
     * @return int
     * A DiagnosticSeverity constant used by the language server protocol.
     */
    public static function diagnosticSeverityFromPhanSeverity(int $severity): int
    {
        switch ($severity) {
            case Issue::SEVERITY_LOW:
                return DiagnosticSeverity::INFORMATION;
            case Issue::SEVERITY_NORMAL:
                return DiagnosticSeverity::WARNING;
            default:
                return DiagnosticSeverity::ERROR;
        }
    }

    /**
     * Prepare the socket pair to be used in a parent process and
     * return the stream the parent will use to read results.
     *
     * @param resource[] $sockets the socket pair for IPC
     * @return resource
     */
    private static function streamForParent(array $sockets)
    {
        [$for_read, $for_write] = $sockets;

        // The parent will not use the write channel, so it
        // must be closed to prevent deadlock.
        \fclose($for_write);

        // stream_select will be used to read multiple streams, so these
        // must be set to non-blocking mode.
        if (!\stream_set_blocking($for_read, false)) {
            \error_log('unable to set read stream to non-blocking');
            exit(EXIT_FAILURE);
        }

        return $for_read;
    }

    /**
     * Prepare the socket pair to be used in a child process and return
     * the stream the child will use to write results.
     *
     * @param resource[] $sockets the socket pair for IPC.
     * @return resource
     */
    private static function streamForChild(array $sockets)
    {
        [$for_read, $for_write] = $sockets;

        // The while will not use the read channel, so it must
        // be closed to prevent deadlock.
        \fclose($for_read);
        return $for_write;
    }


    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor) @phan-unused-param
     * @param string|null $rootPath The rootPath of the workspace. Is null if no folder is open. @phan-unused-param
     * @param int|null $processId The process Id of the parent process that started the server. @phan-unused-param
     *                            This is null if the process has not been started by another process.
     *                            If the parent process is not alive,
     *                            then the server should exit (see exit notification) its process.
     *                            NOTE: For most use cases, we'll know about the disconnection because the connection hits the end of file or an error.
     *
     * @return Promise <InitializeResult>
     */
    public function initialize(ClientCapabilities $capabilities, ?string $rootPath = null, ?int $processId = null): Promise
    {
        return coroutine(function (): \Generator {
            // Eventually, this might block on something. Leave it as a generator.
            // @phan-suppress-next-line PhanImpossibleCondition deliberately unreachable yield
            if (false) {
                yield;
            }

            // There would be an asynchronous indexing step, but the startup already did the indexing.
            if ($this->textDocument === null) {
                $this->textDocument = new TextDocument(
                    $this->client,
                    $this,
                    $this->file_mapping
                );
            }

            $server_capabilities = new ServerCapabilities();

            // FULL: Ask the client to return always return full documents (because we need to rebuild the AST from scratch)
            // NONE: Don't sync until the user explicitly saves a document.
            $server_capabilities->textDocumentSync = self::makeTextDocumentSyncOptions();

            // TODO: Support "Find all symbols"?
            //$server_capabilities->documentSymbolProvider = true;
            // TODO: Support "Find all symbols in workspace"?
            //$server_capabilities->workspaceSymbolProvider = true;
            // XXX do this next?

            $supports_go_to_definition = (bool)Config::getValue('language_server_enable_go_to_definition');
            $server_capabilities->definitionProvider = $supports_go_to_definition;
            $server_capabilities->typeDefinitionProvider = $supports_go_to_definition;
            $server_capabilities->hoverProvider = (bool)Config::getValue('language_server_enable_hover');
            if (Config::getValue('language_server_enable_completion')) {
                // TODO: What about `:`?
                $completion_provider = new CompletionOptions();
                $completion_provider->resolveProvider = false;
                $completion_provider->triggerCharacters = ['$', '>'];
                $server_capabilities->completionProvider = $completion_provider;
            }

            // TODO: (probably impractical, slow) Support "Find all references"? (We don't track this, except when checking for dead code elimination possibilities.
            // $server_capabilities->referencesProvider = false;
            // Can't support "Hover" without phpdoc for internal functions, such as those from PHPStorm
            // Can't support global references at the moment, I think.
            //$server_capabilities->xworkspaceReferencesProvider = true;
            //$server_capabilities->xdefinitionProvider = true;
            //$server_capabilities->xdependenciesProvider = true;

            return new InitializeResult($server_capabilities);
        });
    }

    private static function makeTextDocumentSyncOptions(): TextDocumentSyncOptions
    {
        $textDocumentSyncOptions = new TextDocumentSyncOptions();
        $textDocumentSyncOptions->openClose = true;
        $textDocumentSyncOptions->change = TextDocumentSyncKind::FULL;

        $saveOptions = new SaveOptions();
        $saveOptions->includeText = true;
        $textDocumentSyncOptions->save = $saveOptions;
        return $textDocumentSyncOptions;
    }

    /**
     * Currently a no-op.
     *
     * The initialized notification is sent from the client to the server after the client received the result of the initialize request
     * but before the client is sending any other request or notification to the server.
     *
     * @suppress PhanUnreferencedPublicMethod
     */
    public function initialized(): void
    {
        Logger::logInfo("Called initialized on language server, currently a no-op");
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification that
     * asks the server to exit.
     */
    public function shutdown(): void
    {
        // TODO: Does phan need to do anything else except respond?
        Logger::logInfo("Called shutdown on language server");
    }

    /**
     * A notification to ask the server to exit its process.
     * @return never
     */
    public function exit(): void
    {
        // This is handled by the main process. No forks are active.
        Logger::logInfo("Called exit on language server");
        exit(0);
    }
}
