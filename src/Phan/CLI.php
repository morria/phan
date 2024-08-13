<?php

declare(strict_types=1);

namespace Phan;

use AssertionError;
use Exception;
use InvalidArgumentException;
use Phan\Config\Initializer;
use Phan\Daemon\ExitException;
use Phan\Debug\SignalHandler;
use Phan\Exception\UsageException;
use Phan\ForkPool\Writer;
use Phan\Language\Element\AddressableElement;
use Phan\Language\Element\Comment\Builder;
use Phan\Language\FQSEN;
use Phan\Language\Type;
use Phan\Library\Restarter;
use Phan\Library\StderrLogger;
use Phan\Library\StringUtil;
use Phan\Output\Collector\BufferingCollector;
use Phan\Output\Colorizing;
use Phan\Output\Filter\CategoryIssueFilter;
use Phan\Output\Filter\ChainedIssueFilter;
use Phan\Output\Filter\FileIssueFilter;
use Phan\Output\Filter\MinimumSeverityFilter;
use Phan\Output\PrinterFactory;
use Phan\Plugin\ConfigPluginSet;
use Phan\Plugin\Internal\MethodSearcherPlugin;
use ReflectionExtension;
use SplFileInfo;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;

use function array_key_exists;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function escapeshellarg;
use function fwrite;
use function getenv;
use function in_array;
use function is_array;
use function is_executable;
use function is_string;
use function min;
use function phpversion;
use function printf;
use function shell_exec;
use function sprintf;
use function str_repeat;
use function strcasecmp;
use function strlen;
use function trim;

use const DIRECTORY_SEPARATOR;
use const EXIT_FAILURE;
use const EXIT_SUCCESS;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const FILTER_VALIDATE_INT;
use const FILTER_VALIDATE_IP;
use const PHP_EOL;
use const STDERR;
use const STR_PAD_LEFT;

/**
 * Contains methods for parsing CLI arguments to Phan,
 * outputting to the CLI, as well as helper methods to retrieve files/folders
 * for the analyzed project.
 *
 * @phan-file-suppress PhanPartialTypeMismatchArgumentInternal
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod
 * @phan-file-suppress PhanPluginRemoveDebugAny
 */
class CLI
{
    /**
     * This should be updated to x.y.z-dev after every release, and x.y.z before a release.
     */
    public const PHAN_VERSION = '5.4.5';

    /**
     * List of short flags passed to getopt
     * still available: g,n,w
     * @internal
     */
    public const GETOPT_SHORT_OPTIONS = 'f:m:o:c:k:aeqbr:pid:3:y:l:tuxXj:zhvs:SCP:I:DB:';

    /**
     * List of long flags passed to getopt
     * @internal
     */
    public const GETOPT_LONG_OPTIONS = [
        'absolute-path-issue-messages',
        'allow-polyfill-parser',
        'analyze-all-files',
        'assume-real-types-for-internal-functions',
        'automatic-fix',
        'backward-compatibility-checks',
        'baseline-summary-type:',
        'color',
        'color-scheme:',
        'config-file:',
        'constant-variable-detection',
        'daemonize-socket:',
        'daemonize-tcp-host:',
        'daemonize-tcp-port:',
        'dead-code-detection',
        'dead-code-detection-prefer-false-positive',
        'debug',
        'debug-emitted-issues:',
        'debug-signal-handler',
        'directory:',
        'disable-cache',
        'disable-plugins',
        'dump-analyzed-file-list',
        'dump-ast',
        'dump-ctags:',
        'dump-parsed-file-list',
        'dump-signatures-file:',
        'find-signature:',
        'exclude-directory-list:',
        'exclude-file:',
        'extended-help',
        'file-list:',
        'file-list-only:',
        'force-polyfill-parser',
        'force-polyfill-parser-with-original-tokens',
        'help',
        'help-annotations',
        'ignore-undeclared',
        'include-analysis-file-list:',
        'init',
        'init-level:',
        'init-analyze-dir:',
        'init-analyze-file:',
        'init-no-composer',
        'init-overwrite',
        'language-server-allow-missing-pcntl',
        'language-server-analyze-only-on-save',
        'language-server-completion-vscode',
        'language-server-disable-completion',
        'language-server-disable-go-to-definition',
        'language-server-disable-hover',
        'language-server-disable-output-filter',
        'language-server-enable',
        'language-server-enable-completion',
        'language-server-enable-go-to-definition',
        'language-server-enable-hover',
        'language-server-force-missing-pcntl',
        'language-server-hide-category',
        'language-server-on-stdin',
        'language-server-require-pcntl',
        'language-server-tcp-connect:',
        'language-server-tcp-server:',
        'language-server-verbose',
        'load-baseline:',
        'analyze-twice',
        'always-exit-successfully-after-analysis',
        'long-progress-bar',
        'markdown-issue-messages',
        'memory-limit:',
        'minimum-severity:',
        'minimum-target-php-version:',
        'native-syntax-check:',
        'no-color',
        'no-config-file',
        'no-progress-bar',
        'output:',
        'output-mode:',
        'parent-constructor-required:',
        'plugin:',
        'print-memory-usage-summary',
        'processes:',
        'progress-bar',
        'project-root-directory:',
        'quick',
        'redundant-condition-detection',
        'require-config-exists',
        'save-baseline:',
        'signature-compatibility',
        'strict-method-checking',
        'strict-object-checking',
        'strict-param-checking',
        'strict-property-checking',
        'strict-return-checking',
        'strict-type-checking',
        'target-php-version:',
        'unused-variable-detection',
        'use-fallback-parser',
        'version',
    ];

    /**
     * @internal
     */
    public const DUMP_ANALYZED = 'dump_analyzed';

    /**
     * @var OutputInterface used for outputting the formatted issue messages.
     */
    private $output;

    /**
     * @suppress PhanUnreferencedPublicMethod not used yet.
     */
    public function getOutput(): OutputInterface
    {
        return $this->output;
    }

    /**
     * @var list<string>
     * The set of file names to analyze, from the config
     */
    private $file_list_in_config = [];

    /**
     * @var list<string>
     * The set of file names to analyze, from the combined config and CLI options
     */
    private $file_list = [];

    /**
     * @var bool
     * Set to true to ignore all files and directories
     * added by means other than -file-list-only on the CLI
     */
    private $file_list_only = false;

    /**
     * @var string|null
     * A possibly null path to the config file to load
     */
    private $config_file = null;

    /**
     * @param string|string[] $value
     * @return list<string>
     */
    public static function readCommaSeparatedListOrLists($value): array
    {
        if (is_array($value)) {
            $value = \implode(',', $value);
        }
        $value_set = [];
        foreach (\explode(',', (string)$value) as $file) {
            if ($file === '') {
                continue;
            }
            $value_set[$file] = $file;
        }
        return \array_values($value_set);
    }

    /**
     * @param array<string,mixed> $opts
     * @param list<string> $argv
     * @param string $short_options_string
     * @param list<string> $long_options
     * @throws UsageException
     */
    public static function checkAllArgsUsed(
        array $opts,
        array &$argv,
        string $short_options_string = self::GETOPT_SHORT_OPTIONS,
        array $long_options = self::GETOPT_LONG_OPTIONS
    ): void {
        $pruneargv = [];
        foreach ($opts as $opt => $value) {
            foreach ($argv as $key => $chunk) {
                $regex = '/^' . (isset($opt[1]) ? '--' : '-') . \preg_quote((string) $opt, '/') . '/';

                if (in_array($chunk, is_array($value) ? $value : [$value], true)
                    && $argv[$key - 1][0] === '-'
                    || \preg_match($regex, $chunk)
                ) {
                    $pruneargv[] = $key;
                }
            }
        }

        while (count($pruneargv) > 0) {
            $key = \array_pop($pruneargv);
            unset($argv[$key]);
        }

        foreach ($argv as $arg) {
            if ($arg[0] === '-') {
                $parts = \explode('=', $arg, 2);
                $key = $parts[0];
                $value = $parts[1] ?? '';  // php getopt() treats --processes and --processes= the same way
                $key = \preg_replace('/^--?/', '', $key);
                if ($value === '') {
                    if (in_array($key . ':', $long_options, true)) {
                        throw new UsageException("Missing required value for '$arg'", EXIT_FAILURE);
                    }
                    if (strlen($key) === 1 && strlen($parts[0]) === 2) {
                        // @phan-suppress-next-line PhanParamSuspiciousOrder this is deliberate
                        if (\strpos($short_options_string, "$key:") !== false) {
                            throw new UsageException("Missing required value for '-$key'", EXIT_FAILURE);
                        }
                    }
                }
                throw new UsageException("Unknown option '$arg'" . self::getFlagSuggestionString($key), EXIT_FAILURE);
            }
        }
    }

    /**
     * Creates a CLI object from argv
     */
    public static function fromArgv(): CLI
    {
        global $argv;

        // Parse command line args
        $opts = \getopt(self::GETOPT_SHORT_OPTIONS, self::GETOPT_LONG_OPTIONS);
        $opts = \is_array($opts) ? $opts : [];

        try {
            return new self($opts, $argv);
        } catch (UsageException $e) {
            self::usage($e->getMessage(), (int)$e->getCode(), $e->print_type, $e->forbid_color);
            exit((int)$e->getCode());  // unreachable
        } catch (ExitException $e) {
            \fwrite(STDERR, $e->getMessage());
            exit($e->getCode());
        }
    }

    /**
     * Create and read command line arguments, configuring
     * \Phan\Config as a side effect.
     *
     * @param array<string,string|list<mixed>|false> $opts
     * @param list<string> $argv
     * @throws ExitException
     * @throws UsageException
     * @internal - used for unit tests only
     */
    public static function fromRawValues(array $opts, array $argv): CLI
    {
        return new self($opts, $argv);
    }

