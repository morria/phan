<?php

declare(strict_types=1);

namespace Phan\Language\Element;

use AssertionError;
use Closure;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\FileRef;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedGlobalStructuralElement;
use Phan\Language\UnionType;
use Phan\Memoize;

/**
 * An addressable element is a TypedElement with an FQSEN.
 * (E.g. a class, property, function, method, etc.)
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
abstract class AddressableElement extends TypedElement implements AddressableElementInterface
{
    use Memoize;

    /**
     * @var FQSEN
     * A fully qualified name for the element
     */
    protected $fqsen;

    /**
     * @var array<string,FileRef>
     * A list of locations in which this typed structural
     * element is referenced from.
     * References from the same file and line are deduplicated to save memory.
     */
    protected $reference_list = [];

    /**
     * @var ?string
     * The doc comment of the element
     */
    protected $doc_comment;

    /**
     * @var bool
     * Has this element been hydrated yet?
     * (adding information from ancestor classes for more detailed type information)
     */
    protected $is_hydrated = false;

    /**
     * @param Context $context
     * The context in which the structural element lives
     *
     * @param string $name
     * The name of the typed structural element
     *
     * @param UnionType $type
     * A '|' delimited set of types satisfied by this
     * typed structural element.
     *
     * @param int $flags
     * The flags property contains node specific flags. It is
     * always defined, but for most nodes it is always zero.
     * ast\kind_uses_flags() can be used to determine whether
     * a certain kind has a meaningful flags value.
     *
     * @param FQSEN $fqsen
     * A fully qualified name for the element
     */
    public function __construct(
        Context $context,
        string $name,
        UnionType $type,
        int $flags,
        FQSEN $fqsen
    ) {
        parent::__construct(
            $context,
            $name,
            $type,
            $flags
        );

        $this->setFQSEN($fqsen);
    }

    /**
     * @return FQSEN
     * The fully-qualified structural element name of this
     * structural element
     */
    public function getFQSEN()
    {
        return $this->fqsen;
    }

    /**
     * @param FQSEN $fqsen
     * A fully qualified structural element name to set on
     * this element
     */
    public function setFQSEN(FQSEN $fqsen): void
    {
        $this->fqsen = $fqsen;
    }

    /**
     * @return bool true if this element's visibility
     *                   is strictly more visible than $other (public > protected > private)
     */
    public function isStrictlyMoreVisibleThan(AddressableElementInterface $other): bool
    {
        if ($this->isPrivate()) {
            return false;
        } // $this is public or protected

        if ($other->isPrivate()) {
            return true;
        }

        if ($other->isProtected()) {
            // True if this is public.
            return !$this->isProtected();
        }
        // $other is public
        return false;
    }

    /**
     * @return bool
     * True if this is a public property
     */
    public function isPublic(): bool
    {
        return !(
            $this->isProtected() || $this->isPrivate()
        );
    }

    /**
     * @return bool
     * True if this is a protected element
     */
    public function isProtected(): bool
    {
        return $this->getFlagsHasState(\ast\flags\MODIFIER_PROTECTED);
    }

    /**
     * @return bool
     * True if this is a private element
     */
    public function isPrivate(): bool
    {
        return $this->getFlagsHasState(\ast\flags\MODIFIER_PRIVATE);
    }

    /**
     * @param CodeBase $code_base (@phan-unused-param, this is used by subclasses)
     * The code base in which this element exists.
     *
     * @return bool
     * True if this is marked as an `(at)internal` element
     */
    public function isNSInternal(CodeBase $code_base): bool
    {
        return $this->getPhanFlagsHasState(Flags::IS_NS_INTERNAL);
    }

    /**
     * Set this element as being `internal`.
     */
    public function setIsNSInternal(bool $is_internal): void
    {
        $this->setPhanFlags(Flags::bitVectorWithState(
            $this->getPhanFlags(),
            Flags::IS_NS_INTERNAL,
            $is_internal
        ));
    }

    /**
     * @param CodeBase $code_base (@phan-unused-param, this is used by subclasses)
     * The code base in which this element exists.
     *
     * @return bool
     * True if this element is intern
     */
    public function isNSInternalAccessFromContext(
        CodeBase $code_base,
        Context $context
    ): bool {
        // Figure out which namespace this element is within
        $element_namespace = $this->getElementNamespace() ?: '\\';

        // Get our current namespace from the context
        $context_namespace = $context->getNamespace() ?: '\\';

        // Test to see if the context is within the same
        // namespace as where the element is defined
        return (0 === \strcasecmp($context_namespace, $element_namespace));
    }

    /**
     * @param FileRef $file_ref
     * A reference to a location in which this typed structural
     * element is referenced.
     */
    public function addReference(FileRef $file_ref): void
    {
        if (Config::get_track_references()) {
            // Currently, we don't need to track references to PHP-internal methods/functions/constants
            // such as PHP_VERSION, strlen(), Closure::bind(), etc.
            // This may change in the future.
            if ($this->isPHPInternal()) {
                return;
            }
            $this->reference_list[$file_ref->__toString()] = $file_ref;
        }
    }

    /**
     * Copy addressable references from an element of the same subclass
     */
    public function copyReferencesFrom(AddressableElement $element): void
    {
        if ($this === $element) {
            // Should be impossible
            return;
        }
        foreach ($element->reference_list as $key => $file_ref) {
            $this->reference_list[$key] = $file_ref;
        }
    }

    /**
     * @return array<string,FileRef>
     * A list of references to this typed structural element.
     */
    public function getReferenceList(): array
    {
        return $this->reference_list;
    }

    /**
     * @param CodeBase $code_base (@phan-unused-param)
     * Some elements may need access to the code base to
     * figure out their total reference count.
     *
     * @return int
     * The number of references to this typed structural element
     */
    public function getReferenceCount(
        CodeBase $code_base
    ): int {
        return \count($this->reference_list);
    }

    /**
     * This method must be called before analysis
     * begins.
     * @override
     */
    public function hydrate(CodeBase $code_base): void
    {
        if ($this->is_hydrated) {  // Same as isFirstExecution(), inlined due to being called frequently.
            return;
        }
        $this->is_hydrated = true;

        $this->hydrateOnce($code_base);
    }

    /**
     * @unused-param $code_base
     */
    protected function hydrateOnce(CodeBase $code_base): void
    {
        // Do nothing unless overridden
    }

    /**
     * Returns the namespace in which this element was declared
     */
    public function getElementNamespace(): string
    {
        $element_fqsen = $this->getFQSEN();
        if (!$element_fqsen instanceof FullyQualifiedGlobalStructuralElement) {
            throw new AssertionError('Expected $this->element_fqsen to be FullyQualifiedGlobalStructuralElement');
        }

        // Figure out which namespace this element is within
        return $element_fqsen->getNamespace();
    }

    /**
     * Used by daemon mode to restore an element to the state it had before parsing.
     * @internal
     */
    abstract public function createRestoreCallback(): ?Closure;

    /**
     * @param ?string $doc_comment the 'docComment' for this element, if any exists.
     */
    public function setDocComment(?string $doc_comment = null): void
    {
        $this->doc_comment = $doc_comment;
    }

    /**
     * @return ?string the 'docComment' for this element, if any exists.
     */
    public function getDocComment(): ?string
    {
        return $this->doc_comment;
    }

    /**
     * @return string the reason why this element was deprecated, or null if this could not be determined.
     */
    public function getDeprecationReason(): string
    {
        return $this->memoize(__METHOD__, function (): string {
            if (!\is_string($this->doc_comment)) {
                return '';
            }
            if (!\preg_match('/@deprecated\b/', $this->doc_comment, $matches, \PREG_OFFSET_CAPTURE)) {
                return '';
            }
            $doc_comment = \preg_replace('@(^/\*)|(\*/$)@D', '', $this->doc_comment);
            $lines = \explode("\n", $doc_comment);
            foreach ($lines as $i => $line) {
                $line = MarkupDescription::trimLine($line);
                if (\preg_match('/^\s*@deprecated\b/', $line) > 0) {
                    $new_lines = MarkupDescription::extractTagSummary($lines, $i);
                    if (!$new_lines) {
                        return '';
                    }
                    $new_lines[0] = \preg_replace('/^\s*@deprecated\b\s*/', '', $new_lines[0]);
                    $reason = \implode(' ', \array_filter(\array_map('trim', $new_lines), static function (string $line): bool {
                        return $line !== '';
                    }));
                    if ($reason !== '') {
                        return ' (Deprecated because: ' . $reason . ')';
                    }
                }
            }

            return '';
        });
    }

    /**
     * @return string the representation of this FQSEN for issue messages.
     * Overridden in some subclasses
     * @suppress PhanUnreferencedPublicMethod (inference error?)
     */
    public function getRepresentationForIssue(): string
    {
        return $this->getFQSEN()->__toString();
    }

    /**
     * Returns true if Phan should include doc comments in the output of tool/make_stubs
     */
    public static function shouldAddDescriptionsToStubs(): bool
    {
        return (bool)($_ENV['PHAN_STUB_INCLUDE_DOC'] ?? false);
    }
}
