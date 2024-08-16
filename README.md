Phan is a static analyzer for PHP that prefers to minimize false-positives. Phan attempts to prove incorrectness rather than correctness.

Phan looks for common issues and will verify type compatibility on various operations when type
information is available or can be deduced. Phan has a good (but not comprehensive) understanding of flow control
and can track values in a few use cases (e.g. arrays, integers, and strings).

[![Build Status](https://github.com/phan/phan/actions/workflows/main.yml/badge.svg?branch=v5)](https://github.com/phan/phan/actions/workflows/main.yml?query=branch%3Av5)
[![Build Status (Windows)](https://ci.appveyor.com/api/projects/status/github/phan/phan?branch=v5&svg=true)](https://ci.appveyor.com/project/TysonAndre/phan/branch/v5)
[![Gitter](https://badges.gitter.im/phan/phan.svg)](https://gitter.im/phan/phan?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)
[![Latest Stable Version](https://img.shields.io/packagist/v/phan/phan.svg)](https://packagist.org/packages/phan/phan)
[![License](https://img.shields.io/packagist/l/phan/phan.svg)](https://github.com/phan/phan/blob/v5/LICENSE)

# Getting Started

The easiest way to use Phan is via Composer.

```
composer require phan/phan
```

With Phan installed, you'll want to [create a `.phan/config.php` file](https://github.com/phan/phan/wiki/Getting-Started#creating-a-config-file) in
your project to tell Phan how to analyze your source code. Once configured, you can run it via `./vendor/bin/phan`.

Phan 5 depends on PHP 7.2+ with the [php-ast](https://github.com/nikic/php-ast) extension (1.1.1+ is preferred) and supports analyzing PHP version 7.0-8.2 syntax.
Installation instructions for php-ast can be found [here](https://github.com/nikic/php-ast#installation).
(Phan can be used without php-ast by using the CLI option `--allow-polyfill-parser`, but there are slight differences in the parsing of doc comments)

* **Alternative Installation Methods**<br />
  See [Getting Started](https://github.com/phan/phan/wiki/Getting-Started) for alternative methods of using
Phan and details on how to configure Phan for your project.<br />
* **Incrementally Strengthening Analysis**<br />
  Take a look at [Incrementally Strengthening Analysis](https://github.com/phan/phan/wiki/Incrementally-Strengthening-Analysis) for some tips on how to slowly ramp up the strictness of the analysis as your code becomes better equipped to be analyzed. <br />
* **Installing Dependencies**<br />
  Take a look at [Installing Phan Dependencies](https://github.com/phan/phan/wiki/Getting-Started#installing-phan-dependencies) for help getting Phan's dependencies installed on your system.

The [Wiki has more information about using Phan](https://github.com/phan/phan/wiki#using-phan).

# Features

Phan is able to perform the following kinds of analysis:

* Check that all methods, functions, classes, traits, interfaces, constants, properties and variables are defined and accessible.
* Check for type safety and arity issues on method/function/closure calls.
* Check for PHP8/PHP7/PHP5 backward compatibility.
* Check for features that weren't supported in older PHP 7.x minor releases (E.g. `object`, `void`, `iterable`, `?T`, `[$x] = ...;`, negative string offsets, multiple exception catches, etc.)
* Check for sanity with array accesses.
* Check for type safety on binary operations.
* Check for valid and type safe return values on methods, functions, and closures.
* Check for No-Ops on arrays, closures, constants, properties, variables, unary operators, and binary operators.
* Check for unused/dead/[unreachable](https://github.com/phan/phan/tree/v5/.phan/plugins#unreachablecodepluginphp) code. (Pass in `--dead-code-detection`)
* Check for unused variables and parameters. (Pass in `--unused-variable-detection`)
* Check for redundant or impossible conditions and pointless casts. (Pass in `--redundant-condition-detection`)
* Check for unused `use` statements.
  These and a few other issue types can be automatically fixed with `--automatic-fix`.
* Check for classes, functions and methods being redefined.
* Check for sanity with class inheritance (e.g. checks method signature compatibility).
  Phan also checks for final classes/methods being overridden, that abstract methods are implemented, and that the implemented interface is really an interface (and so on).
* Supports namespaces, traits and variadics.
* Supports [Union Types](https://github.com/phan/phan/wiki/About-Union-Types).
* Supports [Generic Types (i.e. `@template`)](https://github.com/phan/phan/wiki/Generic-Types).
* Supports generic arrays such as `int[]`, `UserObject[]`, `array<int,UserObject>`, etc..
* Supports array shapes such as `array{key:string,otherKey:?stdClass}`, etc. (internally and in PHPDoc tags)
  This also supports indicating that fields of an array shape are optional
  via `array{requiredKey:string,optionalKey?:string}` (useful for `@param`)
* Supports phpdoc [type annotations](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code).
* Supports inheriting phpdoc type annotations.
* Supports checking that phpdoc type annotations are a narrowed form (E.g. subclasses/subtypes) of the real type signatures
* Supports inferring types from [assert() statements](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code) and conditionals in if elements/loops.
* Supports [`@deprecated` annotation](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#deprecated) for deprecating classes, methods and functions
* Supports [`@internal` annotation](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#internal) for elements (such as a constant, function, class, class constant, property or method) as internal to the package in which it's defined.
* Supports `@suppress <ISSUE_TYPE>` annotations for [suppressing issues](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#suppress).
* Supports [magic @property annotations](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#property) (`@property <union_type> <variable_name>`)
* Supports [magic @method annotations](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code#method) (`@method <union_type> <method_name>(<union_type> <param1_name>)`)
* Supports [`class_alias` annotations (experimental, off by default)](https://github.com/phan/phan/pull/586)
* Supports indicating the class to which a closure will be bound, via `@phan-closure-scope` ([example](tests/files/src/0264_closure_override_context.php))
* Supports analysis of closures and return types passed to `array_map`, `array_filter`, and other internal array functions.
* Offers extensive configuration for weakening the analysis to make it useful on large sloppy code bases
* Can be run on many cores. (requires `pcntl`)
* Output can be emitted in Checkstyle, CodeClimate, CSV, Gitlab, HTML, JSON,
  Pylint, text (default) or text verbose formats.
* Can run [user plugins on source for checks specific to your code](https://github.com/phan/phan/wiki/Writing-Plugins-for-Phan).
  [Phan includes various plugins you may wish to enable for your project](https://github.com/phan/phan/tree/v5/.phan/plugins#2-general-use-plugins).

See [Phan Issue Types](https://github.com/phan/phan/wiki/Issue-Types-Caught-by-Phan) for descriptions
and examples of all issues that can be detected by Phan. Take a look at the
[\Phan\Issue](https://github.com/phan/phan/blob/v5/src/Phan/Issue.php) to see the
definition of each error type.

Take a look at the [Tutorial for Analyzing a Large Sloppy Code Base](https://github.com/phan/phan/wiki/Tutorial-for-Analyzing-a-Large-Sloppy-Code-Base) to get a sense of what the process of doing ongoing analysis might look like for you.

Phan can be used from [various editors and IDEs](https://github.com/phan/phan/wiki/Editor-Support) for its error checking, "go to definition" support, etc. via the [Language Server Protocol](https://github.com/Microsoft/language-server-protocol).
Editors and tools can also request analysis of individual files in a project using the simpler [Daemon Mode](https://github.com/phan/phan/wiki/Using-Phan-Daemon-Mode).

See the [tests](https://github.com/phan/phan/blob/v5/tests/files) directory for some examples of the various checks.

Phan is imperfect and shouldn't be used to prove that your PHP-based rocket guidance system is free of defects.

## Features provided by plugins

Additional analysis features have been provided by [plugins](https://github.com/phan/phan/tree/v5/.phan/plugins#plugins).

- [Checking for syntactically unreachable statements](https://github.com/phan/phan/tree/v5/.phan/plugins#unreachablecodepluginphp) (E.g. `{ throw new Exception("Message"); return $value; }`)
- [Checking `*printf()` format strings against the provided arguments](https://github.com/phan/phan/tree/v5/.phan/plugins#printfcheckerplugin) (as well as checking for common errors)
- [Checking that PCRE regexes passed to `preg_*()` are valid](https://github.com/phan/phan/tree/v5/.phan/plugins#pregregexcheckerplugin)
- [Checking for `@suppress` annotations that are no longer needed.](https://github.com/phan/phan/tree/v5/.phan/plugins#unusedsuppressionpluginphp)
- [Checking for duplicate or missing array keys.](https://github.com/phan/phan/tree/v5/.phan/plugins#duplicatearraykeypluginphp)
- [Checking coding style conventions](https://github.com/phan/phan/tree/v5/.phan/plugins#3-plugins-specific-to-code-styles)
- [Others](https://github.com/phan/phan/tree/v5/.phan/plugins#plugins)

Example: [Phan's plugins for self-analysis.](https://github.com/phan/phan/blob/3.2.8/.phan/config.php#L601-L674)

# Usage

After [installing Phan](#getting-started), Phan needs to be configured with details on where to find code to analyze and how to analyze it. The
easiest way to tell Phan where to find source code is to [create a `.phan/config.php` file](https://github.com/phan/phan/wiki/Getting-Started#creating-a-config-file).
A simple `.phan/config.php` file might look something like the following.

```php
<?php

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 */
return [

    // Supported values: `'5.6'`, `'7.0'`, `'7.1'`, `'7.2'`, `'7.3'`, `'7.4'`,
    // `'8.0'`, `'8.1'`, `'8.2'`, `'8.3'`, `null`.
    // If this is set to `null`,
    // then Phan assumes the PHP version which is closest to the minor version
    // of the php executable used to execute Phan.
    "target_php_version" => null,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    'directory_list' => [
        'src',
        'vendor/symfony/console',
    ],

    // A directory list that defines files that will be excluded
    // from static analysis, but whose class and method
    // information should be included.
    //
    // Generally, you'll want to include the directories for
    // third-party code (such as "vendor/") in this list.
    //
    // n.b.: If you'd like to parse but not analyze 3rd
    //       party code, directories containing that code
    //       should be added to the `directory_list` as
    //       to `exclude_analysis_directory_list`.
    "exclude_analysis_directory_list" => [
        'vendor/'
    ],

    // A list of plugin files to execute.
    // Plugins which are bundled with Phan can be added here by providing their name
    // (e.g. 'AlwaysReturnPlugin')
    //
    // Documentation about available bundled plugins can be found
    // at https://github.com/phan/phan/tree/v5/.phan/plugins
    //
    // Alternately, you can pass in the full path to a PHP file
    // with the plugin's implementation.
    // (e.g. 'vendor/phan/phan/.phan/plugins/AlwaysReturnPlugin.php')
    'plugins' => [
        // checks if a function, closure or method unconditionally returns.
        // can also be written as 'vendor/phan/phan/.phan/plugins/AlwaysReturnPlugin.php'
        'AlwaysReturnPlugin',
        'DollarDollarPlugin',
        'DuplicateArrayKeyPlugin',
        'DuplicateExpressionPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'SleepCheckerPlugin',
        // Checks for syntactically unreachable statements in
        // the global scope or function bodies.
        'UnreachableCodePlugin',
        'UseReturnValuePlugin',
        'EmptyStatementListPlugin',
        'LoopVariableReusePlugin',
    ],
];
```

Take a look at [Creating a Config File](https://github.com/phan/phan/wiki/Getting-Started#creating-a-config-file) and
[Incrementally Strengthening Analysis](https://github.com/phan/phan/wiki/Incrementally-Strengthening-Analysis) for
more details.

Running `phan --help` will show [usage information and command-line options](./internal/CLI-HELP.md).

## Annotating Your Source Code

Phan reads and understands most [PHPDoc](http://www.phpdoc.org/docs/latest/guides/types.html)
type annotations including [Union Types](https://github.com/phan/phan/wiki/About-Union-Types)
(like `int|MyClass|string|null`) and generic array types (like `int[]` or `string[]|MyClass[]` or `array<int,MyClass>`).

Take a look at [Annotating Your Source Code](https://github.com/phan/phan/wiki/Annotating-Your-Source-Code)
and [About Union Types](https://github.com/phan/phan/wiki/About-Union-Types) for some help
getting started with defining types in your code.

Phan supports `(int|string)[]` style annotations, and represents them internally as `int[]|string[]`
(Both annotations are treated like array which may have integers and/or strings).
When you have arrays of mixed types, just use `array`.

The following code shows off the various annotations that are supported.

```php
/**
 * @return void
 */
function f() {}

/** @deprecated */
class C {
    /** @var int */
    const C = 42;

    /** @var string[]|null */
    public $p = null;

    /**
     * @param int|null $p
     * @return string[]|null
     */
    public static function f($p) {
        if (is_null($p)) {
            return null;
        }

        return array_map(
            /** @param int $i */
            function($i) {
                return "thing $i";
            },
            range(0, $p)
        );
    }
}
```

Just like in PHP, any type can be nulled in the function declaration which also
means a null is allowed to be passed in for that parameter.

Phan checks the type of every single element of arrays (Including keys and values).
In practical terms, this means that `[$int1=>$int2,$int3=>$int4,$int5=>$str6]` is seen as `array<int,int|string>`,
which Phan represents as `array<int,int>|array<int,string>`.
`[$strKey => new MyClass(), $strKey2 => $unknown]` will be represented as
`array<string,MyClass>|array<string,mixed>`.

- Literals such as `[12,'myString']` will be represented internally as array shapes such as `array{0:12,1:'myString'}`

# Generating a file list

This static analyzer does not track includes or try to figure out autoloader magic. It treats
all the files you throw at it as one big application. For code encapsulated in classes this
works well. For code running in the global scope it gets a bit tricky because order
matters. If you have an `index.php` including a file that sets a bunch of global variables and
you then try to access those after the `include(...)` in `index.php` the static analyzer won't
know anything about these.

In practical terms this simply means that you should put your entry points and any files
setting things in the global scope at the top of your file list. If you have a `config.php`
that sets global variables that everything else needs, then you should put that first in the list followed by your
various entry points, then all your library files containing your classes.

# Development

Take a look at [Developer's Guide to Phan](https://github.com/phan/phan/wiki/Developer's-Guide-To-Phan) for help getting started hacking on Phan.

When you find an issue, please take the time to create a tiny reproducing code snippet that illustrates
the bug. And once you have done that, fix it. Then turn your code snippet into a test and add it to
[tests](tests) then `./test` and send a PR with your fix and test. Alternatively, you can open an Issue with
details.

To run Phan's unit tests, just run `./test`.

To run all of Phan's unit tests and integration tests, run `./tests/run_all_tests.sh`

# Code of Conduct

We are committed to fostering a welcoming community. Any participant and
contributor is required to adhere to our [Code of Conduct](./CODE_OF_CONDUCT.md).

# Online Demo

**This requires an up to date version of Firefox/Chrome and at least 4 GB of free RAM.** (this is a 15 MB download)

[Run Phan entirely in your browser](https://phan.github.io/demo/).

[![Preview of analyzing PHP](https://raw.githubusercontent.com/phan/demo/master/static/preview.png)](https://phan.github.io/demo/)