    /**
     * Create and read command line arguments, configuring
     * \Phan\Config as a side effect.
     *
     * @param array<string,string|list<mixed>|false> $opts
     * @param list<string> $argv
     * @return void
     * @throws ExitException
     * @throws UsageException
     */
    private function __construct(array $opts, array $argv)
    {
        self::detectAndConfigureColorSupport($opts);

        if (array_key_exists('extended-help', $opts)) {
            throw new UsageException('', EXIT_SUCCESS, UsageException::PRINT_EXTENDED);  // --extended-help prints help and calls exit(0)
        }

        if (array_key_exists('h', $opts) || array_key_exists('help', $opts)) {
            throw new UsageException('', EXIT_SUCCESS, UsageException::PRINT_NORMAL);  // --help prints help and calls exit(0)
        }
        if (array_key_exists('help-annotations', $opts)) {
            $result = "See https://github.com/phan/phan/wiki/Annotating-Your-Source-Code for more details." . PHP_EOL . PHP_EOL;

            $result .= "Annotations specific to Phan:" . PHP_EOL;
            // @phan-suppress-next-line PhanAccessClassConstantInternal
            foreach (Builder::SUPPORTED_ANNOTATIONS as $key => $_) {
                $result .= "- " . $key . PHP_EOL;
            }
            throw new ExitException($result, EXIT_SUCCESS);
        }
        if (array_key_exists('v', $opts) || array_key_exists('version', $opts)) {
            printf("Phan %s" . PHP_EOL, self::PHAN_VERSION);
            $ast_version = (string) phpversion('ast');
            $ast_version_repr = $ast_version !== '' ? "version $ast_version" : "is not installed";
            printf("php-ast %s" . PHP_EOL, $ast_version_repr);
            printf("PHP version used to run Phan: %s" . PHP_EOL, \PHP_VERSION);
            throw new ExitException('', EXIT_SUCCESS);
        }

        // Determine the root directory of the project from which
        // we route all relative paths passed in as args
        $overridden_project_root_directory = $opts['d'] ?? $opts['project-root-directory'] ?? null;
        if (\is_string($overridden_project_root_directory)) {
            if (!\is_dir($overridden_project_root_directory)) {
                throw new UsageException(StringUtil::jsonEncode($overridden_project_root_directory) . ' is not a directory', EXIT_FAILURE, null, true);
            }
            // Set the current working directory so that relative paths within the project will work.
            // TODO: Add an option to allow searching ancestor directories?
            \chdir($overridden_project_root_directory);
        }
        $cwd = \getcwd();
        if (!is_string($cwd)) {
            fwrite(STDERR, "Failed to find current working directory\n");
            exit(1);
        }
        Config::setProjectRootDirectory($cwd);

        if (array_key_exists('init', $opts)) {
            Initializer::initPhanConfig($opts);
            exit(EXIT_SUCCESS);
        }

        // Before reading the config, check for an override on
        // the location of the config file path.
        $config_file_override = $opts['k'] ?? $opts['config-file'] ?? null;
        if ($config_file_override !== null) {
            if (!is_string($config_file_override)) {
                // Doesn't work for a mix of -k and --config-file, but low priority
                throw new ExitException("Expected exactly one file for --config-file, but saw " . StringUtil::jsonEncode($config_file_override) . "\n", 1);
            }
            if (!\is_file($config_file_override)) {
                throw new ExitException("Could not find the config file override " . StringUtil::jsonEncode($config_file_override) . "\n", 1);
            }
            $this->config_file = $config_file_override;
        }

        if (isset($opts['language-server-force-missing-pcntl'])) {
            Config::setValue('language_server_use_pcntl_fallback', true);
        } elseif (!isset($opts['language-server-require-pcntl'])) {
            // --language-server-allow-missing-pcntl is now the default
            if (!\extension_loaded('pcntl')) {
                Config::setValue('language_server_use_pcntl_fallback', true);
            }
        }

        // Now that we have a root directory, attempt to read a
        // configuration file `.phan/config.php` if it exists
        if (array_key_exists('no-config-file', $opts)) {
            if (array_key_exists('require-config-exists', $opts)) {
                throw new ExitException('no-config-file conflicts with --require-config-exists');
            }
            if ($config_file_override !== null) {
                throw new ExitException('no-config-file conflicts with --config-file');
            }
        } else {
            $this->maybeReadConfigFile(array_key_exists('require-config-exists', $opts));
        }

        // We need to know the process count after `--processes N` is parsed if that CLI flag is passed in,
        // to know if grpc should be excluded.
        // Before that, we need to have parsed the config file to override config settings.
        self::parseProcessCountOverride($opts);
        self::restartWithoutProblematicExtensions();

        // Only after restarting, emit output.
        $this->warnSuspiciousShortOptions($argv);

        $this->output = new ConsoleOutput();
        $factory = new PrinterFactory();
        $printer_type = 'text';
        $minimum_severity = Config::getValue('minimum_severity');
        $mask = -1;
        $progress_bar = null;

        self::throwIfUsingInitModifiersWithoutInit($opts);

        foreach ($opts as $key => $value) {
            $key = (string)$key;
            switch ($key) {
                case 'r':
                case 'file-list-only':
                    // Mark it so that we don't load files through
                    // other mechanisms.
                    $this->file_list_only = true;

                    // Empty out the file list
                    $this->file_list_in_config = [];

                    // Intentionally fall through to load the
                    // file list
                case 'f':
                case 'file-list':
                    $file_list = \is_array($value) ? $value : [$value];
                    foreach ($file_list as $file_name) {
                        if (!is_string($file_name)) {
                            // Should be impossible?
                            throw new UsageException(
                                "invalid argument for --file-list",
                                EXIT_FAILURE,
                                null,
                                true
                            );
                        }
                        $file_path = Config::projectPath($file_name);
                        if (\is_file($file_path) && \is_readable($file_path)) {
                            $lines = \file(Config::projectPath($file_name), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                            if (is_array($lines)) {
                                $this->file_list_in_config = \array_merge(
                                    $this->file_list_in_config,
                                    $lines
                                );
                                continue;
                            }
                        }
                        throw new UsageException(
                            "Unable to read --file-list of $file_path",
                            EXIT_FAILURE,
                            null,
                            true
                        );
                    }
                    break;
                case 'l':
                case 'directory':
                    if (!$this->file_list_only) {
                        $directory_list = \is_array($value) ? $value : [$value];
                        foreach ($directory_list as $directory_name) {
                            if (!is_string($directory_name)) {
                                throw new UsageException(
                                    'Invalid --directory setting (expected a single argument)',
                                    EXIT_FAILURE,
                                    null,
                                    false
                                );
                            }
                            $this->file_list_in_config = \array_merge(
                                $this->file_list_in_config,
                                $this->directoryNameToFileList(
                                    $directory_name
                                )
                            );
                        }
                    }
                    break;
                case 'k':
                case 'config-file':
                case 'no-config-file':
                    break;
                case 'm':
                case 'output-mode':
                    if (!is_string($value) || !in_array($value, $factory->getTypes(), true)) {
                        throw new UsageException(
                            sprintf(
                                'Unknown output mode %s. Known values are [%s]',
                                StringUtil::jsonEncode($value),
                                \implode(',', $factory->getTypes())
                            ),
                            EXIT_FAILURE,
                            null,
                            true
                        );
                    }
                    $printer_type = $value;
                    break;
                case 'c':
                case 'parent-constructor-required':
                    Config::setValue('parent_constructor_required', \explode(',', $value));
                    break;
                case 'q':
                case 'quick':
                    Config::setValue('quick_mode', true);
                    break;
                case 'b':
                case 'backward-compatibility-checks':
                    Config::setValue('backward_compatibility_checks', true);
                    break;
                case 'p':
                case 'progress-bar':
                    $progress_bar = true;
                    break;
                case 'long-progress-bar':
                    Config::setValue('__long_progress_bar', true);
                    $progress_bar = true;
                    break;
                case 'no-progress-bar':
                    $progress_bar = false;
                    break;
                case 'D':
                case 'debug':
                    Config::setValue('debug_output', true);
                    break;
                case 'debug-emitted-issues':
                    if (!is_string($value)) {
                        $value = Issue::TRACE_BASIC;
                    }
                    BufferingCollector::setTraceIssues($value);
                    break;
                case 'debug-signal-handler':
                    SignalHandler::init();
                    break;
                case 'a':
                case 'dump-ast':
                    Config::setValue('dump_ast', true);
                    break;
                case 'dump-ctags':
                    if (strcasecmp($value, 'basic') !== 0) {
                        CLI::printErrorToStderr("Unsupported value --dump-ctags='$value'. Supported values are 'basic'.\n");
                        exit(1);
                    }
                    Config::setValue('plugins', \array_merge(
                        Config::getValue('plugins'),
                        [__DIR__ . '/Plugin/Internal/CtagsPlugin.php']
                    ));
                    break;
                case 'dump-parsed-file-list':
                    Config::setValue('dump_parsed_file_list', true);
                    break;
                case 'dump-analyzed-file-list':
                    Config::setValue('dump_parsed_file_list', self::DUMP_ANALYZED);
                    break;
                case 'dump-signatures-file':
                    Config::setValue('dump_signatures_file', $value);
                    break;
                case 'find-signature':
                    try {
                        if (!is_string($value)) {
                            throw new InvalidArgumentException("Expected a string, got " . \json_encode($value));
                        }
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        MethodSearcherPlugin::setSearchString($value);
                    } catch (InvalidArgumentException $e) {
                        throw new UsageException("Invalid argument '$value' to --find-signature. Error: " . $e->getMessage() . "\n", EXIT_FAILURE);
                    }

                    Config::setValue('plugins', \array_merge(
                        Config::getValue('plugins'),
                        [__DIR__ . '/Plugin/Internal/MethodSearcherPluginLoader.php']
                    ));
                    break;
                case 'automatic-fix':
                    Config::setValue('plugins', \array_merge(
                        Config::getValue('plugins'),
                        [__DIR__ . '/Plugin/Internal/IssueFixingPlugin.php']
                    ));
                    break;
                case 'o':
                case 'output':
                    if (!is_string($value)) {
                        throw new UsageException(sprintf("Invalid arguments to --output: args=%s\n", StringUtil::jsonEncode($value)), EXIT_FAILURE);
                    }
                    $output_file = \fopen($value, 'w');
                    if (!$output_file) {
                        throw new UsageException("Failed to open output file '$value'\n", EXIT_FAILURE, null, true);
                    }
                    $this->output = new StreamOutput($output_file);
                    break;
                case 'i':
                case 'ignore-undeclared':
                    $mask &= ~Issue::CATEGORY_UNDEFINED;
                    break;
                case '3':
                case 'exclude-directory-list':
                    // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
                    Config::setValue('exclude_analysis_directory_list', self::readCommaSeparatedListOrLists($value));
                    break;
                case 'exclude-file':
                    Config::setValue('exclude_file_list', \array_merge(
                        Config::getValue('exclude_file_list'),
                        \is_array($value) ? $value : [$value]
                    ));
                    break;
                case 'I':
                case 'include-analysis-file-list':
                    // @phan-suppress-next-line PhanPossiblyFalseTypeArgument
                    Config::setValue('include_analysis_file_list', self::readCommaSeparatedListOrLists($value));
                    break;
                case 'j':
                case 'processes':
                    // Already parsed in parseProcessCountOverride
                    break;
                case 'z':
                case 'signature-compatibility':
                    Config::setValue('analyze_signature_compatibility', true);
                    break;
                case 'y':
                case 'minimum-severity':
                    $minimum_severity = \strtolower($value);
                    if ($minimum_severity === 'low') {
                        $minimum_severity = Issue::SEVERITY_LOW;
                    } elseif ($minimum_severity === 'normal') {
                        $minimum_severity = Issue::SEVERITY_NORMAL;
                    } elseif ($minimum_severity === 'critical') {
                        $minimum_severity = Issue::SEVERITY_CRITICAL;
                    } else {
                        $minimum_severity = (int)$minimum_severity;
                    }
                    break;
                case 'target-php-version':
                    Config::setValue('target_php_version', $value);
                    break;
                case 'minimum-target-php-version':
                    Config::setValue('minimum_target_php_version', $value);
                    break;
                case 'd':
                case 'project-root-directory':
                    // We handle this flag before parsing options so
                    // that we can get the project root directory to
                    // base other config flags values on
                    break;
                case 'require-config-exists':
                    break;  // handled earlier.
                case 'language-server-allow-missing-pcntl':
                case 'language-server-force-missing-pcntl':
                case 'language-server-require-pcntl':
                    break;  // handled earlier
                case 'language-server-hide-category':
                    Config::setValue('language_server_hide_category_of_issues', true);
                    break;
                case 'native-syntax-check':
                    if ($value === '') {
                        throw new UsageException(sprintf("Invalid arguments to --native-syntax-check: args=%s\n", StringUtil::jsonEncode($value)), EXIT_FAILURE);
                    }
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    self::addPHPBinariesForSyntaxCheck($value);
                    break;
                case 'disable-cache':
                    Config::setValue('cache_polyfill_asts', false);
                    break;
                case 'disable-plugins':
                    // Slightly faster, e.g. for daemon mode with lowest latency (along with --quick).
                    Config::setValue('plugins', []);
                    break;
                case 'P':
                case 'plugin':
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    self::addPlugins($value);
                    break;
                case 'use-fallback-parser':
                    Config::setValue('use_fallback_parser', true);
                    break;
                case 'strict-method-checking':
                    Config::setValue('strict_method_checking', true);
                    break;
                case 'strict-param-checking':
                    Config::setValue('strict_param_checking', true);
                    break;
                case 'strict-property-checking':
                    Config::setValue('strict_property_checking', true);
                    break;
                case 'strict-object-checking':
                    Config::setValue('strict_object_checking', true);
                    break;
                case 'strict-return-checking':
                    Config::setValue('strict_return_checking', true);
                    break;
                case 'S':
                case 'strict-type-checking':
                    Config::setValue('strict_method_checking', true);
                    Config::setValue('strict_object_checking', true);
                    Config::setValue('strict_param_checking', true);
                    Config::setValue('strict_property_checking', true);
                    Config::setValue('strict_return_checking', true);
                    break;
                case 's':
                case 'daemonize-socket':
                    self::checkCanDaemonize('unix', $key);
                    if (!is_string($value)) {
                        throw new UsageException(sprintf("Invalid arguments to --daemonize-socket: args=%s", StringUtil::jsonEncode($value)), EXIT_FAILURE);
                    }
                    $socket_dirname = \realpath(\dirname($value));
                    if (!is_string($socket_dirname) || !\file_exists($socket_dirname) || !\is_dir($socket_dirname)) {
                        $msg = sprintf(
                            'Requested to create Unix socket server in %s, but folder %s does not exist',
                            StringUtil::jsonEncode($value),
                            StringUtil::jsonEncode($socket_dirname)
                        );
                        throw new UsageException($msg, 1, null, true);
                    } else {
                        Config::setValue('daemonize_socket', $value);  // Daemonize. Assumes the file list won't change. Accepts requests over a Unix socket, or some other IPC mechanism.
                    }
                    break;
                    // TODO(possible idea): HTTP server binding to 127.0.0.1, daemonize-http-port.
                case 'daemonize-tcp-host':
                    $this->checkCanDaemonize('tcp', $key);
                    Config::setValue('daemonize_tcp', true);
                    $host = \filter_var($value, FILTER_VALIDATE_IP);
                    if (\strcasecmp($value, 'default') !== 0 && !$host) {
                        throw new UsageException("--daemonize-tcp-host must be the string 'default' or a valid ip address to listen on, got '$value'", 1);
                    }
                    if ($host) {
                        Config::setValue('daemonize_tcp_host', $host);
                    }
                    break;
                case 'daemonize-tcp-port':
                    $this->checkCanDaemonize('tcp', $key);
                    Config::setValue('daemonize_tcp', true);
                    $port = \filter_var($value, FILTER_VALIDATE_INT);
                    if (\strcasecmp($value, 'default') !== 0 && !($port >= 1024 && $port <= 65535)) {
                        throw new UsageException("--daemonize-tcp-port must be the string 'default' or a value between 1024 and 65535, got '$value'", 1);
                    }
                    if ($port) {
                        Config::setValue('daemonize_tcp_port', $port);
                    }
                    break;
                case 'language-server-on-stdin':
                    Config::setValue('language_server_config', ['stdin' => true]);
                    break;
                case 'language-server-tcp-server':
                    // TODO: could validate?
                    Config::setValue('language_server_config', ['tcp-server' => $value]);
                    break;
                case 'language-server-tcp-connect':
                    Config::setValue('language_server_config', ['tcp' => $value]);
                    break;
                case 'language-server-analyze-only-on-save':
                    Config::setValue('language_server_analyze_only_on_save', true);
                    break;
                case 'language-server-disable-go-to-definition':
                    Config::setValue('language_server_enable_go_to_definition', false);
                    break;
                case 'language-server-enable-go-to-definition':
                    Config::setValue('language_server_enable_go_to_definition', true);
                    break;
                case 'language-server-disable-hover':
                    Config::setValue('language_server_enable_hover', false);
                    break;
                case 'language-server-enable-hover':
                    Config::setValue('language_server_enable_hover', true);
                    break;
                case 'language-server-completion-vscode':
                    break;
                case 'language-server-disable-completion':
                    Config::setValue('language_server_enable_completion', false);
                    break;
                case 'language-server-enable-completion':
                    break;
                case 'language-server-verbose':
                    Config::setValue('language_server_debug_level', 'info');
                    break;
                case 'language-server-disable-output-filter':
                    Config::setValue('language_server_disable_output_filter', true);
                    break;
                case 'x':
                case 'dead-code-detection':
                    Config::setValue('dead_code_detection', true);
                    break;
                case 'X':
                case 'dead-code-detection-prefer-false-positive':
                    Config::setValue('dead_code_detection', true);
                    Config::setValue('dead_code_detection_prefer_false_negative', false);
                    break;
                case 'u':
                case 'unused-variable-detection':
                    Config::setValue('unused_variable_detection', true);
                    break;
                case 'constant-variable-detection':
                    Config::setValue('constant_variable_detection', true);
                    Config::setValue('unused_variable_detection', true);
                    break;
                case 't':
                case 'redundant-condition-detection':
                    Config::setValue('redundant_condition_detection', true);
                    break;
                case 'assume-real-types-for-internal-functions':
                    Config::setValue('assume_real_types_for_internal_functions', true);
                    break;
                case 'allow-polyfill-parser':
                    // Just check if it's installed and of a new enough version.
                    // Assume that if there is an installation, it works, and warn later in ensureASTParserExists()
                    if (!\extension_loaded('ast')) {
                        Config::setValue('use_polyfill_parser', true);
                        break;
                    }
                    $ast_version = (new ReflectionExtension('ast'))->getVersion();
                    // In order to parse with AST version 85, 1.0.11+ is required
                    if (\version_compare($ast_version, Config::MINIMUM_AST_EXTENSION_VERSION) < 0) {
                        Config::setValue('use_polyfill_parser', true);
                        break;
                    }
                    break;
                case 'force-polyfill-parser':
                    Config::setValue('use_polyfill_parser', true);
                    break;
                case 'force-polyfill-parser-with-original-tokens':
                    Config::setValue('use_polyfill_parser', true);
                    Config::setValue('__parser_keep_original_node', true);
                    break;
                case 'memory-limit':
                    if (\preg_match('@^([1-9][0-9]*)([KMG])?$@D', $value, $match)) {
                        \ini_set('memory_limit', $value);
                    } else {
                        fwrite(STDERR, "Invalid --memory-limit '$value', ignoring\n");
                    }
                    break;
                case 'print-memory-usage-summary':
                    Config::setValue('print_memory_usage_summary', true);
                    break;
                case 'markdown-issue-messages':
                    Config::setValue('markdown_issue_messages', true);
                    break;
                case 'absolute-path-issue-messages':
                    Config::setValue('absolute_path_issue_messages', true);
                    break;
                case 'color-scheme':
                case 'C':
                case 'color':
                case 'no-color':
                case 'analyze-all-files':
                    // Handled before processing the CLI flag `--help`
                    break;
                case 'save-baseline':
                    if (!is_string($value)) {
                        throw new UsageException("--save-baseline expects a single writeable file", 1);
                    }
                    if (!\is_dir(\dirname($value))) {
                        throw new UsageException("--save-baseline expects a file in a folder that already exists, got path '$value' in folder '" . \dirname($value) . "'", 1);
                    }
                    Config::setValue('__save_baseline_path', $value);
                    break;
                case 'B':
                case 'load-baseline':
                    if (!is_string($value)) {
                        throw new UsageException("--load-baseline expects a single readable file", 1);
                    }
                    if (!\is_file($value)) {
                        throw new UsageException("--load-baseline expects a path to a file, got '$value'", 1);
                    }
                    if (!\is_readable($value)) {
                        throw new UsageException("--load-baseline passed file '$value' which could not be read", 1);
                    }
                    Config::setValue('baseline_path', $value);
                    break;
                case 'baseline-summary-type':
                    if (!is_string($value)) {
                        throw new UsageException("--baseline-summary-type expects 'ordered_by_count', 'ordered_by_count', 'or 'none', but got multiple values", 1);
                    }
                    Config::setValue('baseline_summary_type', $value);
                    break;
                case 'analyze-twice':
                    Config::setValue('__analyze_twice', true);
                    break;
                case 'always-exit-successfully-after-analysis':
                    Config::setValue('__always_exit_successfully_after_analysis', true);
                    break;
                default:
                    // All of phan's long options are currently at least 2 characters long.
                    $key_repr = strlen($key) >= 2 ? "--$key" : "-$key";
                    if ($value === false && in_array($key . ':', self::GETOPT_LONG_OPTIONS, true)) {
                        throw new UsageException("Missing required argument value for '$key_repr'", EXIT_FAILURE);
                    }
                    throw new UsageException("Unknown option '$key_repr'" . self::getFlagSuggestionString($key), EXIT_FAILURE);
            }
        }
        if (isset($opts['language-server-completion-vscode']) && Config::getValue('language_server_enable_completion')) {
            Config::setValue('language_server_enable_completion', Config::COMPLETION_VSCODE);
        }
        if (Config::getValue('color_issue_messages') === null && in_array($printer_type, ['text', 'verbose'], true)) {
            if (Config::getValue('color_issue_messages_if_supported') && self::supportsColor(\STDOUT)) {
                Config::setValue('color_issue_messages', true);
            }
        }
        self::ensureASTParserExists();
        self::checkPluginsExist();
        self::checkValidFileConfig();
        if (\is_null($progress_bar)) {
            if (self::isProgressBarDisabledByDefault()) {
                $progress_bar = false;
            } else {
                $progress_bar = true;
                if (!self::isTerminal(\STDERR)) {
                    Config::setValue('__long_progress_bar', true);
                }
            }
        }
        Config::setValue('progress_bar', $progress_bar);

        $output = $this->output;
        $printer = $factory->getPrinter($printer_type, $output);
        $filter  = new ChainedIssueFilter([
            new FileIssueFilter(new Phan()),
            new MinimumSeverityFilter($minimum_severity),
            new CategoryIssueFilter($mask)
        ]);
        $collector = new BufferingCollector($filter);

        self::checkAllArgsUsed($opts, $argv);

        Phan::setPrinter($printer);
        Phan::setIssueCollector($collector);
        if (!$this->file_list_only) {
            // Merge in any remaining args on the CLI
            $this->file_list_in_config = \array_merge(
                $this->file_list_in_config,
                array_slice($argv, 1)
            );
        }
        if (isset($opts['analyze-all-files'])) {
            Config::setValue('exclude_analysis_directory_list', []);
        }

        $this->recomputeFileList();

        // We can't run dead code detection on multiple cores because
        // we need to update reference lists in a globally accessible
        // way during analysis. With our parallelization mechanism, there
        // is no shared state between processes, making it impossible to
        // have a complete set of reference lists.
        if (Config::getValue('processes') !== 1) {
            if (Config::getValue('dead_code_detection')) {
                throw new AssertionError("We cannot run dead code detection on more than one core.");
            }
        }
        self::checkSaveBaselineOptionsAreValid();
        self::ensureServerRunsSingleAnalysisProcess();
    }

    /**
     * @param list<string> $plugins plugins to add to the plugin list
     */
    private static function addPlugins(array $plugins): void
    {
        Config::setValue(
            'plugins',
            \array_unique(\array_merge(Config::getValue('plugins'), $plugins))
        );
    }

    /**
     * @param list<string> $binaries - various binaries, such as 'php72' and '/usr/bin/php'
     * @throws UsageException
     */
    private static function addPHPBinariesForSyntaxCheck(array $binaries): void
    {
        $resolved_binaries = [];
        foreach ($binaries as $binary) {
            if ($binary === '') {
                throw new UsageException(sprintf("Invalid arguments to --native-syntax-check: args=%s\n", StringUtil::jsonEncode($binaries)), EXIT_FAILURE);
            }
            if (DIRECTORY_SEPARATOR === '\\') {
                $cmd = 'where ' . escapeshellarg($binary);
            } else {
                $cmd = 'command -v ' . escapeshellarg($binary);
            }
            $resolved = trim((string) shell_exec($cmd));
            if ($resolved === '') {
                throw new UsageException(sprintf("Could not find PHP binary for --native-syntax-check: arg=%s\n", StringUtil::jsonEncode($binary)), EXIT_FAILURE);
            }
            if (!is_executable($resolved)) {
                throw new UsageException(sprintf("PHP binary for --native-syntax-check is not executable: arg=%s\n", StringUtil::jsonEncode($binary)), EXIT_FAILURE);
            }
            $resolved_binaries[] = $resolved;
        }
        self::addPlugins(['InvokePHPNativeSyntaxCheckPlugin']);
        $plugin_config = Config::getValue('plugin_config') ?: [];
        $old_resolved_binaries = $plugin_config['php_native_syntax_check_binaries'] ?? [];
        $resolved_binaries = array_values(array_unique(array_merge($old_resolved_binaries, $resolved_binaries)));
        $plugin_config['php_native_syntax_check_binaries'] = $resolved_binaries;
        Config::setValue('plugin_config', $plugin_config);
    }

    /**
     * @param list<string> $argv
     */
    private static function warnSuspiciousShortOptions(array $argv): void
    {
        $opt_set = [];
        foreach (self::GETOPT_LONG_OPTIONS as $opt) {
            $opt_set['-' . \rtrim($opt, ':')] = true;
        }
        foreach (array_slice($argv, 1) as $arg) {
            $arg = \preg_replace('/=.*$/D', '', $arg);
            if (array_key_exists($arg, $opt_set)) {
                self::printHelpSection(
                    "WARNING: Saw suspicious CLI arg '$arg' (did you mean '-$arg')\n",
                    false,
                    true
                );
            }
        }
    }

    /**
     * @param array<string|int,mixed> $opts
     * @throws UsageException if using a flag such as --init-level without --init
     */
    private static function throwIfUsingInitModifiersWithoutInit(array $opts): void
    {
        if (isset($opts['init'])) {
            return;
        }
        $bad_options = [];
        foreach ($opts as $other_key => $_) {
            // -3 is an option, and gets converted to `3` in an array key.
            if (\strncmp((string)$other_key, 'init-', 5) === 0) {
                $bad_options[] = "--$other_key";
            }
        }
        if (count($bad_options) > 0) {
            $option_pluralized = count($bad_options) > 1 ? "options" : "option";
            $make_pluralized = count($bad_options) > 1 ? "make" : "makes";
            throw new UsageException("The $option_pluralized " . \implode(' and ', $bad_options) . " only $make_pluralized sense when initializing a new Phan config with --init", EXIT_FAILURE, UsageException::PRINT_INIT_ONLY);
        }
    }

    /**
     * Configure settings for colorized output for help and issue messages.
     * @param array<string,mixed> $opts
     */
    private static function detectAndConfigureColorSupport(array $opts): void
    {
        if (is_string($opts['color-scheme'] ?? false)) {
            \putenv('PHAN_COLOR_SCHEME=' . $opts['color-scheme']);
        }
        if (isset($opts['C']) || isset($opts['color'])) {
            Config::setValue('color_issue_messages', true);
        } elseif (isset($opts['no-color'])) {
            Config::setValue('color_issue_messages', false);
        } elseif (self::hasNoColorEnv()) {
            Config::setValue('color_issue_messages', false);
        } elseif (getenv('PHAN_ENABLE_COLOR_OUTPUT')) {
            Config::setValue('color_issue_messages_if_supported', true);
        }
    }

    private static function hasNoColorEnv(): bool
    {
        return getenv('PHAN_DISABLE_COLOR_OUTPUT') || getenv('NO_COLOR');
    }

    private static function checkValidFileConfig(): void
    {
        $include_analysis_file_list = Config::getValue('include_analysis_file_list');
        if ($include_analysis_file_list) {
            $valid_files = 0;
            foreach ($include_analysis_file_list as $file) {
                $absolute_path = Config::projectPath($file);
                if (\file_exists($absolute_path)) {
                    $valid_files++;
                } else {
                    \fprintf(
                        STDERR,
                        "%sCould not find file '%s' passed in %s" . PHP_EOL,
                        self::colorizeHelpSectionIfSupported('WARNING: '),
                        $absolute_path,
                        self::colorizeHelpSectionIfSupported('--include-analysis-file-list')
                    );
                }
            }
            if ($valid_files === 0) {
                // TODO convert this to an error in Phan 5.
                $error_message = sprintf(
                    "None of the files to analyze in %s exist - This will be an error in future Phan releases." . PHP_EOL,
                    Config::getProjectRootDirectory()
                );
                CLI::printWarningToStderr($error_message);
            }
        }
    }

    /**
     * Returns true if the output stream supports colors
     *
     * This is tricky on Windows, because Cygwin, Msys2 etc emulate pseudo
     * terminals via named pipes, so we can only check the environment.
     *
     * Reference: Composer\XdebugHandler\Process::supportsColor
     * https://github.com/composer/xdebug-handler
     * (This is internal, so it was duplicated in case their API changed)
     *
     * @param resource $output A valid CLI output stream
     * @suppress PhanUndeclaredFunction
     */
    public static function supportsColor($output): bool
    {
        if (self::isDaemonOrLanguageServer()) {
            return false;
        }
        if ('Hyper' === getenv('TERM_PROGRAM')) {
            return true;
        }
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            return (\function_exists('sapi_windows_vt100_support')
                && \sapi_windows_vt100_support($output))
                || false !== \getenv('ANSICON')
                || 'ON' === \getenv('ConEmuANSI')
                || 'xterm' === \getenv('TERM');
        }

        if (\function_exists('stream_isatty')) {
            return \stream_isatty($output);
        } elseif (\function_exists('posix_isatty')) {
            return \posix_isatty($output);
        }

        $stat = \fstat($output);
        // Check if formatted mode is S_IFCHR
        return $stat ? 0020000 === ($stat['mode'] & 0170000) : false;
    }

