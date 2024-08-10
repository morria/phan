<?php

declare(strict_types=1);

/**
 * Set up error handlers, exception handlers, autoloaders, etc. Check that all dependencies are met for running Phan or its utilities.
 *
 * @phan-file-suppress PhanPluginRemoveDebugAny this has a lot of warnings to stderr
 */

use Phan\CLI;
use Phan\CodeBase;
use Phan\Config;
use Phan\Library\StringUtil;

// Listen for all errors
error_reporting(E_ALL);

// Take as much memory as we need
ini_set("memory_limit", '-1');

// Add the root to the include path
define('CLASS_DIR', __DIR__ . '/../');
set_include_path(get_include_path() . PATH_SEPARATOR . CLASS_DIR);

if (function_exists('uopz_allow_exit') && !ini_get('uopz.disable')) {
    // This is safe to do in the uopz PECL module, it toggles a global variable.
    try {
        uopz_allow_exit(true); // @phan-suppress-current-line PhanUndeclaredFunction
    } catch (Throwable $e) {
        fprintf(STDERR, "uopz_allow_exit failed: %s" . PHP_EOL, $e->getMessage());
    }
}

if (PHP_VERSION_ID < 70200) {
    fprintf(
        STDERR,
        "ERROR: Phan 5.x requires PHP 7.2+ to run, but PHP %s is installed." . PHP_EOL,
        PHP_VERSION
    );
    fwrite(STDERR, "PHP 7.1 reached its end of life in December 2019." . PHP_EOL);
    fwrite(STDERR, "Exiting without analyzing code." . PHP_EOL);
    // The version of vendor libraries this depends on will also require php 7.1
    exit(1);
}

const LATEST_KNOWN_PHP_AST_VERSION = '1.1.1';

/**
 * Dump instructions on how to install php-ast
 */
function phan_output_ast_installation_instructions(): void
{
    require_once __DIR__ . '/Library/StringUtil.php';
    $ini_path = php_ini_loaded_file() ?: '(php.ini path could not be determined - try creating one at ' . dirname(PHP_BINARY) . '\\php.ini as a new empty file, or one based on php.ini.development or php.ini.production)';
    $configured_extension_dir = ini_get('extension_dir');
    $extension_dir = StringUtil::isNonZeroLengthString($configured_extension_dir) ? $configured_extension_dir : '(extension directory could not be determined)';
    $extension_dir = "'$extension_dir'";
    $new_extension_dir = dirname(PHP_BINARY) . '\\ext';
    if (!is_dir((string)$configured_extension_dir)) {
        $extension_dir .= ' (extension directory does not exist and may need to be changed)';
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        if (PHP_VERSION_ID >= 70300 && PHP_VERSION_ID < 80100 || !preg_match('/[a-zA-Z]/', PHP_VERSION)) {
            // e.g. https://downloads.php.net/~windows/pecl/releases/ast/1.1.1/php_ast-1.1.1-8.0-nts-vs16-x64.zip for php 8.0, 64-bit non thread safe
            // e.g. https://downloads.php.net/~windows/pecl/releases/ast/1.1.1/php_ast-1.1.1-7.4-ts-vc15-x86.zip for php 7.4, 32-bit thread safe
            // The older release https://pecl.php.net/package/ast/1.0.16/windows has releases for PHP 7.3 and 7.4
            $version = PHP_VERSION_ID >= 80000 ? LATEST_KNOWN_PHP_AST_VERSION : '1.0.16';
            fprintf(
                STDERR,
                PHP_EOL . "Windows users can download php-ast from https://downloads.php.net/~windows/pecl/releases/ast/%s/php_ast-%s-%s-%s-%s-%s.zip" . PHP_EOL,
                $version,
                $version,
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                PHP_ZTS ? 'ts' : 'nts',
                PHP_VERSION_ID >= 80000 ? 'vs16' : 'vc15',
                PHP_INT_SIZE == 4 ? 'x86' : 'x64'
            );
            fwrite(STDERR, "(if that link doesn't work, check https://downloads.php.net/~windows/pecl/releases/ast/ )" . PHP_EOL);
            fwrite(STDERR, "php-ast 1.0.11 is the minimum php-ast version needed for ast version 85. https://pecl.php.net/package/ast/1.0.11/windows does not supply dlls for php 7.2 because php-ast 1.0.11 was published after security support for php 7.2 was dropped" . PHP_EOL);

            fwrite(STDERR, "To install php-ast, add php_ast.dll from the zip to $extension_dir," . PHP_EOL);
        } else {
            if (PHP_VERSION_ID < 70300) {
                fwrite(STDERR, "php-ast 1.0.11 is the minimum php-ast version needed for ast version 85. https://pecl.php.net/package/ast/1.0.11/windows does not supply dlls for php 7.2 because php-ast 1.0.11 was published after security support for php 7.2 was dropped" . PHP_EOL);
            } else {
                fprintf(STDERR, "Releases for php %s may not yet be available at https://downloads.php.net/~windows/pecl/releases/ast/" . PHP_EOL, PHP_VERSION);
            }
            fwrite(STDERR, "To build php-ast from source for Windows, see https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2 and https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2#building_pecl_extensions" . PHP_EOL);
        }
        fwrite(STDERR, "Then, enable php-ast by adding the following lines to your php.ini file at '$ini_path'" . PHP_EOL . PHP_EOL);
        if (!is_dir((string)$configured_extension_dir) && is_dir($new_extension_dir)) {
            fwrite(STDERR, "extension_dir=$new_extension_dir" . PHP_EOL);
        }
        fwrite(STDERR, "extension=php_ast.dll" . PHP_EOL . PHP_EOL);
    } else {
        fwrite(STDERR, <<<EOT
php-ast can be installed in the following ways:

1. Unix (PECL): Run 'pecl install ast' and add extension=ast.so to your php.ini.

2. Unix (Compile): Download https://github.com/nikic/php-ast then compile and install the extension as follows:

   cd path/to/php-ast
   phpize
   ./configure
   make
   sudo make install

   Additionally, add extension=ast.so to your php.ini file.

EOT
        );
    }
    fwrite(STDERR, "For more information, see https://github.com/phan/phan/wiki/Getting-Started#installing-dependencies" . PHP_EOL);
}

