<?php

declare(strict_types=1);

namespace Phan\Plugin;

use AssertionError;
use ast\Node;
use Closure;
use Phan\AST\Parser;
use Phan\AST\Visitor\Element;
use Phan\CLI;
use Phan\CodeBase;
use Phan\Config;
use Phan\Exception\IssueException;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\Element\Property;
use Phan\Language\Element\TypedElement;
use Phan\Language\Element\UnaddressableTypedElement;
use Phan\Language\Element\Variable;
use Phan\Language\FQSEN;
use Phan\Language\Scope;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\LanguageServer\CompletionRequest;
use Phan\LanguageServer\CompletionResolver;
use Phan\LanguageServer\DefinitionResolver;
use Phan\LanguageServer\GoToDefinitionRequest;
use Phan\Library\RAII;
use Phan\Plugin\Internal\ArrayReturnTypeOverridePlugin;
use Phan\Plugin\Internal\BaselineLoadingPlugin;
use Phan\Plugin\Internal\BaselineSavingPlugin;
use Phan\Plugin\Internal\BuiltinSuppressionPlugin;
use Phan\Plugin\Internal\CallableParamPlugin;
use Phan\Plugin\Internal\ClosureReturnTypeOverridePlugin;
use Phan\Plugin\Internal\CompactPlugin;
use Phan\Plugin\Internal\DependentReturnTypeOverridePlugin;
use Phan\Plugin\Internal\ExtendedDependentReturnTypeOverridePlugin;
use Phan\Plugin\Internal\IssueFixingPlugin\IssueFixer;
use Phan\Plugin\Internal\MiscParamPlugin;
use Phan\Plugin\Internal\NodeSelectionPlugin;
use Phan\Plugin\Internal\NodeSelectionVisitor;
use Phan\Plugin\Internal\RedundantConditionCallPlugin;
use Phan\Plugin\Internal\RequireExistsPlugin;
use Phan\Plugin\Internal\StringFunctionPlugin;
use Phan\Plugin\Internal\ThrowAnalyzerPlugin;
use Phan\Plugin\Internal\VariableTrackerPlugin;
use Phan\PluginV3;
use Phan\PluginV3\AfterAnalyzeFileCapability;
use Phan\PluginV3\AnalyzeClassCapability;
use Phan\PluginV3\AnalyzeFunctionCallCapability;
use Phan\PluginV3\AnalyzeFunctionCapability;
use Phan\PluginV3\AnalyzeLiteralStatementCapability;
use Phan\PluginV3\AnalyzeMethodCapability;
use Phan\PluginV3\AnalyzePropertyCapability;
use Phan\PluginV3\AutomaticFixCapability;
use Phan\PluginV3\BeforeAnalyzeCapability;
use Phan\PluginV3\BeforeAnalyzeFileCapability;
use Phan\PluginV3\BeforeAnalyzePhaseCapability;
use Phan\PluginV3\BeforeLoopBodyAnalysisCapability;
use Phan\PluginV3\BeforeLoopBodyAnalysisVisitor;
use Phan\PluginV3\FinalizeProcessCapability;
use Phan\PluginV3\HandleLazyLoadInternalFunctionCapability;
use Phan\PluginV3\MergeVariableInfoCapability;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PluginAwarePreAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;
use Phan\PluginV3\PreAnalyzeNodeCapability;
use Phan\PluginV3\ReturnTypeOverrideCapability;
use Phan\PluginV3\SubscribeEmitIssueCapability;
use Phan\PluginV3\SuppressionCapability;
use Phan\PluginV3\UnloadablePluginException;
use Phan\Suggestion;
use Throwable;
use UnusedSuppressionPlugin;

use function get_class;
use function is_null;
use function is_object;
use function property_exists;

use const EXIT_FAILURE;
use const PHP_EOL;
use const STDERR;

/**
 * The root plugin that calls out each hook
 * on any plugins defined in the configuration.
 *
 * (Note: This is called almost once per each AST node being analyzed.
 * Speed is preferred over using Phan\Memoize.)
 *
 * @phan-file-suppress PhanPluginDescriptionlessCommentOnPublicMethod TODO: Document
 */
