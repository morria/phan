<?php

declare(strict_types=1);

namespace Phan\Tests\Internal;

use Phan\Config;

use function array_search;
use function is_int;
use function json_encode;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function strncmp;
use function trim;

use const JSON_UNESCAPED_SLASHES;

/**
 * Information that can be inferred about the config name from the source code and other data
 */
class ConfigEntry
{
    const CATEGORY_ANALYSIS = 'Analysis';
    const CATEGORY_ANALYSIS_VERSION = 'Analysis (of a PHP Version)';
    const CATEGORY_DEAD_CODE_DETECTION = 'Dead Code Detection';
    const CATEGORY_FILES = 'Configuring Files';
    const CATEGORY_HIDDEN_CLI_ONLY = 'Hidden';
    const CATEGORY_OUTPUT = 'Output';
    const CATEGORY_ISSUE_FILTERING = 'Issue Filtering';
    const CATEGORY_TYPE_CASTING = 'Type Casting';

    const ORDER_OF_CATEGORIES = [
        self::CATEGORY_FILES,
        self::CATEGORY_ISSUE_FILTERING,
        self::CATEGORY_ANALYSIS,
        self::CATEGORY_ANALYSIS_VERSION,
        self::CATEGORY_TYPE_CASTING,
        self::CATEGORY_DEAD_CODE_DETECTION,
        self::CATEGORY_OUTPUT,
        self::CATEGORY_HIDDEN_CLI_ONLY,
    ];