// Use the composer autoloader
$found_autoloader = false;
foreach ([
    dirname(__DIR__, 2) . '/vendor/autoload.php', // autoloader is in this project (we're in src/Phan and want vendor/autoload.php)
    dirname(__DIR__, 5) . '/vendor/autoload.php', // autoloader is in parent project (we're in vendor/phan/phan/src/Phan/Bootstrap.php and want autoload.php
    dirname(__DIR__, 4) . '/autoload.php',        // autoloader is in parent project (we're in non-standard-vendor/phan/phan/src/Phan/Bootstrap.php and want non-standard-vendor/autoload.php
    ] as $file) {
    if (file_exists($file)) {
        require_once($file);
        $found_autoloader = true;
        break;
    }
}

if (extension_loaded('ast')) {
    // Warn if the php-ast version is too low.
    $ast_version = (string)phpversion('ast');
    if ($ast_version === '') {
        // Seen in php 7.3 with file_cache when ast is initially enabled but later disabled, due to the result of extension_loaded being assumed to be a constant by opcache.
        CLI::printErrorToStderr("extension_loaded('ast') is true, but phpversion('ast') is the empty string. You probably need to clear opcache (opcache.file_cache='" . ini_get('opcache.file_cache') . "')" . PHP_EOL);
    }
    $phan_output_ast_too_old_and_exit = /** @return never */ static function (string $minimum_ast_version, string $php_version_bound) use ($ast_version): void {
        $error_message = sprintf(
            "Phan 5.x requires php-ast %s+ to properly analyze ASTs for php %s+. php-ast %s and php %s is installed." . PHP_EOL,
            $minimum_ast_version,
            $php_version_bound,
            $ast_version,
            PHP_VERSION
        );
        CLI::printErrorToStderr($error_message);
        phan_output_ast_installation_instructions();
        fwrite(STDERR, "Exiting without analyzing files." . PHP_EOL);
        exit(1);
    };

    if (PHP_VERSION_ID >= 80300 && version_compare($ast_version, '1.1.1') < 0) {
        $phan_output_ast_too_old_and_exit('1.1.1', '8.3');
    } elseif (PHP_VERSION_ID >= 80200 && version_compare($ast_version, '1.1.0') < 0) {
        $phan_output_ast_too_old_and_exit('1.1.0', '8.2');
    } elseif (PHP_VERSION_ID >= 80100 && version_compare($ast_version, '1.0.14') < 0) {
        $phan_output_ast_too_old_and_exit('1.0.14', '8.1');
    } elseif (PHP_VERSION_ID >= 80000 && version_compare($ast_version, '1.0.11') < 0) {
        $phan_output_ast_too_old_and_exit('1.0.11', '8.0');
    } elseif (PHP_VERSION_ID >= 70400 && version_compare($ast_version, '1.0.2') < 0) {
        fprintf(
            STDERR,
            "WARNING: Phan 5.x requires php-ast 1.0.2+ to properly analyze ASTs for php 7.4+ (1.0.15+ is recommended). php-ast %s and php %s is installed." . PHP_EOL,
            $ast_version,
            PHP_VERSION
        );
        phan_output_ast_installation_instructions();
    } elseif (version_compare($ast_version, '1.0.0') <= 0) {
        $error_message = sprintf(
            "Phan 5.x requires php-ast %s+ because it depends on AST version %d. php-ast '%s' is installed." . PHP_EOL,
            Config::MINIMUM_AST_EXTENSION_VERSION,
            Config::AST_VERSION,
            $ast_version
        );
        CLI::printErrorToStderr($error_message);
        phan_output_ast_installation_instructions();
        fwrite(STDERR, "Exiting without analyzing files." . PHP_EOL);
        exit(1);
    }
    // @phan-suppress-next-line PhanRedundantCondition, PhanImpossibleCondition, PhanSuspiciousValueComparison
    if (PHP_VERSION_ID < 80400 && PHP_VERSION_ID % 100 === 0 && PHP_EXTRA_VERSION !== '') {
        // Warn for 8.3.0RC1, 8.0.0RC1, 7.4.0alpha1, 7.3.0-dev, etc.
        // But don't warn for 8.4.0 since there's no way yet to upgrade to a stable release.
        fwrite(STDERR, "WARNING: Phan may not work properly in versions prior to the first stable release of a php minor version. The currently used PHP version is " . PHP_VERSION . PHP_EOL);
    }
    unset($ast_version);
}
unset($file);
if (!$found_autoloader) {
    fwrite(STDERR, "Could not locate the autoloader\n");
}
unset($found_autoloader);

