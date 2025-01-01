<?php

declare(strict_types=1);

namespace Phan\LanguageServer\Protocol;

/**
 * Represents a diagnostic, such as a compiler error or warning. Diagnostic objects are only valid in the scope of a
 * resource.
 *
 * Source: https://github.com/felixfbecker/php-language-server/tree/master/src/Protocol/Diagnostic.php
 * See ../../../../LICENSE.LANGUAGE_SERVER
 * @phan-immutable
 */
class Diagnostic
{
    /**
     * The range at which the message applies.
     *
     * @var Range
     * @suppress PhanWriteOnlyPublicProperty this is serialized and sent to the client.
     */
    public $range;

    /**
     * The diagnostic's severity. Can be omitted. If omitted it is up to the
     * client to interpret diagnostics as error, warning, info or hint.
     *
     * @var int|null
     * @suppress PhanWriteOnlyPublicProperty this is serialized and sent to the client.
     */
    public $severity;

    /**
     * The diagnostic's code. Can be omitted.
     *
     * @var int|string|null
     * @suppress PhanWriteOnlyPublicProperty this is serialized and sent to the client.
     */
    public $code;

    /**
     * A human-readable string describing the source of this
     * diagnostic, e.g. 'typescript' or 'super lint'.
     *
     * @var string|null
     * @suppress PhanWriteOnlyPublicProperty this is serialized and sent to the client.
     */
    public $source;

    /**
     * The diagnostic's message.
     *
     * @var string
     * @suppress PhanWriteOnlyPublicProperty this is serialized and sent to the client.
     */
    public $message;

    /**
     * @param  string $message  The diagnostic's message
     * @param  Range  $range    The range at which the message applies
     * @param  int    $code     The diagnostic's code
     * @param  int    $severity DiagnosticSeverity
     * @param  string $source   A human-readable string describing the source of this diagnostic
     * @suppress PhanPossiblyNullTypeMismatchProperty
     * @suppress PhanTypeMismatchDeclaredParamNullable
     */
    public function __construct(?string $message = null, ?Range $range = null, ?int $code = null, ?int $severity = null, ?string $source = null)
    {
        $this->message = $message;
        $this->range = $range;
        $this->code = $code;
        $this->severity = $severity;
        $this->source = $source;
    }
}