final class ConfigPluginSet extends PluginV3 implements
    AfterAnalyzeFileCapability,
    AnalyzeClassCapability,
    AnalyzeFunctionCapability,
    AnalyzeFunctionCallCapability,
    AnalyzeLiteralStatementCapability,
    AnalyzeMethodCapability,
    AnalyzePropertyCapability,
    BeforeAnalyzeCapability,
    BeforeAnalyzePhaseCapability,
    BeforeAnalyzeFileCapability,
    FinalizeProcessCapability,
    ReturnTypeOverrideCapability,
    SubscribeEmitIssueCapability,
    SuppressionCapability
{

    /** @var list<PluginV3>|null - Cached plugin set for this instance. Lazily generated. */
    private $plugin_set;

    /** @var array<string,PluginV3> - Shared cache of plugin instances to avoid requiring class files more than once. */
    private static $plugin_instances_cache = [];

    /**
     * @var associative-array<int, Closure(CodeBase,Context,Node|int|string|float):void> - plugins to analyze nodes in pre-order
     */
    private $pre_analyze_node_plugin_set;

    /**
     * @var associative-array<int, Closure(CodeBase,Context,Node|int|string|float,list<Node>):void> - plugins to analyze nodes in post-order
     */
    private $post_analyze_node_plugin_set;

    /**
     * @var associative-array<int, Closure(CodeBase,Context,Node|int|string|float):void> - plugins to analyze loop conditions before body
     */
    private $before_loop_body_analysis_plugin_set;

    /**
     * @var list<BeforeAnalyzeFileCapability> - plugins to analyze files before Phan's analysis of that file is completed.
     */
    private $before_analyze_file_plugin_set;

    /**
     * @var list<BeforeAnalyzeCapability> - plugins to analyze the project before Phan starts the analyze phase and before methods are analyzed.
     */
    private $before_analyze_plugin_set;

    /**
     * @var list<BeforeAnalyzePhaseCapability> - plugins to analyze the project before Phan starts the analyze phase and after methods are analyzed.
     */
    private $before_analyze_phase_plugin_set;

    /**
     * @var list<AfterAnalyzeFileCapability> - plugins to analyze files after Phan's analysis of that file is completed.
     */
    private $after_analyze_file_plugin_set;

    /** @var list<AnalyzeClassCapability>|null - plugins to analyze class declarations. */
    private $analyze_class_plugin_set;

    /** @var list<PluginV3&AnalyzeFunctionCallCapability>|null - plugins to analyze invocations of subsets of functions and methods. */
    private $analyze_function_call_plugin_set;

    /** @var list<AnalyzeFunctionCapability>|null - plugins to analyze function declarations. */
    private $analyze_function_plugin_set;

    /** @var list<AnalyzeLiteralStatementCapability>|null - plugins to analyze no-op string literals. */
    private $analyze_literal_statement_plugin_set;

    /** @var list<AnalyzePropertyCapability>|null - plugins to analyze property declarations. */
    private $analyze_property_plugin_set;

    /** @var list<AnalyzeMethodCapability>|null - plugins to analyze method declarations.*/
    private $analyze_method_plugin_set;

    /** @var list<HandleLazyLoadInternalFunctionCapability>|null - plugins to modify Phan's information about internal Funcs when loaded for the first time */
    private $handle_lazy_load_internal_function_plugin_set;

    /** @var list<FinalizeProcessCapability>|null - plugins to call finalize() on after analysis is finished. */
    private $finalize_process_plugin_set;

    /** @var list<ReturnTypeOverrideCapability>|null - plugins which generate return UnionTypes of functions based on arguments. */
    private $return_type_override_plugin_set;

    /** @var list<SubscribeEmitIssueCapability>|null - plugins which get called on issues that aren't suppressed. */
    private $subscribe_emit_issue_plugin_set;

    /** @var list<SuppressionCapability>|null - plugins which can be used to suppress issues or inspect suppressions. */
    private $suppression_plugin_set;

    /** @var ?UnusedSuppressionPlugin - TODO: Refactor*/
    private $unused_suppression_plugin = null;

    /**
     * @var bool
     */
    private $did_analyze_phase_start = false;

    /**
     * @var ?(Closure(Variable, Scope[], bool):void)
     * A closure to call on variables when merging data in ContextMergeVisitor. This is stored in a
     * static property here for performance.
     * @internal For use by ContextMergeVisitor only
     */
    public static $mergeVariableInfoClosure;

    /**
     * Call `ConfigPluginSet::instance()` instead.
     */
    private function __construct()
    {
    }

    /**
     * @return ConfigPluginSet
     * A shared single instance of this plugin
     */
    public static function instance(): ConfigPluginSet
    {
        static $instance = null;
        if ($instance === null) {
            $instance = self::newInstance();
        }
        return $instance;
    }

    /**
     * Returns a brand-new ConfigPluginSet where all plugins are initialized.
     *
     * If one of the plugins could not be instantiated, this prints an error message and terminates the program.
     */
    private static function newInstance(): ConfigPluginSet
    {
        try {
            $instance = new self();
            $instance->ensurePluginsExist();
            return $instance;
        } catch (Throwable $e) {
            // An unexpected error.
            // E.g. a third party plugin class threw when building the list of return types to analyze.
            $message = \sprintf(
                "Failed to initialize plugins, exiting: %s: %s at %s:%d\nStack Trace:\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );
            \error_log($message);
            exit(EXIT_FAILURE);
        }
    }

    /**
     * Resets this set of plugins to the state it had before any user-defined or internal plugins were added,
     * then re-initialize plugins based on the current configuration.
     *
     * @internal - Used only for testing
     */
    public static function reset(): void
    {
        $instance = self::instance();
        // Set all of the private properties to their uninitialized default values
        // @phan-suppress-next-line PhanTypeSuspiciousNonTraversableForeach this is intentionally iterating over private properties of the clone.
        foreach (new self() as $k => $v) {
            $instance->{$k} = $v;
        }
        $instance->ensurePluginsExist();
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * The context in which the node exits. This is
     * the context inside the given node rather than
     * the context outside of the given node
     *
     * @param Node $node
     * The php-ast Node being analyzed.
     */
    public function preAnalyzeNode(
        CodeBase $code_base,
        Context $context,
        Node $node
    ): void {
        $plugin_callback = $this->pre_analyze_node_plugin_set[$node->kind] ?? null;
        if ($plugin_callback !== null) {
            $plugin_callback(
                $code_base,
                $context,
                $node
            );
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * The context in which the node exits. This is
     * the context inside the given node rather than
     * the context outside of the given node
     *
     * @param Node $node
     * The php-ast Node being analyzed.
     *
     * @param list<Node> $parent_node_list
     * The parent node of the given node (if one exists).
     */
    public function postAnalyzeNode(
        CodeBase $code_base,
        Context $context,
        Node $node,
        array $parent_node_list = []
    ): void {
        $plugin_callback = $this->post_analyze_node_plugin_set[$node->kind] ?? null;
        if (\is_object($plugin_callback)) {
            $plugin_callback(
                $code_base,
                $context,
                $node,
                $parent_node_list
            );
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * The context in which the node exits. This is
     * the context inside the given node rather than
     * the context outside of the given node
     *
     * @param Node $node
     * The php-ast Node being analyzed.
     */
    public function analyzeLoopBeforeBody(
        CodeBase $code_base,
        Context $context,
        Node $node
    ): void {
        $plugin_callback = $this->before_loop_body_analysis_plugin_set[$node->kind] ?? null;
        if ($plugin_callback !== null) {
            $plugin_callback(
                $code_base,
                $context,
                $node
            );
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * A context with the file name for $file_contents and the scope before analyzing $node.
     *
     * @param string $file_contents
     * @param Node $node
     * @override
     */
    public function beforeAnalyzeFile(
        CodeBase $code_base,
        Context $context,
        string $file_contents,
        Node $node
    ): void {
        foreach ($this->before_analyze_file_plugin_set as $plugin) {
            $plugin->beforeAnalyzeFile(
                $code_base,
                $context,
                $file_contents,
                $node
            );
        }
    }

    /**
     * This method is called before analyzing a project and before analyzing methods.
     *
     * @param CodeBase $code_base
     * The code base in which the project exists
     *
     * @override
     */
    public function beforeAnalyze(CodeBase $code_base): void
    {
        $this->did_analyze_phase_start = true;
        foreach ($this->before_analyze_plugin_set as $plugin) {
            $plugin->beforeAnalyze($code_base);
        }
    }

    /**
     * This method is called before analyzing a project and after analyzing methods.
     *
     * @param CodeBase $code_base
     * The code base in which the project exists
     *
     * @override
     */
    public function beforeAnalyzePhase(CodeBase $code_base): void
    {
        $this->did_analyze_phase_start = true;
        foreach ($this->before_analyze_phase_plugin_set as $plugin) {
            $plugin->beforeAnalyzePhase($code_base);
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the node exists
     *
     * @param Context $context
     * A context with the file name for $file_contents and the scope after analyzing $node.
     *
     * @param string $file_contents
     * @param Node $node
     * @override
     */
    public function afterAnalyzeFile(
        CodeBase $code_base,
        Context $context,
        string $file_contents,
        Node $node
    ): void {
        foreach ($this->after_analyze_file_plugin_set as $plugin) {
            $plugin->afterAnalyzeFile(
                $code_base,
                $context,
                $file_contents,
                $node
            );
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the class exists
     *
     * @param Clazz $class
     * A class being analyzed
     * @override
     */
    public function analyzeClass(
        CodeBase $code_base,
        Clazz $class
    ): void {
        foreach ($this->analyze_class_plugin_set as $plugin) {
            $plugin->analyzeClass(
                $code_base,
                $class
            );
        }
        if ($this->hasAnalyzePropertyPlugins()) {
            foreach ($class->getPropertyMap($code_base) as $property) {
                $this->analyzeProperty($code_base, $property);
            }
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the method exists
     *
     * @param Method $method
     * A method being analyzed
     * @override
     */
    public function analyzeMethod(
        CodeBase $code_base,
        Method $method
    ): void {
        foreach ($this->analyze_method_plugin_set as $plugin) {
            $plugin->analyzeMethod(
                $code_base,
                $method
            );
        }
    }

    /**
     * This will be called if Phan's file and element-based suppressions did not suppress the issue.
     *
     * @param CodeBase $code_base
     *
     * @param Context $context context near where the issue occurred
     *
     * @param string $issue_type
     * The type of issue to emit such as Issue::ParentlessClass
     *
     * @param int $lineno
     * The line number where the issue was found
     *
     * @param list<string|int|float|bool|Type|UnionType|FQSEN|TypedElement|UnaddressableTypedElement> $parameters
     *
     * @param ?Suggestion $suggestion Phan's suggestion for how to fix the issue, if any.
     *
     * @return bool true if the given issue instance should be suppressed, given the current file contents.
     */
    public function shouldSuppressIssue(
        CodeBase $code_base,
        Context $context,
        string $issue_type,
        int $lineno,
        array $parameters,
        ?Suggestion $suggestion
    ): bool {
        foreach ($this->suppression_plugin_set as $plugin) {
            if ($plugin->shouldSuppressIssue(
                $code_base,
                $context,
                $issue_type,
                $lineno,
                $parameters,
                $suggestion
            )) {
                if ($this->unused_suppression_plugin) {
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    $this->unused_suppression_plugin->recordPluginSuppression($plugin, $context->getFile(), $issue_type, $lineno);
                }
                return true;
            }
        }
        return false;
    }

    /**
     * @param CodeBase $code_base
     * @param string $file_path
     * @return array<string,list<int>> Maps 0 or more issue types to a *list* of lines that this plugin set is going to suppress.
     */
    public function getIssueSuppressionList(
        CodeBase $code_base,
        string $file_path
    ): array {
        $result = [];
        foreach ($this->suppression_plugin_set as $plugin) {
            $result += $plugin->getIssueSuppressionList(
                $code_base,
                $file_path
            );
        }
        return $result;
    }

    /**
     * @return list<SuppressionCapability>
     * @suppress PhanPossiblyNullTypeReturn should always be initialized before any issues get emitted.
     */
    public function getSuppressionPluginSet(): array
    {
        return $this->suppression_plugin_set;
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the function exists
     *
     * @param Func $function
     * A function being analyzed
     * @override
     */
    public function analyzeFunction(
        CodeBase $code_base,
        Func $function
    ): void {
        foreach ($this->analyze_function_plugin_set as $plugin) {
            $plugin->analyzeFunction(
                $code_base,
                $function
            );
        }
    }

    /**
     * Analyze a string literal statement,
     * after parsing and before analyzing.
     *
     * @param CodeBase $code_base
     *
     * @param Context $context
     *
     * @param string $statement
     * The no-op literal statement
     *
     * @return bool
     * Whether the statement was consumed in any way (i.e. it wasn't no-op)
     * @override
     */
    public function analyzeStringLiteralStatement(
        CodeBase $code_base,
        Context $context,
        string $statement
    ): bool {
        $consumed = false;
        foreach ($this->analyze_literal_statement_plugin_set as $plugin) {
            $consumed = $plugin->analyzeStringLiteralStatement(
                $code_base,
                $context,
                $statement
            ) || $consumed;
        }
        return $consumed;
    }

    /**
     * @param CodeBase $code_base
     * The code base in which the property exists
     *
     * @param Property $property
     * A property being analyzed
     *
     * (Called by analyzeClass())
     * @override
     */
    public function analyzeProperty(
        CodeBase $code_base,
        Property $property
    ): void {
        foreach ($this->analyze_property_plugin_set as $plugin) {
            try {
                $plugin->analyzeProperty(
                    $code_base,
                    $property
                );
            } catch (IssueException $exception) {
                // e.g. getUnionType() can throw, PropertyTypesAnalyzer is probably emitting duplicate issues
                Issue::maybeEmitInstance(
                    $code_base,
                    $property->getContext(),
                    $exception->getIssueInstance()
                );
                continue;
            }
        }
    }

    /**
     * @param CodeBase $code_base
     * The code base used for previous analysis steps
     * @override
     */
    public function finalizeProcess(
        CodeBase $code_base
    ): void {
        foreach ($this->finalize_process_plugin_set as $plugin) {
            $plugin->finalizeProcess($code_base);
        }
    }

    /**
     * Returns true if analyzeFunction() will execute any plugins.
     */
    public function hasAnalyzeFunctionPlugins(): bool
    {
        if (is_null($this->plugin_set)) {
            throw new AssertionError("Expected plugins to be loaded in " . __METHOD__);
        }
        // @phan-suppress-next-line PhanPossiblyNullTypeArgumentInternal
        return \count($this->analyze_function_plugin_set) > 0;
    }

    /**
     * Returns true if analyzeMethod() will execute any plugins.
     */
    public function hasAnalyzeMethodPlugins(): bool
    {
        if (is_null($this->plugin_set)) {
            throw new AssertionError("Expected plugins to be loaded in " . __METHOD__);
        }
        // @phan-suppress-next-line PhanPossiblyNullTypeArgumentInternal
        return \count($this->analyze_method_plugin_set) > 0;
    }

    /**
     * @param Closure(CodeBase, Context, FunctionInterface, list<Node|int|string|float>, ?Node):void $a
     * @param ?Closure(CodeBase, Context, FunctionInterface, list<Node|int|string|float>, ?Node):void $b
     * @return Closure(CodeBase, Context, FunctionInterface, list<Node|int|string|float>, ?Node):void $b
     */
    public static function mergeAnalyzeFunctionCallClosures(Closure $a, ?Closure $b = null): Closure
    {
        if (!$b) {
            return $a;
        }
        /**
         * @param list<Node|mixed> $args
         */
        return static function (CodeBase $code_base, Context $context, FunctionInterface $func, array $args, ?Node $node) use ($a, $b): void {
            $a($code_base, $context, $func, $args, $node);
            $b($code_base, $context, $func, $args, $node);
        };
    }
    /**
     * @param CodeBase $code_base
     * @return array<string,\Closure> maps FQSEN string to closure
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $code_base): array
    {
        if (is_null($this->plugin_set)) {
            throw new AssertionError("Expected plugins to be loaded in " . __METHOD__);
        }
        $result = [];
        foreach ($this->analyze_function_call_plugin_set as $plugin) {
            // TODO: Make this case-insensitive.
            foreach ($plugin->getAnalyzeFunctionCallClosures($code_base) as $fqsen_name => $closure) {
                $other_closure = $result[$fqsen_name] ?? null;
                $closure = self::mergeAnalyzeFunctionCallClosures($closure, $other_closure);
                $result[$fqsen_name] = $closure;
            }
        }
        return $result;
    }

    /**
     * @return list<PluginV3&AnalyzeFunctionCallCapability>
     * @internal
     */
    public function getAnalyzeFunctionCallPluginSet(): array
    {
        return $this->analyze_function_call_plugin_set ?? [];
    }

    /**
     * @param CodeBase $code_base
     * @return array<string,\Closure> maps FQSEN string to closure
     */
    public function getReturnTypeOverrides(CodeBase $code_base): array
    {
        if (is_null($this->plugin_set)) {
            throw new AssertionError("Expected plugins to be loaded in " . __METHOD__);
        }
        $result = [];
        foreach ($this->return_type_override_plugin_set as $plugin) {
            $result += $plugin->getReturnTypeOverrides($code_base);
        }
        return $result;
    }

    /** @var list<IssueInstance> $issues */
    private $deferred_emitted_issues = [];

    /**
     * This method is called before Phan emits an issue not suppressed elsewhere.
     *
     * @return bool true if the issue should be suppressed.
     *              Most plugins should use SuppressionCapability instead,
     *              so that more generic issues can be used to suppress specific issues,
     *              and to avoid interfering with baselines.
     */
    public function onEmitIssue(IssueInstance $issue_instance): bool
    {
        if (is_null($this->plugin_set)) {
            $this->deferred_emitted_issues[] = $issue_instance;
            return false;
        }
        if ($this->deferred_emitted_issues) {
            try {
                foreach ($this->deferred_emitted_issues as $issue) {
                    foreach ($this->subscribe_emit_issue_plugin_set as $plugin) {
                        if ($plugin->onEmitIssue($issue)) {
                            break;
                        }
                    }
                }
            } finally {
                $this->deferred_emitted_issues = [];
            }
        }

        foreach ($this->subscribe_emit_issue_plugin_set as $plugin) {
            if ($plugin->onEmitIssue($issue_instance)) {
                return true;
            }
        }
        return false;
    }

    /** @var ?NodeSelectionPlugin - If the language server requests more information about a node, this may be set (e.g. for "Go To Definition") */
    private $node_selection_plugin;

    /**
     * @internal
     * @see addTemporaryAnalysisPlugin
     */
    public function prepareNodeSelectionPluginForNode(Node $node): void
    {
        if (!$this->node_selection_plugin) {
            // @phan-suppress-next-line PhanPluginRemoveDebugCall
            \fwrite(STDERR, "Error: " . __METHOD__ . " called before node selection plugin was created\n");
            return;
        }

        // TODO: Track if this has been added already(not necessary yet)

        $kind = $node->kind;
        if (!\is_int($kind)) {
            throw new AssertionError("Invalid kind for node");
        }

        /**
         * @param list<Node|mixed> $parent_node_list
         */
        $closure = static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []): void {
            $visitor = new NodeSelectionVisitor($code_base, $context);
            $visitor->visitCommonImplementation($node, $parent_node_list);
        };

        $this->addNodeSelectionClosureForKind($node->kind, $closure);
    }

    /**
     * Adds a plugin that will stay around until the language client's request has been fulfilled
     * (E.g. a plugin that will analyze the node targeted by "go to definition")
     */
    public function addTemporaryAnalysisPlugin(CodeBase $code_base, ?\Phan\Daemon\Request $request): ?RAII
    {
        if (!$request) {
            return null;
        }
        $node_info_request = $request->getMostRecentNodeInfoRequest();
        if (!$node_info_request) {
            return null;
        }
        $node_selection_plugin = new NodeSelectionPlugin();
        if ($node_info_request instanceof GoToDefinitionRequest) {
            $node_selection_plugin->setNodeSelectorClosure(DefinitionResolver::createGoToDefinitionClosure($node_info_request, $code_base));
        } elseif ($node_info_request instanceof CompletionRequest) {
            $node_selection_plugin->setNodeSelectorClosure(CompletionResolver::createCompletionClosure($node_info_request, $code_base));
        } else {
            throw new AssertionError("Unknown subclass of NodeInfoRequest - Should not happen");
        }
        $this->node_selection_plugin = $node_selection_plugin;

        $old_post_analyze_node_plugin_set = $this->post_analyze_node_plugin_set;

        /*
        $new_post_analyze_node_plugins = self::filterPostAnalysisPlugins([$node_selection_plugin]);
        if (!$new_post_analyze_node_plugins) {
            throw new \RuntimeException("Invalid NodeSelectionPlugin");
        }

        // TODO: This can be removed?
        foreach ($new_post_analyze_node_plugins as $kind => $new_plugin) {
            $this->addNodeSelectionClosureForKind($kind, $new_plugin);
        }
         */

        return new RAII(function () use ($old_post_analyze_node_plugin_set): void {
            $this->post_analyze_node_plugin_set = $old_post_analyze_node_plugin_set;
            $this->node_selection_plugin = null;
        });
    }

    /**
     * @param Closure(CodeBase,Context,Node,array=) $new_plugin
     */
    private function addNodeSelectionClosureForKind(int $kind, Closure $new_plugin): void
    {
        $old_plugin_for_kind = $this->post_analyze_node_plugin_set[$kind] ?? null;
        if ($old_plugin_for_kind) {
            /**
             * @param list<Node> $parent_node_list
             */
            $this->post_analyze_node_plugin_set[$kind] = static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []) use ($old_plugin_for_kind, $new_plugin): void {
                $old_plugin_for_kind($code_base, $context, $node, $parent_node_list);
                $new_plugin($code_base, $context, $node, $parent_node_list);
            };
        } else {
            $this->post_analyze_node_plugin_set[$kind] = $new_plugin;
        }
    }

    /**
     * Returns true if analyzeProperty() will execute any plugins.
     */
    private function hasAnalyzePropertyPlugins(): bool
    {
        if (is_null($this->plugin_set)) {
            throw new AssertionError("Expected plugins to be loaded in " . __METHOD__);
        }
        // @phan-suppress-next-line PhanPossiblyNullTypeArgumentInternal
        return \count($this->analyze_property_plugin_set) > 0;
    }

    /**
     * Given a plugin's name in the config, return the path Phan expects the plugin to be located in
     * Allow any word/UTF-8 identifier as a php file name.
     * E.g. 'AlwaysReturnPlugin' becomes /path/to/phan/.phan/plugins/AlwaysReturnPlugin.php
     * (Useful when using phan.phar, etc.)
     *
     * @internal
     */
    public static function normalizePluginPath(string $plugin_file_name): string
    {
        if (\preg_match('@^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$@D', $plugin_file_name) > 0) {
            $plugin_file_name = self::getBuiltinPluginDirectory() . '/' . $plugin_file_name . '.php';
        }
        if (\preg_match('@^phar://@', $plugin_file_name)) {
            // This is a plugin, probably from phan.phar
            return $plugin_file_name;
        }
        return Config::projectPath($plugin_file_name);
    }

    /**
     * Returns the path to the plugins bundled with Phan.
     */
    public static function getBuiltinPluginDirectory(): string
    {
        return \dirname(__DIR__, 3) . '/.phan/plugins';
    }

    private function ensurePluginsExist(): void
    {
        if (!is_null($this->plugin_set)) {
            return;
        }
        $loaded_plugin_files = [];
        $load_plugin = static function (string $plugin_file_name) use (&$loaded_plugin_files): ?PluginV3 {
            $plugin_file_name = self::normalizePluginPath($plugin_file_name);
            if (isset($loaded_plugin_files[$plugin_file_name])) {
                return null;
            }
            $loaded_plugin_files[$plugin_file_name] = true;

            if (isset(self::$plugin_instances_cache[$plugin_file_name])) {
                return clone(self::$plugin_instances_cache[$plugin_file_name]);
            }

            try {
                $plugin_instance = require($plugin_file_name);
            } catch (UnloadablePluginException $e) {
                CLI::printErrorToStderr("Could not load plugin '$plugin_file_name', proceeding without it: " . $e->getMessage() . PHP_EOL);
                return null;
            } catch (Throwable $e) {
                // An unexpected error.
                // E.g. a plugin class threw a SyntaxError because it required PHP 7.1 or newer but 7.0 was used.
                $message = \sprintf(
                    "Failed to initialize plugin %s, exiting: %s: %s at %s:%d\nStack Trace:\n%s",
                    $plugin_file_name,
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    $e->getTraceAsString()
                );
                \error_log($message);
                exit(EXIT_FAILURE);
            }

            if (!is_object($plugin_instance)) {
                throw new AssertionError("Plugins must return an instance of the plugin. The plugin at $plugin_file_name does not return an object.");
            }

            if (!($plugin_instance instanceof PluginV3)) {
                throw new AssertionError("Plugins must extend \Phan\PluginV3. The plugin at $plugin_file_name does not.");
            }

            self::$plugin_instances_cache[$plugin_file_name] = $plugin_instance;
            return $plugin_instance;
        };
        // Add user-defined plugins.
        $plugin_set = \array_values(\array_filter(\array_map(
            $load_plugin,
            Config::getValue('plugins')
        )));
        // Add internal plugins. Can be disabled by disable_internal_return_type_plugins.
        if (Config::getValue('enable_internal_return_type_plugins')) {
            $internal_return_type_plugins = [
                new ArrayReturnTypeOverridePlugin(),
                new CallableParamPlugin(),
                new CompactPlugin(),
                new ClosureReturnTypeOverridePlugin(),
                new DependentReturnTypeOverridePlugin(),
                new StringFunctionPlugin(),
                new MiscParamPlugin(),
            ];
            if (Config::getValue('enable_extended_internal_return_type_plugins')) {
                \array_unshift($internal_return_type_plugins, new ExtendedDependentReturnTypeOverridePlugin());
            }
            $plugin_set = \array_merge($internal_return_type_plugins, $plugin_set);
        }
        if (Config::getValue('redundant_condition_detection')) {
            $plugin_set[] = new RedundantConditionCallPlugin();
        }
        if (Config::getValue('enable_include_path_checks')) {
            $plugin_set[] = new RequireExistsPlugin();
        }
        $plugin_set[] = new ThrowAnalyzerPlugin();
        if (Config::getValue('unused_variable_detection') || Config::getValue('dead_code_detection')) {
            $plugin_set[] = new VariableTrackerPlugin();
        }
        if (self::requiresPluginBasedBuiltinSuppressions()) {
            if (\function_exists('token_get_all')) {
                $plugin_set[] = new BuiltinSuppressionPlugin();
            } else {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, "ext-tokenizer is required for file-based and line-based suppressions to work, as well as the error-tolerant parser fallback." . PHP_EOL);
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, "(This warning can be disabled by setting skip_missing_tokenizer_warning in the project's config)" . PHP_EOL);
            }
        }
        if (Config::getValue('dead_code_detection') && \count(self::filterByClass($plugin_set, \UnreachableCodePlugin::class)) === 0) {
            $plugin_set[] = $load_plugin('UnreachableCodePlugin');
        }

        // The baseline saving plugin will save all issues that weren't suppressed by line suppressions, file suppressions, and global Phan suppressions.
        $save_baseline_path = Config::getValue('__save_baseline_path');
        if ($save_baseline_path) {
            // TODO: Phan isn't currently capable of saving a baseline when there are multiple processes.
            $plugin_set[] = new BaselineSavingPlugin($save_baseline_path);
        }
        // NOTE: The baseline loading plugin is deliberately loaded after the saving plugin,
        // so that BaselineSavingPlugin will read the issues before they get filtered out by the baseline.
        $load_baseline_path = Config::getValue('baseline_path');
        if ($load_baseline_path) {
            if (\is_readable($load_baseline_path)) {
                $plugin_set[] = new BaselineLoadingPlugin($load_baseline_path);
            } else {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fwrite(STDERR, CLI::colorizeHelpSectionIfSupported("WARNING: ") . "Could not load baseline from file '$load_baseline_path'" . PHP_EOL);
            }
        }
        $plugin_set = \array_values(\array_filter($plugin_set));

        // Register the entire set.
        $this->plugin_set = $plugin_set;

        $this->pre_analyze_node_plugin_set          = self::filterPreAnalysisPlugins($plugin_set);
        $this->post_analyze_node_plugin_set         = self::filterPostAnalysisPlugins($plugin_set);
        $this->before_loop_body_analysis_plugin_set = self::filterBeforeLoopBodyAnalysisPlugins($plugin_set);
        $this->before_analyze_plugin_set            = self::filterByClass($plugin_set, BeforeAnalyzeCapability::class);
        $this->before_analyze_phase_plugin_set      = self::filterByClass($plugin_set, BeforeAnalyzePhaseCapability::class);
        $this->before_analyze_file_plugin_set       = self::filterByClass($plugin_set, BeforeAnalyzeFileCapability::class);
        $this->after_analyze_file_plugin_set        = self::filterByClass($plugin_set, AfterAnalyzeFileCapability::class);
        $this->analyze_method_plugin_set            = self::filterByClass($plugin_set, AnalyzeMethodCapability::class);
        $this->analyze_function_plugin_set          = self::filterByClass($plugin_set, AnalyzeFunctionCapability::class);
        $this->analyze_literal_statement_plugin_set = self::filterByClass($plugin_set, AnalyzeLiteralStatementCapability::class);
        $this->analyze_property_plugin_set          = self::filterByClass($plugin_set, AnalyzePropertyCapability::class);
        $this->analyze_class_plugin_set             = self::filterByClass($plugin_set, AnalyzeClassCapability::class);
        $this->finalize_process_plugin_set          = self::filterByClass($plugin_set, FinalizeProcessCapability::class);
        $this->return_type_override_plugin_set      = self::filterByClass($plugin_set, ReturnTypeOverrideCapability::class);
        $this->subscribe_emit_issue_plugin_set      = self::filterByClass($plugin_set, SubscribeEmitIssueCapability::class);
        $this->suppression_plugin_set               = self::filterByClass($plugin_set, SuppressionCapability::class);
        $this->analyze_function_call_plugin_set     = self::filterByClass($plugin_set, AnalyzeFunctionCallCapability::class);
        $this->handle_lazy_load_internal_function_plugin_set = self::filterByClass($plugin_set, HandleLazyLoadInternalFunctionCapability::class);
        $this->unused_suppression_plugin        = self::findUnusedSuppressionPlugin($plugin_set);
        self::registerIssueFixerClosures($plugin_set);
        self::registerMergeVariableInfoClosure($plugin_set);
    }

    /**
     * @param list<PluginV3> $plugin_set
     */
    private static function registerIssueFixerClosures(array $plugin_set): void
    {
        if (!Config::isIssueFixingPluginEnabled()) {
            // Don't load these if we won't need them.
            return;
        }
        // NOTE: Currently limited to exactly one closure per issue type
        // (the last plugin ends up taking precedence)
        foreach (self::filterByClass($plugin_set, AutomaticFixCapability::class) as $fixer) {
            foreach ($fixer->getAutomaticFixers() as $issue_type => $closure) {
                IssueFixer::registerFixerClosure($issue_type, $closure);
            }
        }
    }

    /**
     * @param list<PluginV3> $plugin_set
     */
    private static function registerMergeVariableInfoClosure(array $plugin_set): void
    {
        $closures = [];
        foreach (self::filterByClass($plugin_set, MergeVariableInfoCapability::class) as $plugin) {
            $closures[] = $plugin->getMergeVariableInfoClosure();
        }

        // If we have no plugins registering closures, no overall closure is needed
        if (!$closures) {
            self::$mergeVariableInfoClosure = null;
            return;
        }

        // If we have only one plugin registering a closure, just use that
        if (count($closures) === 1) {
            self::$mergeVariableInfoClosure = reset($closures);
            return;
        }

        // If we have multiple plugins registering closures, combine them
        /**
         * @param list<Scope> $child_scopes
         */
        self::$mergeVariableInfoClosure = static function (Variable $variable, array $child_scopes, bool $var_exists_in_all_branches) use ($closures): void {
            foreach ($closures as $c) {
                $c($variable, $child_scopes, $var_exists_in_all_branches);
            }
        };
    }

    private static function requiresPluginBasedBuiltinSuppressions(): bool
    {
        if (Config::getValue('disable_suppression')) {
            return false;
        }
        if (Config::getValue('disable_line_based_suppression') && Config::getValue('disable_file_based_suppression')) {
            return false;
        }
        return true;
    }

    /**
     * @param list<PluginV3> $plugin_set
     * @return associative-array<int, Closure(CodeBase,Context,Node,list<Node>=):void>
     *         Returned value maps ast\Node->kind to [function(CodeBase $code_base, Context $context, Node $node, list<Node> $parent_node_list = []): void]
     */
    private static function filterPreAnalysisPlugins(array $plugin_set): array
    {
        $closures_for_kind = new ClosuresForKind();
        foreach ($plugin_set as $plugin) {
            if ($plugin instanceof PreAnalyzeNodeCapability) {
                self::addClosuresForPreAnalyzeNodeCapability($closures_for_kind, $plugin);
            }
        }
        /**
         * @param list<Closure> $closure_list
         */
        return $closures_for_kind->getFlattenedClosures(static function (array $closure_list): Closure {
            return static function (CodeBase $code_base, Context $context, Node $node) use ($closure_list): void {
                foreach ($closure_list as $closure) {
                    $closure($code_base, $context, $node);
                }
            };
        });
    }

    private static function addClosuresForPreAnalyzeNodeCapability(
        ClosuresForKind $closures_for_kind,
        PreAnalyzeNodeCapability $plugin
    ): void {
        $plugin_analysis_class = $plugin->getPreAnalyzeNodeVisitorClassName();
        if (!\is_subclass_of($plugin_analysis_class, PluginAwarePreAnalysisVisitor::class)) {
            throw new \TypeError(
                \sprintf(
                    "Result of %s::getAnalyzeNodeVisitorClassName must be the name of a subclass of '%s', but '%s' is not",
                    \get_class($plugin),
                    PluginAwarePreAnalysisVisitor::class,
                    $plugin_analysis_class
                )
            );
        }
        // @see PreAnalyzeNodeCapability (magic to create parent_node_list)
        $closure = self::getGenericClosureForPluginAwarePreAnalysisVisitor($plugin_analysis_class);
        $handled_node_kinds = $plugin_analysis_class::getHandledNodeKinds();
        if (!$handled_node_kinds) {
            // @phan-suppress-next-line PhanPluginRemoveDebugCall
            \fprintf(
                STDERR,
                "Plugin %s has a preAnalyzeNode visitor %s (subclass of %s) which doesn't override any known visit<Suffix>() methods, but expected at least one method to be overridden\n",
                \get_class($plugin),
                $plugin_analysis_class,
                PluginAwarePreAnalysisVisitor::class
            );
        }
        $closures_for_kind->recordForKinds($handled_node_kinds, $closure);
    }

    /**
     * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind, in pre-order.
     *
     * @return Closure(CodeBase,Context,Node,array=)
     */
    private static function getGenericClosureForPluginAwarePreAnalysisVisitor(string $plugin_analysis_class): Closure
    {
        if (property_exists($plugin_analysis_class, 'parent_node_list')) {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @param list<Node> $parent_node_list
             * @phan-closure-scope PluginAwarePreAnalysisVisitor
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                // @phan-suppress-next-line PhanUndeclaredProperty checked via $has_parent_node_list
                $visitor->parent_node_list = $parent_node_list;
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        } else {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @phan-closure-scope PluginAwarePreAnalysisVisitor
             * @param list<Node> $unused_parent_node_list
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $unused_parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        }
    }

    /**
     * @param list<PluginV3> $plugin_set
     * @return associative-array<int, \Closure> - [Node kind => function(CodeBase $code_base, Context $context, Node $node, list<Node> $parent_node_list = []): void]
     */
    private static function filterPostAnalysisPlugins(array $plugin_set): array
    {
        $closures_for_kind = new ClosuresForKind();
        foreach ($plugin_set as $plugin) {
            if ($plugin instanceof PostAnalyzeNodeCapability) {
                self::addClosuresForPostAnalyzeNodeCapability($closures_for_kind, $plugin);
            }
        }
        /**
         * @param list<Closure> $closure_list
         */
        return $closures_for_kind->getFlattenedClosures(static function (array $closure_list): Closure {
            /**
             * @param list<Node> $parent_node_list
             */
            return static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []) use ($closure_list): void {
                foreach ($closure_list as $closure) {
                    $closure($code_base, $context, $node, $parent_node_list);
                }
            };
        });
    }

    /**
     * @throws \TypeError if the returned getPostAnalyzeNodeVisitorClassName() is invalid
     */
    private static function addClosuresForPostAnalyzeNodeCapability(
        ClosuresForKind $closures_for_kind,
        PostAnalyzeNodeCapability $plugin
    ): void {
        $plugin_analysis_class = $plugin->getPostAnalyzeNodeVisitorClassName();
        if (!\is_subclass_of($plugin_analysis_class, PluginAwarePostAnalysisVisitor::class)) {
            throw new \TypeError(
                \sprintf(
                    "Result of %s::getAnalyzeNodeVisitorClassName must be the name of a subclass of '%s', but '%s' is not",
                    \get_class($plugin),
                    PluginAwarePostAnalysisVisitor::class,
                    $plugin_analysis_class
                )
            );
        }

        // @see PostAnalyzeNodeCapability (magic to create parent_node_list)
        $closure = self::getGenericClosureForPluginAwarePostAnalysisVisitor($plugin_analysis_class);

        $handled_node_kinds = $plugin_analysis_class::getHandledNodeKinds();
        if (!$handled_node_kinds) {
            // @phan-suppress-next-line PhanPluginRemoveDebugCall
            \fprintf(
                STDERR,
                "Plugin %s has an analyzeNode visitor %s (subclass of %s) which doesn't override any known visit<Suffix>() methods, but expected at least one method to be overridden\n",
                \get_class($plugin),
                $plugin_analysis_class,
                PluginAwarePostAnalysisVisitor::class
            );
        }
        $closures_for_kind->recordForKinds($handled_node_kinds, $closure);
    }

    /**
     * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind, in post-order.
     *
     * @return Closure(CodeBase,Context,Node,array=)
     */
    private static function getGenericClosureForPluginAwarePostAnalysisVisitor(string $plugin_analysis_class): Closure
    {
        if (property_exists($plugin_analysis_class, 'parent_node_list')) {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @phan-closure-scope PluginAwarePostAnalysisVisitor
             * @param list<Node> $parent_node_list
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                // @phan-suppress-next-line PhanUndeclaredProperty checked via $has_parent_node_list
                $visitor->parent_node_list = $parent_node_list;
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        } else {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @phan-closure-scope PluginAwarePostAnalysisVisitor
             * @param list<Node> $unused_parent_node_list
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $unused_parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        }
    }

    /**
     * @param list<PluginV3> $plugin_set
     * @return associative-array<int, \Closure>
     *   Shape: [Node kind => function(CodeBase $code_base, Context $context, Node $node, list<Node> $parent_node_list = []): void]
     */
    private static function filterBeforeLoopBodyAnalysisPlugins(array $plugin_set): array
    {
        $closures_for_kind = new ClosuresForKind();
        foreach ($plugin_set as $plugin) {
            if ($plugin instanceof BeforeLoopBodyAnalysisCapability) {
                self::addClosuresForBeforeLoopBodyAnalysisCapability($closures_for_kind, $plugin);
            }
        }
        /**
         * @param list<Closure> $closure_list
         */
        return $closures_for_kind->getFlattenedClosures(static function (array $closure_list): Closure {
            /**
             * @param list<Node> $parent_node_list
             */
            return static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []) use ($closure_list): void {
                foreach ($closure_list as $closure) {
                    $closure($code_base, $context, $node, $parent_node_list);
                }
            };
        });
    }

    /**
     * @throws \TypeError if the returned getBeforeLoopBodyAnalysisVisitorClassName() is invalid
     */
    private static function addClosuresForBeforeLoopBodyAnalysisCapability(
        ClosuresForKind $closures_for_kind,
        BeforeLoopBodyAnalysisCapability $plugin
    ): void {
        $plugin_analysis_class = $plugin->getBeforeLoopBodyAnalysisVisitorClassName();
        if (!\is_subclass_of($plugin_analysis_class, BeforeLoopBodyAnalysisVisitor::class)) {
            throw new \TypeError(
                \sprintf(
                    "Result of %s::getBeforeLoopBodyAnalysisVisitorClassName must be the name of a subclass of '%s', but '%s' is not",
                    \get_class($plugin),
                    BeforeLoopBodyAnalysisVisitor::class,
                    $plugin_analysis_class
                )
            );
        }

        // @see BeforeLoopBodyAnalysisCapability (magic to create parent_node_list)
        $closure = self::getGenericClosureForBeforeLoopBodyAnalysisVisitor($plugin_analysis_class);

        $handled_node_kinds = $plugin_analysis_class::getHandledNodeKinds();
        if (!$handled_node_kinds) {
            // @phan-suppress-next-line PhanPluginRemoveDebugCall
            \fprintf(
                STDERR,
                "Plugin %s has an analyzeNode visitor %s (subclass of %s) which doesn't override any known visit<Suffix>() methods, but expected at least one method to be overridden\n",
                \get_class($plugin),
                $plugin_analysis_class,
                BeforeLoopBodyAnalysisVisitor::class
            );
        } else {
            $expected_kinds = [ \ast\AST_FOR, \ast\AST_FOREACH, \ast\AST_WHILE ];
            // TODO(tyson) figure out why Phan isn't warning about this example not being fully qualified outside of the language server.
            // --disable-plugins --quick --plugin NotFullyQualifiedUsagePlugin properly warns.
            $additional_kinds = \array_diff($handled_node_kinds, $expected_kinds);
            if ($additional_kinds) {
                throw new AssertionError(
                    \sprintf(
                        "The following node kinds cannot be used in %s: %s",
                        $plugin_analysis_class,
                        \implode(', ', \array_map([Parser::class, 'getKindName'], $additional_kinds))
                    )
                );
            }
        }
        $closures_for_kind->recordForKinds($handled_node_kinds, $closure);
    }

    /**
     * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind
     *
     * @return Closure(CodeBase,Context,Node,array=)
     */
    private static function getGenericClosureForBeforeLoopBodyAnalysisVisitor(string $plugin_analysis_class): Closure
    {
        if (property_exists($plugin_analysis_class, 'parent_node_list')) {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @phan-closure-scope BeforeLoopBodyAnalysisVisitor
             * @param list<Node> $parent_node_list
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                // @phan-suppress-next-line PhanUndeclaredProperty checked via $has_parent_node_list
                $visitor->parent_node_list = $parent_node_list;
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        } else {
            /**
             * Create an instance of $plugin_analysis_class and run the visit*() method corresponding to $node->kind.
             *
             * @phan-closure-scope BeforeLoopBodyAnalysisVisitor
             * @param list<Node> $unused_parent_node_list
             */
            return (static function (CodeBase $code_base, Context $context, Node $node, array $unused_parent_node_list = []): void {
                $visitor = new static($code_base, $context);
                $fn_name = Element::VISIT_LOOKUP_TABLE[$node->kind];
                $visitor->{$fn_name}($node);
            })->bindTo(null, $plugin_analysis_class);
        }
    }

    /**
     * @template T
     * @param list<PluginV3> $plugin_set
     * @param class-string<T> $interface_name
     * @return list<PluginV3&T>
     * @suppress PhanPartialTypeMismatchReturn unable to infer this
     */
    private static function filterByClass(array $plugin_set, string $interface_name): array
    {
        $result = [];
        foreach ($plugin_set as $plugin) {
            if ($plugin instanceof $interface_name) {
                $result[] = $plugin;
            }
        }
        return $result;
    }

    /**
     * @param PluginV3[] $plugin_set
     */
    private static function findUnusedSuppressionPlugin(array $plugin_set): ?UnusedSuppressionPlugin
    {
        foreach ($plugin_set as $plugin) {
            // Don't use instanceof, avoid triggering class autoloader unnecessarily.
            // (load one less file)
            if (\get_class($plugin) === UnusedSuppressionPlugin::class) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * If an internal function is loaded after the start of the analysis phase,
     * notify plugins in case they need to make modifications to the Func information or the way that Func is handled.
     */
    public function handleLazyLoadInternalFunction(CodeBase $code_base, Func $function): void
    {
        if (!$this->did_analyze_phase_start) {
            return;
        }
        foreach ($this->handle_lazy_load_internal_function_plugin_set as $plugin) {
            $plugin->handleLazyLoadInternalFunction($code_base, $function);
        }
    }
}
