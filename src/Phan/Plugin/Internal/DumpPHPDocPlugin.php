<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal;

use Phan\CodeBase;
use Phan\Language\Element\AddressableElement;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\MarkupDescription;
use Phan\Language\Element\Method;
use Phan\Language\Element\Property;
use Phan\Library\StringUtil;
use Phan\Phan;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeClassCapability;
use Phan\PluginV3\AnalyzeFunctionCapability;
use Phan\PluginV3\AnalyzeMethodCapability;
use Phan\PluginV3\AnalyzePropertyCapability;
use Phan\PluginV3\FinalizeProcessCapability;

/**
 * This file dumps Phan's inferred signatures and markup descriptions as markdown.
 *
 * NOTE: This is automatically loaded by phan. Do not include it in a config.
 *
 * @phan-file-suppress PhanPluginRemoveDebugEcho outputting is deliberate
 */
final class DumpPHPDocPlugin extends PluginV3 implements
    AnalyzeClassCapability,
    AnalyzeFunctionCapability,
    AnalyzeMethodCapability,
    AnalyzePropertyCapability,
    FinalizeProcessCapability
{
    /**
     * @var array<string,string> the stubs to use
     */
    private $stubs = [];

    private static function generatePHPMarkdownBlock(string $php_snippet): string
    {
        $php_snippet = \trim($php_snippet);
        return "```php\n$php_snippet\n```";
    }

    /**
     * @param CodeBase $code_base @unused-param
     * The code base in which the class exists
     *
     * @param Clazz $class
     * A class being analyzed
     * @override
     */
    public function analyzeClass(
        CodeBase $code_base,
        Clazz $class
    ): void {
        if ($class->getFQSEN()->isAlternate()) {
            return;
        }
        $description = MarkupDescription::extractDescriptionFromDocComment($class);

        $this->recordStub(
            $class,
            self::generatePHPMarkdownBlock($class->getMarkupDescription()),
            $description
        );
    }

    private function recordStub(AddressableElement $element, string $header_text, ?string $doc_comment_markup = null): void
    {
        if (Phan::isExcludedAnalysisFile($element->getFileRef()->getFile())) {
            return;
        }
        $markup = "## " . \ltrim($element->getFQSEN()->__toString(), "\\") . "\n\n";
        $markup .= $header_text . "\n\n";
        if ($doc_comment_markup !== null) {
            $markup .= "Description:\n\n";
            $markup .= $doc_comment_markup . "\n\n";
        }
        $this->stubs[$element->getFQSEN() . "\x00" . \get_class($element)] = $markup;
    }

    /**
     * @param CodeBase $code_base @unused-param
     * The code base in which the property exists
     *
     * @param Property $property
     * A property being analyzed
     * @override
     */
    public function analyzeProperty(
        CodeBase $code_base,
        Property $property
    ): void {
        if ($property->isDynamicProperty()) {
            // Dynamic properties don't have declarations or phpdoc.
            return;
        }
        if ($property->isFromPHPDoc()) {
            // Phan does not track descriptions of (at)property.
            // TODO: Enable
            return;
        }
        if ($property->getFQSEN() !== $property->getRealDefiningFQSEN()) {
            // Only emit stubs for the original definition of this property.
            return;
        }
        $description = MarkupDescription::extractDescriptionFromDocComment($property);

        $this->recordStub(
            $property,
            self::generatePHPMarkdownBlock($property->getMarkupDescription()),
            $description
        );
    }

    /**
     * @param CodeBase $code_base @unused-param
     * The code base in which the method exists
     *
     * @param Method $method
     * A method being analyzed
     * @override
     */
    public function analyzeMethod(
        CodeBase $code_base,
        Method $method
    ): void {
        if ($method->isFromPHPDoc()) {
            // Phan does not track descriptions of (at)method.
            return;
        }
        if ($method->getFQSEN() !== $method->getRealDefiningFQSEN()) {
            // Only warn once for the original definition of this method.
            // Don't warn about subclasses inheriting this method.
            return;
        }
        $description = MarkupDescription::extractDescriptionFromDocComment($method);
        if (!($method->getDocComment() || !StringUtil::isNonZeroLengthString($description)) && $method->isOverride()) {
            // Note: This deliberately avoids showing a summary for methods that are just overrides of other methods,
            // unless they have their own phpdoc.
            // Eventually, extractDescriptionFromDocComment will search ancestor classes for $description
            return;
        }

        foreach (MarkupDescription::extractParamTagsFromDocComment($method) as $param_name => $param_markup) {
            if ($description === null) {
                $description = "";
            }
            $description .= "\n\n### \$$param_name\n\n$param_markup\n\n";
        }

        $this->recordStub(
            $method,
            self::generatePHPMarkdownBlock($method->getMarkupDescription()),
            $description
        );
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the function exists
     *
     * @param Func $function
     * A function being analyzed
     * @override
     */
    public function analyzeFunction(
        CodeBase $code_base,
        Func $function
    ): void {
        if ($function->isPHPInternal()) {
            // This isn't user-defined, there's no reason to warn or way to change it.
            return;
        }
        if ($function->isNSInternal($code_base)) {
            // (at)internal are internal to the library, and there's less of a need to document them
            return;
        }
        if ($function->isClosure()) {
            // Probably not useful in many cases to document a short closure passed to array_map, etc.
            return;
        }
        $description = MarkupDescription::extractDescriptionFromDocComment($function);

        foreach (MarkupDescription::extractParamTagsFromDocComment($function) as $param_name => $param_markup) {
            if ($description === null) {
                $description = "";
            }
            $description .= "\n\n### \$$param_name\n\n$param_markup\n\n";
        }


        $this->recordStub(
            $function,
            self::generatePHPMarkdownBlock($function->getMarkupDescription()),
            $description
        );
    }

    /**
     * Executed before the analysis phase starts.
     *
     * @unused-param $code_base
     * @override
     * @return never
     */
    public function finalizeProcess(CodeBase $code_base): void
    {
        \ksort($this->stubs);
        echo "# Phan Signatures\n\n";
        echo \implode('', $this->stubs);
        exit(\EXIT_SUCCESS);
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which it's defined.
return new DumpPHPDocPlugin();
