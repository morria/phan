<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use Exception;
use Phan\CodeBase;
use Phan\Language\Element\Comment\Builder;
use Phan\Language\UnionType;
use Phan\Library\StringUtil;

use function count;

/**
 * APIs for generating markup (markdown) description of elements
 *
 * This is used by the language server to send markup to the language client (e.g. for hover text of an element).
 */
class MarkupDescription
{
    /**
     * Generates a markup snippet with a description of the declaration of $element,
     * and its doc comment summary and description.
     */
    public static function buildForElement(
        AddressableElementInterface $element,
        CodeBase $code_base
    ): string {
        // TODO: Use the doc comments of the ancestors if unavailable or if (at)inheritDoc is used.
        $markup = $element->getMarkupDescription();
        $result = "```php\n$markup\n```";
        $extracted_doc_comment = self::extractDescriptionFromDocCommentOrAncestor($element, $code_base);
        if (StringUtil::isNonZeroLengthString($extracted_doc_comment)) {
            $result .= "\n\n" . $extracted_doc_comment;
        }

        return $result;
    }

    /**
     * Convert a string description to a newline-terminated doc comment
     */
    public static function convertStringToDocComment(string $description, string $indent = ''): string
    {
        $description = \trim($description);
        if ($description === '') {
            return '';
        }
        $description = \str_replace('*/', '*\/', \trim($description));
        if (\strpos($description, "\n") !== false) {
            $result = "$indent/**\n";
            foreach (\explode("\n", $description) as $line) {
                $line = \rtrim($line);
                $result .= "$indent *";
                if ($line !== '') {
                    $result .= " $line";
                }
                $result .= "\n";
            }
            $result .= "$indent */\n";
            return $result;
        }
        return "$indent/** $description */\n";
    }

    /**
     * @template T
     * @param array<string,T> $signatures
     * @return array<string,T>
     */
    private static function signaturesToLower(array $signatures): array
    {
        $result = [];
        foreach ($signatures as $fqsen => $summary) {
            $result[\strtolower($fqsen)] = $summary;
        }
        return $result;
    }

    /**
     * Eagerly load all of the hover signatures into memory before potentially forking.
     */
    public static function eagerlyLoadAllDescriptionMaps(): void
    {
        if (!\extension_loaded('pcntl')) {
            // There's no forking, so descriptions will always be available after the first time they're loaded.
            // No need to force phan to load these prior to forking.
            return;
        }
        self::loadClassDescriptionMap();
        self::loadConstantDescriptionMap();
        self::loadFunctionDescriptionMap();
        self::loadPropertyDescriptionMap();
    }

    /**
     * @return array<string,string> mapping lowercase function/method FQSENs to short summaries.
     * @internal - The data format may change
     */
    public static function loadFunctionDescriptionMap(): array
    {
        static $descriptions = null;
        if (\is_array($descriptions)) {
            return $descriptions;
        }
        return $descriptions = self::signaturesToLower(require(\dirname(__DIR__) . '/Internal/FunctionDocumentationMap.php'));
    }

    /**
     * @return array<string,string> mapping lowercase constant/class constant FQSENs to short summaries.
     * @internal - The data format may change
     */
    public static function loadConstantDescriptionMap(): array
    {
        static $descriptions = null;
        if (\is_array($descriptions)) {
            return $descriptions;
        }
        return $descriptions = self::signaturesToLower(require(\dirname(__DIR__) . '/Internal/ConstantDocumentationMap.php'));
    }

    /**
     * @return array<string,string> mapping class FQSENs to short summaries.
     */
    public static function loadClassDescriptionMap(): array
    {
        static $descriptions = null;
        if (\is_array($descriptions)) {
            return $descriptions;
        }
        return $descriptions = self::signaturesToLower(require(\dirname(__DIR__) . '/Internal/ClassDocumentationMap.php'));
    }

    /**
     * @return array<string,string> mapping property FQSENs to short summaries.
     * @internal - The data format may change
     */
    public static function loadPropertyDescriptionMap(): array
    {
        static $descriptions = null;
        if (\is_array($descriptions)) {
            return $descriptions;
        }
        return $descriptions = self::signaturesToLower(require(\dirname(__DIR__) . '/Internal/PropertyDocumentationMap.php'));
    }

