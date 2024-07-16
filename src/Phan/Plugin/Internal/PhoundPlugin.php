<?php

declare(strict_types=1);

use ast\Node;
use Phan\AST\ContextNode;
use Phan\Language\Context;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\CodeBase;
use Phan\Language\Element\ClassElement;
use Phan\Config;

/**
 * Populates a sqlite database with callsites of class elements. Class elements include
 * methods, static methods, properties, static properties, and constants. The database
 * can be queried to find callsites of a given class element.
 *
 * Examples:
 *
 * 1) Search for callsites of the \Foo::bar method:
 *     select * from callsites where element = '\Foo::bar' and type = 'method' order by callsite
 *
 * 2) Search for callsites of the \Foo::bar method in a specific directory 'my_directory':
 *     select * from callsites where element = '\Foo::bar' and type = 'method' and callsite like 'my_directory%' order by callsite
 *
 * 3) Search for callsites of the \Foo::baz property:
 *     select * from callsites where element = '\Foo::baz' and type = 'prop' order by callsite
 *
 * 4) Search for callsites of the \Foo::BANG constant:
 *     select * from callsites where element = '\Foo::BANG' and type = 'const' order by callsite
 */
final class PhoundVisitor extends PluginAwarePostAnalysisVisitor
{

    private const NUM_DB_COLS = 3; // element, type, callsite

    // Avoid `SQLite3::prepare(): Unable to prepare statement: 1, too many SQL variables`
    // See #9: https://www.sqlite.org/limits.html
    private const BULK_INSERT_SIZE = 999 / self::NUM_DB_COLS;

    /** @var SQLite3 */
    private static $db;

    /** @var SQLite3Stmt */
    private static $prepared_insert;

    /** @var list<array{string,string,string}> */
    private static $callsites = [];

    /**
     * @param CodeBase $code_base
     * @param Context  $context
     * @throws Exception
     */
    public function __construct(CodeBase $code_base, Context $context) {
        parent::__construct($code_base, $context);

        if (self::$db) {
            return;
        }

        $db_path = (string) (Config::getValue('plugin_config')['phound_sqlite_path'] ?? '');
        if ($db_path === '') {
            throw new Exception("You must specify a `plugin_config.phound_sqlite_path` in your phan configuration.");
        }
        self::$db = new SQLite3($db_path);

        if (!self::$db->exec('DROP TABLE IF EXISTS callsites')) {
            throw new Exception();
        }

        if (!self::$db->exec(
            <<<'EOD'
            create table callsites(
                element TEXT NOT NULL,
                type TEXT NOT NULL,
                callsite TEXT NOT NULL,
                PRIMARY KEY (element, type, callsite)
            )
EOD
        )) {
            throw new Exception();
        }

        if (!self::$db->exec('CREATE INDEX element_and_callsite ON callsites (element, callsite)')) {
            throw new Exception();
        }

        if (!self::$db->exec("PRAGMA synchronous = OFF")) {
            throw new Exception();
        }
        if (!self::$db->exec("PRAGMA journal_mode = OFF")) {
            throw new Exception();
        }
        if (!self::$db->exec("PRAGMA page_size = 4096")) {
            throw new Exception();
        }

        self::$prepared_insert = $this->createBulkInsertPreparedStatement(self::BULK_INSERT_SIZE);
    }

    /**
     * @param  int    $bulk_insert_size
     * @throws Exception
     */
    private static function createBulkInsertPreparedStatement(int $bulk_insert_size): SQLite3Stmt {
        $bulk_insert_sql = "INSERT OR IGNORE INTO callsites ('element', 'type', 'callsite') VALUES ";
        $bulk_insert_sql .= str_repeat("(?, ?, ?), ", $bulk_insert_size);
        $bulk_insert_sql = rtrim($bulk_insert_sql, ', ');
        $stmt = self::$db->prepare($bulk_insert_sql);
        if ($stmt === false) {
            throw new Exception();
        }
        return $stmt;
    }