    /**
     * @var array<string,string>
     */
    const CATEGORIES = [
        'target_php_version' => self::CATEGORY_ANALYSIS_VERSION,
        'minimum_target_php_version' => self::CATEGORY_ANALYSIS_VERSION,
        'pretend_newer_core_methods_exist' => self::CATEGORY_ANALYSIS_VERSION,
        'polyfill_parse_all_element_doc_comments' => self::CATEGORY_ANALYSIS_VERSION,
        'file_list' => self::CATEGORY_FILES,
        'directory_list' => self::CATEGORY_FILES,
        'analyzed_file_extensions' => self::CATEGORY_FILES,
        'exclude_file_regex' => self::CATEGORY_FILES,
        'exclude_file_list' => self::CATEGORY_FILES,
        'enable_include_path_checks' => self::CATEGORY_ANALYSIS,
        'include_paths' => self::CATEGORY_ANALYSIS,
        'warn_about_relative_include_statement' => self::CATEGORY_ANALYSIS,
        'exclude_analysis_directory_list' => self::CATEGORY_FILES,
        'include_analysis_file_list' => self::CATEGORY_FILES,
        'backward_compatibility_checks' => self::CATEGORY_ANALYSIS,
        'parent_constructor_required' => self::CATEGORY_ANALYSIS,
        'quick_mode' => self::CATEGORY_ANALYSIS,
        'analyze_signature_compatibility' => self::CATEGORY_ANALYSIS,
        'assume_no_external_class_overrides' => self::CATEGORY_ANALYSIS,
        'allow_method_param_type_widening' => self::CATEGORY_ANALYSIS_VERSION,
        'guess_unknown_parameter_type_using_default' => self::CATEGORY_ANALYSIS,
        'use_tentative_return_type' => self::CATEGORY_ANALYSIS,
        'allow_overriding_vague_return_types' => self::CATEGORY_ANALYSIS,
        'override_return_types' => self::CATEGORY_ANALYSIS,
        'infer_default_properties_in_construct' => self::CATEGORY_ANALYSIS,
        'inherit_phpdoc_types' => self::CATEGORY_ANALYSIS,
        'minimum_severity' => self::CATEGORY_ISSUE_FILTERING,
        'allow_missing_properties' => self::CATEGORY_ANALYSIS,
        'null_casts_as_array' => self::CATEGORY_TYPE_CASTING,
        'array_casts_as_null' => self::CATEGORY_TYPE_CASTING,
        'null_casts_as_any_type' => self::CATEGORY_TYPE_CASTING,
        'strict_method_checking' => self::CATEGORY_TYPE_CASTING,
        'strict_object_checking' => self::CATEGORY_TYPE_CASTING,
        'strict_param_checking' => self::CATEGORY_TYPE_CASTING,
        'strict_property_checking' => self::CATEGORY_TYPE_CASTING,
        'strict_return_checking' => self::CATEGORY_TYPE_CASTING,
        'scalar_implicit_cast' => self::CATEGORY_TYPE_CASTING,
        'scalar_array_key_cast' => self::CATEGORY_TYPE_CASTING,
        'scalar_implicit_partial' => self::CATEGORY_TYPE_CASTING,
        'error_prone_truthy_condition_detection' => self::CATEGORY_ANALYSIS,
        'ignore_undeclared_variables_in_global_scope' => self::CATEGORY_ANALYSIS,
        'convert_possibly_undefined_offset_to_nullable' => self::CATEGORY_ANALYSIS,
        'check_docblock_signature_return_type_match' => self::CATEGORY_ANALYSIS,
        'check_docblock_signature_param_type_match' => self::CATEGORY_ANALYSIS,
        'prefer_narrowed_phpdoc_param_type' => self::CATEGORY_ANALYSIS,
        'prefer_narrowed_phpdoc_return_type' => self::CATEGORY_ANALYSIS,
        'dead_code_detection' => self::CATEGORY_DEAD_CODE_DETECTION,
        'unused_variable_detection' => self::CATEGORY_DEAD_CODE_DETECTION,
        'unused_variable_detection_assume_override_exists' => self::CATEGORY_DEAD_CODE_DETECTION,
        'force_tracking_references' => self::CATEGORY_DEAD_CODE_DETECTION,
        'constant_variable_detection' => self::CATEGORY_DEAD_CODE_DETECTION,
        'dead_code_detection_prefer_false_negative' => self::CATEGORY_DEAD_CODE_DETECTION,
        'dead_code_detection_treat_never_type_as_unreachable' => self::CATEGORY_DEAD_CODE_DETECTION,
        'warn_about_redundant_use_namespaced_class' => self::CATEGORY_DEAD_CODE_DETECTION,
        'redundant_condition_detection' => self::CATEGORY_DEAD_CODE_DETECTION,
        'assume_real_types_for_internal_functions' => self::CATEGORY_DEAD_CODE_DETECTION,
        'simplify_ast' => self::CATEGORY_ANALYSIS,
        'enable_class_alias_support' => self::CATEGORY_ANALYSIS,
        'read_magic_property_annotations' => self::CATEGORY_ANALYSIS,
        'read_magic_method_annotations' => self::CATEGORY_ANALYSIS,
        'read_mixin_annotations' => self::CATEGORY_ANALYSIS,
        'read_type_annotations' => self::CATEGORY_ANALYSIS,
        'warn_about_undocumented_throw_statements' => self::CATEGORY_ANALYSIS,
        'warn_about_undocumented_exceptions_thrown_by_invoked_functions' => self::CATEGORY_ANALYSIS,
        'exception_classes_with_optional_throws_phpdoc' => self::CATEGORY_ANALYSIS,
        'phpdoc_type_mapping' => self::CATEGORY_ANALYSIS,
        'disable_suppression' => self::CATEGORY_ISSUE_FILTERING,
        'disable_line_based_suppression' => self::CATEGORY_ISSUE_FILTERING,
        'disable_file_based_suppression' => self::CATEGORY_ISSUE_FILTERING,
        'dump_ast' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'dump_matching_functions' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'dump_signatures_file' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'dump_parsed_file_list' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'debug_max_frame_length' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'debug_output' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'progress_bar' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'progress_bar_sample_interval' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'processes' => self::CATEGORY_ANALYSIS,
        'profiler_enabled' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'suggestion_check_limit' => self::CATEGORY_OUTPUT,
        'disable_suggestions' => self::CATEGORY_OUTPUT,
        'suppress_issue_types' => self::CATEGORY_ISSUE_FILTERING,
        'whitelist_issue_types' => self::CATEGORY_ISSUE_FILTERING,
        'baseline_path' => self::CATEGORY_ISSUE_FILTERING,
        'baseline_summary_type' => self::CATEGORY_ISSUE_FILTERING,
        'runkit_superglobals' => self::CATEGORY_ANALYSIS,
        'globals_type_map' => self::CATEGORY_ANALYSIS,
        'markdown_issue_messages' => self::CATEGORY_HIDDEN_CLI_ONLY, // self::CATEGORY_OUTPUT,
        'absolute_path_issue_messages' => self::CATEGORY_HIDDEN_CLI_ONLY, // self::CATEGORY_OUTPUT,
        'color_issue_messages' => self::CATEGORY_HIDDEN_CLI_ONLY, // self::CATEGORY_OUTPUT,
        'color_issue_messages_if_supported' => self::CATEGORY_OUTPUT,
        'hide_issue_column' => self::CATEGORY_OUTPUT,
        'color_scheme' => self::CATEGORY_OUTPUT,
        'generic_types_enabled' => self::CATEGORY_ANALYSIS,
        'randomize_file_order' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'consistent_hashing_file_order' => self::CATEGORY_FILES,
        'print_memory_usage_summary' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'skip_slow_php_options_warning' => self::CATEGORY_OUTPUT,
        'skip_missing_tokenizer_warning' => self::CATEGORY_OUTPUT,
        'autoload_internal_extension_signatures' => self::CATEGORY_ANALYSIS,
        'included_extension_subset' => self::CATEGORY_ANALYSIS,
        'ignore_undeclared_functions_with_known_signatures' => self::CATEGORY_ANALYSIS,
        'use_fallback_parser' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'use_polyfill_parser' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'cache_polyfill_asts' => self::CATEGORY_ANALYSIS,
        'daemonize_socket' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'daemonize_tcp' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'daemonize_tcp_host' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'daemonize_tcp_port' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_config' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_analyze_only_on_save' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_debug_level' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_disable_output_filter' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_use_pcntl_fallback' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_enable_go_to_definition' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_enable_hover' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_enable_completion' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'language_server_hide_category_of_issues' => self::CATEGORY_HIDDEN_CLI_ONLY,
        'enable_internal_return_type_plugins' => self::CATEGORY_ANALYSIS,
        'enable_extended_internal_return_type_plugins' => self::CATEGORY_ANALYSIS,
        'max_literal_string_type_length' => self::CATEGORY_ANALYSIS,
        'max_verbose_snippet_length' => self::CATEGORY_OUTPUT,
        'plugins' => self::CATEGORY_ANALYSIS,
        'plugin_config' => self::CATEGORY_ANALYSIS,
        'maximum_recursion_depth' => self::CATEGORY_ANALYSIS,
        'record_variable_context_and_scope' => self::CATEGORY_HIDDEN_CLI_ONLY,
    ];