define('EXIT_SUCCESS', 0);
define('EXIT_FAILURE', 1);
define('EXIT_ISSUES_FOUND', EXIT_FAILURE);

// Note that Phan's source code now throws exceptions rather than using assert(),
// but make the settings consistent for any third party plugins still using assert().

// assert_options has been deprecated starting with PHP 8.3 so use ini_set instead.
// See https://wiki.php.net/rfc/assert-string-eval-cleanup#formal_proposal
// Use ini_set where the value Phan uses is the same as php's defaults.
// https://www.php.net/manual/en/function.assert-options.php

// Throw exceptions so asserts can be linked to the code being analyzed
ini_set('assert.exception', '1');
// enable assert() function
ini_set('assert.active', '1');
// Exceptions are thrown instead of bailing in phan.
ini_set('assert.bail', '0');
// Set a substitute character for StringUtil::asUtf8()
ini_set('mbstring.substitute_character', (string)0xFFFD);
// Disable custom callbacks for assert.
ini_set('assert.callback', '');

if (PHP_VERSION_ID < 80300) {
    // These differ from the ini default and these calls will emit deprecation warnings in PHP 8.3
    // Explicitly set each option in case INI is set otherwise
    ini_set('assert.warning', '0');
    // ASSERT_QUIET_EVAL has been removed and has no effect starting with PHP 8
    ini_set('assert.quiet_eval', '0');
}

// php 8 seems to have segfault issues with disable_function
if (!extension_loaded('filter') && !function_exists('filter_var')) {
    if (!($_ENV['PHAN_DISABLE_FILTER_VAR_POLYFILL'] ?? null)) {
        fwrite(STDERR, "WARNING: Using a limited polyfill for filter_var() instead of the real filter_var(). **ANALYSIS RESULTS MAY DIFFER AND PLUGINS MAY HAVE ISSUES.** Install and/or enable https://www.php.net/filter to fix this. PHAN_DISABLE_FILTER_VAR_POLYFILL=1 can be used to disable this polyfill.\n");
        require_once __DIR__ . '/filter_var.php_polyfill';
    }
}

/**
 * Print more of the backtrace than is done by default
 * @suppress PhanAccessMethodInternal
 * @return never
 */
