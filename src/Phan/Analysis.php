<?php

declare(strict_types=1);

namespace Phan;

use ast;
use ast\Node;
use CompileError;
use InvalidArgumentException;
use ParseError;
use Phan\Analysis\AttributeAnalyzer;
use Phan\Analysis\DuplicateFunctionAnalyzer;
use Phan\Analysis\ParameterTypesAnalyzer;
use Phan\Analysis\ReferenceCountsAnalyzer;
use Phan\Analysis\ThrowsTypesAnalyzer;
use Phan\AST\ASTSimplifier;
use Phan\AST\Parser;
use Phan\AST\PhanAnnotationAdder;
use Phan\AST\TolerantASTConverter\ParseException;
use Phan\AST\Visitor\Element;
use Phan\Daemon\Request;
use Phan\Exception\FQSENException;
use Phan\Exception\RecursionDepthException;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Language\Element\FunctionInterface;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Library\FileCache;
use Phan\Library\StringUtil;
use Phan\Parse\ParseVisitor;
use Phan\Plugin\ConfigPluginSet;
use Throwable;

use function count;
use function strlen;

use const STDERR;

/**
 * This class is the entry point into the static analyzer.
 */
class Analysis
{
    /**
     * This first pass parses code and looks for the subset
     * of issues that can be found without having to have
     * an understanding of the entire code base.
     *
     * @param CodeBase $code_base
     * The CodeBase represents state across the entire
     * code base. This is a mutable object which is
     * populated as we parse files
     *
     * @param string $file_path
     * The full path to a file we'd like to parse
     *
     * @param bool $suppress_parse_errors
     *
     * @param ?string $override_contents
     * If this is not null, this function will act as if $file_path's contents
     * were $override_contents
     *
     * @param bool $is_php_internal_stub
     * If this is true, this function will act as though the parsed constants, functions, and classes are actually part of PHP or it's extension's internals.
     * See autoload_internal_extension_signatures.
     * @param ?Request $request probably a \Phan\Daemon\ParseRequest during language server mode.
     * @throws InvalidArgumentException for invalid stub files
     */
    public static function parseFile(CodeBase $code_base, string $file_path, bool $suppress_parse_errors = false, ?string $override_contents = null, bool $is_php_internal_stub = false, ?Request $request = null): Context
    {
        $original_file_path = $file_path;
        $code_base->setCurrentParsedFile($file_path);
        if ($is_php_internal_stub) {
            /** @see \Phan\Language\FileRef::isPHPInternal() */
            $file_path = 'internal';
        }
        $context = (new Context())->withFile($file_path);

        // Convert the file to an Abstract Syntax Tree
        // before passing it on to the recursive version
        // of this method

        $real_file_path = Config::projectPath($original_file_path);
        if (\is_string($override_contents)) {
            // TODO: Make $override_contents a persistent entry in FileCache, make Request and language server manage this
            $cache_entry = FileCache::addEntry($real_file_path, $override_contents);
        } else {
            $cache_entry = FileCache::getOrReadEntry($real_file_path);
        }
        $file_contents = $cache_entry->getContents();
        if ($file_contents === '') {
            if ($is_php_internal_stub) {
                throw new InvalidArgumentException("Unexpected empty php file for autoload_internal_extension_signatures: path=" . StringUtil::jsonEncode($original_file_path));
            }
            // php-ast would return null for 0 byte files as an implementation detail.
            // Make Phan consistently emit this warning.
            Issue::maybeEmit(
                $code_base,
                $context,
                Issue::EmptyFile,
                0,
                $original_file_path
            );

            return $context;
        }
        // TODO: Figure out why Phan doesn't suggest combining these catches except in language server mode
        try {
            $node = Parser::parseCode($code_base, $context, $request, $file_path, $file_contents, $suppress_parse_errors);
        } catch (ParseError | CompileError | ParseException $_) {
            return $context;
        }

        if (Config::getValue('dump_ast')) {
            // @phan-file-suppress PhanPluginRemoveDebugEcho
            echo $file_path . "\n"
                . \str_repeat("\u{00AF}", strlen($file_path))
                . "\n";
            Debug::printNode($node);
            return $context;
        }

        if (Config::getValue('simplify_ast')) {
            try {
                // Transform the original AST, and if successful, then analyze the new AST instead of the original
                $node = ASTSimplifier::applyStatic($node);
            } catch (\Exception $e) {
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::SyntaxError,  // Not the right kind of error. I don't think it would throw, anyway.
                    $e->getLine(),
                    $e->getMessage()
                );
            }
        }

