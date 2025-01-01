<?php

declare(strict_types=1);

namespace Phan;

use ArrayObject;
use AssertionError;
use Closure;
use Exception;
use InvalidArgumentException;
use Phan\CodeBase\ClassMap;
use Phan\CodeBase\UndoTracker;
use Phan\Exception\FQSENException;
use Phan\Language\Context;
use Phan\Language\Element\ClassAliasRecord;
use Phan\Language\Element\ClassConstant;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionFactory;
use Phan\Language\Element\GlobalConstant;
use Phan\Language\Element\Method;
use Phan\Language\Element\Parameter;
use Phan\Language\Element\Property;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassElement;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Language\NamespaceMapEntry;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\Library\Map;
use Phan\Library\Set;
use Phan\Library\StringSuggester;
use Phan\Plugin\ConfigPluginSet;
use ReflectionClass;

use function count;
use function get_defined_constants;
use function get_extension_funcs;
use function get_loaded_extensions;
use function is_array;
use function strtolower;

use const STDERR;

/**
 * A CodeBase represents the known state of a code base
 * we're analyzing.
 *
 * In order to understand internal classes, interfaces,
 * traits and functions, a CodeBase needs to be
 * initialized with the list of those elements begotten
 * before any classes are loaded.
 *
 * # Example
 *
 * ```php
 * // Grab these before we define our own classes
 * $internal_class_name_list = get_declared_classes();
 * $internal_interface_name_list = get_declared_interfaces();
 * $internal_trait_name_list = get_declared_traits();
 * $internal_function_name_list = get_defined_functions()['internal'];
 *
 * // Load any required code ...
 *
 * $code_base = new CodeBase(
 *     $internal_class_name_list,
 *     $internal_interface_name_list,
 *     $internal_trait_name_list,
 *     CodeBase::getPHPInternalConstantNameList(),
 *     $internal_function_name_list
 * );
 *
 * // Do stuff ...
 * ```
 *
 * This supports undoing some operations in the parse phase,
 * for a background daemon analyzing single files. (Phan\CodeBase\UndoTracker)
 *
 * @phan-file-suppress PhanPartialTypeMismatchReturn the way generic objects is type hinted is inadequate, etc.
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 */
class CodeBase
{
    /**
     * @var Map<FullyQualifiedClassName,Clazz>
     * A map from FQSEN to an internal or user defined class
     *
     * TODO: Improve Phan's self-analysis, allow the shorthand array access set syntax to be used without making bad inferences
     * (e.g. $this->fqsen_class_map[$fqsen] = $clazz;
     */
    private $fqsen_class_map;

    /**
     * @var Map<FullyQualifiedClassName,Clazz>
     * A map from FQSEN to a user defined class
     */
    private $fqsen_class_map_user_defined;

    /**
     * @var Map<FullyQualifiedClassName,Clazz>
     * A map from FQSEN to an internal class
     */
    private $fqsen_class_map_internal;

    /**
     * @var Map<FullyQualifiedClassName,ReflectionClass>
     * A map from FQSEN to a ReflectionClass
     */
    private $fqsen_class_map_reflection;

    /**
     * @var Map<FullyQualifiedClassName,Set<ClassAliasRecord>>
     * A map from FQSEN to set of ClassAliasRecord objects
     */
    private $fqsen_alias_map;

    /**
     * @var Map<FullyQualifiedGlobalConstantName,GlobalConstant>
     * A map from FQSEN to a global constant
     */
    private $fqsen_global_constant_map;

    /**
     * @var Map<FullyQualifiedFunctionName,Func>
     * A map from FQSEN to function
     */
    private $fqsen_func_map;

    /**
     * @var Set<FullyQualifiedFunctionName>
     * A set of internal function FQSENs to lazily initialize.
     * Entries are removed as new entries get added to fqsen_func_map.
     */
    private $internal_function_fqsen_set;

    /**
     * @var Set<Method>
     * The set of all methods
     */
    private $method_set;

    /**
     * @var Map<FullyQualifiedClassName,ClassMap>
     * A map from FullyQualifiedClassName to a ClassMap,
     * an object that holds properties, methods and class
     * constants.
     */
    private $class_fqsen_class_map_map;

    /**
     * @var array<string,Set<Method>>
     * A map from a string method name to a Set of
     * Methods
     */
    private $name_method_map = [];

    /**
     * @var array<string,array<string,associative-array<int,array<string,NamespaceMapEntry>>>>
     * Maps the file and namespace identifier to the use statements found in that namespace
     */
    private $parsed_namespace_maps = [];

    /**
     * @var array<string,array<string,int>>
     * Maps file paths to a set of file-level suppressions (E.g. 'PhanUnreferencedUseNormal', etc.)
     * The corresponding value is the number of times the issue was suppressed
     */
    private $file_level_suppression_set = [];

    /**
     * @var bool
     * If true, elements will be ensured to be hydrated
     * on demand as they are requested.
     */
    private $should_hydrate_requested_elements = false;

    /**
     * @var UndoTracker|null - undoes the addition of global constants, classes, functions, and methods.
     */
    private $undo_tracker;

    /**
     * @var bool is the undo tracker currently enabled?
     *
     * If the Phan Language Server or Daemon Mode is enabled,
     * the undo tracker will be enabled prior to the analysis phase, and disabled afterwards.
     */
    private $has_enabled_undo_tracker = false;

    /**
     * @var bool should Phan expect files contents for any path to be changed frequently
     * (i.e. running as Daemon or the language server)
     */
    private $expect_changes_to_file_contents = false;

    /**
     * @var ?string (The currently parsed or analyzed file, if any. Used only for the crash reporting output)
     */
    private static $current_file = null;

    /**
     * Initialize a new CodeBase
     * TODO: Remove internal_function_name_list completely?
     * @param string[] $internal_class_name_list
     * @param string[] $internal_interface_name_list
     * @param string[] $internal_trait_name_list
     * @param string[] $internal_constant_name_list
     * @param string[] $internal_function_name_list
     */
    public function __construct(
        array $internal_class_name_list,
        array $internal_interface_name_list,
        array $internal_trait_name_list,
        array $internal_constant_name_list,
        array $internal_function_name_list
    ) {
        $this->fqsen_class_map = new Map();
        $this->fqsen_class_map_internal = new Map();
        $this->fqsen_class_map_reflection = new Map();
        $this->fqsen_class_map_user_defined = new Map();
        $this->fqsen_alias_map = new Map();
        $this->fqsen_global_constant_map = new Map();
        $this->fqsen_func_map = new Map();
        $this->class_fqsen_class_map_map = new Map();
        $this->method_set = new Set();
        $this->internal_function_fqsen_set = new Set();

        // Add any pre-defined internal classes, interfaces,
        // constants, traits and functions
        $this->addClassesByNames($internal_class_name_list);
        $this->addClassesByNames($internal_interface_name_list);
        $this->addClassesByNames($internal_trait_name_list);
        $this->addGlobalConstantsByNames($internal_constant_name_list);
        // These are keywords that Phan expects to always exist - make sure to add them even if they weren't provided.
        $this->addGlobalConstantsByNames(['true', 'false', 'null']);
        // We initialize the FQSENs early on so that they show up
        // in the proper casing.
        $this->addInternalFunctionsByNames($internal_function_name_list);
    }

    /**
     * Start to enable the tracking of closures that can undo adding elements (class declarations, method declarations, etc.)
     * to this codebase.
     *
     * This should only be called once, before the start of the parse phase.
     */
    public function enableUndoTracking(): void
    {
        if ($this->has_enabled_undo_tracker) {
            throw new \RuntimeException("Undo tracking already enabled");
        }
        $this->has_enabled_undo_tracker = true;
        $this->undo_tracker = new UndoTracker();
    }

    /**
     * Start to disable the tracking of closures that can undo adding elements (class declarations, method declarations, etc.)
     * to this codebase.
     */
    public function disableUndoTracking(): void
    {
        if (!$this->has_enabled_undo_tracker) {
            throw new \RuntimeException("Undo tracking was never enabled");
        }
        $this->undo_tracker = null;
    }

    /**
     * @return bool is undo tracking enabled (i.e. are there closures that will revert the side effect of adding a file?)
     */
    public function isUndoTrackingEnabled(): bool
    {
        return $this->undo_tracker !== null;
    }

    /**
     * Enable hydration of elements. (populating class elements with information from their ancestors)
     *
     * This is called after the parse phase is finished.
     *
     * - Prior to the end of the parse phase, ancestors of class elements would be unavailable,
     *   so hydration would result in an inconsistent state.
     */
    public function setShouldHydrateRequestedElements(
        bool $should_hydrate_requested_elements
    ): void {
        $this->should_hydrate_requested_elements =
            $should_hydrate_requested_elements;
    }

    /**
     * Returns true if hydration of elements is enabled.
     * This is called after the parse phase is finished.
     */
    public function shouldHydrateRequestedElements(): bool
    {
        return $this->should_hydrate_requested_elements;
    }

    /**
     * @return list<string> - The list of files which are successfully parsed.
     * This changes whenever the file list is reloaded from disk.
     * This also includes files which don't declare classes or functions or globals,
     * because those files use classes/functions/constants.
     *
     * (This is the list prior to any analysis exclusion or whitelisting steps)
     */
    public function getParsedFilePathList(): array
    {
        if ($this->undo_tracker) {
            return $this->undo_tracker->getParsedFilePathList();
        }
        throw new \RuntimeException("Calling getParsedFilePathList without an undo tracker");
    }

    /**
     * @return int The size of $this->getParsedFilePathList()
     */
    public function getParsedFilePathCount(): int
    {
        if ($this->undo_tracker) {
            return $this->undo_tracker->getParsedFilePathCount();
        }
        throw new \RuntimeException("Calling getParsedFilePathCount without an undo tracker");
    }

