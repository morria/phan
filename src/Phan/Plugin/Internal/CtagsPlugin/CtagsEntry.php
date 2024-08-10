<?php

declare(strict_types=1);

namespace Phan\Plugin\Internal\CtagsPlugin;

use Exception;
use Phan\CLI;
use Phan\Config;
use Phan\Language\FileRef;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassElement;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Library\FileCache;
use Phan\Phan;

use function is_string;
use function strlen;

/**
 * Represents a single ctags entry.
 *
 * @phan-read-only
 */
class CtagsEntry
{
    public const KIND_CLASS = 'c';
    /** "define" in ctags is also used for constants */
    public const KIND_CONSTANT = 'd';
    public const KIND_FUNCTION = 'f';
    public const KIND_PROPERTY = 'p';

    /** @var string the name of the tag */
    public $name;
    /** @var FileRef the file and line the tag referred to */
    public $context;
    /** @var string the kind of tag*/
    public $kind;
    /** @var ?string the scope, e.g. `function:some_function`, `namespace:MyNS\SubNS`, `class:SomeClass`, etc. */
    public $scope;
    /** @var string the code fragment to locate the tag */
    public $fragment;
    /** @var bool is this entry valid */
    private $is_valid = false;

    public function __construct(string $name, FileRef $context, string $kind, ?string $scope, ?string $fragment = null)
    {
        if ($context->isPHPInternal()) {
            return;
        }
        if (!\is_string($fragment)) {
            $path = Config::projectPath($context->getFile());
            try {
                $entry = FileCache::getOrReadEntry($path);
            } catch (Exception $e) {
                CLI::printToStderr("Failed to read $path: {$e->getMessage()}\n");
                return;
            }

            $fragment = $entry->getLines()[$context->getLineNumberStart()] ?? '';
        }
        if (\strlen($fragment) === 0) {
            CLI::printToStderr("Empty line for generating ctags for name=$name at $context kind=$kind\n");
            return;
        }
        $this->name = $name;
        $this->context = $context;
        $this->kind = $kind;
        $this->scope = $scope;
        $this->fragment = \rtrim($fragment);
        $this->is_valid = true;
    }

    /**
     * Is this ctags entry valid?
     */
    public function isValid(): bool
    {
        return $this->is_valid;
    }

    public function __toString()
    {
        /*
         * > The name of the "kind:" field can be omitted.  This is to reduce the size of
         * > the tags file by about 15%.  A program reading the tags file can recognize the
         * > "kind:" field by the missing ':'.
         */
        $ctags_path = Config::projectPath($this->context->getFile());
        $common = \sprintf(
            "%s\t%s\t%s\t%s\tline:%d",
            $this->name,
            $ctags_path,
            self::escapeFragment($this->fragment),
            $this->kind,
            $this->context->getLineNumberStart()
        );
        if (is_string($this->scope) && strlen($this->scope) > 0) {
            $common .= "\t$this->scope";
        }
        return $common;
    }

    /**
     * Escape a code fragment for the ctags file format.
     */
    public static function escapeFragment(string $fragment): string
    {
        $escaped = \str_replace(['\\', '/', "\0"], ['\\\\', '\/', '\\0'], $fragment);
        return '/^' . $escaped . '$/;"';
    }

    /**
     * Generate a scope label for the provided FQSEN
     */
    public static function generateScopeLabelForFQSEN(?FQSEN $fqsen): ?string
    {
        if ($fqsen instanceof FullyQualifiedClassName) {
            return 'class:' . \ltrim((string)$fqsen, '\\');
        } elseif ($fqsen instanceof FullyQualifiedClassElement) {
            return self::generateScopeLabelForFQSEN($fqsen->getFullyQualifiedClassName());
        }
        return null;
    }

    /**
     * Generate a scope label for the provided namespace.
     */
    public static function generateScopeLabelForNamespace(string $namespace): ?string
    {
        $namespace = \ltrim($namespace, "\\");
        if (strlen($namespace) > 0) {
            return "namespace:$namespace";
        }
        return null;
    }

    /**
     * Key for ordering tags by strcmp
     */
    public function getOrderKey(): string
    {
        $is_analyzed_order = Phan::isExcludedAnalysisFile($this->context->getFile()) ? "1" : "0";
        switch ($this->kind) {
            case self::KIND_CLASS:
                $type_order = "0";
                break;
            case self::KIND_FUNCTION:
                if (\preg_match('/^class:/', $this->scope ?? '')) {
                    $type_order = "3";
                } else {
                    $type_order = "1";
                }
                break;
            case self::KIND_CONSTANT:
                $type_order = "2";
                break;
            case self::KIND_PROPERTY:
                $type_order = "4";
                break;
            default:
                $type_order = "9";
                break;
        }
        return $is_analyzed_order . "\0" . $type_order;
    }
}
