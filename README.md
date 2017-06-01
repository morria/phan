Phan is a static analyzer for PHP that prefers to minimize false-positives. Phan attempts to prove incorrectness rather than correctness.

Phan looks for common issues and will verify type compatibility on various operations when type
information is available or can be deduced. Phan does not have a strong understanding of flow control
and does not attempt to track values.

[![Code Climate](https://codeclimate.com/github/etsy/phan/badges/gpa.svg)](https://codeclimate.com/github/etsy/phan) [![Build Status](https://travis-ci.org/etsy/phan.svg?branch=master)](https://travis-ci.org/etsy/phan) [![Gitter](https://badges.gitter.im/etsy/phan.svg)](https://gitter.im/etsy/phan?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge)
[![Latest Stable Version](https://poser.pugx.org/etsy/phan/v/stable)](https://packagist.org/packages/etsy/phan)
[![License](https://poser.pugx.org/etsy/phan/license)](https://github.com/etsy/phan/blob/master/LICENSE)

# Getting Started

The easiest way to use Phan is via Composer.

```
composer require --dev etsy/phan
```

With Phan installed, you'll want to [create a `.phan/config.php` file](https://github.com/etsy/phan/wiki/Getting-Started#creating-a-config-file) in
your project to tell Phan how to analyze your source code. Once configured, you can run it via `./vendor/bin/phan`.

This version (branch) of Phan depends on PHP 7.1.x with pcntl enabled as well as the [php-ast](https://github.com/nikic/php-ast) extension and supports PHP version 7.1+ syntax. For PHP 7.0.x use the [0.8 branch](https://github.com/etsy/phan/tree/0.8).

* **Alternative Installation Methods**<br />
  See [Getting Started](https://github.com/etsy/phan/wiki/Getting-Started) for alternative methods of using
Phan and details on how to configure Phan for your project.<br />
* **Incrementally Strengthening Analysis**<br />
  Take a look at [Incrementally Strengthening Analysis](https://github.com/etsy/phan/wiki/Incrementally-Strengthening-Analysis) for some tips on how to slowly ramp up the strictness of the analysis as your code becomes better equipped to be analyzed. <br />
* **Installing Dependencies**<br />
  Take a look at [Installing Phan Dependencies](https://github.com/etsy/phan/wiki/Getting-Started#installing-phan-dependencies) for help getting Phan's dependencies installed on your system.

# Features

Phan is able to perform the following kinds of analysis.

* Check for all methods, functions, classes, traits, interfaces, constants, properties and variables to be defined and accessible.
* Check for type safety and arity issues on method/function/closure calls.
* Check for PHP7/PHP5 backward compatibility
* Check for sanity with array accesses
* Check for type safety on binary operations
* Check for valid and type safe return values on methods, functions, and closures
* Check for No-Ops on arrays, closures, constants, properties, variables.
* Check for unused/dead code.
* Check for classes, functions and methods being redefined
* Supports namespaces, traits and variadics
* Supports [Union Types](https://github.com/etsy/phan/wiki/About-Union-Types)
* Supports generic arrays such as `int[]`, `UserObject[]`, etc..
* Supports phpdoc [type annotations](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code)
* Supports inferring types from [assert() statements](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code)
* Supports [`@deprecated` annotation](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code#deprecated) for deprecating classes, methods and functions
* Supports [`@internal` annotation](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code#internal) for elements (such as a constant, function, class, class constant, property or method) as internal to the package in which its defined.
* Supports `@suppress <ISSUE_TYPE>` annotations for [suppressing issues](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code#suppress).
* Supports [magic property annotations](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code#property) as of Phan 0.9.1 (partial) (`@property <union_type> <variable_name>`)
* Offers extensive configuration for weakening the analysis to make it useful on large sloppy code bases
* Can be run on many cores.
* Output is emitted in text, checkstyle, json or codeclimate formats.
* Can run user plugins on source for checks specific to your code.

See [Phan Issue Types](https://github.com/etsy/phan/wiki/Issue-Types-Caught-by-Phan) for descriptions
and examples of all issues that can be detected by Phan. Take a look at the
[\Phan\Issue](https://github.com/etsy/phan/blob/master/src/Phan/Issue.php) to see the
definition of each error type.

Take a look at the [Tutorial for Analyzing a Large Sloppy Code Base](https://github.com/etsy/phan/wiki/Tutorial-for-Analyzing-a-Large-Sloppy-Code-Base) to get a sense of what the process of doing ongoing analysis might look like for you.

See the [tests](https://github.com/etsy/phan/blob/master/tests/files) directory for some examples of the various checks.

Phan is imperfect and shouldn't be used to prove that your PHP-based rocket guidance system is free of defects.

# Usage

Phan needs to be configured with details on where to find code to analyze and how to analyze it. The
easiest way to tell Phan where to find source code is to [create a `.phan/config.php` file](https://github.com/etsy/phan/wiki/Getting-Started#creating-a-config-file).
A simple `.phan/config.php` file might look something like the following.

```php
<?php

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 */
return [

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
];
```

Take a look at [Creating a Config File](https://github.com/etsy/phan/wiki/Getting-Started#creating-a-config-file) and
[Incrementally Strengthening Analysis](https://github.com/etsy/phan/wiki/Incrementally-Strengthening-Analysis) for
more details.

Running `phan --help` will show usage information and command-line options.

```
Usage: ./phan [options] [files...]
 -f, --file-list <filename>
  A file containing a list of PHP files to be analyzed

 -l, --directory <directory>
  A directory that should be parsed for class and
  method information. After excluding the directories
  defined in --exclude-directory-list, the remaining
  files will be statically analyzed for errors.

  Thus, both first-party and third-party code being used by
  your application should be included in this list.

  You may include multiple `--directory DIR` options.

 --exclude-file <file>
  A file that should not be parsed or analyzed (or read
  at all). This is useful for excluding hopelessly
  unanalyzable files.

 -3, --exclude-directory-list <dir_list>
  A comma-separated list of directories that defines files
  that will be excluded from static analysis, but whose
  class and method information should be included.

  Generally, you'll want to include the directories for
  third-party code (such as "vendor/") in this list.

 -d, --project-root-directory
  Hunt for a directory named .phan in the current or parent
  directory and read configuration file config.php from that
  path.

 -r, --file-list-only
  A file containing a list of PHP files to be analyzed to the
  exclusion of any other directories or files passed in. This
  is unlikely to be useful.

 -k, --config-file
  A path to a config file to load (instead of the default of
  .phan/config.php).

 -m <mode>, --output-mode
  Output mode from 'text', 'json', 'csv', 'codeclimate', 'checkstyle', or 'pylint'

 -o, --output <filename>
  Output filename

 -p, --progress-bar
  Show progress bar

 -a, --dump-ast
  Emit an AST for each file rather than analyze

 --dump-signatures-file <filename>
  Emit JSON serialized signatures to the given file.
  This uses a method signature format similar to FunctionSignatureMap.php.

 -q, --quick
  Quick mode - doesn't recurse into all function calls

 -b, --backward-compatibility-checks
  Check for potential PHP 5 -> PHP 7 BC issues

 -i, --ignore-undeclared
  Ignore undeclared functions and classes

 -y, --minimum-severity <level in {0,5,10}>
  Minimum severity level (low=0, normal=5, critical=10) to report.
  Defaults to 0.

 -c, --parent-constructor-required
  Comma-separated list of classes that require
  parent::__construct() to be called

 -x, --dead-code-detection
  Emit issues for classes, methods, functions, constants and
  properties that are probably never referenced and can
  possibly be removed.

 -j, --processes <int>
  The number of parallel processes to run during the analysis
  phase. Defaults to 1.

 -z, --signature-compatibility
  Analyze signatures for methods that are overrides to ensure
  compatibility with what they're overriding.

 -h,--help
  This help information
```

## Annotating Your Source Code

Phan reads and understands most [PHPDoc](http://www.phpdoc.org/docs/latest/guides/types.html)
type annotations including [Union Types](https://github.com/etsy/phan/wiki/About-Union-Types)
(like `int|MyClass|string|null`) and generic array types (like `int[]` or `string[]|MyClass[]`).

Take a look at [Annotating Your Source Code](https://github.com/etsy/phan/wiki/Annotating-Your-Source-Code)
and [About Union Types](https://github.com/etsy/phan/wiki/About-Union-Types) for some help
getting started with defining types in your code.

One important note is that Phan doesn't support `(int|string)[]` style annotations. Instead, use
`int[]|string[]`. When you have arrays of mixed types, just use `array`.

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

By default, and completely arbitrarily, for things like `int[]` it checks the first 5
elements. If the first 5 are of the same type, it assumes the rest are as well. If it can't
determine the array sub-type it just becomes `array` which will pass through most type
checks. In practical terms, this means that `[1,2,'a']` is seen as `array` but `[1,2,3]`
is `int[]` and `['a','b','c']` as `string[]`.

# Generating a file list

This static analyzer does not track includes or try to figure out autoloader magic. It treats
all the files you throw at it as one big application. For code encapsulated in classes this
works well. For code running in the global scope it gets a bit tricky because order
matters. If you have an `index.php` including a file that sets a bunch of global variables and
you then try to access those after the include in `index.php` the static analyzer won't
know anything about these.

In practical terms this simply means that you should put your entry points and any files
setting things in the global scope at the top of your file list. If you have a `config.php`
that sets global variables that everything else needs put that first in the list followed by your
various entry points, then all your library files containing your classes.

# Development

Take a look at [Developer's Guide to Phan](https://github.com/etsy/phan/wiki/Developer's-Guide-To-Phan) for help getting started hacking on Phan.

When you find an issue, please take the time to create a tiny reproducing code snippet that illustrates
the bug. And once you have done that, fix it. Then turn your code snippet into a test and add it to
[tests][tests] then `./test` and send a PR with your fix and test. Alternatively, you can open an Issue with
details.

To run Phan's tests, just run `./test`.

# Code of Conduct

We are committed to fostering a welcoming community. Any participant and
contributor is required to adhere to our [Code of Conduct](http://etsy.github.io/codeofconduct.html).
