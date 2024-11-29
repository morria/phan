<?php

declare(strict_types=1);

namespace Phan\Library;

use Phan\AST\ASTReverter;

/**
 * StringUtil contains methods to simplify working with strings in Phan and its plugins.
 */
class StringUtil
{
    /**
     * Encode a scalar value in a compact, unambiguous representation for emitted issues.
     * The encoder used by encodeValue may change.
     * This aims to fit on a single line.
     *
     * @param string|int|float|bool|null $value
     */
    public static function encodeValue($value): string
    {
        if (\is_string($value) && \preg_match('/([\0-\15\16-\37])/', $value)) {
            // Use double quoted strings if this contains newlines, tabs, control characters, etc.
            return '"' . ASTReverter::escapeInnerString($value, '"') . '"';
        }
        return self::varExportPretty($value);
    }

    /**
     * Encode a scalar value in a compact, unambiguous representation for emitted issues.
     * The encoder used by encodeValue may change.
     * This fits on a single line.
     *
     * @param ?(int|bool|string|array|float) $value
     */
    public static function varExportPretty($value): string
    {
        if (\is_resource($value)) {
            return \array_search($value, \get_defined_constants(), true) ?:
                \sprintf('resource#%d(%s)', \get_resource_id($value), \get_resource_type($value));
        }
        return \var_representation($value, \VAR_REPRESENTATION_SINGLE_LINE);
    }

    /**
     * JSON encodes a value - Guaranteed to return a string.
     * @param string|int|float|bool|null|array|object $value
     */
    public static function jsonEncode($value): string
    {
        $result = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        return \is_string($result) ? $result : '(invalid data)';
    }

    /**
     * Encode a list of values in a compact, unambiguous representation for emitted issues.
     * @param list<string|int|float|bool> $values
     */
    public static function encodeValueList(string $separator, array $values): string
    {
        return \implode(
            $separator,
            \array_map([self::class, 'encodeValue'], $values)
        );
    }

    /**
     * Coerce $str to valid utf-8
     */
    public static function asUtf8(string $str): string
    {
        return \mb_convert_encoding($str, 'UTF-8', 'UTF-8') ?: $str;
    }

    /**
     * Coerce $str to valid utf-8 and replace newlines with placeholders
     */
    public static function asSingleLineUtf8(string $str): string
    {
        if (!\preg_match("@[\\n\\r\x80-\xff]@", $str)) {
            // Around 5x faster for the common case of being ASCII without newlines.
            return $str;
        }
        return \str_replace(["\n", "\r"], "�", self::asUtf8($str));
    }

    /**
     * Returns true if the provided argument is a non-zero length string.
     * Unlike (bool)$str, this allows the literal string '0', and rejects types other than strings.
     *
     * @param ?string|?false $str typical inputs are nullable/falsable strings
     * @phan-assert string $str TODO: This unconditionally sets the type of $str to string - add an equivalent that only sets the type when true.
     * @psalm-assert-if-true string $str
     */
    public static function isNonZeroLengthString($str): bool
    {
        return \is_string($str) && $str !== '';
    }
}
