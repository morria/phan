<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

use AdvancedJsonRpc\Message as MessageBody;

/**
 * This represents a notification, request, or response from the Language Server Protocol,
 * which uses JSON-RPC 2.
 *
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/Message.php
 */
class Message
{
    /**
     * @var ?\AdvancedJsonRpc\Message the optional decoded message body for this message
     */
    public $body;

    /**
     * @var array<string,string> the headers associated with this message (e.g. Content-Length)
     */
    public $headers;

    /**
     * Parses a message
     *
     * @param string $msg
     */
    public static function parse(string $msg): Message
    {
        $obj = new self();
        $parts = \explode("\r\n", $msg);
        $obj->body = MessageBody::parse(\array_pop($parts));
        foreach ($parts as $line) {
            if ($line !== '') {
                $pair = \explode(': ', $line);
                $obj->headers[$pair[0]] = $pair[1];
            }
        }
        return $obj;
    }

    /**
     * @param ?MessageBody $body
     * @param array<string,string> $headers
     */
    public function __construct(?MessageBody $body = null, array $headers = [])
    {
        $this->body = $body;
        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/vscode-jsonrpc; charset=utf8';
        }
        $this->headers = $headers;
    }

    public function __toString(): string
    {
        $body = (string)$this->body;
        $contentLength = \strlen($body);
        $this->headers['Content-Length'] = (string)$contentLength;
        $headers = '';
        foreach ($this->headers as $name => $value) {
            $headers .= "$name: $value\r\n";
        }
        return $headers . "\r\n" . $body;
    }
}
