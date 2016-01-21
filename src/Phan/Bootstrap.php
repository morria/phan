<?php
declare(strict_types=1);

// Listen for all errors
error_reporting(E_ALL);

// Take as much memory as we need
ini_set("memory_limit", '-1');

// Add the root to the include path
define('CLASS_DIR', __DIR__ . '/../');
set_include_path(get_include_path().PATH_SEPARATOR.CLASS_DIR);

// Use the composer autoloader
foreach ([
    __DIR__.'/../../vendor/autoload.php',          // autoloader is in this project
    __DIR__.'/../../../../../vendor/autoload.php', // autoloader is in parent project
    ] as $file) {
    if (file_exists($file)) {
        require_once($file);
        break;
    }
}

// Customize assertions
assert_options(ASSERT_ACTIVE, true);
assert_options(ASSERT_BAIL, true);
assert_options(ASSERT_WARNING, false);
assert_options(
    ASSERT_CALLBACK,
    function (string $script, int $line, $expression, $message) {
        print "$script:$line ($expression) $message\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    }
);

// Print more of the backtrace than is done by default
set_exception_handler(function (Throwable $throwable) {
    print "$throwable\n";
});

/**
 * @suppress PhanUnreferencedMethod
 */
function phan_error_handler($errno, $errstr, $errfile, $errline)
{
    print "$errfile:$errline [$errno] $errstr\n";
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
}
set_error_handler('phan_error_handler');