    /**
     * @throws Exception
     */
    public function visitNew(Node $node)
    {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethodList('__construct', false, false, true);
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'method');
    }

    /**
     * @throws Exception
     */
    public function visitMethodCall(Node $node)
    {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethodList($node->children['method'], false, false); // @phan-suppress-current-line PhanPartialTypeMismatchArgument
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'method');
    }


    /**
     * @param Node $node a node of type AST_NULLSAFE_METHOD_CALL
     * @override
     * @throws Exception
     */
    public function visitNullsafeMethodCall(Node $node): void
    {
        $this->visitMethodCall($node);
    }

    /**
     * @throws Exception
     */
    public function visitStaticCall(Node $node)
    {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getMethodList($node->children['method'], true, false); // @phan-suppress-current-line PhanPartialTypeMismatchArgument
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'method');
    }

    /**
     * Visit a node with kind `\ast\AST_CLASS_CONST`
     * @throws Exception
     */
    public function visitClassConst(Node $node) {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getClassConstList();
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'const');
    }

    /**
     * Visit a node with kind `\ast\AST_STATIC_PROP`
     * @throws Exception
     */
    public function visitStaticProp(Node $node) {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getPropertyList(true);
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'prop');
    }

    /**
     * Visit a node with kind `\ast\AST_PROP`
     * @throws Exception
     */
    public function visitProp(Node $node) {
        try {
            $elements = (new ContextNode(
                $this->code_base,
                $this->context,
                $node
            ))->getPropertyList(false);
        } catch (Exception $_) {
            return;
        }
        $this->genericVisitClassElements($elements, 'prop');
    }

    /**
     * Visit a node with kind `\ast\AST_NULLSAFE_PROP`
     * @throws Exception
     */
    public function visitNullsafeProp(Node $node) {
        $this->visitProp($node);
    }

    /**
     * Helper function to add class elements to the DB
     * @param  list<ClassElement> $elements
     * @param  string       $type
     * @throws Exception
     */
    public function genericVisitClassElements(array $elements, string $type): void {
        foreach ($elements as $element) {
            $element_name = $element->getFQSEN()->__toString();
            $callsite = $this->context->__toString();
            self::$callsites[] = [$element_name, $type, $callsite];

            if (count(self::$callsites) >= self::BULK_INSERT_SIZE) {
                self::doBulkWrite(self::$prepared_insert);
            }
        }
    }

    /**
     * @param  SQLite3Stmt $stmt
     * @throws Exception
     */
    private static function doBulkWrite(SQLite3Stmt $stmt): void {
        sort(self::$callsites);
        $bind_index = 1;
        foreach (self::$callsites as $callsite) {
            $stmt->bindValue($bind_index, $callsite[0], SQLITE3_TEXT);
            $bind_index++;
            $stmt->bindValue($bind_index, $callsite[1], SQLITE3_TEXT);
            $bind_index++;
            $stmt->bindValue($bind_index, $callsite[2], SQLITE3_TEXT);
            $bind_index++;
        }

        if (!$stmt->execute()) {
            throw new Exception();
        }

        if (!$stmt->reset()) {
            throw new Exception();
        }

        if (!$stmt->clear()) {
            throw new Exception();
        }

        self::$callsites = [];
    }

    /**
     * Finish pending bulk writes.
     * @throws Exception
     */
    public static function finalizeProcess(): void {
        if (count(self::$callsites) <= 0) {
            return;
        }

        $stmt = self::createBulkInsertPreparedStatement(count(self::$callsites));
        self::doBulkWrite($stmt);
    }

}

use Phan\PluginV3;
use Phan\PluginV3\PostAnalyzeNodeCapability;
use Phan\PluginV3\FinalizeProcessCapability;
use Phan\PluginV3\AnalyzeFunctionCallCapability;
use Phan\AST\UnionTypeVisitor;
use Phan\Language\Element\FunctionInterface;

/**
 * Plugin to go with PhoundVisitor.
 */
final class PhoundPlugin extends PluginV3 implements PostAnalyzeNodeCapability, AnalyzeFunctionCallCapability, FinalizeProcessCapability
{

    /**
     * Returns the name of the visitor class to be instantiated and invoked to analyze a node in the analysis phase.
     * (To post-analyze a node)
     * (PostAnalyzeNodeCapability is run after PreAnalyzeNodeCapability and after analysis of child nodes)
     *
     * The class should be created by the plugin visitor, and must extend PluginAwarePostAnalysisVisitor.
     *
     * If state needs to be shared with a visitor and a plugin, a plugin author may use static variables of that plugin.
     *
     * @return string - The name of a class extending PluginAwarePostAnalysisVisitor
     */
    public static function getPostAnalyzeNodeVisitorClassName(): string
    {
        return PhoundVisitor::class;
    }