    private static function isProgressBarDisabledByDefault(): bool
    {
        if (self::isDaemonOrLanguageServer()) {
            return true;
        }
        if (\getenv('PHAN_DISABLE_PROGRESS_BAR')) {
            return true;
        }
        return false;
    }

    /**
     * Returns true if the output stream is a TTY.
     *
     * @param resource $output A valid CLI output stream
     * @suppress PhanUndeclaredFunction
     */
    private static function isTerminal($output): bool
    {
        if (\defined('PHP_WINDOWS_VERSION_BUILD')) {
            // https://www.php.net/sapi_windows_vt100_support
            // >  By the way, if a stream is redirected, the VT100 feature will not be enabled:
            return (\function_exists('sapi_windows_vt100_support')
                && \sapi_windows_vt100_support($output));
        }

        if (\function_exists('stream_isatty')) {
            return \stream_isatty($output);
        } elseif (\function_exists('posix_isatty')) {
            return \posix_isatty($output);
        }

        $stat = \fstat($output);
        // Check if formatted mode is S_IFCHR
        return $stat ? 0020000 === ($stat['mode'] & 0170000) : false;
    }

    private static function checkPluginsExist(): void
    {
        $all_plugins_exist = true;
        foreach (Config::getValue('plugins') as $plugin_path_or_name) {
            // @phan-suppress-next-line PhanAccessMethodInternal
            $plugin_file_name = ConfigPluginSet::normalizePluginPath($plugin_path_or_name);
            if (!\is_file($plugin_file_name)) {
                if ($plugin_file_name === $plugin_path_or_name) {
                    $details = '';
                } else {
                    $details = ' (Referenced as ' . StringUtil::jsonEncode($plugin_path_or_name) . ')';
                    $details .= self::getPluginSuggestionText($plugin_path_or_name);
                }
                self::printErrorToStderr(sprintf(
                    "Phan %s could not find plugin %s%s\n",
                    CLI::PHAN_VERSION,
                    StringUtil::jsonEncode($plugin_file_name),
                    $details
                ));
                $all_plugins_exist = false;
            }
        }
        if (!$all_plugins_exist) {
            fwrite(STDERR, "Exiting due to invalid plugin config.\n");
            exit(1);
        }
    }