        $context = self::parseNodeInContext(
            $code_base,
            $context,
            $node
        );
        // @phan-suppress-next-line PhanAccessMethodInternal
        $code_base->addParsedNamespaceMap($context->getFile(), $context->getNamespace(), $context->getNamespaceId(), $context->getNamespaceMap());
        return $context;
    }

    /**
     * Parse the given node in the given context populating
     * the code base within the context as a side effect. The
     * returned context is the new context from within the
     * given node.
     *
     *
     * @param CodeBase $code_base
     * The global code base in which we store all
     * state
     *
     * @param Context $context
     * The context in which this node exists
     *
     * @param Node $node
     * A node to parse and scan for errors
     *
     * @return Context
     * The context from within the node is returned
     * @suppress PhanPluginCanUseReturnType
     * NOTE: This is called extremely frequently, so the real signature types were omitted for performance.
     */
    public static function parseNodeInContext(CodeBase $code_base, Context $context, Node $node)
    {
        $kind = $node->kind;
        $context->setLineNumberStart($node->lineno);

        // Visit the given node populating the code base
        // with anything we learn and get a new context
        // indicating the state of the world within the
        // given node.
        // NOTE: This is called extremely frequently
        // (E.g. on a large number of the analyzed project's vendor dependencies,
        // proportionally to the node count in the files), so code style was sacrificed for performance.
        // Equivalent to (new ParseVisitor(...))($node), which uses ParseVisitor->__invoke
        $inner_context = (new ParseVisitor(
            $code_base,
            $context
        ))->{Element::VISIT_LOOKUP_TABLE[$kind] ?? 'handleMissingNodeKind'}($node);

        // ast\AST_GROUP_USE has ast\AST_USE as a child.
        // We don't want to use block twice in the parse phase.
        // (E.g. `use MyNS\{const A, const B}` would lack the MyNs part if this were to recurse.
        // And ast\AST_DECLARE has AST_CONST_DECL as a child, so don't parse a constant declaration either.
        if ($kind === ast\AST_GROUP_USE) {
            return $inner_context;
        }
        if ($kind === ast\AST_DECLARE) {
            // Check for class declarations, etc. within the statements of a declare directive.
            $child_node = $node->children['stmts'];
            if (\is_object($child_node)) {
                // Step into each child node and get an
                // updated context for the node
                return self::parseNodeInContext($code_base, $inner_context, $child_node);
            }
            return $inner_context;
        }

        // Recurse into each child node
        $child_context = $inner_context;
        foreach ($node->children as $child_node) {
            // Skip any non Node children.
            if (\is_object($child_node)) {
                // Step into each child node and get an
                // updated context for the node
                $child_context = self::parseNodeInContext($code_base, $child_context, $child_node);
            }
        }

        switch ($kind) {
            case ast\AST_CLASS:
            case ast\AST_METHOD:
            case ast\AST_FUNC_DECL:
            case ast\AST_ARROW_FUNC:
            case ast\AST_CLOSURE:
                // For closed context elements (that have an inner scope)
                // return the outer context instead of their inner context
                // after we finish parsing their children.
                return $context;
            case ast\AST_STMT_LIST:
                // Workaround that ensures that the context from namespace blocks gets passed to the caller.
                return $child_context;
            default:
                // Pass the context back up to our parent
                return $inner_context;
        }
    }

    /**
     * Take a pass over all functions verifying various states.
     *
     * @param ?array<string,mixed> $file_filter if non-null, limit analysis to functions and methods declared in this array
     */
    public static function analyzeFunctions(CodeBase $code_base, ?array $file_filter = null): void
    {
        $plugin_set = ConfigPluginSet::instance();
        $has_function_or_method_plugins = $plugin_set->hasAnalyzeFunctionPlugins() || $plugin_set->hasAnalyzeMethodPlugins();
        $show_progress = CLI::shouldShowProgress();
        $analyze_function_or_method = static function (FunctionInterface $function_or_method) use (
            $code_base,
            $plugin_set,
            $has_function_or_method_plugins,
            $file_filter
        ): void {
            if ($function_or_method->isPHPInternal()) {
                return;
            }
            // Phan always has to call this, to add default values to types of parameters.
            $function_or_method->ensureScopeInitialized($code_base);

            // If there is an array limiting the set of files, skip this file if it's not in the list.
            if (\is_array($file_filter) && !isset($file_filter[$function_or_method->getContext()->getFile()])) {
                return;
            }

            DuplicateFunctionAnalyzer::analyzeDuplicateFunction(
                $code_base,
                $function_or_method
            );

            // This is the most time consuming step.
            // Can probably apply this to other functions, but this was the slowest.
            ParameterTypesAnalyzer::analyzeParameterTypes(
                $code_base,
                $function_or_method
            );

            AttributeAnalyzer::analyzeAttributesOfFunctionInterface(
                $code_base,
                $function_or_method
            );

            // Infer more accurate return types
            // For daemon mode/the language server, we also call this whenever we use the return type of a function/method.
            $function_or_method->analyzeReturnTypes($code_base);

            ThrowsTypesAnalyzer::analyzeThrowsTypes(
                $code_base,
                $function_or_method
            );
            // Let any plugins analyze the methods or functions
            // XXX: Add a way to run plugins on all functions/methods, this was limited for speed.
            // Assumes that the given plugins will emit an issue in the same file as the function/method,
            // which isn't necessarily the case.
            // 0.06
            if ($has_function_or_method_plugins) {
                if ($function_or_method instanceof Func) {
                    $plugin_set->analyzeFunction(
                        $code_base,
                        $function_or_method
                    );
                } elseif ($function_or_method instanceof Method) {
                    $plugin_set->analyzeMethod(
                        $code_base,
                        $function_or_method
                    );
                }
            }
        };

        // Analyze user-defined method declarations.
        // Plugins may also analyze user-defined methods here.
        $i = 0;
        CLI::progress('function', 0.0, null);
        $function_map = $code_base->getFunctionMap();
        foreach ($function_map as $function) {  // iterate, ignoring $fqsen
            if ($show_progress) {
                CLI::progress('function', (++$i) / (count($function_map)), $function);
            }
            $analyze_function_or_method($function);
        }

        // Analyze user-defined method declarations.
        // Plugins may also analyze user-defined methods here.
        $i = 0;
        $method_set = $code_base->getMethodSet();
        CLI::progress('method', 0.0, null);
        foreach ($method_set as $method) {
            if ($show_progress) {
                // I suspect that method analysis is hydrating some of the classes,
                // adding even more inherited methods to the end of the set.
                // This recalculation is needed so that the progress bar is accurate.
                CLI::progress('method', (++$i) / (count($method_set)), $method);
            }
            $analyze_function_or_method($method);
        }
    }

    /**
     * Loads extra logic for analyzing function and method calls.
     * @suppress PhanPluginUnknownObjectMethodCall TODO: Fix for ArrayObject<FullyQualifiedMethodName>
     */
    public static function loadMethodPlugins(CodeBase $code_base): void
    {
        $plugin_set = ConfigPluginSet::instance();
        $return_type_overrides = $plugin_set->getReturnTypeOverrides($code_base);
        $return_type_override_fqsen_strings = [];
        foreach ($return_type_overrides as $fqsen_string => $unused_closure) {
            if (\strpos($fqsen_string, '::') !== false) {
                try {
                    $fqsen = FullyQualifiedMethodName::fromFullyQualifiedString($fqsen_string);
                } catch (FQSENException | InvalidArgumentException $e) {
                    // @phan-suppress-next-line PhanPluginRemoveDebugCall
                    \fprintf(STDERR, "getReturnTypeOverrides returned an invalid FQSEN %s: %s\n", $fqsen_string, $e->getMessage());
                    continue;
                }

                // The FQSEN that's actually in the code base is allowed to differ from what the plugin used as an array key.
                // Thus, we use $fqsen->__toString() rather than $fqsen_string.
                $return_type_override_fqsen_strings[$fqsen->__toString()] = true;
            }
        }

        $methods_by_defining_fqsen = null;
        foreach ($return_type_overrides as $fqsen_string => $closure) {
            try {
                if (\strpos($fqsen_string, '::') !== false) {
                    $fqsen = FullyQualifiedMethodName::fromFullyQualifiedString($fqsen_string);
                    $class_fqsen = $fqsen->getFullyQualifiedClassName();
                    // We have to call hasClassWithFQSEN before calling hasMethodWithFQSEN in order to autoload the internal function signatures.
                    // TODO: Move class autoloading into hasMethodWithFQSEN()?
                    if ($code_base->hasClassWithFQSEN($class_fqsen)) {
                        // This is an override of a method.
                        if ($code_base->hasMethodWithFQSEN($fqsen)) {
                            // The $fqsen_string from a plugin can refer to a method
                            // that is not the original definition.
                            $method = $code_base->getMethodByFQSEN($fqsen);
                            $method->setDependentReturnTypeClosure($closure);

                            $methods_by_defining_fqsen = $methods_by_defining_fqsen ?? $code_base->getMethodsMapGroupedByDefiningFQSEN();
                            if (!$methods_by_defining_fqsen->offsetExists($fqsen)) {
                                continue;
                            }

                            // 1) The FQSEN that's actually in the code base is allowed to differ from what the plugin used as an array key.
                            //      Thus, we use $fqsen->__toString() rather than $fqsen_string.
                            // 2) The parent method is included in this list, i.e. the parent method is its own defining method.
                            foreach ($methods_by_defining_fqsen->offsetGet($fqsen) as $child_method) {
                                if ($child_method->getFQSEN() !== $fqsen &&
                                    isset($return_type_override_fqsen_strings[$child_method->getFQSEN()->__toString()])
                                ) {
                                    // An override closure targeting SubClass::foo should take precedence over BaseClass::foo
                                    // even if the definition was BaseClass::foo
                                    continue;
                                }
                                $child_method->setDependentReturnTypeClosure($closure);
                            }
                        }
                    }
                } else {
                    // This is an override of a function.
                    $fqsen = FullyQualifiedFunctionName::fromFullyQualifiedString($fqsen_string);
                    if ($code_base->hasFunctionWithFQSEN($fqsen)) {
                        $function = $code_base->getFunctionByFQSEN($fqsen);
                        $function->setDependentReturnTypeClosure($closure);
                    }
                }
            } catch (FQSENException | InvalidArgumentException $e) {
                // @phan-suppress-next-line PhanPluginRemoveDebugCall
                \fprintf(STDERR, "getReturnTypeOverrides returned an invalid FQSEN %s: %s\n", $fqsen_string, $e->getMessage());
            }
        }

        // To fix issues such as https://github.com/phan/phan/issues/4090, this (1) always calls addFunctionCallAnalyzer,
        // and (2) automatically loads definitions for functions and methods by calling hasFunctionWithFQSEN (etc.), so that internal function definitions used by previous plugins can be iterated over by the next plugin being called (e.g. if getFunctionSet is called).
        // @phan-suppress-next-line PhanAccessMethodInternal
        foreach ($plugin_set->getAnalyzeFunctionCallPluginSet() as $plugin) {
            foreach ($plugin->getAnalyzeFunctionCallClosures($code_base) as $fqsen_string => $closure) {
                try {
                    if (\strpos($fqsen_string, '::') !== false) {
                        // This is an override of a method.
                        [$class, $method_name] = \explode('::', $fqsen_string, 2);
                        $class_fqsen = FullyQualifiedClassName::fromFullyQualifiedString($class);
                        if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                            continue;
                        }
                        $class = $code_base->getClassByFQSEN($class_fqsen);
                        // Note: This is used because it will create methods such as __construct if they do not exist.
                        if ($class->hasMethodWithName($code_base, $method_name, false)) {
                            $method = $class->getMethodByName($code_base, $method_name);
                            $method->addFunctionCallAnalyzer($closure, $plugin);

                            $methods_by_defining_fqsen = $methods_by_defining_fqsen ?? $code_base->getMethodsMapGroupedByDefiningFQSEN();
                            $fqsen = FullyQualifiedMethodName::fromFullyQualifiedString($fqsen_string);
                            if (!$methods_by_defining_fqsen->offsetExists($fqsen)) {
                                continue;
                            }

                            foreach ($methods_by_defining_fqsen->offsetGet($fqsen) as $child_method) {
                                $child_method->addFunctionCallAnalyzer($closure, $plugin);
                            }
                        }
                    } else {
                        // This is a function.
                        $fqsen = FullyQualifiedFunctionName::fromFullyQualifiedString($fqsen_string);
                        if ($code_base->hasFunctionWithFQSEN($fqsen)) {
                            $function = $code_base->getFunctionByFQSEN($fqsen);
                            $function->addFunctionCallAnalyzer($closure, $plugin);
                        }
                    }
                } catch (FQSENException | InvalidArgumentException $e) {
                    // @phan-suppress-next-line PhanPluginRemoveDebugCall
                    \fprintf(STDERR, "getAnalyzeFunctionCallClosures returned an invalid FQSEN %s: %s\n", $fqsen_string, $e->getMessage());
                }
            }
        }
    }

    /**
     * Take a pass over all classes/traits/interfaces
     * verifying various states.
     *
     * @param ?array<string,mixed> $path_filter if non-null, limit analysis to classes in this array
     */
    public static function analyzeClasses(CodeBase $code_base, ?array $path_filter = null): void
    {
        CLI::progress('classes', 0.0, null);
        $classes = $code_base->getUserDefinedClassMap();
        if (\is_array($path_filter)) {
            // If a list of files is provided, then limit analysis to classes defined in those files.
            $old_classes = $classes;
            $classes = [];
            foreach ($old_classes as $class) {
                if (isset($path_filter[$class->getContext()->getFile()])) {
                    $classes[] = $class;
                }
            }
        }
        $i = 0;
        foreach ($classes as $class) {
            CLI::progress('classes', $i++ / count($classes), null);
            try {
                $class->analyze($code_base);
            } catch (RecursionDepthException $_) {
                continue;
            }
            AttributeAnalyzer::analyzeAttributesOfClass(
                $code_base,
                $class
            );
        }
        CLI::progress('classes', 1.0, null);
    }

    /**
     * Take a look at all globally accessible elements and see if
     * we can find any dead code that is never referenced
     */
    public static function analyzeDeadCode(CodeBase $code_base): void
    {
        // Check to see if dead code detection is enabled. Keep
        // in mind that the results here are just a guess and
        // we can't tell with certainty that anything is
        // definitely unreferenced.
        if (!Config::getValue('dead_code_detection')) {
            return;
        }

        ReferenceCountsAnalyzer::analyzeReferenceCounts($code_base);
    }

    /**
     * Once we know what the universe looks like we
     * can scan for more complicated issues.
     *
     * @param CodeBase $code_base
     * The global code base holding all state
     *
     * @param ?Request $request
     * A daemon mode request if in daemon mode. May affect the parser used for $file_path
     *
     * @param ?string $override_contents
     * If this is not null, this function will act as if $file_path's contents
     * were $override_contents
     */
    public static function analyzeFile(
        CodeBase $code_base,
        string $file_path,
        ?Request $request,
        ?string $override_contents = null
    ): Context {
        // Set the file on the context
        $context = (new Context())->withFile($file_path);
        // @phan-suppress-next-line PhanAccessMethodInternal
        $context->importNamespaceMapFromParsePhase($code_base);

        $code_base->setCurrentAnalyzedFile($file_path);

        // Convert the file to an Abstract Syntax Tree
        // before passing it on to the recursive version
        // of this method
        try {
            $real_file_path = Config::projectPath($file_path);
            if (\is_string($override_contents)) {
                $cache_entry = FileCache::addEntry($real_file_path, $override_contents);
            } else {
                $cache_entry = FileCache::getOrReadEntry($real_file_path);
            }
            $file_contents = $cache_entry->getContents();
            if ($file_contents === '') {
                // php-ast would return null for 0 byte files as an implementation detail.
                // Make Phan consistently emit this warning.
                Issue::maybeEmit(
                    $code_base,
                    $context,
                    Issue::EmptyFile,
                    0,
                    $file_path
                );

                return $context;
            }
            $node = Parser::parseCode($code_base, $context, $request, $file_path, $file_contents, false);
        } catch (ParseException | ParseError | CompileError $_) {
            // Issue::SyntaxError was already emitted.
            return $context;
        }

        if (Config::getValue('simplify_ast')) {
            try {
                // Transform the original AST, and if successful, then analyze the new AST instead of the original
                $node = ASTSimplifier::applyStatic($node);
            } catch (\Exception $e) {
                // Not the right kind of Issue to show to the user. I don't think it would throw, anyway.
                self::emitSyntaxError($code_base, $context, $e);
            }
        }
        PhanAnnotationAdder::applyFull($node);

        ConfigPluginSet::instance()->beforeAnalyzeFile($code_base, $context, $file_contents, $node);

        $context = (new BlockAnalysisVisitor($code_base, $context))($node);
        // @phan-suppress-next-line PhanAccessMethodInternal
        $context->warnAboutUnusedUseElements($code_base);

        ConfigPluginSet::instance()->afterAnalyzeFile($code_base, $context, $file_contents, $node);
        return $context;
    }

    private static function emitSyntaxError(
        CodeBase $code_base,
        Context $context,
        Throwable $e
    ): void {
        Issue::maybeEmit(
            $code_base,
            $context,
            Issue::SyntaxError,
            $e->getLine(),
            $e->getMessage()
        );
    }
}
