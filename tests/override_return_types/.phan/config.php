<?php

use \Phan\Issue;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * This is a config file which tests Phan's ability to infer and report missing types.
 */
return [
    'target_php_version' => '7.3',

    // If enabled, Phan will act as though it's certain of real return types of a subset of internal functions,
    // even if those return types aren't available in reflection (real types were taken from php 8.0-dev).
    //
    // Note that in php 7 and earlier, php would return null or false if the argument types or counts were incorrect.
    // As a result, enabling this setting may result in false positives for `--redundant-condition-detection`.
    'assume_real_types_for_internal_functions' => true,

    // Set to true in order to attempt to detect redundant and impossible conditions.
    //
    // This has some false positives involving loops,
    // variables set in branches of loops, and global variables.
    'redundant_condition_detection' => true,

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    'allow_missing_properties' => true,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    'null_casts_as_any_type' => false,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    'scalar_implicit_cast' => false,

    // If enabled, Phan will warn if **any** type in the method's object
    // is definitely not an object.
    // Setting this to true will introduce numerous false positives
    // (and reveal some bugs).
    'strict_method_checking' => true,

    // If enabled, Phan will warn if **any** type in the argument's type
    // cannot be cast to a type in the parameter's expected type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_param_checking' => true,

    // If enabled, Phan will warn if **any** type in a property assignment's type
    // cannot be cast to a type in the property's expected type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_property_checking' => true,

    // If enabled, Phan will warn if **any** type in the return statement's type
    // cannot be cast to a type in the method's declared return type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_return_checking' => true,

    // Test dead code detection
    'dead_code_detection' => true,

    'unused_variable_detection' => true,

    'redundant_condition_detection' => true,

    'guess_unknown_parameter_type_using_default' => true,

    // If enabled, warn about throw statement where the exception types
    // are not documented in the PHPDoc of functions, methods, and closures.
    'warn_about_undocumented_throw_statements' => true,

    // If enabled (and warn_about_undocumented_throw_statements is enabled),
    // warn about function/closure/method calls that have (at)throws
    // without the invoking method documenting that exception.
    'warn_about_undocumented_exceptions_thrown_by_invoked_functions' => true,

    'minimum_severity' => Issue::SEVERITY_LOW,

    'directory_list' => ['src'],

    'analyzed_file_extensions' => ['php'],

    // Set this to true to enable the plugins that Phan uses to infer more accurate literal return types of `implode`, `implode`, and many other functions.
    //
    // Phan is slightly faster when these are disabled.
    'enable_extended_internal_return_type_plugins' => true,

    // This is a unit test of Phan itself, so don't cache it because the polyfill implementation may change before the next release.
    'cache_polyfill_asts' => false,

    // A list of plugin files to execute
    // (Execute all of them.)
    // FooName is shorthand for /path/to/phan/.phan/plugins/FooName.php.
    'plugins' => [
        'AlwaysReturnPlugin',
        'DollarDollarPlugin',
        'DuplicateArrayKeyPlugin',
        'InvokePHPNativeSyntaxCheckPlugin',
        'NumericalComparisonPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'UnreachableCodePlugin',
        'UnusedSuppressionPlugin',
        'UseReturnValuePlugin',
        'DuplicateExpressionPlugin',
        'WhitespacePlugin',
        'UnknownElementTypePlugin',
        'AvoidableGetterPlugin',
        'UnknownClassElementAccessPlugin',
    ],

    // Set this to false to emit `PhanUndeclaredFunction` issues for internal functions that Phan has signatures for,
    // but aren't available in the codebase, or from Reflection.
    // (may lead to false positives if an extension isn't loaded)
    //
    // If this is true(default), then Phan will not warn.
    //
    // Even when this is false, Phan will still infer return values and check parameters of internal functions
    // if Phan has the signatures.
    'ignore_undeclared_functions_with_known_signatures' => false,

    // Allow adding types to vague return types such as @return object, @return ?mixed in function/method/closure union types.
    // Normally, Phan only adds inferred returned types when there is no `@return` type or real return type signature.
    // This setting can be disabled on individual methods by adding `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice`.
    'allow_overriding_vague_return_types' => false,

    // Add types to all return types. Normally, Phan only adds inferred returned types when there is no `@return` type
    // or real return type signature. This setting can be disabled on individual methods by adding
    // `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice` and in conjunction with `PhoundPlugin` to
    // detect more callsite possibilities.
    'override_return_types' => true,
];
