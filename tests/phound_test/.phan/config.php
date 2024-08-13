<?php

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 */
return [
    'plugins' => [__DIR__ . '/../../../src/Phan/Plugin/Internal/PhoundPlugin.php'],

    'directory_list' => ['src'],

    'analyzed_file_extensions' => ['php'],

    'plugin_config' => [
        'phound_sqlite_path' => $_SERVER['HOME'] . '/phound.db',
    ],

    // Add types to all return types. Normally, Phan only adds inferred returned types when there is no `@return` type
    // or real return type signature. This setting can be disabled on individual methods by adding
    // `@phan-hardcode-return-type` to the doc comment.
    //
    // Disabled by default. This is more useful with `--analyze-twice` and in conjunction with `PhoundPlugin` to
    // detect more callsite possibilities. See the [PR description](https://github.com/phan/phan/pull/4874) where
    // this setting was added for more details.
    'override_return_types' => true,
];