    /**
     * Records the file currently being parsed/analyzed so that crash/error reports
     * will indicate the analyzed file causing the error.
     */
    public function setCurrentParsedFile(?string $current_parsed_file): void
    {
        self::$current_file = $current_parsed_file;
        if ($this->undo_tracker) {
            $this->undo_tracker->setCurrentParsedFile($current_parsed_file);
        }
    }

    /**
     * Record that changes to file contents should be expected from now onwards, e.g. this is running as a language server or in daemon mode.
     *
     * E.g. this would disable caching ASTs of the polyfill/fallback to disk.
     */
    public function setExpectChangesToFileContents(): void
    {
        $this->expect_changes_to_file_contents = true;
    }

    /**
     * Returns true if changes to file contents should be expected frequently.
     *
     * E.g. this is called to check if Phan should disable caching ASTs of the polyfill/fallback to disk.
     */
    public function getExpectChangesToFileContents(): bool
    {
        return $this->expect_changes_to_file_contents;
    }

    /**
     * Sets the currently analyzed file, to improve Phan's crash reporting.
     * @param string|null $current_analyzed_file
     */
    public function setCurrentAnalyzedFile(?string $current_analyzed_file): void
    {
        self::$current_file = $current_analyzed_file;
    }

    /**
     * Returns the most recently parsed or analyzed file.
     * @internal - For use only by the phan error handler, to help with debugging crashes
     */
    public static function getMostRecentlyParsedOrAnalyzedFile(): ?string
    {
        return self::$current_file;
    }

    /**
     * Called when a file is unparsable.
     * Removes the classes and functions, etc. from an older version of the file, if one exists.
     */
    public function recordUnparsableFile(string $current_parsed_file): void
    {
        if ($this->undo_tracker) {
            $this->undo_tracker->recordUnparsableFile($this, $current_parsed_file);
        }
    }

    /**
     * @param string[] $class_name_list
     * A list of class names to load type information for
     */
    private function addClassesByNames(array $class_name_list): void
    {
        $included_extension_subset = self::getIncludedExtensionSubset();
        foreach ($class_name_list as $class_name) {
            $reflection_class = new \ReflectionClass($class_name);
            if ($reflection_class->isUserDefined()) {
                continue;
            }
            if (is_array($included_extension_subset) && !isset($included_extension_subset[strtolower($reflection_class->getExtensionName() ?: '')])) {
                // Allow preventing Phan from loading type information for a subset of extensions.
                // This is useful if you have an extension installed locally (e.g. FFI, ast) but it won't be available in the target environment/php version.
                continue;
            }
            // include internal classes, but not external classes such as composer
            $this->addReflectionClass($reflection_class);
        }
    }

    /**
     * @return ?array<string,true> if non-null, the subset of extensions phan will limit the loading of reflection information to.
     */
    private static function getIncludedExtensionSubset(): ?array
    {
        $included_extension_subset = Config::getValue('included_extension_subset');
        if (!is_array($included_extension_subset)) {
            return null;
        }
        $map = [
            'core' => true,
            'date' => true,
            // 'hash' => true,  // always enabled in 7.4.0, too new
            // 'json' => true,  // always enabled in 8.0.0, too new
            'pcre' => true,
            'reflection' => true,
            'spl' => true,
            'standard' => true,
        ];
        foreach ($included_extension_subset as $name) {
            if ($name === 'user') {
                continue;
            }
            $map[strtolower($name)] = true;
        }
        return $map;
    }