    /**
     * Extracts a plaintext description of the element from the doc comment of an element or its ancestor.
     * (or from FunctionDocumentationMap.php)
     *
     * @param array<string,true> $checked_class_fqsens
     */
    public static function extractDescriptionFromDocCommentOrAncestor(
        AddressableElementInterface $element,
        CodeBase $code_base,
        array &$checked_class_fqsens = []
    ): ?string {
        $extracted_doc_comment = self::extractDescriptionFromDocComment($element, $code_base);
        if (StringUtil::isNonZeroLengthString($extracted_doc_comment)) {
            return $extracted_doc_comment;
        }
        if ($element instanceof ClassElement) {
            try {
                $fqsen_string = $element->getClassFQSEN()->__toString();
                if (isset($checked_class_fqsens[$fqsen_string])) {
                    // We already checked this and either succeeded or returned null
                    return null;
                }
                $checked_class_fqsens[$fqsen_string] = true;
                return self::extractDescriptionFromDocCommentOfAncestorOfClassElement($element, $code_base);
            } catch (Exception $_) {
                // ignore
            }
        }
        return null;
    }

    /**
     * @param array<string,true> $checked_class_fqsens used to guard against recursion
     */
    private static function extractDescriptionFromDocCommentOfAncestorOfClassElement(
        ClassElement $element,
        CodeBase $code_base,
        array &$checked_class_fqsens = []
    ): ?string {
        if (!$element->isOverride() && $element->getRealDefiningFQSEN() === $element->getFQSEN()) {
            return null;
        }
        $class_fqsen = $element->getDefiningClassFQSEN();
        $class = $code_base->getClassByFQSEN($class_fqsen);
        foreach ($class->getAncestorFQSENList() as $ancestor_fqsen) {
            $ancestor_element = Clazz::getAncestorElement($code_base, $ancestor_fqsen, $element);
            if (!$ancestor_element) {
                continue;
            }

            $extracted_doc_comment = self::extractDescriptionFromDocCommentOrAncestor($ancestor_element, $code_base, $checked_class_fqsens);
            if (StringUtil::isNonZeroLengthString($extracted_doc_comment)) {
                return $extracted_doc_comment;
            }
        }
        return null;
    }

    /**
     * Extracts a plaintext description of the element from the doc comment of an element.
     * (or from FunctionDocumentationMap.php)
     */
    public static function extractDescriptionFromDocComment(
        AddressableElementInterface $element,
        ?CodeBase $code_base = null
    ): ?string {
        $extracted_doc_comment = self::extractDescriptionFromDocCommentRaw($element);
        if (StringUtil::isNonZeroLengthString($extracted_doc_comment)) {
            return $extracted_doc_comment;
        }

        // This is an element internal to PHP.
        if ($element->isPHPInternal()) {
            if ($element instanceof FunctionInterface) {
                // This is a function/method - Use Phan's FunctionDocumentationMap.php to try to load a markup description.
                if ($element instanceof Method && \strtolower($element->getName()) !== '__construct') {
                    $fqsen = $element->getDefiningFQSEN();
                } else {
                    $fqsen = $element->getFQSEN();
                }
                $key = \strtolower(\ltrim((string)$fqsen, '\\'));
                $result = self::loadFunctionDescriptionMap()[$key] ?? null;
                if (StringUtil::isNonZeroLengthString($result)) {
                    return $result;
                }
                if ($code_base && $element instanceof Method) {
                    try {
                        if (\strtolower($element->getName()) === '__construct') {
                            $class = $element->getClass($code_base);
                            $class_description = self::extractDescriptionFromDocComment($class, $code_base);
                            if (StringUtil::isNonZeroLengthString($class_description)) {
                                return "Construct an instance of `{$class->getFQSEN()}`.\n\n$class_description";
                            }
                        }
                    } catch (Exception $_) {
                    }
                }
            } elseif ($element instanceof ConstantInterface) {
                // This is a class or global constant - Use Phan's ConstantDocumentationMap.php to try to load a markup description.
                if ($element instanceof ClassConstant) {
                    $fqsen = $element->getDefiningFQSEN();
                } else {
                    $fqsen = $element->getFQSEN();
                }
                $key = \strtolower(\ltrim((string)$fqsen, '\\'));
                return self::loadConstantDescriptionMap()[$key] ?? null;
            } elseif ($element instanceof Clazz) {
                $key = \strtolower(\ltrim((string)$element->getFQSEN(), '\\'));
                return self::loadClassDescriptionMap()[$key] ?? null;
            } elseif ($element instanceof Property) {
                $key = \strtolower(\ltrim((string)$element->getDefiningFQSEN(), '\\'));
                return self::loadPropertyDescriptionMap()[$key] ?? null;
            }
        }
        return null;
    }