set_exception_handler(static function (Throwable $throwable): void {
    fwrite(STDERR, "ERROR: $throwable\n");
    if (class_exists(CodeBase::class, false)) {
        $most_recent_file = CodeBase::getMostRecentlyParsedOrAnalyzedFile();
        if (is_string($most_recent_file)) {
            fprintf(STDERR, "(Phan %s crashed due to an uncaught Throwable when parsing/analyzing '%s')\n", CLI::PHAN_VERSION, $most_recent_file);
        } else {
            fprintf(STDERR, "(Phan %s crashed due to an uncaught Throwable)\n", CLI::PHAN_VERSION);
        }
    }
    // Flush output in case this is related to a bug in a php or its engine that may crash when generating a frame
    fflush(STDERR);
    fwrite(STDERR, 'More details:' . PHP_EOL);
    if (class_exists(Config::class, false)) {
        $max_frame_length = max(100, Config::getValue('debug_max_frame_length'));
    } else {
        $max_frame_length = 1000;
    }
    $truncated = false;
    foreach ($throwable->getTrace() as $i => $frame) {
        $frame_details = \Phan\Debug\Frame::frameToString($frame);
        if (strlen($frame_details) > $max_frame_length) {
            $truncated = true;
            if (function_exists('mb_substr')) {
                $frame_details = mb_substr($frame_details, 0, $max_frame_length) . '...';
            } else {
                $frame_details = substr($frame_details, 0, $max_frame_length) . '...';
            }
        }
        fprintf(STDERR, '#%d: %s' . PHP_EOL, $i, $frame_details);
        fflush(STDERR);
    }

    if ($truncated) {
        fwrite(STDERR, "(Some long strings (usually JSON of AST Nodes) were truncated. To print more details for some stack frames of this uncaught exception," .
           "increase the Phan config setting debug_max_frame_length)" . PHP_EOL);
    }

    exit(EXIT_FAILURE);
});

/**
 * Executes $closure with Phan's default error handler disabled.
 *
 * This is useful in cases where PHP notices are unavoidable,
 * e.g. notices in preg_match() when checking if a regex is valid
 * and you don't want the default behavior of terminating the program.
 *
 * @template T
 * @param Closure():T $closure
 * @return T
 * @see PregRegexCheckerPlugin
 */
function with_disabled_phan_error_handler(Closure $closure)
{
    global $__no_echo_phan_errors;
    $__no_echo_phan_errors = true;
    try {
        return $closure();
    } finally {
        $__no_echo_phan_errors = false;
    }
}

/**
 * Print a backtrace with values to stderr.
 */
function phan_print_backtrace(bool $is_crash = false, int $frames_to_skip = 2): void
{
    // Uncomment this if even trying to print the details would crash
    /*
    ob_start();
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    fwrite(STDERR, rtrim(ob_get_clean() ?: "failed to dump backtrace") . PHP_EOL);
     */

    $frames = debug_backtrace();
    if (isset($frames[1])) {
        fwrite(STDERR, 'More details:' . PHP_EOL);
        if (class_exists(Config::class, false)) {
            $max_frame_length = max(100, Config::getValue('debug_max_frame_length'));
        } else {
            $max_frame_length = 1000;
        }
        $truncated = false;
        foreach ($frames as $i => $frame) {
            if ($i < $frames_to_skip) {
                continue;
            }
            $frame_details = \Phan\Debug\Frame::frameToString($frame);
            if (strlen($frame_details) > $max_frame_length) {
                $truncated = true;
                if (function_exists('mb_substr')) {
                    $frame_details = mb_substr($frame_details, 0, $max_frame_length) . '...';
                } else {
                    $frame_details = substr($frame_details, 0, $max_frame_length) . '...';
                }
            }
            fprintf(STDERR, '#%d: %s' . PHP_EOL, $i, $frame_details);
        }
        if ($truncated) {
            fwrite(STDERR, "(Some long strings (usually JSON of AST Nodes) were truncated. To print more details for some stack frames of this " . ($is_crash ? "crash" : "log") . ", " .
               "increase the Phan config setting debug_max_frame_length)" . PHP_EOL);
        }
    }
}

/**
 * The error handler for PHP notices, etc.
 * This is a named function instead of a closure to make stack traces easier to read.
 *
 * @suppress PhanAccessMethodInternal
 * @param int $errno
 * @param string $errstr
 * @param string $errfile
 * @param int $errline
 */