    /**
     * @throws UsageException if the combination of options is invalid
     */
    private static function checkSaveBaselineOptionsAreValid(): void
    {
        if (Config::getValue('__save_baseline_path')) {
            if (Config::getValue('processes') !== 1) {
                // This limitation may be fixed in a subsequent release.
                throw new UsageException("--save-baseline is not supported in combination with --processes", 1);
            }
            if (self::isDaemonOrLanguageServer()) {
                // This will never be supported
                throw new UsageException("--save-baseline does not make sense to use in Daemon mode or as a language server.", 1);
            }
        }
    }

    private static function ensureServerRunsSingleAnalysisProcess(): void
    {
        if (!self::isDaemonOrLanguageServer()) {
            return;
        }
        // If the client has multiple files open at once (and requests analysis of multiple files),
        // then there there would be multiple processes doing analysis.
        //
        // This would not work with Phan's current design - the socket used by the daemon can only be used by one process.
        // Also, the implementation of some requests such as "Go to Definition", "Find References" (planned), etc. assume Phan runs as a single process.
        $processes = Config::getValue('processes');
        if ($processes !== 1) {
            \fprintf(STDERR, "Notice: Running with processes=1 instead of processes=%s - the daemon/language server assumes it will run as a single process" . PHP_EOL, (string)\json_encode($processes));
            Config::setValue('processes', 1);
        }
        if (Config::getValue('__analyze_twice')) {
            \fwrite(STDERR, "Notice: Running analysis phase once instead of --analyze-twice - the daemon/language server assumes it will run as a single process" . PHP_EOL);
            Config::setValue('__analyze_twice', false);
        }
    }

    /**
     * @internal (visible for tests)
     */
    public static function getPluginSuggestionText(string $plugin_path_or_name): string
    {
        $plugin_dirname = ConfigPluginSet::getBuiltinPluginDirectory();
        $candidates = [];
        foreach (\scandir($plugin_dirname) as $basename) {
            if (\substr($basename, -4) !== '.php') {
                continue;
            }
            $plugin_name = \substr($basename, 0, -4);
            $candidates[$plugin_name] = $plugin_name;
        }
        $suggestions = IssueFixSuggester::getSuggestionsForStringSet($plugin_path_or_name, $candidates);
        if (!$suggestions) {
            return '';
        }
        return ' (Did you mean ' . \implode(' or ', $suggestions) . '?)';
    }

    /**
     * Recompute the list of files (used in daemon mode or language server mode)
     */
    public function recomputeFileList(): void
    {
        $this->file_list = $this->file_list_in_config;

        if (!$this->file_list_only) {
            // Merge in any files given in the config
            /** @var list<string> */
            $this->file_list = \array_merge(
                $this->file_list,
                Config::getValue('file_list')
            );

            // Merge in any directories given in the config
            foreach (Config::getValue('directory_list') as $directory_name) {
                $this->file_list = \array_merge(
                    $this->file_list,
                    self::directoryNameToFileList($directory_name)
                );
            }

            // Don't scan anything twice
            $this->file_list = self::uniqueFileList($this->file_list);
        }

        // Exclude any files that should be excluded from
        // parsing and analysis (not read at all)
        if (count(Config::getValue('exclude_file_list')) > 0) {
            $exclude_file_set = [];
            foreach (Config::getValue('exclude_file_list') as $file) {
                $normalized_file = \str_replace('\\', '/', $file);
                $exclude_file_set[$normalized_file] = true;
                $exclude_file_set["./$normalized_file"] = true;
            }

            $this->file_list = \array_values(\array_filter(
                $this->file_list,
                static function (string $file) use ($exclude_file_set): bool {
                    // Handle edge cases such as 'mydir/subdir\subsubdir' on Windows, if mydir/subdir was in the Phan config.
                    return !isset($exclude_file_set[\str_replace('\\', '/', $file)]);
                }
            ));
        }
    }

    /**
     * @param string[] $file_list
     * @return list<string> $file_list without duplicates
     */
    public static function uniqueFileList(array $file_list): array
    {
        $result = [];
        foreach ($file_list as $file) {
            // treat src/a.php, src//a.php, and src\a.php (on Windows) as the same file
            $file_key = \preg_replace('@/{2,}@', '/', \str_replace(\DIRECTORY_SEPARATOR, '/', $file));
            if (!isset($result[$file_key])) {
                $result[$file_key] = $file;
            }
        }
        return \array_values($result);
    }

    /**
     * @return void - exits on usage error
     * @throws UsageException
     */
    private static function checkCanDaemonize(string $protocol, string $opt): void
    {
        $opt = strlen($opt) >= 2 ? "--$opt" : "-$opt";
        if (!in_array($protocol, \stream_get_transports(), true)) {
            throw new UsageException("The $protocol:///path/to/file schema is not supported on this system, cannot create a daemon with $opt", 1);
        }
        if (!Config::getValue('language_server_use_pcntl_fallback') && !\function_exists('pcntl_fork')) {
            throw new UsageException("The pcntl extension is not available to fork a new process, so $opt will not be able to create workers to respond to requests.", 1);
        }
        if ($opt === '--daemonize-socket' && Config::getValue('daemonize_tcp')) {
            throw new UsageException('Can specify --daemonize-socket or --daemonize-tcp-port only once', 1);
        } elseif (($opt === '--daemonize-tcp-host' || $opt === '--daemonize-tcp-port') && Config::getValue('daemonize_socket')) {
            throw new UsageException("Can specify --daemonize-socket or $opt only once", 1);
        }
    }

    /**
     * @return list<string>
     * Get the set of files to analyze
     */
    public function getFileList(): array
    {
        return $this->file_list;
    }

    public const INIT_HELP = <<<'EOT'
 --init
   [--init-level=3]
   [--init-analyze-dir=path/to/src]
   [--init-analyze-file=path/to/file.php]
   [--init-no-composer]

  Generates a `.phan/config.php` in the current directory
  based on the project's composer.json.
  The logic used to generate the config file is currently very simple.
  Some third party classes (e.g. in vendor/)
  will need to be manually added to 'directory_list' or excluded,
  and you may end up with a large number of issues to be manually suppressed.
  See https://github.com/phan/phan/wiki/Tutorial-for-Analyzing-a-Large-Sloppy-Code-Base

  [--init-level <level>] affects the generated settings in `.phan/config.php`
    (e.g. null_casts_as_array).
    `--init-level` can be set to 1 (strictest) to 5 (least strict)
  [--init-analyze-dir <dir>] can be used as a relative path alongside directories
    that Phan infers from composer.json's "autoload" settings
  [--init-analyze-file <file>] can be used as a relative path alongside files
    that Phan infers from composer.json's "bin" settings
  [--init-no-composer] can be used to tell Phan that the project
    is not a composer project.
    Phan will not check for composer.json or vendor/,
    and will not include those paths in the generated config.
  [--init-overwrite] will allow 'phan --init' to overwrite .phan/config.php.

EOT;