    private static function extractDescriptionFromDocCommentRaw(AddressableElementInterface $element): ?string
    {
        $doc_comment = $element->getDocComment();
        if (!StringUtil::isNonZeroLengthString($doc_comment)) {
            return null;
        }
        $comment_category = null;
        if ($element instanceof Property) {
            $comment_category = Comment::ON_PROPERTY;
        } elseif ($element instanceof ConstantInterface) {
            $comment_category = Comment::ON_CONST;
        } elseif ($element instanceof FunctionInterface) {
            $comment_category = Comment::ON_FUNCTION;
        }
        $extracted_doc_comment = self::extractDocComment($doc_comment, $comment_category, $element->getUnionType());
        return StringUtil::isNonZeroLengthString($extracted_doc_comment) ? $extracted_doc_comment : null;
    }

    /**
     * @return array<string,string> information about the param tags
     */
    public static function extractParamTagsFromDocComment(AddressableElementInterface $element, bool $with_param_details = true): array
    {
        $doc_comment = $element->getDocComment();
        if (!\is_string($doc_comment)) {
            return [];
        }
        if (\strpos($doc_comment, '@param') === false) {
            return [];
        }
        // Trim the start and the end of the doc comment.
        //
        // We leave in the second `*` of `/**` so that every single non-empty line
        // of a typical doc comment will begin with a `*`
        $doc_comment = \preg_replace('@(^/\*)|(\*/$)@D', '', $doc_comment);

        $results = [];
        $lines = \explode("\n", $doc_comment);
        foreach ($lines as $i => $line) {
            $line = self::trimLine($line);
            if (\preg_match('/^\s*@param(\s|$)/D', $line) > 0) {
                // Extract all of the (at)param annotations.
                $param_tag_summary = self::extractTagSummary($lines, $i);
                if (\end($param_tag_summary) === '') {
                    \array_pop($param_tag_summary);
                }
                $full_comment = \implode("\n", self::trimLeadingWhitespace($param_tag_summary));
                // @phan-suppress-next-line PhanAccessClassConstantInternal
                $matched = \preg_match(Builder::PARAM_COMMENT_REGEX, $full_comment, $match);
                if (!$matched) {
                    continue;
                }
                if (!isset($match[17])) {
                    continue;
                }

                $name = $match[17];
                if ($with_param_details) {
                    // Keep the param details and put them in a markdown quote
                    // @phan-suppress-next-line PhanAccessClassConstantInternal
                    $full_comment = \preg_replace(Builder::PARAM_COMMENT_REGEX, '`\0`', $full_comment);
                } else {
                    // Drop the param details
                    // @phan-suppress-next-line PhanAccessClassConstantInternal
                    $full_comment = \trim(\preg_replace(Builder::PARAM_COMMENT_REGEX, '', $full_comment));
                }
                $results[$name] = $full_comment;
            }
        }
        return $results;
    }

    /**
     * Returns a doc comment with:
     *
     * - leading `/**` and trailing `*\/` removed
     * - leading/trailing space on lines removed,
     * - blank lines removed from the beginning and end.
     *
     * @return string simplified version of the doc comment, with leading `*` on lines preserved.
     */
    public static function getDocCommentWithoutWhitespace(string $doc_comment): string
    {
        // Trim the start and the end of the doc comment.
        //
        // We leave in the second `*` of `/**` so that every single non-empty line
        // of a typical doc comment will begin with a `*`
        $doc_comment = \preg_replace('@(^/\*)|(\*/$)@D', '', $doc_comment);

        $lines = \explode("\n", $doc_comment);
        $lines = \array_map('trim', $lines);
        $lines = MarkupDescription::trimLeadingWhitespace($lines);
        while (\in_array(\end($lines), ['*', ''], true)) {
            \array_pop($lines);
        }
        while (\in_array(\reset($lines), ['*', ''], true)) {
            \array_shift($lines);
        }
        return \implode("\n", $lines);
    }