function phan_error_handler(int $errno, string $errstr, string $errfile, int $errline): bool
{
    global $__no_echo_phan_errors;
    if ($__no_echo_phan_errors) {
        if ($__no_echo_phan_errors instanceof Closure) {
            if ($__no_echo_phan_errors($errno, $errstr, $errfile, $errline)) {
                return true;
            }
        } else {
            return false;
        }
    }
    // php-src/ext/standard/streamsfuncs.c suggests that this is the only error caused by signal handlers and there are no translations.
    // In PHP 8.0, "Unable" becomes uppercase.
    if ($errno === E_WARNING && preg_match('/^stream_select.*unable to select/i', $errstr)) {
        // Don't execute the PHP internal error handler
        return true;
    }
    if ($errno === E_USER_DEPRECATED && preg_match('/(^Passing a command as string when creating a |method is deprecated since Symfony 4\.4)/', $errstr)) {
        // Suppress deprecation notices running `vendor/bin/paratest`.
        // Don't execute the PHP internal error handler.
        return true;
    }
    if ($errno === E_DEPRECATED) {
        // Because php 7.2 is used in CI we're stuck on an unmaintained paratest version.
        // NOTE: Known issues with dynamic properties in tolerant-php-parser are fixed in `main` (but not 0.1.1) but there may be remaining unknown ones.
        if (preg_match('/^Creation of dynamic property (ParaTest\\\\Runners|Microsoft\\\\PhpParser|Phan\\\\LanguageServer\\\\LanguageServer::)/', $errstr)) {
            return true;
        }
        if (preg_match('/^Use of "\w+" in callables is deprecated/i', $errstr) && str_contains(str_replace('\\', '/', $errfile), 'vendor/webmozart/assert')) {
            // TODO: Remove after bumping the minimum webmozart version to a release that fixes this
            // https://github.com/webmozarts/assert/pull/260/files
            return true;
        }
        if (preg_match('/^(Constant |Method ReflectionParameter::getClass)/', $errstr)) {
            // Suppress deprecation notices running `vendor/bin/paratest` in php 8
            // Constants such as ENCHANT can be deprecated when calling constant()
            return true;
        }
        if (preg_match('/^The Serializable interface is deprecated/', $errstr)) {
            if (preg_match('@/vendor/phpunit/@', $errfile)) {
                // Suppress deprecation notices running phpunit in php 8.1 with the Serializable interface.
                // phpunit 8 stopped being maintained before Serializable was deprecated.
                return true;
            }
        }
        if (preg_match('/ast\\\\parse_.*Version.*is deprecated/i', $errstr)) {
            static $did_warn = false;
            if (!$did_warn) {
                $did_warn = true;
                if (!getenv('PHAN_SUPPRESS_AST_DEPRECATION')) {
                    CLI::printWarningToStderr(sprintf(
                        "php-ast AST version %d used by Phan %s has been deprecated in php-ast %s. Check if a newer version of Phan is available." . PHP_EOL,
                        Config::AST_VERSION,
                        CLI::PHAN_VERSION,
                        (string)phpversion('ast')
                    ));
                    fwrite(STDERR, "(Set PHAN_SUPPRESS_AST_DEPRECATION=1 to suppress this message)" . PHP_EOL);
                }
            }
            // Don't execute the PHP internal error handler
            return true;
        }
        if (preg_match('/Implicitly marking parameter.*nullable/', $errstr)) {
            // tolerant-php-parser and a few other dependencies have implicitly nullable parameters
            return true;
        }
    }
    if ($errno === E_NOTICE && preg_match('/^(iconv_strlen)/', $errstr)) {
        // Suppress deprecation notices in symfony/polyfill-mbstring
        return true;
    }
    fwrite(STDERR, "$errfile:$errline [$errno] $errstr\n");
    if (error_reporting() === 0) {
        // https://secure.php.net/manual/en/language.operators.errorcontrol.php
        // Don't make Phan terminate if the @-operator was being used on an expression.
        return false;
    }

    if (class_exists(CodeBase::class, false)) {
        $most_recent_file = CodeBase::getMostRecentlyParsedOrAnalyzedFile();
        if (is_string($most_recent_file)) {
            fprintf(STDERR, "(Phan %s crashed when parsing/analyzing '%s')" . PHP_EOL, CLI::PHAN_VERSION, $most_recent_file);
        } else {
            fprintf(STDERR, "(Phan %s crashed)" . PHP_EOL, CLI::PHAN_VERSION);
        }
    }

    phan_print_backtrace(true);

    exit(EXIT_FAILURE);
}
set_error_handler('phan_error_handler');

if (!class_exists(CompileError::class)) {
    /**
     * For self-analysis, add CompileError if it was not already declared.
     *
     * In PHP 7.3, a new CompileError class was introduced, and ParseError was turned into a subclass of CompileError.
     *
     * Phan handles both of those separately, so that Phan will work in 7.1+
     *
     * @suppress PhanRedefineClassInternal
     */
    // phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace
    class CompileError extends Error
    {
    }
}