    /**
     * @param string[] $const_name_list
     * A list of global constant names to load type information for
     */
    private function addGlobalConstantsByNames(array $const_name_list): void
    {
        $included_extension_subset = self::getIncludedExtensionSubset();
        if (is_array($included_extension_subset)) {
            $excluded_constant_set = [];
            foreach (get_defined_constants(true) as $ext_name => $constant_values) {
                if (isset($included_extension_subset[strtolower($ext_name)])) {
                    continue;
                }
                foreach ($constant_values as $constant_name => $_) {
                    $excluded_constant_set[$constant_name] = true;
                }
            }
            foreach ($const_name_list as $i => $const_name) {
                if (isset($excluded_constant_set[$const_name])) {
                    unset($const_name_list[$i]);
                }
            }
        }
        foreach ($const_name_list as $const_name) {
            // #1015 workaround for empty constant names ('' and '0').
            if (!\is_string($const_name)) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fprintf(STDERR, "Saw constant with non-string name of %s. There may be a bug in a PECL extension you are using (php -m will list those)\n", \var_representation($const_name));
                continue;
            }
            try {
                $const_obj = GlobalConstant::fromGlobalConstantName($const_name);
                $this->addGlobalConstant($const_obj);
            } catch (InvalidArgumentException | FQSENException $e) {
                self::handleGlobalConstantException($const_name, $e);
            }
        }
    }

    private static function handleGlobalConstantException(string $const_name, Exception $e): void
    {
        // e.g. "\000apc_register_serializer-" APC_SERIALIZER_ABI or immutable_cache or windows bug in #1011
        if (\strncmp($const_name, "\x00", 1) === 0) {
            return;
        }
        // @phan-suppress-next-line PhanPluginRemoveDebugCall
        \fprintf(STDERR, "Failed to load global constant value for %s, continuing: %s\n", \var_representation($const_name), $e->getMessage());
    }

    /**
     * @param list<string> $new_file_list
     * @param array<string,string> $file_mapping_contents maps relative path to absolute paths
     * @param ?(string[]) $reanalyze_files files to re-analyze
     * @return list<string> - Subset of $new_file_list which changed on disk and has to be parsed again. Automatically unparses the old versions of files which were modified.
     */
    public function updateFileList(array $new_file_list, array $file_mapping_contents = [], ?array $reanalyze_files = null): array
    {
        if ($this->undo_tracker) {
            $this->invalidateDependentCacheEntries();

            return $this->undo_tracker->updateFileList($this, $new_file_list, $file_mapping_contents, $reanalyze_files);
        }
        throw new \RuntimeException("Calling updateFileList without undo tracker");
    }

    /**
     * @param string $file_name
     * @return bool - true if caller should replace contents
     */
    public function beforeReplaceFileContents(string $file_name): bool
    {
        if ($this->undo_tracker) {
            $this->invalidateDependentCacheEntries();

            return $this->undo_tracker->beforeReplaceFileContents($this, $file_name);
        }
        throw new \RuntimeException("Calling replaceFileContents without undo tracker");
    }

    /**
     * Eagerly load all signatures.
     *
     * This is useful if we expect Phan to be running for a long time and forking processes (in language server or daemon mode),
     * or if we need all of the signatures of functions (e.g. for tools that need all signatures)
     */
    public function eagerlyLoadAllSignatures(): void
    {
        $this->getInternalClassMap();  // Force initialization of remaining internal php classes to reduce latency of future analysis requests.
        $this->forceLoadingInternalFunctions();  // Force initialization of internal functions to reduce latency of future analysis requests.
    }

    /**
     * Load all internal global functions for analysis.
     *
     * This is useful if we expect Phan to be running for a long time and forking processes (in language server or daemon mode),
     * or if we need all of the signatures of functions (e.g. for tools that need all signatures)
     */
    public function forceLoadingInternalFunctions(): void
    {
        $internal_function_fqsen_set = $this->internal_function_fqsen_set;
        try {
            foreach ($internal_function_fqsen_set as $function_fqsen) {
                // hasFunctionWithFQSEN will automatically load $function_name, **unless** we don't have a signature for that function.
                if (!$this->hasFunctionWithFQSEN($function_fqsen)) {
                    // Force loading these even if automatic loading failed.
                    // (Shouldn't happen, the function list is fetched from reflection by callers.
                    $function_alternate_generator = FunctionFactory::functionListFromReflectionFunction(
                        $function_fqsen,
                        new \ReflectionFunction($function_fqsen->getNamespacedName())
                    );
                    foreach ($function_alternate_generator as $function) {
                        $this->addFunction($function);
                    }
                }
            }
        } finally {
            // Don't need to track these any more *afteR* loading everything.
            // hasFunctionWithFQSEN calls hasInternalFunctionWithFQSEN,
            // which will only load the function if it was in internal_function_fqsen_set
            $this->internal_function_fqsen_set = new Set();
        }
    }

    /**
     * @param string[] $internal_function_name_list
     * @suppress PhanThrowTypeAbsentForCall
     */
    private function addInternalFunctionsByNames(array $internal_function_name_list): void
    {
        $included_extension_subset = self::getIncludedExtensionSubset();
        if (is_array($included_extension_subset)) {
            $forbidden_function_set = [];
            // Forbid functions both from extensions and zend_extensions such as xdebug
            foreach (get_loaded_extensions() as $ext_name) {
                if (isset($included_extension_subset[strtolower($ext_name)])) {
                    continue;
                }
                foreach (get_extension_funcs($ext_name) ?: [] as $function_name) {
                    $forbidden_function_set[strtolower($function_name)] = true;
                }
            }
            foreach ($internal_function_name_list as $i => $function_name) {
                if (isset($forbidden_function_set[$function_name])) {
                    unset($internal_function_name_list[$i]);
                }
            }
        }

        foreach ($internal_function_name_list as $function_name) {
            $this->internal_function_fqsen_set->attach(FullyQualifiedFunctionName::fromFullyQualifiedString($function_name));
        }
    }

    /**
     * Clone dependent objects when cloning this object.
     */
    public function __clone()
    {
        $this->fqsen_class_map =
            $this->fqsen_class_map->deepCopyValues();

        $this->fqsen_class_map_user_defined =
            new Map();

        $this->fqsen_class_map_internal =
            new Map();

        foreach ($this->fqsen_class_map as $fqsen => $clazz) {
            if ($clazz->isPHPInternal()) {
                $this->fqsen_class_map_internal->offsetSet($fqsen, $clazz);
            } else {
                $this->fqsen_class_map_user_defined->offsetSet($fqsen, $clazz);
            }
        }

        $this->fqsen_class_map_reflection =
            clone($this->fqsen_class_map_reflection);

        $this->fqsen_alias_map =
            $this->fqsen_alias_map->deepCopyValues();

        $this->fqsen_global_constant_map =
            $this->fqsen_global_constant_map->deepCopyValues();

        $this->fqsen_func_map =
            $this->fqsen_func_map->deepCopyValues();

        // NOTE: If this were to become a deep copy, this would also have to update class_map.
        // (That also has references to Method, which should be shared in the resulting clone)
        $this->method_set = clone($this->method_set);

        $this->class_fqsen_class_map_map =
            $this->class_fqsen_class_map_map->deepCopyValues();

        $this->internal_function_fqsen_set =
            clone($this->internal_function_fqsen_set);

        $name_method_map = $this->name_method_map;
        $this->name_method_map = [];
        foreach ($name_method_map as $name => $method_map) {
            $this->name_method_map[$name] = $method_map->deepCopy();
        }
    }

    /**
     * @param array{clone:CodeBase,callbacks:(?Closure():void)[]} $restore_point
     */
    public function restoreFromRestorePoint(array $restore_point): void
    {
        $clone = $restore_point['clone'];

        // TODO: Restore the inner state of Clazz objects as well
        // (e.g. memoizations, types added in method/analysis phases, plugin changes, etc.
        // NOTE: Type::clearAllMemoizations is called elsewhere already.
        // @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach this is intentionally iterating over the private properties of $clone
        foreach ($clone as $key => $value) {
            $this->{$key} = $value;
        }

        foreach ($restore_point['callbacks'] as $callback) {
            if ($callback) {
                $callback();
            }
        }
    }

    /**
     * For use by daemon mode when running without pcntl
     * Returns a serialized representation of everything in this CodeBase.
     * @internal
     * @return array{clone:CodeBase,callbacks:(?Closure():void)[]}
     * @suppress PhanAccessMethodInternal
     */
    public function createRestorePoint(): array
    {
        // Create a deep copy of this CodeBase
        $clone = clone($this);
        // make a deep copy of the NamespaceMapEntry objects within parsed_namespace_maps
        // NOTE: It is faster to *create* the clone if this used unserialize(serialize(parsed_namespace_maps).
        // However, it is 10 times slower once you include the time needed to garbage collect the data in the copies, because strings in values are brand new copies in unserialize().
        // It is also likely to require more memory.
        $clone->parsed_namespace_maps = $this->parsed_namespace_maps;
        foreach ($clone->parsed_namespace_maps as &$map_for_file) {
            foreach ($map_for_file as &$map_for_namespace_id) {
                foreach ($map_for_namespace_id as &$map_for_use_type) {
                    foreach ($map_for_use_type as &$entry) {
                        $entry = clone($entry);
                    }
                }
            }
        }

        /** @var list<?Closure()> */
        $callbacks = [];
        // Create callbacks to restore classes
        foreach ($this->fqsen_class_map as $class) {
            $callbacks[] = $class->createRestoreCallback();
        }
        // Create callbacks to restore methods and global functions
        foreach ($this->fqsen_func_map as $func) {
            $callbacks[] = $func->createRestoreCallback();
        }
        // Create callbacks to back up global constants
        // (They may refer to constants from other files.
        // The other files may change.)
        foreach ($this->fqsen_global_constant_map as $const) {
            $callbacks[] = $const->createRestoreCallback();
        }
        // Create callbacks to back up global constants
        // (They may refer to constants from other files.
        // The other files may change.)
        foreach ($this->class_fqsen_class_map_map as $class_map) {
            // Create callbacks to back up class constants and properties.
            // Methods were already backed up.
            foreach ($class_map->getClassConstantMap() as $const) {
                $callbacks[] = $const->createRestoreCallback();
            }
            foreach ($class_map->getPropertyMap() as $property) {
                $callbacks[] = $property->createRestoreCallback();
            }
        }

        return [
            'clone' => $clone,
            'callbacks' => $callbacks,
        ];
    }

    /**
     * @return CodeBase
     * A new code base is returned which is a shallow clone
     * of this one, which is to say that the sets and maps
     * of elements themselves are cloned, but the keys and
     * values within those sets and maps are not cloned.
     *
     * Updates to elements will bleed through code bases
     * with only shallow clones. See
     * https://github.com/phan/phan/issues/257
     */
    public function shallowClone(): CodeBase
    {
        $code_base = new CodeBase([], [], [], [], []);
        $code_base->fqsen_class_map =
            clone($this->fqsen_class_map);
        $code_base->fqsen_class_map_user_defined =
            clone($this->fqsen_class_map_user_defined);
        $code_base->fqsen_class_map_internal =
            clone($this->fqsen_class_map_internal);
        $code_base->fqsen_class_map_reflection =
            clone($this->fqsen_class_map_reflection);
        $code_base->fqsen_alias_map =
            clone($this->fqsen_alias_map);

        $code_base->fqsen_global_constant_map =
            clone($this->fqsen_global_constant_map);
        $code_base->fqsen_func_map =
            clone($this->fqsen_func_map);
        $code_base->internal_function_fqsen_set =
            clone($this->internal_function_fqsen_set);
        $code_base->class_fqsen_class_map_map =
            clone($this->class_fqsen_class_map_map);
        $code_base->method_set =
            clone($this->method_set);
        return $code_base;
    }

    /**
     * @param Clazz $class
     * A class to add.
     */
    public function addClass(Clazz $class): void
    {
        // Map the FQSEN to the class
        $fqsen = $class->getFQSEN();
        $this->fqsen_class_map->offsetSet($fqsen, $class);
        $this->fqsen_class_map_user_defined->offsetSet($fqsen, $class);
        if ($this->undo_tracker) {
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($fqsen): void {
                Daemon::debugf("Undoing addClass %s\n", $fqsen);
                $inner->fqsen_class_map->offsetUnset($fqsen);
                $inner->fqsen_class_map_user_defined->offsetUnset($fqsen);
                // unset($inner->fqsen_class_map_reflection[$fqsen]);  // should not be necessary
                $inner->class_fqsen_class_map_map->offsetUnset($fqsen);
            });
        }
    }

    /**
     * This should be called in the parse phase
     *
     * @param associative-array<int,array<string,NamespaceMapEntry>> $namespace_map
     * @internal
     */
    public function addParsedNamespaceMap(string $file, string $namespace, int $id, array $namespace_map): void
    {
        $key = "$namespace@$id";
        // print("Adding $file $key count=" .count($namespace_map) . "\n");
        // debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $this->parsed_namespace_maps[$file][$key] = $namespace_map;
        if ($this->undo_tracker) {
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($file, $key): void {
                Daemon::debugf("Undoing addParsedNamespaceMap file = %s namespace = %s\n", $file, $key);
                unset($inner->parsed_namespace_maps[$file][$key]);
                // Hack: addParsedNamespaceMap is called at least once per each file, so unset file-level suppressions at the same time in daemon mode
                unset($inner->file_level_suppression_set[$file]);
            });
        }
    }

    /**
     * This should be called in the analysis phase.
     * It retrieves the NamespaceMapEntry built in the parse phase
     * (This is implemented this way to allow Phan to know if 'use Foo\Bar' was ever used and warn if it wasn't.)
     *
     * @param string $file the value of $context->getFile()
     * @param string $namespace the namespace value. Probably redundant.
     * @param int $id (An incrementing counter for namespaces. 0 or 1 in single namespace/absent namespace files)
     * @return associative-array<int,array<string,NamespaceMapEntry>> $namespace_map
     * @internal
     */
    public function getNamespaceMapFromParsePhase(string $file, string $namespace, int $id): array
    {
        $key = "$namespace@$id";

        // I'd hope that this is always defined when this is called.
        // However, it may not be if files rapidly change and add/remove namespaces?
        return $this->parsed_namespace_maps[$file][$key] ?? [];
    }

    /**
     * Add a class from reflection to the codebase,
     * to be analyzed if any part of the analysis uses its fqsen.
     *
     * @param ReflectionClass $class
     * A class to add, lazily.
     */
    public function addReflectionClass(ReflectionClass $class): void
    {
        // Map the FQSEN to the class
        try {
            $class_fqsen = FullyQualifiedClassName::fromFullyQualifiedString($class->getName());
            $this->fqsen_class_map_reflection->offsetSet($class_fqsen, $class);
        } catch (FQSENException $_) {
            // Fixes uncaught Phan\Exception\InvalidFQSENException for #2222
            // Just give up on analyzing uses of the class "OCI-Lob" and anything similar - It's invalid because of the hyphen.
        }
    }

    /**
     * Call this to record the existence of a class_alias in the global scope.
     * After parse phase is complete (And daemonize has split off a new process),
     * call resolveClassAliases() to create FQSEN entries.
     *
     * @param FullyQualifiedClassName $original
     *  an existing class to alias to
     *
     * @param FullyQualifiedClassName $alias
     *  a name to alias $original to
     */
    public function addClassAlias(
        FullyQualifiedClassName $original,
        FullyQualifiedClassName $alias,
        Context $context,
        int $lineno
    ): void {
        if (!$this->fqsen_alias_map->offsetExists($original)) {
            $this->fqsen_alias_map->offsetSet($original, new Set());
        }
        $alias_record = new ClassAliasRecord($alias, $context, $lineno);
        $this->fqsen_alias_map->offsetGet($original)->attach($alias_record);

        if ($this->undo_tracker) {
            // TODO: Track a count of aliases instead? This doesn't work in daemon mode if multiple files add the same alias to the same class.
            // TODO: Allow .phan/config.php to specify aliases or precedences for aliases?
            /** @suppress PhanPluginUnknownObjectMethodCall TODO: Infer types from ArrayAccess->offsetGet in UnionTypeVisitor->visitDim() */
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($original, $alias_record): void {
                $fqsen_alias_map = $inner->fqsen_alias_map[$original] ?? null;
                if ($fqsen_alias_map) {
                    $fqsen_alias_map->detach($alias_record);
                    if ($fqsen_alias_map->count() === 0) {
                        unset($inner->fqsen_alias_map[$original]);
                    }
                }
            });
        }
    }

    /**
     * Resolve the aliases of class FQSENs to other class FQSENs.
     *
     * This is called after all calls to class_alias are parsed and all class definitions are parsed
     */
    public function resolveClassAliases(): void
    {
        if ($this->undo_tracker) {
            throw new AssertionError('should only call this after daemon mode is finished');
        }
        // loop through fqsen_alias_map and add entries to fqsen_class_map.
        foreach ($this->fqsen_alias_map as $original_fqsen => $alias_set) {
            $this->resolveClassAliasesForAliasSet($original_fqsen, $alias_set);
        }
    }

    private function resolveClassAliasesForAliasSet(FullyQualifiedClassName $original_fqsen, Set $alias_set): void
    {
        if (!$this->hasClassWithFQSEN($original_fqsen)) {
            // The original class does not exist.
            // Emit issues at the point of every single class_alias call with that original class.
            foreach ($alias_set as $alias_record) {
                if (!($alias_record instanceof ClassAliasRecord)) {
                    throw new AssertionError("Expected instances of ClassAliasRecord in alias_set");
                }
                $suggestion = IssueFixSuggester::suggestSimilarClass($this, $alias_record->context, $original_fqsen);

                Issue::maybeEmitWithParameters(
                    $this,
                    $alias_record->context,
                    Issue::UndeclaredClassAliasOriginal,
                    $alias_record->lineno,
                    [$original_fqsen, $alias_record->alias_fqsen],
                    $suggestion
                );
            }
            return;
        }
        // The original class exists. Attempt to create aliases of the original class.
        $class = $this->getClassByFQSEN($original_fqsen);
        foreach ($alias_set as $alias_record) {
            if (!($alias_record instanceof ClassAliasRecord)) {
                throw new AssertionError("Expected instances of ClassAliasRecord in alias_set");
            }
            $alias_fqsen = $alias_record->alias_fqsen;
            // Don't do anything if there is a real class, or if an earlier class_alias created an alias.
            if ($this->hasClassWithFQSEN($alias_fqsen)) {
                // Emit a different issue type to make filtering out false positives easier.
                $clazz = $this->getClassByFQSEN($alias_fqsen);
                Issue::maybeEmit(
                    $this,
                    $alias_record->context,
                    Issue::RedefineClassAlias,
                    $alias_record->lineno,
                    $alias_fqsen,
                    $alias_record->context->getFile(),
                    $alias_record->lineno,
                    $clazz->getFQSEN(),
                    $clazz->getFileRef()->getFile(),
                    $clazz->getFileRef()->getLineNumberStart()
                );
            } else {
                $this->fqsen_class_map->offsetSet($alias_fqsen, $class);
            }
        }
    }

    /**
     * @return bool
     * True if a Clazz with the given FQSEN exists
     */
    public function hasClassWithFQSEN(
        FullyQualifiedClassName $fqsen
    ): bool {
        if ($this->fqsen_class_map->offsetExists($fqsen)) {
            return true;
        }
        return $this->lazyLoadPHPInternalClassWithFQSEN($fqsen);
    }

    /**
     * @return bool
     * True if a Clazz with the given FQSEN was created
     */
    private function lazyLoadPHPInternalClassWithFQSEN(
        FullyQualifiedClassName $fqsen
    ): bool {
        if ($this->fqsen_class_map_reflection->offsetExists($fqsen)) {
            $reflection_class = $this->fqsen_class_map_reflection->offsetGet($fqsen);
            $this->loadPHPInternalClassWithFQSEN($fqsen, $reflection_class);
            return true;
        }
        return false;
    }

    private function loadPHPInternalClassWithFQSEN(
        FullyQualifiedClassName $fqsen,
        ReflectionClass $reflection_class
    ): void {
        $class = Clazz::fromReflectionClass($this, $reflection_class);
        $this->fqsen_class_map->offsetSet($fqsen, $class);
        $this->fqsen_class_map_internal->offsetSet($fqsen, $class);
        $this->fqsen_class_map_reflection->offsetUnset($fqsen);
    }

    /**
     * @param FullyQualifiedClassName $fqsen
     * The FQSEN of a class to get
     *
     * @return Clazz
     * A class with the given FQSEN.
     *
     * If the parse phase has been completed, this will hydrate the returned class.
     */
    public function getClassByFQSEN(
        FullyQualifiedClassName $fqsen
    ): Clazz {
        $clazz = $this->fqsen_class_map->offsetGet($fqsen);

        // This is an optimization that saves us a few minutes
        // on very large code bases.
        //
        // Instead of 'hydrating' all classes (expanding their
        // types and importing parent methods, properties, etc.)
        // all in one go, we just do it on the fly as they're
        // requested. When running as multiple processes this
        // lets us avoid a significant amount of hydration per
        // process.
        if ($this->should_hydrate_requested_elements) {
            $clazz->hydrate($this);
        }

        return $clazz;
    }

    /**
     * @param FullyQualifiedClassName $fqsen
     * The FQSEN of a class to get
     *
     * @return Clazz
     * A class with the given FQSEN (without hydrating the class)
     */
    public function getClassByFQSENWithoutHydrating(
        FullyQualifiedClassName $fqsen
    ): Clazz {
        return $this->fqsen_class_map->offsetGet($fqsen);
    }

    /**
     * @param FullyQualifiedClassName $original
     * The FQSEN of class to get aliases of
     *
     * @return ClassAliasRecord[]
     * A list of all aliases of $original (and their definitions)
     */
    public function getClassAliasesByFQSEN(
        FullyQualifiedClassName $original
    ): array {
        if ($this->fqsen_alias_map->offsetExists($original)) {
            return $this->fqsen_alias_map->offsetGet($original)->toArray();
        }

        return [];
    }


    /**
     * @return Map<FullyQualifiedClassName,Clazz>
     * A map from FQSENs to classes which are internal.
     */
    public function getUserDefinedClassMap(): Map
    {
        return $this->fqsen_class_map_user_defined;
    }

    /**
     * @return Map<FullyQualifiedClassName,Clazz>
     * A list of all classes which are internal.
     */
    public function getInternalClassMap(): Map
    {
        if ($this->fqsen_class_map_reflection->count() > 0) {
            $fqsen_class_map_reflection = $this->fqsen_class_map_reflection;
            // Free up memory used by old class map. Prevent it from being freed before we can load it manually.
            $this->fqsen_class_map_reflection = new Map();
            foreach ($fqsen_class_map_reflection as $fqsen => $reflection_class) {
                $this->loadPHPInternalClassWithFQSEN($fqsen, $reflection_class);
            }
        }
        // TODO: Resolve internal classes and optimize the implementation.
        return $this->fqsen_class_map_internal;
    }

    /**
     * @param Method $method
     * A method to add to the code base
     */
    public function addMethod(Method $method): void
    {
        // Add the method to the map
        $this->getClassMapByFQSEN(
            $method->getFQSEN()
        )->addMethod($method);

        $this->method_set->attach($method);

        // If we're doing dead code detection(or something else) and this is a
        // method, map the name to the FQSEN so we can do hail-
        // mary references.
        if (Config::get_track_references()) {
            if (!isset($this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()])) {
                $this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()] = new Set();
            }
            $this->name_method_map[$method->getFQSEN()->getNameWithAlternateId()]->attach($method);
        }
        if ($this->undo_tracker) {
            // The addClass's recordUndo should remove the class map. Only need to remove it from method_set
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($method): void {
                $inner->method_set->detach($method);
            });
        }
    }

    /**
     * @return bool
     * True if an element with the given FQSEN exists
     */
    public function hasMethodWithFQSEN(
        FullyQualifiedMethodName $fqsen
    ): bool {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->hasMethodWithName(
            $fqsen->getNameWithAlternateId()
        );
    }

    /**
     * @param FullyQualifiedMethodName $fqsen
     * The FQSEN of a method to get
     *
     * @return Method
     * A method with the given FQSEN
     */
    public function getMethodByFQSEN(
        FullyQualifiedMethodName $fqsen
    ): Method {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->getMethodByName(
            $fqsen->getNameWithAlternateId()
        );
    }

    /**
     * @return array<string,Method>
     * The set of methods associated with the given class
     */
    public function getMethodMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ): array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getMethodMap();
    }

    /**
     * @return Set<Method>
     * A set of all known methods with the given name
     */
    public function getMethodSetByName(string $name): Set
    {
        if (!Config::get_track_references()) {
            throw new AssertionError(
                __METHOD__ . ' can only be called when dead code '
                . ' detection (or force_tracking_references) is enabled.'
            );
        }

        return $this->name_method_map[$name] ?? new Set();
    }

    /**
     * @return Set<Func|Method>
     * The set of all methods and functions
     *
     * This is slow and should be used only for debugging.
     */
    private function getFunctionAndMethodSet(): Set
    {
        $set = clone($this->method_set);
        foreach ($this->fqsen_func_map as $value) {
            // @phan-suppress-next-line PhanTypeMismatchArgument, PhanPartialTypeMismatchArgument deliberately adding different class instances to an existing set
            $set->attach($value);
        }
        return $set;
    }

    /**
     * @return Set<Method>
     * The set of all methods that Phan is tracking.
     */
    public function getMethodSet(): Set
    {
        return $this->method_set;
    }

    /**
     * @return Map<FullyQualifiedMethodName,ArrayObject<Method>>
     */
    public function getMethodsMapGroupedByDefiningFQSEN(): Map
    {
        $methods_by_defining_fqsen = new Map();
        '@phan-var Map<FullyQualifiedMethodName,ArrayObject<Method>> $methods_by_defining_fqsen';
        foreach ($this->method_set as $method) {
            $defining_fqsen = $method->getDefiningFQSEN();
            $real_defining_fqsen = $method->getRealDefiningFQSEN();
            // Older php versions have issues with ?? on SplObjectStorage
            if ($methods_by_defining_fqsen->offsetExists($defining_fqsen)) {
                $methods_by_defining_fqsen->offsetGet($defining_fqsen)->append($method);
            } else {
                $methods_by_defining_fqsen->offsetSet($defining_fqsen, new ArrayObject([$method]));
            }
            if ($real_defining_fqsen !== $defining_fqsen) {
                if ($methods_by_defining_fqsen->offsetExists($real_defining_fqsen)) {
                    $methods_by_defining_fqsen->offsetGet($real_defining_fqsen)->append($method);
                } else {
                    $methods_by_defining_fqsen->offsetSet($real_defining_fqsen, new ArrayObject([$method]));
                }
            }
        }
        return $methods_by_defining_fqsen;
    }

    /**
     * @return array<string,array<string,string>>
     * A human readable encoding of $this->func_and_method_set [string $function_or_method_name => [int|string $pos => string $spec]]
     * Excludes internal functions and methods.
     *
     * This can be used for debugging Phan's inference
     */
    public function exportFunctionAndMethodSet(): array
    {
        $result = [];
        foreach ($this->getFunctionAndMethodSet() as $function_or_method) {
            if ($function_or_method->isPHPInternal()) {
                continue;
            }
            $fqsen = $function_or_method->getFQSEN();
            $function_or_method_name = (string)$fqsen;
            $signature = [(string)$function_or_method->getUnionType()];
            foreach ($function_or_method->getParameterList() as $param) {
                $name = $param->getName();
                $param_type = (string)$param->getUnionType();
                if ($param->isVariadic()) {
                    $name = '...' . $name;
                }
                if ($param->isPassByReference()) {
                    $name = '&' . $name;
                }
                if ($param->isOptional()) {
                    $name .= '=';
                }
                $signature[$name] = $param_type;
            }
            $result[$function_or_method_name] = $signature;
        }
        \ksort($result);
        return $result;
    }

    /**
     * @param Func $function
     * A function to add to the code base
     */
    public function addFunction(Func $function): void
    {
        // Add it to the map of functions
        $this->fqsen_func_map[$function->getFQSEN()] = $function;

        if ($this->undo_tracker) {
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($function): void {
                Daemon::debugf("Undoing addFunction on %s\n", $function->getFQSEN());
                unset($inner->fqsen_func_map[$function->getFQSEN()]);
            });
        }
    }

    /**
     * @return bool
     * True if a global function with the given FQSEN exists
     */
    public function hasFunctionWithFQSEN(
        FullyQualifiedFunctionName $fqsen
    ): bool {
        $has_function = $this->fqsen_func_map->contains($fqsen);

        if ($has_function) {
            return true;
        }

        // Make the following checks:
        //
        // 1. this is an internal function that hasn't been loaded yet.
        // 2. Unless 'ignore_undeclared_functions_with_known_signatures' is true, require that the current php binary or its extensions define this function before that.
        return $this->hasInternalFunctionWithFQSEN($fqsen);
    }

    /**
     * @param FullyQualifiedFunctionName $fqsen
     * The FQSEN of a function to get
     *
     * @return Func
     * A function with the given FQSEN
     */
    public function getFunctionByFQSEN(
        FullyQualifiedFunctionName $fqsen
    ): Func {
        return $this->fqsen_func_map[$fqsen];
    }

    /**
     * @return Map<FullyQualifiedFunctionName,Func>
     */
    public function getFunctionMap(): Map
    {
        return $this->fqsen_func_map;
    }

    /**
     * @param ClassConstant $class_constant
     * A class constant to add to the code base
     */
    public function addClassConstant(ClassConstant $class_constant): void
    {
        $this->getClassMapByFullyQualifiedClassName(
            $class_constant->getClassFQSEN()
        )->addClassConstant($class_constant);
    }

    /**
     * @return bool
     * True if an class constant with the given FQSEN exists
     */
    public function hasClassConstantWithFQSEN(
        FullyQualifiedClassConstantName $fqsen
    ): bool {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->hasClassConstantWithName($fqsen->getNameWithAlternateId());
    }

    /**
     * @param FullyQualifiedClassConstantName $fqsen
     * The FQSEN of a class constant to get
     *
     * @return ClassConstant
     * A class constant with the given FQSEN
     */
    public function getClassConstantByFQSEN(
        FullyQualifiedClassConstantName $fqsen
    ): ClassConstant {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->getClassConstantByName($fqsen->getNameWithAlternateId());
    }

    /**
     * @return ClassConstant[]
     * The set of class constants associated with the given class
     */
    public function getClassConstantMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ): array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getClassConstantMap();
    }

    /**
     * @param GlobalConstant $global_constant
     * A global constant to add to the code base
     */
    public function addGlobalConstant(GlobalConstant $global_constant): void
    {
        $this->fqsen_global_constant_map[
            $global_constant->getFQSEN()
        ] = $global_constant;
        if ($this->undo_tracker) {
            $this->undo_tracker->recordUndo(static function (CodeBase $inner) use ($global_constant): void {
                Daemon::debugf("Undoing addGlobalConstant on %s\n", $global_constant->getFQSEN());
                unset($inner->fqsen_global_constant_map[$global_constant->getFQSEN()]);
            });
        }
    }

    /**
     * @return bool
     * True if a a global constant with the given FQSEN exists
     */
    public function hasGlobalConstantWithFQSEN(
        FullyQualifiedGlobalConstantName $fqsen
    ): bool {
        return $this->fqsen_global_constant_map->offsetExists($fqsen);
    }

    /**
     * @param FullyQualifiedGlobalConstantName $fqsen
     * The FQSEN of a global constant to get
     *
     * @return GlobalConstant
     * A global constant with the given FQSEN
     */
    public function getGlobalConstantByFQSEN(
        FullyQualifiedGlobalConstantName $fqsen
    ): GlobalConstant {
        return $this->fqsen_global_constant_map[$fqsen];
    }

    /**
     * @return Map<FullyQualifiedGlobalConstantName,GlobalConstant>
     */
    public function getGlobalConstantMap(): Map
    {
        return $this->fqsen_global_constant_map;
    }

    /**
     * @param Property $property
     * A property to add to the code base
     */
    public function addProperty(Property $property): void
    {
        $this->getClassMapByFullyQualifiedClassName(
            $property->getClassFQSEN()
        )->addProperty($property);
    }

    /**
     * @return bool
     * True if a property with the given FQSEN exists
     */
    public function hasPropertyWithFQSEN(
        FullyQualifiedPropertyName $fqsen
    ): bool {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->hasPropertyWithName($fqsen->getNameWithAlternateId());
    }

    /**
     * @param FullyQualifiedPropertyName $fqsen
     * The FQSEN of a property to get
     *
     * @return Property
     * A property with the given FQSEN
     */
    public function getPropertyByFQSEN(
        FullyQualifiedPropertyName $fqsen
    ): Property {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        )->getPropertyByName($fqsen->getNameWithAlternateId());
    }

    /**
     * @return Property[]
     * The set of properties associated with the given class
     */
    public function getPropertyMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ): array {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen
        )->getPropertyMap();
    }

    /**
     * @param FullyQualifiedClassElement $fqsen
     * The FQSEN of a class element
     *
     * @return ClassMap
     * Get the class map for the class of the given class element's fqsen.
     */
    private function getClassMapByFQSEN(
        FullyQualifiedClassElement $fqsen
    ): ClassMap {
        return $this->getClassMapByFullyQualifiedClassName(
            $fqsen->getFullyQualifiedClassName()
        );
    }

    /**
     * @param FullyQualifiedClassName $fqsen
     * The FQSEN of a class
     *
     * @return ClassMap
     * Get the class map for an FQSEN of the class.
     */
    private function getClassMapByFullyQualifiedClassName(
        FullyQualifiedClassName $fqsen
    ): ClassMap {
        $class_fqsen_class_map_map = $this->class_fqsen_class_map_map;
        if ($class_fqsen_class_map_map->offsetExists($fqsen)) {
            return $class_fqsen_class_map_map->offsetGet($fqsen);
        }
        $class_fqsen_class_map_map->offsetSet($fqsen, new ClassMap());
        return $class_fqsen_class_map_map->offsetGet($fqsen);
    }

    /**
     * @return Map<FullyQualifiedClassName,ClassMap>
     */
    public function getClassMapMap(): Map
    {
        return $this->class_fqsen_class_map_map;
    }

    /**
     * @param FullyQualifiedFunctionName $fqsen
     * The FQSEN of a function we'd like to look up
     *
     * @return bool
     * If the FQSEN represents an internal function that
     * hasn't been loaded yet, true is returned.
     */
    private function hasInternalFunctionWithFQSEN(
        FullyQualifiedFunctionName $fqsen
    ): bool {
        $canonical_fqsen = $fqsen->withAlternateId(0);
        $found = isset($this->internal_function_fqsen_set[$canonical_fqsen]);
        if (!$found) {
            // Act as though functions don't exist if they aren't loaded into the php binary
            // running phan (or that binary's extensions), even if the signature map contains them.
            // (All of the functions were loaded during initialization)
            //
            // Also, skip over user-defined global functions defined **by Phan** and its dependencies for analysis
            if (!Config::getValue('ignore_undeclared_functions_with_known_signatures')) {
                return false;
            }
            // If we already created the alternates, do nothing.
            // TODO: This assumes we call hasFunctionWithFQSEN before adding.
            if ($this->fqsen_func_map->offsetExists($canonical_fqsen)) {
                return false;
            }
        }

        $name = $canonical_fqsen->getName();
        if ($canonical_fqsen->getNamespace() !== '\\') {
            $name = \ltrim($canonical_fqsen->getNamespace(), '\\') . '\\' . $name;
        }

        // For elements in the root namespace, check to see if
        // there's a static method signature for something that
        // hasn't been loaded into memory yet and create a
        // method out of it as it's requested

        $function_signature_map =
            UnionType::internalFunctionSignatureMap(Config::get_closest_target_php_version_id());

        // Don't need to track this any more
        unset($this->internal_function_fqsen_set[$canonical_fqsen]);

        if (isset($function_signature_map[$name])) {
            $signature = $function_signature_map[$name];

            // Add each method returned for the signature
            foreach (FunctionFactory::functionListFromSignature(
                $canonical_fqsen,
                $signature
            ) as $function) {
                if ($name === 'each' && Config::get_closest_target_php_version_id() >= 70200) {
                    $function->setIsDeprecated(true);
                }
                if ($found) {
                    $reflection_function = new \ReflectionFunction($name);
                    if ($reflection_function->isDeprecated()) {
                        $function->setIsDeprecated(true);
                    }
                    $real_return_type = FunctionFactory::getRealReturnTypeFromReflection($reflection_function);
                    if (Config::getValue('assume_real_types_for_internal_functions')) {
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        $real_type_string = UnionType::getLatestRealFunctionSignatureMap(Config::get_closest_target_php_version_id())[$name] ?? null;
                        if (\is_string($real_type_string)) {
                            $real_return_type = UnionType::fromStringInContext($real_type_string, new Context(), Type::FROM_TYPE);
                        }
                    }
                    if (!$real_return_type->isEmpty()) {
                        $real_type_set = $real_return_type->getTypeSet();
                        $function->setRealReturnType($real_return_type);
                        $function->setUnionType(UnionType::of($function->getUnionType()->getTypeSet() ?: $real_type_set, $real_type_set));
                    }

                    $real_parameter_list = Parameter::listFromReflectionParameterList($reflection_function->getParameters());
                    $function->setRealParameterList($real_parameter_list);
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    $function->inheritRealParameterDefaults();
                }
                $this->addFunction($function);
                $this->updatePluginsOnLazyLoadInternalFunction($function);
            }

            return true;
        } elseif ($found) {
            // Phan doesn't have extended information for the signature for this function, but the function exists.
            foreach (FunctionFactory::functionListFromReflectionFunction(
                $canonical_fqsen,
                new \ReflectionFunction($name)
            ) as $function) {
                $this->addFunction($function);
                $this->updatePluginsOnLazyLoadInternalFunction($function);
            }

            return true;
        }
        return false;
    }

    /**
     * Returns 0 or more stub functions for a FQSEN that wasn't found in stub files (from .phan/config.php) or Reflection.
     *
     * This is used to warn about invalid argument counts and types when invoking a method,
     * to return the type that would exist if the function existed, etc.
     *
     * NOTE: These placeholders do not get added to the CodeBase instance,
     * and are currently different objects every time they get used.
     *
     * @return list<Func>
     */
    public function getPlaceholdersForUndeclaredFunction(FullyQualifiedFunctionName $fqsen): array
    {
        $canonical_fqsen = $fqsen->withAlternateId(0);
        if ($this->fqsen_func_map->offsetExists($canonical_fqsen)) {
            // Should not be needed
            return [$this->fqsen_func_map->offsetGet($canonical_fqsen)];
        }

        $name = $canonical_fqsen->getName();
        if ($canonical_fqsen->getNamespace() !== '\\') {
            $name = \ltrim($canonical_fqsen->getNamespace(), '\\') . '\\' . $name;
        }

        // For elements in the root namespace, check to see if
        // there's a static method signature for something that
        // hasn't been loaded into memory yet and create a
        // method out of it as it's requested

        $function_signature_map =
            UnionType::internalFunctionSignatureMap(Config::get_closest_target_php_version_id());

        // Don't need to track this any more
        unset($this->internal_function_fqsen_set[$canonical_fqsen]);

        if (!isset($function_signature_map[$name])) {
            return [];
        }
        $signature = $function_signature_map[$name];
        return FunctionFactory::functionListFromSignature(
            $canonical_fqsen,
            $signature
        );
    }

    private function updatePluginsOnLazyLoadInternalFunction(Func $function): void
    {
        ConfigPluginSet::instance()->handleLazyLoadInternalFunction($this, $function);
    }

    /**
     * @return int
     * The total number of elements of all types in the
     * code base.
     */
    public function totalElementCount(): int
    {
        $sum = (
            \count($this->fqsen_func_map)
            + \count($this->fqsen_global_constant_map)
            + \count($this->fqsen_class_map_user_defined)
            + \count($this->fqsen_class_map_internal)  // initialized internal classes
            + \count($this->fqsen_class_map_reflection)  // uninitialized internal classes
        );

        foreach ($this->class_fqsen_class_map_map as $class_map) {
            $sum += (
                \count($class_map->getClassConstantMap())
                + \count($class_map->getPropertyMap())
                + \count($class_map->getMethodMap())
            );
        }

        return $sum;
    }

    /**
     * @param string $file_path @phan-unused-param
     * @suppress PhanPluginUseReturnValueNoopVoid
     */
    public function flushDependenciesForFile(string $file_path): void
    {
        // TODO: ...
    }

    /**
     * @param string $file_path @phan-unused-param
     * @return string[]
     * The list of files that depend on the code in the given
     * file path
     */
    public function dependencyListForFile(string $file_path): array
    {
        // TODO: ...
        return [];
    }

    /**
     * @return non-empty-list<string> every constant name except user-defined constants.
     */
    public static function getPHPInternalConstantNameList(): array
    {
        // Unit tests call this on every test case. Cache the **internal** constants in a static variable for efficiency; those won't change.
        static $constant_name_list = null;
        if ($constant_name_list === null) {
            // 'true', 'false', and 'null' aren't actually defined constants, they're keywords? Add them because anything using AST_CONST would expect them to exist.
            $constant_name_list = \array_keys(\array_merge(['true' => true, 'false' => false, 'null' => null], ...\array_values(
                \array_diff_key(\get_defined_constants(true), ['user' => []])
            )));
        }
        return $constant_name_list;
    }

    /**
     * @param string $file path to a file
     * @param string $issue_type (e.g. 'PhanUnreferencedUseNormal')
     */
    public function addFileLevelSuppression(string $file, string $issue_type): void
    {
        // TODO: Modify the implementation so that it can be checked by UnusedSuppressionPlugin.
        if (!isset($this->file_level_suppression_set[$file][$issue_type])) {
            $this->file_level_suppression_set[$file][$issue_type] = 0;
        }
    }

    /**
     * @param string $file path to a file
     * @param string $issue_type (e.g. 'PhanUnreferencedUseNormal')
     */
    public function hasFileLevelSuppression(string $file, string $issue_type): bool
    {
        // TODO: Modify the implementation so that it can be checked by UnusedSuppressionPlugin.
        if (isset($this->file_level_suppression_set[$file][$issue_type])) {
            ++$this->file_level_suppression_set[$file][$issue_type];
            return true;
        }
        return false;
    }

    /**
     * @var array<string,array<string,string>>|null
     * Maps lowercase class name to (lowercase namespace => namespace)
     */
    private $namespaces_for_class_names = null;

    /**
     * @var array<string,array<string,string>>|null
     * Maps lowercase function name to (lowercase namespace => namespace)
     */
    private $namespaces_for_function_names = null;

    /**
     * @var array<string,array<string,string>>|null
     * Maps lowercase class name to (lowercase class => class)
     */
    private $class_names_in_namespace = null;

    /**
     * @var array<string,array<string,string>>|null
     * Maps lowercase function name to (lowercase function => function)
     */
    private $function_names_in_namespace = null;

    /**
     * @var array<string,array<string,string>>|null
     * Maps lowercase function name to (lowercase constant => constant)
     */
    private $constant_names_in_namespace = null;

    /**
     * @var array<string,StringSuggester>
     * Maps namespace to an object suggesting class names in that namespace
     */
    private $class_names_suggester_in_namespace = [];

    /**
     * @var array<string,StringSuggester>
     * Maps namespace to an object suggesting function names in that namespace
     */
    private $function_names_suggester_in_namespace = [];

    /**
     * @var array<string,StringSuggester>
     * Maps namespace to an object suggesting constant names in that namespace
     */
    private $constant_names_suggester_in_namespace = [];

    private function invalidateDependentCacheEntries(): void
    {
        // TODO: Should refactor suggestions logic into a separate class
        $this->namespaces_for_class_names = null;
        $this->namespaces_for_function_names = null;
        $this->class_names_in_namespace = null;
        $this->function_names_in_namespace = null;
        $this->constant_names_in_namespace = null;
        $this->class_names_suggester_in_namespace = [];
        $this->function_names_suggester_in_namespace = [];
        $this->constant_names_suggester_in_namespace = [];
        $this->constant_lookup_map_for_name = null;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function getNamespacesForClassNames(): array
    {
        return $this->namespaces_for_class_names ?? ($this->namespaces_for_class_names = $this->computeNamespacesForClassNames());
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function getNamespacesForFunctionNames(): array
    {
        return $this->namespaces_for_function_names ?? ($this->namespaces_for_function_names = $this->computeNamespacesForFunctionNames());
    }

    /**
     * @return array<string,array<string,string>> a newly computed list of namespaces which have each class name
     */
    private function computeNamespacesForClassNames(): array
    {
        $class_fqsen_list = [];
        // NOTE: This helper performs shallow clones to avoid interfering with the iteration pointer
        // in other iterations over these class maps
        foreach (clone($this->fqsen_class_map_user_defined) as $class_fqsen => $_) {
            $class_fqsen_list[] = $class_fqsen;
        }
        foreach (clone($this->fqsen_class_map_internal) as $class_fqsen => $_) {
            $class_fqsen_list[] = $class_fqsen;
        }

        $suggestion_set = [];
        foreach ($class_fqsen_list as $class_fqsen) {
            $namespace = $class_fqsen->getNamespace();
            $suggestion_set[strtolower($class_fqsen->getName())][strtolower($namespace)] = $namespace;
        }
        foreach (clone($this->fqsen_class_map_reflection) as $reflection_class) {
            $namespace = '\\' . $reflection_class->getNamespaceName();
            // https://secure.php.net/manual/en/reflectionclass.getnamespacename.php
            $suggestion_set[strtolower($reflection_class->getShortName())][strtolower($namespace)] = $namespace;
        }
        return $suggestion_set;
    }

    /**
     * @return array<string,array<string,string>> a newly computed list of namespaces which have each function name
     */
    private function computeNamespacesForFunctionNames(): array
    {
        $function_fqsen_list = [];
        // NOTE: This helper performs shallow clones to avoid interfering with the iteration pointer
        // in other iterations over these function maps
        foreach (clone($this->fqsen_func_map) as $function_fqsen => $_) {
            $function_fqsen_list[] = $function_fqsen;
        }
        foreach (clone($this->internal_function_fqsen_set) as $function_fqsen) {
            $function_fqsen_list[] = $function_fqsen;
        }

        $suggestion_set = [];
        foreach ($function_fqsen_list as $function_fqsen) {
            $namespace = $function_fqsen->getNamespace();
            $suggestion_set[strtolower($function_fqsen->getName())][strtolower($namespace)] = $namespace;
        }
        return $suggestion_set;
    }

    /**
     * @return list<FullyQualifiedClassName> (Don't rely on unique names)
     */
    private function getClassFQSENList(): array
    {
        $class_fqsen_list = [];
        // NOTE: This helper performs shallow clones to avoid interfering with the iteration pointer
        // in other iterations over these class maps
        foreach (clone($this->fqsen_class_map_user_defined) as $class_fqsen => $_) {
            $class_fqsen_list[] = $class_fqsen;
        }
        foreach (clone($this->fqsen_class_map_internal) as $class_fqsen => $_) {
            $class_fqsen_list[] = $class_fqsen;
        }
        return $class_fqsen_list;
    }

    /**
     * @return array<string,array<string,string>> a list of namespaces which have each class name
     */
    private function getClassNamesInNamespaceMap(): array
    {
        return $this->class_names_in_namespace ?? ($this->class_names_in_namespace = $this->computeClassNamesInNamespace());
    }

    /**
     * @return array<string,array<string,string>> a list of namespaces which have each function name
     */
    private function getFunctionNamesInNamespaceMap(): array
    {
        return $this->function_names_in_namespace ?? ($this->function_names_in_namespace = $this->computeFunctionNamesInNamespace());
    }

    /**
     * @return array<string,array<string,string>> a list of namespaces which have each constant name
     */
    private function getConstantNamesInNamespaceMap(): array
    {
        return $this->constant_names_in_namespace ?? ($this->constant_names_in_namespace = $this->computeConstantNamesInNamespace());
    }

    /**
     * @return array<string,string> a list of class names in $namespace
     */
    public function getClassNamesOfNamespace(string $namespace): array
    {
        $namespace = strtolower($namespace);
        if (\substr($namespace, 0, 1) !== '\\') {
            $namespace = "\\$namespace";
        }
        return $this->getClassNamesInNamespaceMap()[$namespace] ?? [];
    }

    /**
     * @return array<string,string> a list of function names in $namespace
     */
    public function getFunctionNamesOfNamespace(string $namespace): array
    {
        $namespace = strtolower($namespace);
        if (\substr($namespace, 0, 1) !== '\\') {
            $namespace = "\\$namespace";
        }
        return $this->getFunctionNamesInNamespaceMap()[$namespace] ?? [];
    }

    /**
     * @return array<string,string> a list of constant names in $namespace
     */
    public function getConstantNamesOfNamespace(string $namespace): array
    {
        $namespace = strtolower($namespace);
        if (\substr($namespace, 0, 1) !== '\\') {
            $namespace = "\\$namespace";
        }
        return $this->getConstantNamesInNamespaceMap()[$namespace] ?? [];
    }

    /**
     * This limits the suggested class names from getClassNamesOfNamespace for $namespace_lower to
     * the names which are similar enough in length to be a potential suggestion,
     * or those which have the requested name as a prefix
     */
    private function getClassNameSuggesterForNamespace(string $namespace): StringSuggester
    {
        $namespace = strtolower($namespace);
        return $this->class_names_suggester_in_namespace[$namespace]
            ?? ($this->class_names_suggester_in_namespace[$namespace] = new StringSuggester($this->getClassNamesOfNamespace($namespace)));
    }

    /**
     * This limits the suggested function names from getFunctionNamesOfNamespace for $namespace_lower to
     * the names which are similar enough in length to be a potential suggestion,
     * or those which have the requested name as a prefix
     */
    private function getFunctionNameSuggesterForNamespace(string $namespace): StringSuggester
    {
        $namespace = strtolower($namespace);
        return $this->function_names_suggester_in_namespace[$namespace]
            ?? ($this->function_names_suggester_in_namespace[$namespace] = new StringSuggester($this->getFunctionNamesOfNamespace($namespace)));
    }

    /**
     * This limits the suggested constant names from getConstantNamesOfNamespace for $namespace_lower to
     * the names which are similar enough in length to be a potential suggestion,
     * or those which have the requested name as a prefix
     */
    private function getConstantNameSuggesterForNamespace(string $namespace): StringSuggester
    {
        $namespace = strtolower($namespace);
        return $this->constant_names_suggester_in_namespace[$namespace]
            ?? ($this->constant_names_suggester_in_namespace[$namespace] = new StringSuggester($this->getConstantNamesOfNamespace($namespace)));
    }

    /**
     * @return array<string,array<string,string>> maps namespace name to unique classes in that namespace.
     */
    private function computeClassNamesInNamespace(): array
    {
        $class_fqsen_list = $this->getClassFQSENList();

        $suggestion_set = [];
        foreach ($class_fqsen_list as $class_fqsen) {
            $namespace = $class_fqsen->getNamespace();
            $name = $class_fqsen->getName();
            $suggestion_set[strtolower($namespace)][strtolower($name)] = $name;
        }
        foreach (clone($this->fqsen_class_map_reflection) as $reflection_class) {
            $namespace = '\\' . $reflection_class->getNamespaceName();
            $name = '\\' . $reflection_class->getName();
            // https://secure.php.net/manual/en/reflectionclass.getnamespacename.php
            $suggestion_set[strtolower($namespace)][strtolower($name)] = $name;
        }
        return $suggestion_set;
    }

    /**
     * @return array<string,array<string,string>> maps namespace name to unique functions in that namespace.
     */
    private function computeFunctionNamesInNamespace(): array
    {
        $suggestion_set = [];
        foreach (clone($this->fqsen_func_map) as $function_fqsen => $_) {
            $namespace = $function_fqsen->getNamespace();
            $name = $function_fqsen->getName();
            $suggestion_set[strtolower($namespace)][strtolower($name)] = $name;
        }
        foreach (clone($this->internal_function_fqsen_set) as $function_fqsen) {
            $namespace = $function_fqsen->getNamespace();
            $name = $function_fqsen->getName();
            $suggestion_set[strtolower($namespace)][strtolower($name)] = $name;
        }
        return $suggestion_set;
    }

    /**
     * @return array<string,array<string,string>> maps namespace name to unique constants in that namespace.
     */
    private function computeConstantNamesInNamespace(): array
    {
        $suggestion_set = [];
        foreach (clone($this->fqsen_global_constant_map) as $fqsen => $_) {
            $namespace = $fqsen->getNamespace();
            $name = $fqsen->getName();
            $suggestion_set[strtolower($namespace)][$name] = $name;
        }
        foreach (['TRUE', 'FALSE', 'NULL'] as $redundant) {
            unset($suggestion_set['\\'][$redundant]);
        }
        return $suggestion_set;
    }

    /**
     * @unused-param $context
     * @return list<FullyQualifiedClassName> 0 or more namespaced class names found in this code base
     */
    public function suggestSimilarClassInOtherNamespace(
        FullyQualifiedClassName $missing_class,
        Context $context
    ): array {
        $class_name = $missing_class->getName();
        $class_name_lower = strtolower($class_name);
        $namespaces_for_class_names = $this->getNamespacesForClassNames();

        $namespaces_for_class = $namespaces_for_class_names[$class_name_lower] ?? [];
        if (count($namespaces_for_class) === 0) {
            return [];
        }
        // We're looking for similar names, not identical ones
        unset($namespaces_for_class[strtolower($missing_class->getNamespace())]);
        $namespaces_for_class = \array_values($namespaces_for_class);

        \usort($namespaces_for_class, 'strcmp');

        /** @suppress PhanThrowTypeAbsentForCall */
        return \array_map(static function (string $namespace_name) use ($class_name): FullyQualifiedClassName {
            return FullyQualifiedClassName::make($namespace_name, $class_name);
        }, $namespaces_for_class);
    }

    /**
     * @unused-param $context
     * @return list<FullyQualifiedFunctionName> 0 or more namespaced function names found in this code base with the same name but different namespaces
     */
    public function suggestSimilarGlobalFunctionInOtherNamespace(
        string $namespace,
        string $function_name,
        Context $context,
        bool $include_same_namespace = false
    ): array {
        $function_name_lower = strtolower($function_name);
        $namespaces_for_function_names = $this->getNamespacesForFunctionNames();

        $namespaces_for_function = $namespaces_for_function_names[$function_name_lower] ?? [];
        if (count($namespaces_for_function) === 0) {
            return [];
        }
        if (!$include_same_namespace) {
            // We're looking for similar names, not identical ones
            unset($namespaces_for_function[strtolower($namespace)]);
        }
        $namespaces_for_function = \array_values($namespaces_for_function);

        \usort($namespaces_for_function, 'strcmp');

        /** @suppress PhanThrowTypeAbsentForCall */
        return \array_map(static function (string $namespace_name) use ($function_name): FullyQualifiedFunctionName {
            return FullyQualifiedFunctionName::make($namespace_name, $function_name);
        }, $namespaces_for_function);
    }

    private static function phpVersionIdToString(int $php_version_id): string
    {
        return \sprintf('%d.%d', $php_version_id / 10000, ($php_version_id / 100) % 100);
    }

    /**
     * @unused-param $context
     * @return list<string> 0 or more namespaced function names found in this code base for newer php versions
     */
    public function suggestSimilarGlobalFunctionInNewerVersion(
        string $namespace,
        string $function_name,
        Context $context,
        bool $suggest_in_global_namespace
    ): array {
        if (!$suggest_in_global_namespace && $namespace !== '\\') {
            return [];
        }
        $target_php_version_config = Config::get_closest_target_php_version_id();
        $target_php_version = (int)\floor(\min($target_php_version_config, \PHP_VERSION_ID) / 100) * 100;
        $targets = [50600, 70000, 70100, 70200, 70300, 70400, 80000];
        $function_name_lower = strtolower($function_name);
        foreach ($targets as $i => $target) {
            // If $target_php_version is 7.1 only check for functions added in 7.2 or newer that weren't in the previous version.
            // Don't suggest functions added in 7.1
            $next_target = $targets[$i + 1] ?? 0;
            if (!$next_target || $next_target <= $target_php_version) {
                continue;
            }
            $signature_map = UnionType::internalFunctionSignatureMap($next_target);
            if (isset($signature_map[$function_name_lower])) {
                $old_signature_map = UnionType::internalFunctionSignatureMap($target);
                if (!isset($old_signature_map[$function_name_lower])) {
                    $details = \sprintf('target_php_version=%s run with PHP %s', self::phpVersionIdToString($target_php_version_config), self::phpVersionIdToString(\PHP_VERSION_ID));
                    $suggestion = \sprintf(
                        '(to use the function %s() added in PHP %s in a project with %s without a polyfill parsed by Phan)',
                        $function_name,
                        self::phpVersionIdToString($next_target),
                        $details
                    );
                    return [$suggestion];
                }
            }
        }
        return [];
    }

    /**
     * @internal
     */
    protected const _NON_CLASS_TYPE_SUGGESTION_SET = [
        'array'     => 'array',
        'bool'      => 'bool',
        'callable'  => 'callable',
        'false'     => 'false',
        'float'     => 'float',
        'int'       => 'int',
        'iterable'  => 'iterable',
        'mixed'     => 'mixed',
        'null'      => 'null',
        'object'    => 'object',
        'resource'  => 'resource',
        'scalar'    => 'scalar',
        'self'      => 'self',
        'static'    => 'static',
        'string'    => 'string',
        'true'      => 'true',
        // 'void' only makes sense for return type suggestions
    ];

    /**
     * @return list<FullyQualifiedFunctionName|string> 0 or more namespaced function names found in this code base in $namespace
     */
    public function suggestSimilarGlobalFunctionInSameNamespace(
        string $namespace,
        string $name,
        Context $context,
        bool $suggest_in_global_namespace
    ): array {
        $suggestions = $this->suggestSimilarGlobalFunctionForNamespaceAndName($namespace, $name, $context);
        if ($namespace !== "\\" && $suggest_in_global_namespace) {
            $suggestions = \array_merge(
                $suggestions,
                $this->suggestSimilarGlobalFunctionForNamespaceAndName("\\", $name, $context)
            );
        }
        return $suggestions;
    }

    /**
     * @throws FQSENException
     */
    private function getClassIfConstructorAccessible(string $namespace, string $name, Context $context): ?FullyQualifiedClassName
    {
        $fqsen = FullyQualifiedClassName::makeIfLoaded($namespace, $name);
        if (!$fqsen || !$this->hasClassWithFQSEN($fqsen)) {
            return null;
        }
        $class = $this->getClassByFQSEN($fqsen);
        if (!$class->isClass()) {
            return null;
        }
        if (!$class->hasMethodWithName($this, '__construct', true)) {
            // Allow both the constructor and the absence of a constructor
            return $fqsen;
        }
        $class_fqsen_in_current_scope = IssueFixSuggester::maybeGetClassInCurrentScope($context);
        if ($class->getMethodByName($this, '__construct')->isAccessibleFromClass($this, $class_fqsen_in_current_scope)) {
            return $fqsen;
        }
        return null;
    }

    /**
     * @return list<FullyQualifiedClassName> 0 or more namespaced class names found in this code base in $namespace
     */
    public function suggestSimilarNewInAnyNamespace(
        string $namespace,
        string $name,
        Context $context,
        bool $suggest_in_global_namespace
    ): array {
        try {
            $suggestions = [];
            $fqsen = $this->getClassIfConstructorAccessible($namespace, $name, $context);
            if ($fqsen && $this->hasClassWithFQSEN($fqsen)) {
                $suggestions[] = $fqsen;
            }
            if ($namespace !== "\\" && $suggest_in_global_namespace) {
                $fqsen = $this->getClassIfConstructorAccessible('\\', $name, $context);
                if ($fqsen && $this->hasClassWithFQSEN($fqsen)) {
                    $suggestions[] = $fqsen;
                }
            }
        } catch (Exception $_) {
            // ignore
        }
        return $suggestions;
    }

    /**
     * @return list<FullyQualifiedGlobalConstantName> an array of constants similar to the missing constant.
     */
    public function suggestSimilarConstantsToConstant(string $name): array
    {
        $map = $this->getConstantLookupMapForName();
        $results = $map[strtolower($name)] ?? [];
        return \array_values($results);
    }

    /**
     * @var ?array<string,array<string,FullyQualifiedGlobalConstantName>> maps lowercase name to FQSEN to constant
     */
    private $constant_lookup_map_for_name;

    /** @return array<string,array<string,FullyQualifiedGlobalConstantName>> maps constant name to namespace to constant (cached) */
    private function getConstantLookupMapForName(): array
    {
        return $this->constant_lookup_map_for_name ?? ($this->constant_lookup_map_for_name = $this->computeConstantLookupMapForName());
    }

    /** @return array<string,array<string,FullyQualifiedGlobalConstantName>> maps constant name to namespace to constant */
    private function computeConstantLookupMapForName(): array
    {
        $result = [];
        foreach ($this->fqsen_global_constant_map as $fqsen => $_) {
            $result[strtolower($fqsen->getName())][$fqsen->__toString()] = $fqsen;
        }
        return $result;
    }

    /**
     * @unused-param $context
     * @return list<FullyQualifiedFunctionName> 0 or more namespaced function names found in this code base, from various namespaces
     */
    public function suggestSimilarGlobalFunctionForNamespaceAndName(
        string $namespace,
        string $name,
        Context $context
    ): array {
        $suggester = $this->getFunctionNameSuggesterForNamespace($namespace);
        $suggested_function_names = $suggester->getSuggestions($name);

        /**
         * @suppress PhanThrowTypeAbsentForCall
         */
        return \array_values(\array_map(static function (string $function_name) use ($namespace): FullyQualifiedFunctionName {
            return FullyQualifiedFunctionName::make($namespace, $function_name);
        }, $suggested_function_names));
    }

    /**
     * @return list<FullyQualifiedGlobalConstantName> 0 or more namespaced constant names found in this code base, from various namespaces
     */
    public function suggestSimilarGlobalConstantForNamespaceAndName(
        string $namespace,
        string $name
    ): array {
        $suggester = $this->getConstantNameSuggesterForNamespace($namespace);
        $suggested_constant_names = $suggester->getSuggestions($name);

        /**
         * @suppress PhanThrowTypeAbsentForCall
         */
        return \array_values(\array_map(static function (string $constant_name): FullyQualifiedGlobalConstantName {
            return FullyQualifiedGlobalConstantName::fromFullyQualifiedString($constant_name);
        }, $suggested_constant_names));
    }

    /**
     * @param int $class_suggest_type value from IssueFixSuggester::CLASS_SUGGEST_*
     * @unused-param $context
     *
     * @return list<FullyQualifiedClassName|string> 0 or more namespaced class names found in this code base
     *
     * NOTE: Non-classes are always represented as strings (and will be suggested even if there is a namespace),
     * classes are always represented as FullyQualifiedClassName
     */
    public function suggestSimilarClassInSameNamespace(
        FullyQualifiedClassName $missing_class,
        Context $context,
        int $class_suggest_type = IssueFixSuggester::CLASS_SUGGEST_ONLY_CLASSES
    ): array {
        $namespace = $missing_class->getNamespace();
        $class_name = $missing_class->getName();
        $class_name_lower = strtolower($class_name);

        $suggester = $this->getClassNameSuggesterForNamespace($namespace);
        $class_names_in_namespace = $suggester->getSuggestions($class_name);

        if (count($class_names_in_namespace) > Config::getValue('suggestion_check_limit')) {
            return [];
        }

        $suggestion_set = $class_names_in_namespace;
        if ($class_suggest_type !== IssueFixSuggester::CLASS_SUGGEST_ONLY_CLASSES) {
            // TODO: Could limit earlier here and precompute (based on similar string length)
            $suggestion_set += self::_NON_CLASS_TYPE_SUGGESTION_SET;
            if ($class_suggest_type === IssueFixSuggester::CLASS_SUGGEST_CLASSES_AND_TYPES_AND_VOID) {
                $suggestion_set['void'] = 'void';
            }
        }
        unset($suggestion_set[$class_name_lower]);
        if (count($suggestion_set) === 0) {
            return [];
        }

        // We're looking for similar names, not identical names
        $suggested_class_names = \array_keys(
            IssueFixSuggester::getSuggestionsForStringSet($class_name_lower, $suggestion_set)
        );

        if (\count($suggested_class_names) === 0) {
            return [];
        }
        \usort($suggested_class_names, 'strcmp');

        /**
         * @return string|FullyQualifiedClassName
         * @suppress PhanThrowTypeAbsentForCall
         */
        return \array_map(static function (string $class_name_lower) use ($namespace, $class_names_in_namespace) {
            if (!\array_key_exists($class_name_lower, $class_names_in_namespace)) {
                // This is a builtin type
                return $class_name_lower;
            }
            $class_name = $class_names_in_namespace[$class_name_lower];
            return FullyQualifiedClassName::make($namespace, $class_name);
        }, $suggested_class_names);
    }
}