    /**
     * Returns a markup string with the extracted description of this element (known to be a comment of an element with type $comment_category).
     * On success, this is a non-empty string.
     *
     * @return string markup string
     * @internal
     */
    public static function extractDocComment(string $doc_comment, ?int $comment_category = null, ?UnionType $element_type = null, bool $remove_type = false): string
    {
        // Trim the start and the end of the doc comment.
        //
        // We leave in the second `*` of `/**` so that every single non-empty line
        // of a typical doc comment will begin with a `*`
        $doc_comment = \preg_replace('@(^/\*)|(\*/$)@D', '', $doc_comment);

        $results = [];
        $lines = \explode("\n", $doc_comment);
        $saw_phpdoc_tag = false;
        $did_build_from_phpdoc_tag = false;

        foreach ($lines as $i => $line) {
            $line = self::trimLine($line);
            if (\preg_match('/^\s*@/', $line) > 0) {
                $saw_phpdoc_tag = true;
                if (count($results) === 0) {
                    // Special cases:
                    if (\in_array($comment_category, [Comment::ON_PROPERTY, Comment::ON_CONST], true)) {
                        // Treat `@var T description of T` as a valid single-line comment of constants and properties.
                        // Variables don't currently have associated comments
                        if (\preg_match('/^\s*@var\s/', $line) > 0) {
                            $new_lines = self::extractTagSummary($lines, $i);
                            if (isset($new_lines[0])) {
                                $did_build_from_phpdoc_tag = true;
                                // @phan-suppress-next-line PhanAccessClassConstantInternal
                                $new_lines[0] = \preg_replace(Builder::PARAM_COMMENT_REGEX, $remove_type ? '' : '`\0`', $new_lines[0]);
                            }
                            $results = \array_merge($results, $new_lines);
                        }
                    } elseif (\in_array($comment_category, Comment::FUNCTION_LIKE, true)) {
                        // Treat `@return T description of return value` as a valid single-line comment of closures, functions, and methods.
                        // Variables don't currently have associated comments
                        if (\preg_match('/^\s*@return(\s|$)/D', $line) > 0) {
                            $new_lines = self::extractTagSummary($lines, $i);
                            if (isset($new_lines[0])) {
                                // @phan-suppress-next-line PhanAccessClassConstantInternal
                                $new_lines[0] = \preg_replace(Builder::RETURN_COMMENT_REGEX, $remove_type ? '' : '`\0`', $new_lines[0]);
                            }
                            $results = \array_merge($results, $new_lines);
                        }
                    }
                }
            }
            if ($saw_phpdoc_tag) {
                continue;
            }
            if (\trim($line) === '') {
                $line = '';
                if (\in_array(\end($results), ['', false], true)) {
                    continue;
                }
            }
            $results[] = $line;
        }
        if (\end($results) === '') {
            \array_pop($results);
        }
        $results = self::trimLeadingWhitespace($results);
        $str = \implode("\n", $results);
        if ($comment_category === Comment::ON_PROPERTY && !$did_build_from_phpdoc_tag && !$remove_type) {
            if ($element_type && !$element_type->isEmpty()) {
                $str = \trim("`@var $element_type` $str");
            }
        }
        return $str;
    }

    /**
     * Remove leading * and spaces (and trailing spaces) from the provided line of text.
     * This is useful for trimming raw doc comment lines
     */
    public static function trimLine(string $line): string
    {
        $line = \rtrim($line);
        $pos = \strpos($line, '*');
        if ($pos !== false) {
            return (string)\substr($line, $pos + 1);
        } else {
            return \ltrim($line, "\n\t ");
        }
    }

    /**
     * @param list<string> $lines
     * @param int $i the offset of the tag in $lines
     * @return list<string> the trimmed lines
     * @internal
     */
    public static function extractTagSummary(array $lines, int $i): array
    {
        $summary = [];
        $summary[] = self::trimLine($lines[$i]);
        for ($j = $i + 1; $j < count($lines); $j++) {
            $line = self::trimLine($lines[$j]);
            if (\preg_match('/^\s*\{?@/', $line)) {
                // Break on other annotations such as (at)internal, {(at)inheritDoc}, etc.
                break;
            }
            if ($line === '' && \end($summary) === '') {
                continue;
            }
            $summary[] = $line;
        }
        if (\end($summary) === '') {
            \array_pop($summary);
        }
        if (count($summary) === 1 && count((array)\preg_split('/\s+/', \trim($summary[0]))) <= 2) {
            // For something uninformative such as "* (at)return int" (and nothing else),
            // don't treat it as a summary.
            //
            // The caller would already show the return type
            return [];
        }
        return $summary;
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function trimLeadingWhitespace(array $lines): array
    {
        if (count($lines) === 0) {
            return [];
        }
        $min_whitespace = \PHP_INT_MAX;
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $min_whitespace = \min($min_whitespace, \strlen($line));
            $min_whitespace = \min($min_whitespace, \strspn($line, ' ', 0, $min_whitespace));
            if ($min_whitespace === 0) {
                return $lines;
            }
        }
        if ($min_whitespace > 0) {
            foreach ($lines as $i => $line) {
                if ($line === '') {
                    continue;
                }
                $lines[$i] = (string)\substr($line, $min_whitespace);
            }
        }
        return $lines;
    }
}
