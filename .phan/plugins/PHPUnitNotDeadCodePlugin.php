<?php declare(strict_types=1);

use Phan\Config;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\PluginV2;
use Phan\PluginV2\PostAnalyzeNodeCapability;
use Phan\PluginV2\PluginAwarePostAnalysisVisitor;

use ast\Node;

/**
 * Mark all phpunit test cases as used for dead code detection during Phan's self analysis.
 *
 * Implements the following capabilities
 * (This choice of capability makes this plugin efficiently analyze only classes that are in the analyzed file list)
 *
 * - public static function getPostAnalyzeNodeVisitorClassName() : string
 *   Returns the name of a class extending PluginAwarePostAnalysisVisitor, which will be used to analyze nodes in the analysis phase.
 *   If the PluginAwarePostAnalysisVisitor subclass has an instance property called parent_node_list,
 *   Phan will automatically set that property to the list of parent nodes (The nodes deepest in the AST are at the end of the list)
 *   (implement \Phan\PluginV2\PostAnalyzeNodeCapability)
 */
class PHPUnitNotDeadCodePlugin extends PluginV2 implements PostAnalyzeNodeCapability
{
    /**
     * @override
     */
    public static function getPostAnalyzeNodeVisitorClassName() : string
    {
        return PHPUnitNotDeadPluginVisitor::class;
    }
}

class PHPUnitNotDeadPluginVisitor extends PluginAwarePostAnalysisVisitor
{
    /** @var FullyQualifiedClassName */
    private static $phpunit_test_case_fqsen;

    /** @var Type */
    private static $phpunit_test_case_type;

    private static $did_warn_unused = false;

    /**
     * This is called after the parse phase is completely finished, so $this->code_base contains all class definitions
     * @return void
     * @override
     */
    public function visitClass(Node $unused_node)
    {
        if (!Config::get_track_references()) {
            return;
        }
        $code_base = $this->code_base;
        if (!$code_base->hasClassWithFQSEN(self::$phpunit_test_case_fqsen)) {
            if (!self::$did_warn_unused) {
                fprintf(STDERR, "Using plugin %s but could not find PHPUnit\Framework\TestCase\n", __CLASS__);
                self::$did_warn_unused = true;
            }
            return;
        }
        // This assumes PreOrderAnalysisVisitor->visitClass is called first.
        $context = $this->context;
        $class = $context->getClassInScope($code_base);
        if (!$class->getFQSEN()->asType()->asExpandedTypes($code_base)->hasType(self::$phpunit_test_case_type)) {
            // This isn't a phpunit test case.
            return;
        }

        // Mark subclasses of TestCase as referenced
        $class->addReference($context);
        // Mark all test cases as referenced
        foreach ($class->getMethodMap($code_base) as $method) {
            if (static::isTestCase($method)) {
                // TODO: Parse @dataProvider methodName, check for method existence,
                // then mark method for dataProvider as referenced.
                $method->addReference($context);
                $this->markDataProvidersAsReferenced($class, $method);
            }
        }
        // https://phpunit.de/manual/current/en/fixtures.html (PHPUnit framework checks for this override)
        if ($class->hasPropertyWithName($code_base, 'backupStaticAttributesBlacklist')) {
            $property = $class->getPropertyByName($code_base, 'backupStaticAttributesBlacklist');
            $property->addReference($context);
            $property->setHasReadReference();
        }
    }

    /**
     * This regex contains a single pattern, which matches a valid PHP identifier.
     * (e.g. for variable names, magic property names, etc.
     * This does not allow backslashes.
     */
    const WORD_REGEX = '([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)';

    /**
     * Marks all data provider methods as being referenced
     *
     * @param Method $method the Method representing a unit test in a test case subclass
     *
     * @return void
     */
    private function markDataProvidersAsReferenced(Clazz $class, Method $method)
    {
        if (preg_match('/@dataProvider\s+' . self::WORD_REGEX . '/', $method->getNode()->children['docComment'] ?? '', $match)) {
            $data_provider_name = $match[1];
            if ($class->hasMethodWithName($this->code_base, $data_provider_name)) {
                $class->getMethodByName($this->code_base, $data_provider_name)->addReference($this->context);
            }
        }
    }
    protected static function isTestCase(Method $method) : bool
    {
        if (!$method->isPublic()) {
            return false;
        }
        if (preg_match('@^test@i', $method->getName())) {
            return true;
        }
        if (preg_match('/@test\b/', $method->getNode()->children['docComment'] ?? '')) {
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    public static function init()
    {
        $fqsen = FullyQualifiedClassName::make('\\PHPUnit\Framework', 'TestCase');
        self::$phpunit_test_case_fqsen = $fqsen;
        self::$phpunit_test_case_type = $fqsen->asType();
    }
}
PHPUnitNotDeadPluginVisitor::init();

// Every plugin needs to return an instance of itself at the
// end of the file in which its defined.
return new PHPUnitNotDeadCodePlugin();