    // FIXME: If I stop using defined() in UnionTypeVisitor,
    // this will warn about the undefined constant EXIT_SUCCESS when a
    // user-defined constant is used in parse phase in a function declaration
    /**
     * Print usage message to stdout.
     * @internal
     */
    public static function usage(string $msg = '', ?int $exit_code = EXIT_SUCCESS, int $usage_type = UsageException::PRINT_NORMAL, bool $forbid_color = true): void
    {
        global $argv;

        if ($msg !== '') {
            self::printHelpSection("ERROR:");
            self::printHelpSection(" $msg\n", $forbid_color);
        }

        $init_help = self::INIT_HELP;
        echo "Usage: {$argv[0]} [options] [files...]\n";
        if ($usage_type === UsageException::PRINT_INVALID_ARGS) {
            self::printHelpSection("Type {$argv[0]} --help (or --extended-help) for usage.\n");
            if ($exit_code === null) {
                return;
            }
            exit($exit_code);
        }
        if ($usage_type === UsageException::PRINT_INIT_ONLY) {
            self::printHelpSection($init_help . "\n");
            if ($exit_code === null) {
                return;
            }
            exit($exit_code);
        }
        self::printHelpSection(
            <<<EOB
 -f, --file-list <filename>
  A file containing a list of PHP files to be analyzed

 -l, --directory <directory>
  A directory that should be parsed for class and
  method information. After excluding the directories
  defined in --exclude-directory-list, the remaining
  files will be statically analyzed for errors.

  Thus, both first-party and third-party code being used by
  your application should be included in this list.

  You may include multiple `--directory <directory>` options.

 --exclude-file <file>
  A file that should not be parsed or analyzed (or read
  at all). This is useful for excluding hopelessly
  unanalyzable files.

 -3, --exclude-directory-list <dir_list>
  A comma-separated list of directories that defines files
  that will be excluded from static analysis, but whose
  class and method information should be included.
  (can be repeated, ignored if --include-analysis-file-list is used)

  Generally, you'll want to include the directories for
  third-party code (such as "vendor/") in this list.

 -I, --include-analysis-file-list <file_list>
  A comma-separated list of files that will be included in
  static analysis. All others won't be analyzed.
  (can be repeated)

  This is primarily intended for performing standalone
  incremental analysis.

 -d, --project-root-directory </path/to/project>
  The directory of the project to analyze.
  Phan expects this directory to contain the configuration file `.phan/config.php`.
  If not provided, the current working directory is analyzed.

 -r, --file-list-only <file>
  A file containing a list of PHP files to be analyzed to the
  exclusion of any other directories or files passed in. This
  is unlikely to be useful.

 -k, --config-file <file>
  A path to a config file to load (instead of the default of
  `.phan/config.php`).

 -m, --output-mode <mode>
  Output mode from 'text', 'verbose', 'json', 'csv', 'codeclimate', 'checkstyle', 'pylint', or 'html'

 -o, --output <filename>
  Output filename

$init_help
 -C, --color, --no-color
  Add colors to the outputted issues.
  This is recommended for only --output-mode=text (the default) and 'verbose'

  [--color-scheme={default,code,eclipse_dark,vim,light,light_high_contrast}]
    This (or the environment variable PHAN_COLOR_SCHEME) can be used to set the color scheme for emitted issues.

 -p, --progress-bar, --no-progress-bar, --long-progress-bar
  Show progress bar. --no-progress-bar disables the progress bar.
  --long-progress-bar shows a progress bar that doesn't overwrite the current line.

 -D, --debug
  Print debugging output to stderr. Useful for looking into performance issues or crashes.

 -q, --quick
  Quick mode - doesn't recurse into all function calls

 -b, --backward-compatibility-checks
  Check for potential PHP 5 -> PHP 7 BC issues

 --target-php-version {5.6,7.0,7.1,7.2,7.3,7.4,8.0,8.1,native}
  The PHP version that the codebase will be checked for compatibility against.
  For best results, the PHP binary used to run Phan should have the same PHP version.
  (Phan relies on Reflection for some param counts
   and checks for undefined classes/methods/functions)

 --minimum-target-php-version {5.6,7.0,7.1,7.2,7.3,7.4,8.0,8.1,native}
  The PHP version that will be used for feature/syntax compatibility warnings.

 -i, --ignore-undeclared
  Ignore undeclared functions and classes

 -y, --minimum-severity <level>
  Minimum severity level (low=0, normal=5, critical=10) to report.
  Defaults to `--minimum-severity 0` (i.e. `--minimum-severity low`)

 -c, --parent-constructor-required
  Comma-separated list of classes that require
  parent::__construct() to be called

 -x, --dead-code-detection
  Emit issues for classes, methods, functions, constants and
  properties that are probably never referenced and can
  be removed. This implies `--unused-variable-detection`.

 -u, --unused-variable-detection
  Emit issues for variables, parameters and closure use variables
  that are probably never referenced.
  This has a few known false positives, e.g. for loops or branches.

 -t, --redundant-condition-detection
  Emit issues for conditions such as `is_int(expr)` that are redundant or impossible.

  This has some known false positives for loops, variables set in loops,
  and global variables.

 -j, --processes <int>
  The number of parallel processes to run during the analysis
  phase. Defaults to 1.

 -z, --signature-compatibility
  Analyze signatures for methods that are overrides to ensure
  compatibility with what they're overriding.

 --disable-cache
  Don't cache any ASTs from the polyfill/fallback.

  ASTs from the native parser (php-ast) don't need to be cached.

  This is useful if Phan will be run only once and php-ast is unavailable (e.g. in Travis)

 --disable-plugins
  Don't run any plugins. Slightly faster.

 -P, --plugin <pluginName|path/to/Plugin.php>
  Add a plugin to run. This flag can be repeated.
  (Either pass the name of the plugin or a relative/absolute path to the plugin)

 --strict-method-checking
  Warn if any type in a method invocation's object is definitely not an object,
  or any type in an invoked expression is not a callable.
  (Enables the config option `strict_method_checking`)

 --strict-param-checking
  Warn if any type in an argument's union type cannot be cast to
  the parameter's expected union type.
  (Enables the config option `strict_param_checking`)

 --strict-property-checking
  Warn if any type in a property assignment's union type
  cannot be cast to a type in the property's declared union type.
  (Enables the config option `strict_property_checking`)

 --strict-object-checking
  Warn if any type of the object expression for a property access
  does not contain that property.
  (Enables the config option `strict_object_checking`)

 --strict-return-checking
  Warn if any type in a returned value's union type
  cannot be cast to the declared return type.
  (Enables the config option `strict_return_checking`)

 -S, --strict-type-checking
  Equivalent to
  `--strict-method-checking --strict-object-checking --strict-param-checking --strict-property-checking --strict-return-checking`.

 --use-fallback-parser
  If a file to be analyzed is syntactically invalid
  (i.e. "php --syntax-check path/to/file" would emit a syntax error),
  then retry, using a different, slower error tolerant parser to parse it.
  (And phan will then analyze what could be parsed).
  This flag is experimental and may result in unexpected exceptions or errors.
  This flag does not affect excluded files and directories.

 --allow-polyfill-parser
  If the `php-ast` extension isn't available or is an outdated version,
  then use a slower parser (based on tolerant-php-parser) instead.
  Note that https://github.com/Microsoft/tolerant-php-parser
  has some known bugs which may result in false positive parse errors.

 --force-polyfill-parser
  Use a slower parser (based on tolerant-php-parser) instead of the native parser,
  even if the native parser is available.
  Useful mainly for debugging.

 -s, --daemonize-socket </path/to/file.sock>
  Unix socket for Phan to listen for requests on, in daemon mode.

 --daemonize-tcp-host <hostname>
  TCP hostname for Phan to listen for JSON requests on, in daemon mode.
  (e.g. `default`, which is an alias for host `127.0.0.1`, or `0.0.0.0` for
  usage with Docker). `phan_client` can be used to communicate with the Phan Daemon.

 --daemonize-tcp-port <default|1024-65535>
  TCP port for Phan to listen for JSON requests on, in daemon mode.
  (e.g. `default`, which is an alias for port 4846.)
  `phan_client` can be used to communicate with the Phan Daemon.

 --save-baseline <path/to/baseline.php>
  Generates a baseline of pre-existing issues that can be used to suppress
  pre-existing issues in subsequent runs (with --load-baseline)

  This baseline depends on the environment, CLI and config settings used to run Phan
  (e.g. --dead-code-detection, plugins, etc.)

  Paths such as .phan/baseline.php, .phan/baseline_deadcode.php, etc. are recommended.

 -B, --load-baseline <path/to/baseline.php>
  Loads a baseline of pre-existing issues to suppress.

  (For best results, the baseline should be generated with the same/similar
  environment and settings as those used to run Phan)

 --analyze-twice
  Runs the analyze phase twice. Because Phan gathers additional type information for properties, return types, etc. during analysis,
  this may emit a more complete list of issues.

  This cannot be used with --processes <int>.

 -v, --version
  Print Phan's version number

 -h, --help
  This help information

 --extended-help
  This help information, plus less commonly used flags
  (E.g. for daemon mode)

EOB
            ,
            $forbid_color
        );
        if ($usage_type === UsageException::PRINT_EXTENDED) {
            self::printHelpSection(
                <<<EOB

Extended help:
 -a, --dump-ast
  Emit an AST for each file rather than analyze.

 --dump-parsed-file-list
  Emit a newline-separated list of files Phan would parse to stdout.
  This is useful to verify that options such as exclude_file_regex are
  properly set up, or to run other checks on the files Phan would parse.

 --dump-analyzed-file-list
  Emit a newline-separated list of files Phan would analyze to stdout.

 --dump-signatures-file <filename>
  Emit JSON serialized signatures to the given file.
  This uses a method signature format similar to FunctionSignatureMap.php.

 --dump-ctags=basic
  Dump a ctags file to <project root>/tags using the parsed and analyzed files
  in the Phan config.
  Currently, this only dumps classes/constants/functions/properties,
  and not variable definitions.
  This should be used with --quick, and can't be used with --processes <int>.

 --always-exit-successfully-after-analysis
  Always exit with an exit code of 0, even if unsuppressed issues were emitted.
  This helps in checking if Phan crashed.

 --automatic-fix
  Automatically fix any issues Phan is capable of fixing.
  NOTE: This is a work in progress and limited to a small subset of issues
  (e.g. unused imports on their own line)

 --force-polyfill-parser-with-original-tokens
  Force tracking the original tolerant-php-parser and tokens in every node
  generated by the polyfill as `\$node->tolerant_ast_node`, where possible.
  This is slower and more memory intensive.
  Official or third-party plugins implementing functionality such as
  `--automatic-fix` may end up requiring this,
  because the original tolerant-php-parser node contains the original formatting
  and token locations.

 --find-signature <paramUnionType1->paramUnionType2->returnUnionType>
  Find a signature in the analyzed codebase that is similar to the argument.
  See `tool/phoogle` for examples.

 --memory-limit <memory_limit>
  Sets the memory limit for analysis (per process).
  This is useful when developing or when you want guarantees on memory limits.
  K, M, and G are optional suffixes (Kilobytes, Megabytes, Gigabytes).

 --print-memory-usage-summary
  Prints a summary of memory usage and maximum memory usage.
  This is accurate when there is one analysis process.

 --markdown-issue-messages
  Emit issue messages with markdown formatting.

 --absolute-path-issue-messages
  Emit issues with their absolute paths instead of relative paths.
  This does not affect files mentioned within the issue.

 --analyze-all-files
  Ignore the --exclude-directory-list <dir_list> flag and `exclude_analysis_directory_list` config settings and analyze all files that were parsed.
  This is slow, but useful when third-party files being parsed have incomplete type information.
  Also see --analyze-twice.

 --constant-variable-detection
  Emit issues for variables that could be replaced with literals or constants.
  (i.e. they are declared once (as a constant expression) and never modified).
  This is almost entirely false positives for most coding styles.
  Implies --unused-variable-detection

 -X, --dead-code-detection-prefer-false-positive
  When performing dead code detection, prefer emitting false positives
  (reporting dead code that is not actually dead) over false negatives
  (failing to report dead code). This implies `--dead-code-detection`.

 --debug-emitted-issues={basic,verbose}
  Print backtraces of emitted issues which weren't suppressed to stderr.

 --debug-signal-handler
  Set up a signal handler that can handle interrupts, SIGUSR1, and SIGUSR2.
  This requires pcntl, and slows down Phan. When this option is enabled,

  Ctrl-C (kill -INT <pid>) can be used to make Phan stop and print a crash report.
  (This is useful for diagnosing why Phan or a plugin is slow or not responding)
  kill -USR1 <pid> can be used to print a backtrace and continue running.
  kill -USR2 <pid> can be used to print a backtrace, plus values of parameters, and continue running.

 --baseline-summary-type={ordered_by_count,ordered_by_type,none}
  Configures the summary comment generated by --save-baseline. Does not affect analysis.

 --language-server-on-stdin
  Start the language server (For the Language Server protocol).
  This is a different protocol from --daemonize, clients for various IDEs already exist.

 --language-server-tcp-server <addr>
  Start the language server listening for TCP connections on <addr> (e.g. 127.0.0.1:<port>)

 --language-server-tcp-connect <addr>
  Start the language server and connect to the client listening on <addr> (e.g. 127.0.0.1:<port>)

 --language-server-analyze-only-on-save
  Prevent the client from sending change notifications (Only notify the language server when the user saves a document)
  This significantly reduces CPU usage, but clients won't get notifications about issues immediately.

 --language-server-disable-go-to-definition, --language-server-enable-go-to-definition
  Disables/Enables support for "Go To Definition" and "Go To Type Definition" in the Phan Language Server.
  Enabled by default.

 --language-server-disable-hover, --language-server-enable-hover
  Disables/Enables support for "Hover" in the Phan Language Server.
  Enabled by default.

 --language-server-disable-completion, --language-server-enable-completion
  Disables/Enables support for "Completion" in the Phan Language Server.
  Enabled by default.

 --language-server-completion-vscode
  Adds a workaround to make completion of variables and static properties
  that are compatible with language clients such as VS Code.

 --language-server-verbose
  Emit verbose logging messages related to the language server implementation to stderr.
  This is useful when developing or debugging language server clients.

 --language-server-disable-output-filter
  Emit all issues detected from the language server (e.g. invalid phpdoc in parsed files),
  not just issues in files currently open in the editor/IDE.
  This can be very verbose and has more false positives.

  This is useful when developing or debugging language server clients.

 --language-server-allow-missing-pcntl
  No-op (This is the default behavior).
  Allow the fallback that doesn't use pcntl (New and experimental) to be used if the pcntl extension is not installed.
  This is useful for running the language server on Windows.

 --language-server-hide-category
  Remove the Phan issue category from diagnostic messages.
  Makes issue messages slightly shorter.

 --language-server-force-missing-pcntl
  Force Phan to use the fallback for when pcntl is absent (New and experimental). Useful for debugging that fallback.

 --language-server-require-pcntl
  Don't start the language server if PCNTL isn't installed (don't use the fallback). Useful for debugging.

 --native-syntax-check </path/to/php_binary>
  If php_binary (e.g. `php72`, `/usr/bin/php`) can be found in `\$PATH`, enables `InvokePHPNativeSyntaxCheckPlugin`
  and adds `php_binary` (resolved using `\$PATH`) to the `php_native_syntax_check_binaries` array of `plugin_config`
  (treated here as initially being the empty array)
  Phan exits if any php binary could not be found.

  This can be repeated to run native syntax checks with multiple php versions.

 --require-config-exists
  Exit immediately with an error code if `.phan/config.php` does not exist.

 --help-annotations
  Print details on annotations supported by Phan.

EOB
                ,
                $forbid_color
            );
        }
        if ($exit_code === null) {
            return;
        }
        exit($exit_code);
    }

    /**
     * Prints a warning to stderr (except for the label, nothing else is colorized).
     * This clears the progress bar if needed.
     *
     * NOTE: Callers should usually add a trailing newline.
     */
    public static function printWarningToStderr(string $message): void
    {
        self::printToStderr(self::colorizeHelpSectionIfSupported('WARNING: ') . $message);
    }

    /**
     * Prints an error to stderr (except for the label, nothing else is colorized).
     * This clears the progress bar if needed.
     *
     * NOTE: Callers should usually add a trailing newline.
     */
    public static function printErrorToStderr(string $message): void
    {
        self::printToStderr(self::colorizeHelpSectionIfSupported('ERROR: ') . $message);
    }

    /**
     * Prints to stderr, clearing the progress bar if needed.
     * NOTE: Callers should usually add a trailing newline.
     */
    public static function printToStderr(string $message): void
    {
        if (self::shouldClearStderrBeforePrinting()) {
            // http://ascii-table.com/ansi-escape-sequences.php
            // > Clears all characters from the cursor position to the end of the line (including the character at the cursor position).
            $message = "\033[2K" . $message;
        }
        if (\defined('STDERR')) {
            fwrite(STDERR, $message);
        } else {
            // Fallback in case Phan runs interactively or in non-CLI SAPIs.
            // This is incomplete.
            echo $message;
        }
    }

    /**
     * Check if the progress bar should be cleared.
     */
    private static function shouldClearStderrBeforePrinting(): bool
    {
        // Don't clear if a regular progress bar isn't being rendered.
        if (!CLI::shouldShowProgress()) {
            return false;
        }
        if (CLI::shouldShowLongProgress() || CLI::shouldShowDebugOutput()) {
            return false;
        }
        // @phan-suppress-next-line PhanUndeclaredFunction
        if (\function_exists('sapi_windows_vt100_support') && !\sapi_windows_vt100_support(STDERR)) {
            return false;
        }
        return true;
    }

    /**
     * Prints a section of the help or usage message to stdout.
     * @internal
     */
    public static function printHelpSection(string $section, bool $forbid_color = false, bool $toStderr = false): void
    {
        if (!$forbid_color) {
            $section = self::colorizeHelpSectionIfSupported($section);
        }
        if ($toStderr) {
            CLI::printToStderr($section);
        } else {
            echo $section;
        }
    }

    /**
     * Add ansi color codes to the CLI flags included in the --help or --extended-help message,
     * but only if the CLI/config flags and environment supports it.
     */
    public static function colorizeHelpSectionIfSupported(string $section): string
    {
        if (Config::getValue('color_issue_messages') ?? (!self::hasNoColorEnv() && \defined('STDOUT') && self::supportsColor(\STDOUT))) {
            $section = self::colorizeHelpSection($section);
        }
        return $section;
    }

    /**
     * Add ansi color codes to the CLI flags included in the --help or --extended-help message.
     */
    public static function colorizeHelpSection(string $section): string
    {
        $colorize_flag_cb = /** @param list<string> $match */ static function (array $match): string {
            [$_, $prefix, $cli_flag, $suffix] = $match;
            $colorized_cli_flag = Colorizing::colorizeTextWithColorCode(Colorizing::STYLES['green'], $cli_flag);
            return $prefix . $colorized_cli_flag . $suffix;
        };
        $long_flag_regex = '(()((?:--)(?:' . \implode('|', array_map(static function (string $option): string {
            return \preg_quote(\rtrim($option, ':'));
        }, self::GETOPT_LONG_OPTIONS)) . '))([^\w-]|$))';
        $section = \preg_replace_callback($long_flag_regex, $colorize_flag_cb, $section);
        $short_flag_regex = '((\s|\b|\')(-[' . \str_replace(':', '', self::GETOPT_SHORT_OPTIONS) . '])([^\w-]))';

        $section = \preg_replace_callback($short_flag_regex, $colorize_flag_cb, $section);

        $colorize_opt_cb = /** @param list<string> $match */ static function (array $match): string {
            $cli_flag = $match[0];
            return Colorizing::colorizeTextWithColorCode(Colorizing::STYLES['yellow'], $cli_flag);
        };
        $section = \preg_replace_callback('@<\S+>|\{\S+\}@', $colorize_opt_cb, $section);
        $section = \preg_replace('@^ERROR:@', Colorizing::colorizeTextWithColorCode(Colorizing::STYLES['light_red'], '\0'), $section);
        $section = \preg_replace('@^WARNING:@', Colorizing::colorizeTextWithColorCode(Colorizing::STYLES['yellow'], '\0'), $section);
        return $section;
    }

    /**
     * Finds potentially misspelled flags and returns them as a string
     *
     * This will use levenshtein distance, showing the first one or two flags
     * which match with a distance of <= 5
     *
     * @param string $key Misspelled key to attempt to correct
     * @param string $short_options_string
     * @param list<string> $long_options
     * @internal
     */
    public static function getFlagSuggestionString(
        string $key,
        string $short_options_string = self::GETOPT_SHORT_OPTIONS,
        array $long_options = self::GETOPT_LONG_OPTIONS
    ): string {
        $trim = static function (string $s): string {
            return \rtrim($s, ':');
        };
        $generate_suggestion = static function (string $suggestion): string {
            return (strlen($suggestion) === 1 ? '-' : '--') . $suggestion;
        };
        $generate_suggestion_text = static function (string $suggestion, string ...$other_suggestions) use ($generate_suggestion): string {
            $suggestions = \array_merge([$suggestion], $other_suggestions);
            return ' (did you mean ' . \implode(' or ', array_map($generate_suggestion, $suggestions)) . '?)';
        };
        $short_options = \array_filter(array_map($trim, \str_split($short_options_string)));
        if (strlen($key) === 1) {
            if (in_array($key, $short_options, true)) {
                return $generate_suggestion_text($key);
            }
            $alternate = \ctype_lower($key) ? \strtoupper($key) : \strtolower($key);
            if (in_array($alternate, $short_options, true)) {
                return $generate_suggestion_text($alternate);
            }
            return '';
        } elseif ($key === '') {
            return '';
        } elseif (strlen($key) > 255) {
            // levenshtein refuses to compute for longer strings
            return '';
        }
        // include short options in case a typo is made like -aa instead of -a
        $known_flags = \array_merge($long_options, $short_options);

        $known_flags = array_map($trim, $known_flags);

        $similarities = [];

        $key_lower = \strtolower($key);
        foreach ($known_flags as $flag) {
            if (strlen($flag) === 1 && \stripos($key, $flag) === false) {
                // Skip over suggestions of flags that have no common characters
                continue;
            }
            $distance = \levenshtein($key_lower, \strtolower($flag));
            // distance > 5 is too far off to be a typo
            // Make sure that if two flags have the same distance, ties are sorted alphabetically
            if ($distance > 5) {
                continue;
            }
            if ($key === $flag) {
                if (in_array($key . ':', $long_options, true)) {
                    return " (This option is probably missing the required value. Or this option may not apply to a regular Phan analysis, and/or it may be unintentionally unhandled in \Phan\CLI::__construct())";
                } else {
                    return " (This option may not apply to a regular Phan analysis, and/or it may be unintentionally unhandled in \Phan\CLI::__construct())";
                }
            }
            $similarities[$flag] = [$distance, "x" . \strtolower($flag), $flag];
        }

        \asort($similarities); // retain keys and sort descending
        $similarity_values = \array_values($similarities);

        if (count($similarity_values) >= 2 && ($similarity_values[1][0] <= $similarity_values[0][0] + 1)) {
            // If the next-closest suggestion isn't close to as similar as the closest suggestion, just return the closest suggestion
            return $generate_suggestion_text($similarity_values[0][2], $similarity_values[1][2]);
        } elseif (count($similarity_values) >= 1) {
            return $generate_suggestion_text($similarity_values[0][2]);
        }
        return '';
    }

    /**
     * Checks if a file (not a folder) which has potentially not yet been created on disk should be parsed.
     * @param string $file_path a relative path to a file within the project
     */
    public static function shouldParse(string $file_path): bool
    {
        $exclude_file_regex = Config::getValue('exclude_file_regex');
        if ($exclude_file_regex && self::isPathMatchedByRegex($exclude_file_regex, $file_path)) {
            return false;
        }
        $file_extensions = Config::getValue('analyzed_file_extensions');

        if (!\is_array($file_extensions) || count($file_extensions) === 0) {
            return false;
        }
        $extension = \pathinfo($file_path, \PATHINFO_EXTENSION);
        if (!is_string($extension) || !in_array($extension, $file_extensions, true)) {
            return false;
        }

        $directory_regex = Config::getValue('__directory_regex');
        return $directory_regex && \preg_match($directory_regex, $file_path) > 0;
    }

    /**
     * @param string $directory_name
     * The name of a directory to scan for files ending in `.php`.
     *
     * @return list<string>
     * A list of PHP files in the given directory
     *
     * @throws InvalidArgumentException
     * if there is nothing to analyze
     */
    private static function directoryNameToFileList(
        string $directory_name
    ): array {
        $file_list = [];

        try {
            $file_extensions = Config::getValue('analyzed_file_extensions');

            if (!\is_array($file_extensions) || count($file_extensions) === 0) {
                throw new InvalidArgumentException(
                    'Empty list in config analyzed_file_extensions. Nothing to analyze.'
                );
            }

            $exclude_file_regex = Config::getValue('exclude_file_regex');
            $filter_folder_or_file = /** @param mixed $unused_key */ static function (SplFileInfo $file_info, $unused_key, \RecursiveIterator $iterator) use ($file_extensions, $exclude_file_regex): bool {
                try {
                    if (\in_array($file_info->getBaseName(), ['.', '..'], true)) {
                        // Exclude '.' and '..'
                        return false;
                    }
                    if ($file_info->isDir()) {
                        if (!$iterator->hasChildren()) {
                            return false;
                        }
                        // Compare exclude_file_regex against the relative path of the folder within the project
                        // (E.g. src/subfolder/)
                        if ($exclude_file_regex && self::isPathMatchedByRegex($exclude_file_regex, $file_info->getPathname() . '/')) {
                            // E.g. for phan itself, excludes vendor/psr/log/Psr/Log/Test and vendor/symfony/console/Tests
                            return false;
                        }

                        return true;
                    }

                    if (!in_array($file_info->getExtension(), $file_extensions, true)) {
                        return false;
                    }
                    if (!$file_info->isFile()) {
                        // Handle symlinks to invalid real paths
                        $file_path = $file_info->getRealPath() ?: $file_info->__toString();
                        CLI::printErrorToStderr("Unable to read file $file_path: SplFileInfo->isFile() is false for SplFileInfo->getType() == " . \var_representation(self::getSplFileInfoType($file_info)) . "\n");
                        return false;
                    }
                    if (!$file_info->isReadable()) {
                        $file_path = $file_info->getRealPath();
                        CLI::printErrorToStderr("Unable to read file $file_path: SplFileInfo->isReadable() is false, getPerms()=" . sprintf("%o(octal)", @$file_info->getPerms()) . "\n");
                        return false;
                    }

                    // Compare exclude_file_regex against the relative path within the project
                    // (E.g. src/foo.php)
                    if ($exclude_file_regex && self::isPathMatchedByRegex($exclude_file_regex, $file_info->getPathname())) {
                        return false;
                    }
                } catch (Exception $e) {
                    CLI::printErrorToStderr(sprintf("Unexpected error checking if %s should be parsed: %s %s\n", $file_info->getPathname(), \get_class($e), $e->getMessage()));
                    return false;
                }

                return true;
            };
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator(
                        $directory_name,
                        \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                    ),
                    $filter_folder_or_file
                )
            );

            $file_list = \array_keys(\iterator_to_array($iterator));
        } catch (Exception $exception) {
            CLI::printWarningToStderr("Caught exception while listing files in '$directory_name': {$exception->getMessage()}\n");
        }