    /** @var string the configuration setting name (e.g. 'null_casts_as_any_type') */
    private $config_name;
    /** @var list<string> the raw comment lines */
    private $lines;
    /** @var string the category of configuration settings */
    private $category;

    /**
     * @param string $config_name the name of the config setting
     * @param list<string> $lines
     */
    public function __construct(string $config_name, array $lines)
    {
        $this->config_name = $config_name;
        $this->lines = $lines;
        $this->category = self::CATEGORIES[$config_name] ?? 'misc';
    }

    /**
     * @return string the configuration setting name (e.g. `null_casts_as_any_type`)
     */
    public function getConfigName(): string
    {
        return $this->config_name;
    }

    /**
     * Returns a markdown description for this issue type
     */
    public function getMarkdown(): string
    {
        $result = '';
        foreach ($this->lines as $line) {
            $line = preg_replace('@^//( |$)@D', '', trim($line));
            $result .= $line . "\n";
        }
        $result = preg_replace_callback(
            '@(?<!\[)`([A-Za-z_0-9]+)`@',
            /** @param array{0:string,1:string} $matches */
            function (array $matches): string {
                [$markdown, $name] = $matches;
                if ($name !== $this->config_name && isset(Config::DEFAULT_CONFIGURATION[$name])) {
                    return sprintf('[%s](#%s)', $markdown, $name);
                }
                return $markdown;
            },
            $result
        );
        return $result;
    }

    /**
     * @return list<string> the raw lines
     * @suppress PhanUnreferencedPublicMethod
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * @return string the name of the category
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * @return int a value used to group config settings into categories of the documentation that will be generated.
     * Categories are output in the order of the index value.
     */
    public function getCategoryIndex(): int
    {
        $category_index = array_search($this->category, ConfigEntry::ORDER_OF_CATEGORIES, true);
        return is_int($category_index) ? $category_index : 99999;
    }

    /**
     * Is this config setting hidden from the generated markdown document?
     */
    public function isHidden(): bool
    {
        if (strncmp($this->config_name, '__', 2) === 0) {
            return true;
        }
        return $this->category === self::CATEGORY_HIDDEN_CLI_ONLY;
    }

    /**
     * @return string a markdown representation of the default value of this config setting.
     */
    public function getRepresentationOfDefault(): string
    {
        if ($this->config_name === 'minimum_severity') {
            return '`Issue::SEVERITY_LOW`';
        }
        $value = Config::DEFAULT_CONFIGURATION[$this->config_name];
        $result = json_encode($value, JSON_UNESCAPED_SLASHES);

        return '`' . $result . '`';
    }
}
