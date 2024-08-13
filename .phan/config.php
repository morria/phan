<?php

declare(strict_types=1);

use Phan\Issue;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see https://github.com/phan/phan/wiki/Phan-Config-Settings for all configurable options
 * @see src/Phan/Config.php for the configurable options in this version of Phan
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */

return [
    // The PHP version that the codebase will be checked for compatibility against.
    // For best results, the PHP binary used to run Phan should have the same PHP version.
    // (Phan relies on Reflection for some types, param counts,
    // and checks for undefined classes/methods/functions)
    //
    // Supported values: `'5.6'`, `'7.0'`, `'7.1'`, `'7.2'`, `'7.3'`, `'7.4'`,
    // `'8.0'`, `'8.1'`, `null`.
    // If this is set to `null`,
    // then Phan assumes the PHP version which is closest to the minor version
    // of the php executable used to execute Phan.
    //
    // Note that the **only** effect of choosing `'5.6'` is to infer that functions removed in php 7.0 exist.
    // (See `backward_compatibility_checks` for additional options)
    'target_php_version' => null,

    // The PHP version that will be used for feature/syntax compatibility warnings.
    // Supported values: `'5.6'`, `'7.0'`, `'7.1'`, `'7.2'`, `'7.3'`, `'7.4'`,
    // `'8.0'`, `'8.1'`, `null`.
    // If this is set to `null`, Phan will first attempt to infer the value from
    // the project's composer.json's `{"require": {"php": "version range"}}` if possible.
    // If that could not be determined, then Phan assumes `target_php_version`.
    //
    // For analyzing Phan 3.x, this is determined to be `'7.2'` from `"version": "^7.2.0"`.
    'minimum_target_php_version' => '7.2',

    // Default: true. If this is set to true,
    // and target_php_version is newer than the version used to run Phan,
    // Phan will act as though functions added in newer PHP versions exist.
    //
    // NOTE: Currently, this only affects Closure::fromCallable
    'pretend_newer_core_functions_exist' => true,

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    'allow_missing_properties' => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    'null_casts_as_any_type' => false,

    // Allow null to be cast as any array-like type
    // This is an incremental step in migrating away from null_casts_as_any_type.
    // If null_casts_as_any_type is true, this has no effect.
    'null_casts_as_array' => false,

    // Allow any array-like type to be cast to null.
    // This is an incremental step in migrating away from null_casts_as_any_type.
    // If null_casts_as_any_type is true, this has no effect.
    'array_casts_as_null' => false,

    // If enabled, Phan will warn if **any** type in a method invocation's object
    // is definitely not an object,
    // or if **any** type in an invoked expression is not a callable.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    'strict_method_checking' => true,

    // If enabled, Phan will warn if **any** type in the argument's union type
    // cannot be cast to a type in the parameter's expected union type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    'strict_param_checking' => true,

    // If enabled, Phan will warn if **any** type in a property assignment's union type
    // cannot be cast to a type in the property's declared union type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_property_checking' => true,

    // If enabled, Phan will warn if **any** type in a returned value's union type
    // cannot be cast to the declared return type.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_return_checking' => true,

    // If enabled, Phan will warn if **any** type of the object expression for a property access
    // does not contain that property.
    'strict_object_checking' => true,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    // This does not affect checks of array keys. See `scalar_array_key_cast`.
    'scalar_implicit_cast' => false,

    // If enabled, any scalar array keys (int, string)
    // are treated as if they can cast to each other.
    // E.g. `array<int,stdClass>` can cast to `array<string,stdClass>` and vice versa.
    // Normally, a scalar type such as int could only cast to/from int and mixed.
    'scalar_array_key_cast' => false,

    // If this has entries, scalars (int, float, bool, string, null)
    // are allowed to perform the casts listed.
    //
    // E.g. `['int' => ['float', 'string'], 'float' => ['int'], 'string' => ['int'], 'null' => ['string']]`
    // allows casting null to a string, but not vice versa.
    // (subset of `scalar_implicit_cast`)
    'scalar_implicit_partial' => [],

    // If true, Phan will convert the type of a possibly undefined array offset to the nullable, defined equivalent.
    // If false, Phan will convert the type of a possibly undefined array offset to the defined equivalent (without converting to nullable).
    'convert_possibly_undefined_offset_to_nullable' => false,

    // If true, seemingly undeclared variables in the global
    // scope will be ignored.
    //
    // This is useful for projects with complicated cross-file
    // globals that you have no hope of fixing.
    'ignore_undeclared_variables_in_global_scope' => false,

    // Backwards Compatibility Checking (This is very slow)
    'backward_compatibility_checks' => false,

    // If true, check to make sure the return type declared
    // in the doc-block (if any) matches the return type
    // declared in the method signature.
    'check_docblock_signature_return_type_match' => true,

    // If true, check to make sure the param types declared
    // in the doc-block (if any) matches the param types
    // declared in the method signature.
    'check_docblock_signature_param_type_match' => true,

    // If true, make narrowed types from phpdoc params override
    // the real types from the signature, when real types exist.
    // (E.g. allows specifying desired lists of subclasses,
    //  or to indicate a preference for non-nullable types over nullable types)
    //
    // Affects analysis of the body of the method and the param types passed in by callers.
    //
    // (*Requires `check_docblock_signature_param_type_match` to be true*)
    'prefer_narrowed_phpdoc_param_type' => true,

    // (*Requires `check_docblock_signature_return_type_match` to be true*)
    //
    // If true, make narrowed types from phpdoc returns override
    // the real types from the signature, when real types exist.
    //
    // (E.g. allows specifying desired lists of subclasses,
    //  or to indicate a preference for non-nullable types over nullable types)
    // Affects analysis of return statements in the body of the method and the return types passed in by callers.
    'prefer_narrowed_phpdoc_return_type' => true,

    // If enabled, check all methods that override a
    // parent method to make sure its signature is
    // compatible with the parent's. This check
    // can add quite a bit of time to the analysis.
    // This will also check if final methods are overridden, etc.
    'analyze_signature_compatibility' => true,

    // Set this to true to make Phan guess that undocumented parameter types
    // (for optional parameters) have the same type as default values
    // (Instead of combining that type with `mixed`).
    // E.g. `function($x = 'val')` would make Phan infer that $x had a type of `string`, not `string|mixed`.
    // Phan will not assume it knows specific types if the default value is false or null.
    'guess_unknown_parameter_type_using_default' => false,

    // Allow adding types to vague return types such as @return object, @return ?mixed in function/method/closure union types.
    // Normally, Phan only adds inferred returned types when there is no `@return` type or real return type signature.
    // This setting can be disabled on individual methods by adding `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice`.
    'allow_overriding_vague_return_types' => true,

    // Add types to all return types. Normally, Phan only adds inferred returned types when there is no `@return` type
    // or real return type signature. This setting can be disabled on individual methods by adding
    // `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice` and in conjunction with `PhoundPlugin` to
    // detect more callsite possibilities. See the [PR description](https://github.com/phan/phan/pull/4874) where
    // this setting was added for more details.
    'override_return_types' => false,

    // When enabled, infer that the types of the properties of `$this` are equal to their default values at the start of `__construct()`.
    // This will have some false positives due to Phan not checking for setters and initializing helpers.
    // This does not affect inherited properties.
    'infer_default_properties_in_construct' => true,

    // Set this to true to enable the plugins that Phan uses to infer more accurate return types of `implode`, `json_decode`, and many other functions.
    //
    // Phan is slightly faster when these are disabled.
    'enable_extended_internal_return_type_plugins' => true,

    // This setting maps case-insensitive strings to union types.
    //
    // This is useful if a project uses phpdoc that differs from the phpdoc2 standard.
    //
    // If the corresponding value is the empty string,
    // then Phan will ignore that union type (E.g. can ignore 'the' in `@return the value`)
    //
    // If the corresponding value is not empty,
    // then Phan will act as though it saw the corresponding UnionTypes(s)
    // when the keys show up in a UnionType of `@param`, `@return`, `@var`, `@property`, etc.
    //
    // This matches the **entire string**, not parts of the string.
    // (E.g. `@return the|null` will still look for a class with the name `the`, but `@return the` will be ignored with the below setting)
    //
    // (These are not aliases, this setting is ignored outside of doc comments).
    // (Phan does not check if classes with these names exist)
    //
    // Example setting: `['unknown' => '', 'number' => 'int|float', 'char' => 'string', 'long' => 'int', 'the' => '']`
    'phpdoc_type_mapping' => [ ],

    // Set to true in order to attempt to detect dead
    // (unreferenced) code. Keep in mind that the
    // results will only be a guess given that classes,
    // properties, constants and methods can be referenced
    // as variables (like `$class->$property` or
    // `$class->$method()`) in ways that we're unable
    // to make sense of.
    //
    // To more aggressively detect dead code,
    // you may want to set `dead_code_detection_prefer_false_negative` to `false`.
    'dead_code_detection' => false,

    // Set to true in order to attempt to detect unused variables.
    // `dead_code_detection` will also enable unused variable detection.
    //
    // This has a few known false positives, e.g. for loops or branches.
    'unused_variable_detection' => true,

    // Set to true in order to force tracking references to elements
    // (functions/methods/consts/protected).
    // dead_code_detection is another option which also causes references
    // to be tracked.
    'force_tracking_references' => false,

    // Set to true in order to attempt to detect redundant and impossible conditions.
    //
    // This has some false positives involving loops,
    // variables set in branches of loops, and global variables.
    'redundant_condition_detection' => true,

    // Set to true in order to attempt to detect error-prone truthiness/falsiness checks.
    //
    // This is not suitable for all codebases.
    'error_prone_truthy_condition_detection' => true,

    // Enable this to warn about harmless redundant use for classes and namespaces such as `use Foo\bar` in namespace Foo.
    //
    // Note: This does not affect warnings about redundant uses in the global namespace.
    'warn_about_redundant_use_namespaced_class' => true,

    // If true, then run a quick version of checks that takes less time.
    // False by default.
    'quick_mode' => false,

    // If true, then before analysis, try to simplify AST into a form
    // which improves Phan's type inference in edge cases.
    //
    // This may conflict with 'dead_code_detection'.
    // When this is true, this slows down analysis slightly.
    //
    // E.g. rewrites `if ($a = value() && $a > 0) {...}`
    // into $a = value(); if ($a) { if ($a > 0) {...}}`
    'simplify_ast' => true,

    // If true, Phan will read `class_alias` calls in the global scope,
    // then (1) create aliases from the *parsed* files if no class definition was found,
    // and (2) emit issues in the global scope if the source or target class is invalid.
    // (If there are multiple possible valid original classes for an aliased class name,
    //  the one which will be created is unspecified.)
    // NOTE: THIS IS EXPERIMENTAL, and the implementation may change.
    'enable_class_alias_support' => false,

    // Enable or disable support for generic templated
    // class types.
    'generic_types_enabled' => true,

    // If enabled, warn about throw statement where the exception types
    // are not documented in the PHPDoc of functions, methods, and closures.
    'warn_about_undocumented_throw_statements' => true,

    // If enabled (and warn_about_undocumented_throw_statements is enabled),
    // warn about function/closure/method calls that have (at)throws
    // without the invoking method documenting that exception.
    'warn_about_undocumented_exceptions_thrown_by_invoked_functions' => true,

    // If this is a list, Phan will not warn about lack of documentation of (at)throws
    // for any of the listed classes or their subclasses.
    // This setting only matters when warn_about_undocumented_throw_statements is true.
    // The default is the empty array (Warn about every kind of Throwable)
    'exception_classes_with_optional_throws_phpdoc' => [
        'LogicException',
        'RuntimeException',
        'InvalidArgumentException',
        'AssertionError',
        'TypeError',
        'Phan\Exception\IssueException',  // TODO: Make Phan aware that some arguments suppress certain issues
        'Phan\AST\TolerantASTConverter\InvalidNodeException',  // This is used internally in TolerantASTConverter

        // TODO: Undo the suppressions for the below categories of issues:
        'Phan\Exception\CodeBaseException',
        // phpunit
        'PHPUnit\Framework\ExpectationFailedException',
        'SebastianBergmann\RecursionContext\InvalidArgumentException',
    ],

    // Increase this to properly analyze require_once statements
    'max_literal_string_type_length' => 1000,

    // Setting this to true makes the process assignment for file analysis
    // as predictable as possible, using consistent hashing.
    // Even if files are added or removed, or process counts change,
    // relatively few files will move to a different group.
    // (use when the number of files is much larger than the process count)
    // NOTE: If you rely on Phan parsing files/directories in the order
    // that they were provided in this config, don't use this)
    // See https://github.com/phan/phan/wiki/Different-Issue-Sets-On-Different-Numbers-of-CPUs
    'consistent_hashing_file_order' => false,

    // If enabled, Phan will act as though it's certain of real return types of a subset of internal functions,
    // even if those return types aren't available in reflection (real types were taken from php 7.3 or 8.0-dev, depending on target_php_version).
    //
    // Note that with php 7 and earlier, php would return null or false for many internal functions if the argument types or counts were incorrect.
    // As a result, enabling this setting with target_php_version 8.0 may result in false positives for `--redundant-condition-detection` when codebases also support php 7.x.
    'assume_real_types_for_internal_functions' => true,

    // Override to hardcode existence and types of (non-builtin) globals.
    // Class names should be prefixed with '\\'.
    // (E.g. ['_FOO' => '\\FooClass', 'page' => '\\PageClass', 'userId' => 'int'])
    'globals_type_map' => [],

    // The minimum severity level to report on. This can be
    // set to Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL or
    // Issue::SEVERITY_CRITICAL.
    'minimum_severity' => Issue::SEVERITY_LOW,

    // Add any issue types (such as `'PhanUndeclaredMethod'`)
    // to this list to inhibit them from being reported.
    'suppress_issue_types' => [
        'PhanUnreferencedClosure',  // False positives seen with closures in arrays, TODO: move closure checks closer to what is done by unused variable plugin
        'PhanPluginNoCommentOnProtectedMethod',
        'PhanPluginDescriptionlessCommentOnProtectedMethod',
        'PhanPluginNoCommentOnPrivateMethod',
        'PhanPluginDescriptionlessCommentOnPrivateMethod',
        'PhanPluginDescriptionlessCommentOnPrivateProperty',
        // TODO: Fix edge cases in --automatic-fix for PhanPluginRedundantClosureComment
        'PhanPluginRedundantClosureComment',
        'PhanPluginPossiblyStaticPublicMethod',
        'PhanPluginPossiblyStaticProtectedMethod',
        // The types of ast\Node->children are all possibly unset.
        'PhanTypePossiblyInvalidDimOffset',
        // TODO: Fix PhanParamNameIndicatingUnusedInClosure instances (low priority)
        'PhanParamNameIndicatingUnusedInClosure',
    ],

    // If this list is empty, no filter against issues types will be applied.
    // If this list is non-empty, only issues within the list
    // will be emitted by Phan.
    //
    // See https://github.com/phan/phan/wiki/Issue-Types-Caught-by-Phan
    // for the full list of issues that Phan detects.
    //
    // Phan is capable of detecting hundreds of types of issues.
    // Projects should almost always use `suppress_issue_types` instead.
    'whitelist_issue_types' => [
        // 'PhanUndeclaredClass',
    ],

    // A list of files to include in analysis
    'file_list' => [
        'phan',
        'phan_client',
        'plugins/codeclimate/engine',
        'tool/analyze_phpt',
        'tool/make_stubs',
        'tool/pdep',
        'tool/phantasm',
        'tool/phoogle',
        'tool/phan_repl_helpers.php',
        'internal/dump_fallback_ast.php',
        'internal/dump_html_styles.php',
        'internal/emit_signature_map_for_php_version.php',
        'internal/extract_arg_info.php',
        'internal/flatten_signature_map.php',
        'internal/internalsignatures.php',
        'internal/line_deleter.php',
        'internal/package.php',
        'internal/reflection_completeness_check.php',
        'internal/sanitycheck.php',
        'internal/sort_signature_map.php',
        'vendor/phpdocumentor/type-resolver/src/Types/ContextFactory.php',
        'vendor/phpdocumentor/reflection-docblock/src/DocBlockFactory.php',
        'vendor/phpdocumentor/reflection-docblock/src/DocBlock.php',
        // 'vendor/phpunit/phpunit/src/Framework/TestCase.php',
    ],

    // A regular expression to match files to be excluded
    // from parsing and analysis and will not be read at all.
    //
    // This is useful for excluding groups of test or example
    // directories/files, unanalyzable files, or files that
    // can't be removed for whatever reason.
    // (e.g. '@Test\.php$@', or '@vendor/.*/(tests|Tests)/@')
    'exclude_file_regex' => '@^vendor/.*/(tests?|Tests?)/@',

    // Enable this to enable checks of require/include statements referring to valid paths.
    'enable_include_path_checks' => true,

    // A list of include paths to check when checking if `require_once`, `include`, etc. are valid.
    //
    // To refer to the directory of the file being analyzed, use `'.'`
    // To refer to the project root directory, you must use \Phan\Config::getProjectRootDirectory()
    //
    // (E.g. `['.', \Phan\Config::getProjectRootDirectory() . '/src/folder-added-to-include_path']`)
    'include_paths' => ['.'],

    // Enable this to warn about the use of relative paths in `require_once`, `include`, etc.
    // Relative paths are harder to reason about, and opcache may have issues with relative paths in edge cases.
    'warn_about_relative_include_statement' => true,

    // A list of files that will be excluded from parsing and analysis
    // and will not be read at all.
    //
    // This is useful for excluding hopelessly unanalyzable
    // files that can't be removed for whatever reason.
    'exclude_file_list' => [
        'internal/Sniffs/ValidUnderscoreVariableNameSniff.php',
    ],

    // The number of processes to fork off during the analysis
    // phase.
    'processes' => 1,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    'directory_list' => [
        'internal/lib',
        'src',
        'tests/Phan',
        'vendor/composer/semver/src',
        'vendor/composer/xdebug-handler/src',
        'vendor/felixfbecker/advanced-json-rpc/lib',
        'vendor/microsoft/tolerant-php-parser/src',
        'vendor/netresearch/jsonmapper/src',
        'vendor/phpunit/phpunit/src',
        'vendor/psr/log',  // subdirectory depends on dependency version
        'vendor/sabre/event/lib',
        'vendor/symfony/console',
        'vendor/symfony/polyfill-php80',
        'vendor/tysonandre/var_representation_polyfill/src',
        '.phan/plugins',
        '.phan/stubs',
    ],

    // List of case-insensitive file extensions supported by Phan.
    // (e.g. php, html, htm)
    'analyzed_file_extensions' => ['php'],

    // A directory list that defines files that will be excluded
    // from static analysis, but whose class and method
    // information should be included.
    //
    // Generally, you'll want to include the directories for
    // third-party code (such as 'vendor/') in this list.
    //
    // n.b.: If you'd like to parse but not analyze 3rd
    //       party code, directories containing that code
    //       should be added to the `directory_list` as
    //       to `exclude_analysis_directory_list`.
    'exclude_analysis_directory_list' => [
        'vendor/'
    ],

    // By default, Phan will log error messages to stdout if PHP is using options that slow the analysis.
    // (e.g. PHP is compiled with `--enable-debug` or when using Xdebug)
    'skip_slow_php_options_warning' => false,

    // You can put paths to internal stubs in this config option.
    // Phan will continue using its detailed type annotations, but load the constants, classes, functions, and classes (and their Reflection types) from these stub files (doubling as valid php files).
    // Use a different extension from php to avoid accidentally loading these.
    // The 'tool/mkstubs' script can be used to generate your own stubs (compatible with php 7.2+ right now)
    //
    // Also see `include_extension_subset` to configure Phan to analyze a codebase as if a certain extension is not available.
    'autoload_internal_extension_signatures' => [
        'ast'         => '.phan/internal_stubs/ast.phan_php',
        'ctype'       => '.phan/internal_stubs/ctype.phan_php',
        'igbinary'    => '.phan/internal_stubs/igbinary.phan_php',
        'mbstring'    => '.phan/internal_stubs/mbstring.phan_php',
        'pcntl'       => '.phan/internal_stubs/pcntl.phan_php',
        'phar'        => '.phan/internal_stubs/phar.phan_php',
        'posix'       => '.phan/internal_stubs/posix.phan_php',
        'readline'    => '.phan/internal_stubs/readline.phan_php',
        'simplexml'   => '.phan/internal_stubs/simplexml.phan_php',
        'sqlite3'     => '.phan/internal_stubs/sqlite3.phan_php',
        'sysvmsg'     => '.phan/internal_stubs/sysvmsg.phan_php',
        'sysvsem'     => '.phan/internal_stubs/sysvsem.phan_php',
        'sysvshm'     => '.phan/internal_stubs/sysvshm.phan_php',
    ],

    // This can be set to a list of extensions to limit Phan to using the reflection information of.
    // If this is a list, then Phan will not use the reflection information of extensions outside of this list.
    // The extensions loaded for a given php installation can be seen with `php -m` or `get_loaded_extensions(true)`.
    //
    // Note that this will only prevent Phan from loading reflection information for extensions outside of this set.
    // If you want to add stubs, see `autoload_internal_extension_signatures`.
    //
    // If this is used, 'core', 'date', 'pcre', 'reflection', 'spl', and 'standard' will be automatically added.
    //
    // When this is an array, `ignore_undeclared_functions_with_known_signatures` will always be set to false.
    // (because many of those functions will be outside of the configured list)
    //
    // Also see `ignore_undeclared_functions_with_known_signatures` to warn about using unknown functions.
    // E.g. this is what Phan would use for self-analysis
    /*
    'included_extension_subset' => [
        'core',
        'standard',
        'filter',
        'json',
        'tokenizer',  // parsing php code
        'ast',  // parsing php code

        'ctype',  // misc uses, also polyfilled
        'dom',  // checkstyle output format
        'iconv',  // symfony mbstring polyfill
        'igbinary',  // serializing/unserializing polyfilled ASTs
        'libxml',  // internal tools for extracting stubs
        'mbstring',  // utf-8 support
        'pcntl',  // daemon/language server and parallel analysis
        'phar',  // packaging
        'posix',  // parallel analysis
        'readline',  // internal debugging utility, rarely used
        'simplexml',  // report generation
        'sysvmsg',  // parallelism
        'sysvsem',
        'sysvshm',
    ],
     */

    // Set this to false to emit `PhanUndeclaredFunction` issues for internal functions that Phan has signatures for,
    // but aren't available in the codebase, or from Reflection.
    // (may lead to false positives if an extension isn't loaded)
    //
    // If this is true(default), then Phan will not warn.
    //
    // Even when this is false, Phan will still infer return values and check parameters of internal functions
    // if Phan has the signatures.
    'ignore_undeclared_functions_with_known_signatures' => false,

    'plugin_config' => [
        // A list of 1 or more PHP binaries (Absolute path or program name found in $PATH)
        // to use to analyze your files with PHP's native `--syntax-check`.
        //
        // This can be used to simultaneously run PHP's syntax checks with multiple PHP versions.
        // e.g. `'plugin_config' => ['php_native_syntax_check_binaries' => ['php72', 'php70', 'php56']]`
        // if all of those programs can be found in $PATH

        // 'php_native_syntax_check_binaries' => [PHP_BINARY],

        // The maximum number of `php --syntax-check` processes to run at any point in time (Minimum: 1).
        // This may be temporarily higher if php_native_syntax_check_binaries has more elements than this process count.
        'php_native_syntax_check_max_processes' => 4,

        // List of methods to suppress warnings about for HasPHPDocPlugin
        'has_phpdoc_method_ignore_regex' => '@^Phan\\\\Tests\\\\.*::(test.*|.*Provider)$@',
        // Warn about duplicate descriptions for methods and property groups within classes.
        // (This skips over deprecated methods)
        // This may not apply to all code bases,
        // but is useful in avoiding copied and pasted descriptions that may be inapplicable or too vague.
        'has_phpdoc_check_duplicates' => true,

        // If true, then never allow empty statement lists, even if there is a TODO/FIXME/"deliberately empty" comment.
        'empty_statement_list_ignore_todos' => true,

        // Automatically infer which methods are pure (i.e. should have no side effects) in UseReturnValuePlugin.
        'infer_pure_methods' => true,

        // Warn if newline is allowed before end of string for `$` (the default unless the `D` modifier (`PCRE_DOLLAR_ENDONLY`) is passed in).
        // This is specific to coding styles.
        'regex_warn_if_newline_allowed_at_end' => true,
    ],

    // A list of plugin files to execute
    // NOTE: values can be the base name without the extension for plugins bundled with Phan (E.g. 'AlwaysReturnPlugin')
    // or relative/absolute paths to the plugin (Relative to the project root).
    'plugins' => [
        'AlwaysReturnPlugin',  // i.e. '.phan/plugin/AlwaysReturnPlugin.php' in phan itself
        'DollarDollarPlugin',
        'UnreachableCodePlugin',
        'DuplicateArrayKeyPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'PHPUnitAssertionPlugin',  // analyze assertSame/assertInstanceof/assertTrue/assertFalse
        'UseReturnValuePlugin',

        // UnknownElementTypePlugin warns about unknown types in element signatures.
        'UnknownElementTypePlugin',
        'DuplicateExpressionPlugin',
        // warns about carriage returns("\r"), trailing whitespace, and tabs in PHP files.
        'WhitespacePlugin',
        // Warn about inline HTML anywhere in the files.
        'InlineHTMLPlugin',
        ////////////////////////////////////////////////////////////////////////
        // Plugins for Phan's self-analysis
        ////////////////////////////////////////////////////////////////////////

        // Warns about the usage of assert() for Phan's self-analysis. See https://github.com/phan/phan/issues/288
        'NoAssertPlugin',
        'PossiblyStaticMethodPlugin',

        'HasPHPDocPlugin',
        'PHPDocToRealTypesPlugin',  // suggests replacing (at)return void with `: void` in the declaration, etc.
        'PHPDocRedundantPlugin',
        'PreferNamespaceUsePlugin',
        'EmptyStatementListPlugin',

        // Report empty (not overridden or overriding) methods and functions
        // 'EmptyMethodAndFunctionPlugin',

        // This should only be enabled if the code being analyzed contains Phan plugins.
        'PhanSelfCheckPlugin',
        // Warn about using the same loop variable name as a loop variable of an outer loop.
        'LoopVariableReusePlugin',
        // Warn about assigning the value the variable already had to that variable.
        'RedundantAssignmentPlugin',
        // These are specific to Phan's coding style
        'StrictComparisonPlugin',
        // Warn about `$var == SOME_INT_OR_STRING_CONST` due to unintuitive behavior such as `0 == 'a'`
        'StrictLiteralComparisonPlugin',
        'ShortArrayPlugin',
        'SimplifyExpressionPlugin',
        // 'UnknownClassElementAccessPlugin' is more useful with batch analysis than in an editor.
        // It's used in tests/run_test __FakeSelfFallbackTest

        // This checks that there are no accidental echos/printfs left inside Phan's code.
        'RemoveDebugStatementPlugin',
        'UnsafeCodePlugin',
        'DeprecateAliasPlugin',
        // Suggest '@return never'
        '.phan/plugins/AddNeverReturnTypePlugin.php',
        // Still have false positives to suppress
        // '.phan/plugins/StaticVariableMisusePlugin.php',

        ////////////////////////////////////////////////////////////////////////
        // End plugins for Phan's self-analysis
        ////////////////////////////////////////////////////////////////////////

        // 'SleepCheckerPlugin' is useful for projects which heavily use the __sleep() method. Phan doesn't use __sleep().
        // InvokePHPNativeSyntaxCheckPlugin invokes 'php --no-php-ini --syntax-check ${abs_path_to_analyzed_file}.php' and reports any error messages.
        // Using this can cause phan's overall analysis time to more than double.
        // 'InvokePHPNativeSyntaxCheckPlugin',

        // 'PHPUnitNotDeadCodePlugin',  // Marks PHPUnit test case subclasses and test cases as referenced code. This is only useful for runs when dead code detection is enabled.

        // 'PHPDocInWrongCommentPlugin',  // Useful to warn about using "/*" instead of ""/**" where phpdoc annotations are used. This is slow due to needing to tokenize files.

        // NOTE: This plugin only produces correct results when
        //       Phan is run on a single core (-j1).
        // 'UnusedSuppressionPlugin',
    ],
];