        // Normalize leading './' in paths.
        $normalized_file_list = [];
        foreach ($file_list as $file_path) {
            $file_path = \preg_replace('@^(\.[/\\\\]+)+@', '', $file_path);
            // Treat src/file.php and src//file.php and src\file.php the same way
            $normalized_file_list[\preg_replace("@[/\\\\]+@", "\0", $file_path)] = $file_path;
        }
        \uksort($normalized_file_list, 'strcmp');
        return \array_values($normalized_file_list);
    }

    private static function getSplFileInfoType(SplFileInfo $info): string
    {
        try {
            return @$info->getType();
        } catch (Exception $e) {
            return "(unknown: {$e->getMessage()})";
        }
    }

    /**
     * Returns true if the progress bar was requested and it makes sense to display.
     */
    public static function shouldShowProgress(): bool
    {
        return (Config::getValue('progress_bar') || Config::getValue('debug_output')) &&
            !Config::getValue('dump_ast') &&
            !self::isDaemonOrLanguageServer();
    }

    /**
     * Returns true if the long version of the progress bar should be shown.
     * Precondition: shouldShowProgress is true.
     */
    public static function shouldShowLongProgress(): bool
    {
        return Config::getValue('__long_progress_bar');
    }

    /**
     * Returns true if this is a daemon or language server responding to requests
     */
    public static function isDaemonOrLanguageServer(): bool
    {
        return Config::getValue('daemonize_tcp') ||
            Config::getValue('daemonize_socket') ||
            Config::getValue('language_server_config');
    }

    /**
     * Should this show --debug output
     */
    public static function shouldShowDebugOutput(): bool
    {
        return Config::getValue('debug_output') && !self::isDaemonOrLanguageServer();
    }

    /**
     * Check if a path name is excluded by regex, in a platform independent way.
     * Normalizes $path_name on Windows so that '/' is always the directory separator.
     *
     * @param string $exclude_file_regex - PHP regex
     * @param string $path_name - path name within project, beginning with user-provided directory name.
     *                            On windows, may contain '\'.
     *
     * @return bool - True if the user's configured regex is meant to exclude $path_name
     */
    public static function isPathMatchedByRegex(
        string $exclude_file_regex,
        string $path_name
    ): bool {
        // Make this behave the same way on Linux/Unix and on Windows.
        if (DIRECTORY_SEPARATOR === '\\') {
            $path_name = \str_replace(DIRECTORY_SEPARATOR, '/', $path_name);
        }
        return \preg_match($exclude_file_regex, $path_name) > 0;
    }

    // Bound the percentage to [0, 1]
    private static function boundPercentage(float $p): float
    {
        return \min(\max($p, 0.0), 1.0);
    }

    /**
     * Update a progress bar on the screen
     *
     * @param string $msg
     * A short message to display with the progress
     * meter
     *
     * @param float $p
     * The percentage to display
     *
     * @param ?(string|FQSEN|AddressableElement) $details
     * Details about what is being analyzed within the phase for $msg
     *
     * @param ?int $offset
     * The index of this event in the list of events that will be emitted
     *
     * @param ?int $count
     * The number of events in the list.
     * This is constant for 'parse' and 'analyze' phases, but may change for other phases.
     */
    public static function progress(
        string $msg,
        float $p,
        $details = null,
        ?int $offset = null,
        ?int $count = null
    ): void {
        if ($msg !== self::$current_progress_state_any) {
            self::$current_progress_state_any = $msg;
            Type::handleChangeCurrentProgressState($msg);
        }
        if (self::shouldShowDebugOutput()) {
            self::debugProgress($msg, $p, $details);
            return;
        }
        if (!self::shouldShowProgress()) {
            return;
        }
        // Bound the percentage to [0, 1]
        $p = self::boundPercentage($p);

        static $previous_update_time = 0.0;
        $time = \microtime(true);

        // If not enough time has elapsed, then don't update the progress bar.
        // Making the update frequency based on time (instead of the number of files)
        // prevents the terminal from rapidly flickering while processing small files.
        if ($time - $previous_update_time < Config::getValue('progress_bar_sample_interval')) {
            // Make sure to output 100% for all phases, to avoid confusion.
            // https://github.com/phan/phan/issues/2694
            // e.g. `tool/phoogle --progress-bar` will stop partially through the 'method' phase otherwise.
            if ($p < 1.0) {
                return;
            }
        }
        $previous_update_time = $time;
        if ($msg === 'analyze' && Writer::isForkPoolWorker()) {
            // The original process of the fork pool is responsible for rendering the combined progress.
            Writer::recordProgress($p, (int)$offset, (int)$count);
            return;
        }
        $memory = \memory_get_usage() / 1024 / 1024;
        $peak = \memory_get_peak_usage() / 1024 / 1024;

        self::outputProgressLine($msg, $p, $memory, $peak, $offset, $count);
    }

    /** @var ?string the current state of CLI::progress, with any progress bar */
    private static $current_progress_state_any = null;

    /** @var ?string the state for long progress */
    private static $current_progress_state_long_progress = null;
    /** @var int the number of events that were handled */
    private static $current_progress_offset_long_progress = 0;

    // 80 - strlen(' 9999 / 9999 (100%) 9999MB') == 54
    private const PROGRESS_WIDTH = 54;

    /**
     * Returns the number of columns in the terminal
     */
    private static function getColumns(): int
    {
        static $columns = null;
        if ($columns === null) {
            // Only call this once per process, since it can be rather expensive
            $columns = (new Terminal())->getWidth();
        }
        return $columns;
    }

    /**
     * @internal
     */
    public static function outputProgressLine(string $msg, float $p, float $memory, float $peak, ?int $offset = null, ?int $count = null): void
    {
        if (self::shouldShowLongProgress()) {
            self::showLongProgress($msg, $p, $memory, $offset, $count);
            return;
        }

        $columns = self::getColumns();
        $left_side = \str_pad($msg, 10, ' ', STR_PAD_LEFT) .  ' ';
        if ($columns - (60 + 10) > 19) {
            $percent_progress = sprintf('%1$ 5.1f', (int)(1000 * $p) / 10);
        } else {
            $percent_progress = sprintf("%1$ 3d", (int)(100 * $p));
        }
        // Don't make the current memory usage in the progress bar shorter (avoid showing "MBB")
        $width = \max(2, strlen((string)(int)$peak));
        $right_side =
               " " . $percent_progress . "%" .
               sprintf(' %' . $width . 'dMB/%' . $width . 'dMB', (int)$memory, (int)$peak);
        // @phan-suppress-previous-line PhanPluginPrintfVariableFormatString

        // strlen("  99% 999MB/999MB") == 17
        $used_length = strlen($left_side) + \max(17, strlen($right_side));
        $remaining_length = $columns - $used_length;
        $remaining_length = \min(60, \max(0, $remaining_length));
        if ($remaining_length > 0) {
            $progress_bar = self::renderInnerProgressBar($remaining_length, $p);
        } else {
            $progress_bar = '';
            $right_side = \ltrim($right_side);
        }

        // Build up a string, then make a single call to fwrite(). Should be slightly faster and smoother to render to the console.
        $msg = "\r" .
               $left_side .
               $progress_bar .
               $right_side .
               "\r";
        fwrite(STDERR, $msg);
    }

    /**
     * Print an end to progress bars or debug output
     */
    public static function endProgressBar(): void
    {
        static $did_end = false;
        if ($did_end) {
            // Overkill to prevent redundant output.
            return;
        }
        $did_end = true;
        if (self::shouldShowDebugOutput()) {
            fwrite(STDERR, "Phan's analysis is complete\n");
            return;
        }
        if (self::shouldShowProgress()) {
            // Print a newline to stderr to visually separate stderr from stdout
            fwrite(STDERR, PHP_EOL);
            \fflush(\STDOUT);
        }
    }

    /**
     * @param ?(string|FQSEN|AddressableElement) $details
     */
    public static function debugProgress(string $msg, float $p, $details): void
    {
        $pct = sprintf("%d%%", (int)(100 * self::boundPercentage($p)));

        if ($details === null) {
            return;
        }
        if ($details instanceof AddressableElement) {
            $details = $details->getFQSEN();
        }
        switch ($msg) {
            case 'parse':
            case 'analyze':
                $line = "Going to $msg '$details' ($pct)";
                break;
            case 'method':
            case 'function':
                $line = "Going to analyze $msg $details() ($pct)";
                break;
            default:
                $line = "In $msg phase, processing '$details' ($pct)";
                break;
        }
        self::debugOutput($line);
    }

    /**
     * Write a line of output for debugging.
     */
    public static function debugOutput(string $line): void
    {
        if (self::shouldShowDebugOutput()) {
            fwrite(STDERR, $line . PHP_EOL);
        }
    }

    /**
     * Renders a unicode progress bar that goes from light (left) to dark (right)
     * The length in the console is the positive integer $length
     * @see https://en.wikipedia.org/wiki/Block_Elements
     */
    private static function renderInnerProgressBar(int $length, float $p): string
    {
        $current_float = $p * $length;
        $current = (int)$current_float;
        $rest = \max($length - $current, 0);

        if (!self::doesTerminalSupportUtf8()) {
            // Show a progress bar of "XXXX>------" in Windows when utf-8 is unsupported.
            $progress_bar = str_repeat("X", $current);
            $delta = $current_float - $current;
            if ($delta > 0.5) {
                $progress_bar .= ">" . str_repeat("-", $rest - 1);
            } else {
                $progress_bar .= str_repeat("-", $rest);
            }
            return $progress_bar;
        }
        // The left-most characters are "Light shade"
        $progress_bar = str_repeat("\u{2588}", $current);
        $delta = $current_float - $current;
        if ($delta > 1.0 / 3) {
            // The between character is "Full block" or "Medium shade" or "solid shade".
            // The remaining characters on the right are "Full block" (darkest)
            $first = $delta > 2.0 / 3 ? "\u{2593}" : "\u{2592}";
            $progress_bar .= $first . str_repeat("\u{2591}", $rest - 1);
        } else {
            $progress_bar .= str_repeat("\u{2591}", $rest);
        }
        return self::colorizeProgressBarSegment($progress_bar);
    }

    private static function colorizeProgressBarSegment(string $segment): string
    {
        if ($segment === '') {
            return '';
        }
        $progress_bar_color = $_ENV['PHAN_COLOR_PROGRESS_BAR'] ?? '';
        if ($progress_bar_color !== '' && CLI::supportsColor(STDERR)) {
            $progress_bar_color = Colorizing::STYLES[\strtolower($progress_bar_color)] ?? $progress_bar_color;
            return Colorizing::colorizeTextWithColorCode($progress_bar_color, $segment);
        }
        return $segment;
    }

    /**
     * Shows a long version of the progress bar, suitable for Continuous Integration logs
     */
    private static function showLongProgress(string $msg, float $p, float $memory, ?int $offset, ?int $count): void
    {
        $buf = self::renderLongProgress($msg, $p, $memory, $offset, $count);
        // Do a single write call (more efficient than multiple calls)
        if (strlen($buf) > 0) {
            fwrite(STDERR, $buf);
        }
    }

    /**
     * Reset the long progress state to the initial state.
     *
     * Useful for --analyze-twice
     */
    public static function resetLongProgressState(): void
    {
        self::$current_progress_offset_long_progress = 0;
        self::$current_progress_state_long_progress = null;
    }

    private static function renderLongProgress(string $msg, float $p, float $memory, ?int $offset, ?int $count): string
    {
        $buf = '';
        if ($msg !== self::$current_progress_state_long_progress) {
            switch ($msg) {
                case 'parse':
                    $buf = "Parsing files..." . PHP_EOL;
                    break;
                case 'classes':
                    $buf = "Analyzing classes..." . PHP_EOL;
                    break;
                case 'function':
                    $buf = "Analyzing functions..." . PHP_EOL;
                    break;
                case 'method':
                    $buf = "Analyzing methods..." . PHP_EOL;
                    break;
                case 'analyze':
                    static $did_print = false;
                    if ($did_print) {
                        $buf = "Analyzing files a second time..." . PHP_EOL;
                    } else {
                        $buf = "Analyzing files..." . PHP_EOL;
                        $did_print = true;
                    }
                    break;
                case 'dead code':
                    $buf = "Checking for dead code..." . PHP_EOL;
                    break;
                default:
                    $buf = "In '$msg' phase\n";
            }
            self::$current_progress_state_long_progress = $msg;
            self::$current_progress_offset_long_progress = 0;
        }
        if (self::doesTerminalSupportUtf8()) {
            $chr = "\u{2591}";
        } else {
            $chr = ".";
        }
        if (in_array($msg, ['analyze', 'parse'], true)) {
            while (self::$current_progress_offset_long_progress < $offset) {
                $old_mod = self::$current_progress_offset_long_progress % self::PROGRESS_WIDTH;
                $len = (int) min($offset - self::$current_progress_offset_long_progress, self::PROGRESS_WIDTH - $old_mod);
                if (!$len) {
                    // impossible
                    break;
                }

                $buf .= self::colorizeProgressBarSegment(str_repeat($chr, $len));
                self::$current_progress_offset_long_progress += $len;
                $mod = self::$current_progress_offset_long_progress % self::PROGRESS_WIDTH;
                if ($mod === 0 || self::$current_progress_offset_long_progress === $count) {
                    if ($mod) {
                        $buf .= str_repeat(" ", self::PROGRESS_WIDTH - $mod);
                    }
                    // @phan-suppress-next-line PhanPluginPrintfVariableFormatString
                    $buf .= " " . sprintf(
                        "%" . strlen((string)(int)$count) . "d / %d (%3d%%) %.0fMB" . PHP_EOL,
                        min(self::$current_progress_offset_long_progress, $count),
                        (int)$count,
                        100 * $p,
                        $memory
                    );
                }
            }
        } else {
            $offset = (int)($p * self::PROGRESS_WIDTH);
            if (self::$current_progress_offset_long_progress < $offset) {
                $buf .= self::colorizeProgressBarSegment(str_repeat($chr, $offset - self::$current_progress_offset_long_progress));
                self::$current_progress_offset_long_progress = $offset;
                if (self::$current_progress_offset_long_progress === self::PROGRESS_WIDTH) {
                    $buf .= ' ' . sprintf("%.0fMB" . PHP_EOL, $memory);
                }
            }
        }
        return $buf;
    }

    /**
     * Guess if the terminal supports utf-8.
     * In some locales, windows is set to a non-utf-8 codepoint.
     *
     * @see https://github.com/phan/phan/issues/2572
     * @see https://en.wikipedia.org/wiki/Code_page#Windows_code_pages
     * @suppress PhanUndeclaredFunction, UnusedSuppression the function exists only in Windows.
     * @suppress PhanImpossibleTypeComparison, PhanRedundantCondition, PhanImpossibleCondition, PhanSuspiciousValueComparison the value for strtoupper is inferred as a literal.
     */
    public static function doesTerminalSupportUtf8(): bool
    {
        if (getenv('PHAN_NO_UTF8')) {
            return false;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            if (!\function_exists('sapi_windows_cp_is_utf8') || !\sapi_windows_cp_is_utf8()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Look for a `.phan/config` file up to a few directories
     * up the hierarchy and apply anything in there to
     * the configuration.
     * @throws UsageException
     */
    private function maybeReadConfigFile(bool $require_config_exists): void
    {

        // If the file doesn't exist here, try a directory up
        $config_file_name = $this->config_file;
        $config_file_name =
            StringUtil::isNonZeroLengthString($config_file_name)
            ? \realpath($config_file_name)
            : \implode(DIRECTORY_SEPARATOR, [
                Config::getProjectRootDirectory(),
                '.phan',
                'config.php'
            ]);

        // Totally cool if the file isn't there
        if ($config_file_name === false || !\file_exists($config_file_name)) {
            if ($require_config_exists) {
                // But if the CLI option --require-config-exists is provided, exit immediately.
                // (Include extended help documenting that option)
                if ($config_file_name !== false) {
                    throw new UsageException("Could not find a config file at '$config_file_name', but --require-config-exists was set", EXIT_FAILURE, UsageException::PRINT_EXTENDED);
                } else {
                    $msg = sprintf(
                        "Could not figure out the path for config file %s, but --require-config-exists was set",
                        StringUtil::encodeValue($this->config_file)
                    );
                    throw new UsageException($msg, EXIT_FAILURE, UsageException::PRINT_EXTENDED);
                }
            }
            return;
        }

        // Read the configuration file
        $config = require($config_file_name);

        // Write each value to the config
        foreach ($config as $key => $value) {
            Config::setValue($key, $value);
        }
    }

    /**
     * This will assert that ast\parse_code or a polyfill can be called.
     * @throws AssertionError on failure
     */
    private static function ensureASTParserExists(): void
    {
        if (Config::getValue('use_polyfill_parser')) {
            return;
        }
        if (!\extension_loaded('ast')) {
            self::printHelpSection(
                // phpcs:ignore Generic.Files.LineLength.MaxExceeded
                "ERROR: The php-ast extension must be loaded in order for Phan to work. Either install and enable php-ast, or invoke Phan with the CLI option --allow-polyfill-parser (which is noticeably slower)\n",
                false,
                true
            );
            \phan_output_ast_installation_instructions();
            exit(EXIT_FAILURE);
        }
        self::exitIfAstVersionIsInvalid();

        try {
            // Split up the opening PHP tag to fix highlighting in vim.
            \ast\parse_code(
                '<' . '?php 42;',
                Config::AST_VERSION
            );
        } catch (\LogicException $_) {
            self::printHelpSection(
                'ERROR: Unknown AST version ('
                . Config::AST_VERSION
                . ') in configuration. '
                . "You may need to rebuild the latest version of the php-ast extension.\n"
                . "See https://github.com/phan/phan#getting-started for more details.\n"
                . "(You are using php-ast " . (new ReflectionExtension('ast'))->getVersion() . ", but " . Config::MINIMUM_AST_EXTENSION_VERSION . " or newer is required. Alternately, test with --allow-polyfill-parser or --force-polyfill-parser (which are noticeably slower))\n",
                false,
                true
            );
            exit(EXIT_FAILURE);
        }

        // Workaround for https://github.com/nikic/php-ast/issues/79
        try {
            \ast\parse_code(
                '<' . '?php syntaxerror',
                Config::AST_VERSION
            );
            self::printHelpSection(
                'ERROR: Expected ast\\parse_code to throw ParseError on invalid inputs. Configured AST version: '
                . Config::AST_VERSION
                . '. '
                . "You may need to rebuild the latest version of the php-ast extension.\n",
                false,
                true
            );
            exit(EXIT_FAILURE);
        } catch (\ParseError $_) {
            // error message may validate with locale and version, don't validate that.
        }
    }

    /**
     * This duplicates the check in Bootstrap.php, in case opcache.file_cache has outdated information about whether extension_loaded('ast') is true. exists.
     */
    private static function exitIfAstVersionIsInvalid(): void
    {
        $ast_version = (string)\phpversion('ast');
        if (\version_compare($ast_version, '1.0.0') <= 0) {
            if ($ast_version === '') {
                // Seen in php 7.3 with file_cache when ast is initially enabled but later disabled, due to the result of extension_loaded being assumed to be a constant by opcache.
                CLI::printErrorToStderr("ERROR: extension_loaded('ast') is true, but phpversion('ast') is the empty string. You probably need to clear opcache (opcache.file_cache='" . \ini_get('opcache.file_cache') . "')" . PHP_EOL);
            }
            // NOTE: We haven't loaded the autoloader yet, so these issue messages can't be colorized.
            CLI::printErrorToStderr(sprintf(
                "Phan 5.x requires php-ast %s+ because it depends on AST version 85. php-ast '%s' is installed." . PHP_EOL,
                Config::MINIMUM_AST_EXTENSION_VERSION,
                $ast_version
            ));
            require_once __DIR__ . '/Bootstrap.php';
            \phan_output_ast_installation_instructions();
            \fwrite(STDERR, "Exiting without analyzing files." . PHP_EOL);
            exit(1);
        }
        if (\version_compare($ast_version, '1.0.11') < 0) {
            CLI::printWarningToStderr(sprintf("php-ast %s is being used with Phan 5. php-ast 1.0.11 or newer is recommended for compatibility with plugins and support for AST version 85.\n", $ast_version));
            // Reuse PHAN_SUPPRESS_AST_DEPRECATION for this purpose as well.
            if (!getenv('PHAN_SUPPRESS_AST_DEPRECATION')) {
                \phan_output_ast_installation_instructions();
                fwrite(STDERR, "(Set PHAN_SUPPRESS_AST_DEPRECATION=1 to suppress this message)" . PHP_EOL);
            }
        }
    }

    /**
     * Returns a string that can be used to check if dev-master versions changed (approximately).
     *
     * This is useful for checking if caches (e.g. of ASTs) should be invalidated.
     */
    public static function getDevelopmentVersionId(): string
    {
        $news_path = \dirname(__DIR__) . '/NEWS.md';
        $version = self::PHAN_VERSION;
        if (\file_exists($news_path)) {
            $version .= '-' . \filesize($news_path);
        }
        return $version;
    }

    /**
     * Parse the process count override early for the restartWithoutProblematicExtensions check.
     * Imitate the original parsing order for now, this may be strictened to forbid passing both flags in a future release.
     *
     * @param array<string,string|false|array> $opts
     * @throws UsageException
     */
    public function parseProcessCountOverride(array $opts): void
    {
        foreach ($opts as $key => $value) {
            if (in_array($key, ['j', 'processes'], true)) {
                $processes = \filter_var($value, FILTER_VALIDATE_INT);
                if ($processes <= 0) {
                    throw new UsageException(sprintf("Invalid arguments to --processes: %s (expected a positive integer)\n", StringUtil::jsonEncode($value)), EXIT_FAILURE);
                }
                Config::setValue('processes', $processes);
            }
        }
    }

    /**
     * If any problematic extensions are installed, then restart without them
     * @suppress PhanAccessMethodInternal
     */
    public function restartWithoutProblematicExtensions(): void
    {
        $extensions_to_disable = [];
        if (self::shouldRestartToExclude('xdebug')) {
            $extensions_to_disable[] = 'xdebug';
            // Restart if Xdebug is loaded, unless the environment variable PHAN_ALLOW_XDEBUG is set.
            if (!getenv('PHAN_DISABLE_XDEBUG_WARN')) {
                fwrite(STDERR, <<<EOT
[info] Disabling Xdebug: Phan is around five times as slow when Xdebug is enabled (Xdebug only makes sense when debugging Phan itself)
[info] To run Phan with Xdebug, set the environment variable PHAN_ALLOW_XDEBUG to 1.
[info] To disable this warning, set the environment variable PHAN_DISABLE_XDEBUG_WARN to 1.
[info] To include function signatures of Xdebug, see .phan/internal_stubs/xdebug.phan_php

EOT
                );
            }
        }
        if (self::shouldRestartToExclude('uopz')) {
            // NOTE: uopz seems to cause instability when used and switched from enabled to disabled.
            //
            // TODO create and link to stubs if https://github.com/krakjoe/uopz/issues/123 is completed.
            $extensions_to_disable[] = 'uopz';
            fwrite(
                STDERR,
                <<<EOT
[info] Restarting with uopz disabled, it can cause unpredictable behavior.
[info] Set the environment variable PHAN_ALLOW_UOPZ to 1 to disable this message and to allow uopz.
[info] If you are not using uopz to debug Phan, removing uopz from php.ini or setting the ini setting uopz.disable=1 is recommended before running Phan.

EOT
            );
        }
        if (self::shouldRestartToExclude('grpc') && self::willUseMultipleProcesses()) {
            // This still hangs when phan runs with --processes 2, even in 1.22.0
            $extensions_to_disable[] = 'grpc';
            fwrite(
                STDERR,
                "[info] grpc can cause php to hang when Phan is run with options that require forking." . PHP_EOL .
                "[info] Restarting with grpc disabled." . PHP_EOL .
                "[info] Set the environment variable PHAN_ALLOW_GRPC to 1 to disable this message and to allow grpc." . PHP_EOL
            );
        }
        // php-ast + opcache causes issues if we suddenly restart without an outdated php-ast version, so there's no good way to exclude an outdated 'ast'.
        // See https://github.com/phan/phan/issues/2954 for details.

        if ($extensions_to_disable) {
            $ini_handler = new Restarter('phan');
            $ini_handler->setLogger(new StderrLogger());
            foreach ($extensions_to_disable as $extension) {
                $ini_handler->disableExtension($extension);
            }
            // Automatically restart if problematic extensions are loaded
            $ini_handler->check();
        }
    }

    private static function shouldRestartToExclude(string $extension): bool
    {
        return \extension_loaded($extension) && !getenv('PHAN_ALLOW_' . \strtoupper($extension));
    }

    private static function willUseMultipleProcesses(): bool
    {
        if (Config::getValue('processes') > 1) {
            return true;
        }
        if (Config::getValue('language_server_use_pcntl_fallback')) {
            return false;
        }
        $config = Config::getValue('language_server_config');
        if ($config && !isset($config['stdin'])) {
            return true;
        }
        if (Config::getValue('daemonize_tcp')) {
            return true;
        }
        return false;
    }
}