    /**
     * @param CodeBase $code_base @phan-unused-param
     * @return array<string,Closure(CodeBase,Context,FunctionInterface,list<mixed>,?Node)>
     * maps FQSEN of function or method to a closure used to analyze the function in question.
     * '\A::foo' or 'A::foo' as a key will override a method, and '\foo' or 'foo' as a key will override a function.
     * Closure Type: function(CodeBase $code_base, Context $context, Func|Method $function, array $args, ?Node $node) : void {...}
     *
     * If compatibility with older Phan versions is needed, make the param for $node optional.
     *
     * Note that $function->getMostRecentParentNodeListForCall() can be used to get the parent node list of the current call (will be the empty array if fetching it failed).
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $code_base): array
    {
        // Unit tests invoke this repeatedly. Cache it.
        static $analyzers = null;
        if ($analyzers === null) {
            $analyzers = self::getAnalyzeFunctionCallClosuresStatic();
        }
        return $analyzers;
    }

    /**
     * Ensure that we track callsites in callables passed to call_user_func,
     * forward_static_call, call_user_func_array, forward_static_call_array,
     * Closure::fromCallable, etc.
     *
     * Much of the logic in here was cribbed from https://github.com/phan/phan/blob/0fd8121798fa1c77d7f7608cf36d71f0b8325880/src/Phan/Plugin/Internal/ClosureReturnTypeOverridePlugin.php#L199
     *
     * @return array<string,\Closure>
     */
    private static function getAnalyzeFunctionCallClosuresStatic(): array
    {
        /**
         * @param list<Node|int|string|float> $args
         * @throws Exception
         */
        $generic_callback = static function(
            CodeBase $code_base,
            Context $context,
            array $args
        ): void {
            $function_like_list = UnionTypeVisitor::functionLikeListFromNodeAndContext($code_base, $context, $args[0], true);
            if (\count($function_like_list) === 0) {
                return;
            }

            $elements = [];
            foreach ($function_like_list as $function) {
                if ($function instanceof ClassElement) {
                    $elements[] = $function;
                }
            }

            if ($elements) {
                $phound_visitor = new PhoundVisitor($code_base, $context);
                $phound_visitor->genericVisitClassElements($elements, 'method');
            }
        };

        /**
         * @param list<Node|int|string|float> $args
         * @throws Exception
         */
        $call_user_func_callback = static function (
            CodeBase $code_base,
            Context $context,
            FunctionInterface $unused_function,
            array $args,
            ?Node $_
        ) use ($generic_callback) : void {
            if (\count($args) < 1) {
                return;
            }
            $generic_callback($code_base, $context, $args);
        };

        /**
         * @param list<Node|int|string|float> $args
         * @throws Exception
         */
        $call_user_func_array_callback = static function (
            CodeBase $code_base,
            Context $context,
            FunctionInterface $unused_function,
            array $args,
            ?Node $_
        ) use ($generic_callback) : void {
            if (\count($args) < 2) {
                return;
            }
            $generic_callback($code_base, $context, $args);
        };

        /**
         * @param list<Node|int|string|float> $args
         * @throws Exception
         */
        $from_callable_callback = static function (
            CodeBase $code_base,
            Context $context,
            FunctionInterface $unused_function,
            array $args,
            ?Node $_
        ) use ($generic_callback) : void {
            if (\count($args) !== 1) {
                return;
            }

            $generic_callback($code_base, $context, $args);
        };

        return [
            'call_user_func'            => $call_user_func_callback,
            'forward_static_call'       => $call_user_func_callback,
            'call_user_func_array'      => $call_user_func_array_callback,
            'forward_static_call_array' => $call_user_func_array_callback,
            'Closure::fromCallable'     => $from_callable_callback,
        ];
    }

    /**
     * This is called after the other forms of analysis are finished running.
     * Useful if a PluginV3 needs to aggregate results of analysis.
     * This may be used to emit additional issues.
     *
     * This is run once per forked analysis process.
     * Some plugins using this, such as UnusedSuppressionPlugin,
     * will not work as expected with more than one process.
     * If possible, write plugins to emit issues immediately.
     * @unused-param $code_base
     * @throws Exception
     */
    public function finalizeProcess(CodeBase $code_base): void
    {
        PhoundVisitor::finalizeProcess();
    }

}

return new PhoundPlugin();
