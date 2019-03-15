<?php declare(strict_types=1);

namespace Phan\Tests;

// Grab these before we define our own classes
$internal_class_name_list = \get_declared_classes();
$internal_interface_name_list = \get_declared_interfaces();
$internal_trait_name_list = \get_declared_traits();
$internal_function_name_list = \get_defined_functions()['internal'];

use Phan\Analysis;
use Phan\CodeBase;
use Phan\Config;
use Phan\Language\Context;
use Phan\Language\FQSEN\FullyQualifiedClassName;

/**
 * Unit tests of Phan's analysis creating the expected element representations on snippets of code.
 * @phan-file-suppress PhanThrowTypeAbsentForCall
 */
final class AnalyzerTest extends BaseTest
{
    /**
     * @var CodeBase represents the known state of the test code base we're analyzing.
     */
    private $code_base;

    protected function setUp()
    {
        $this->code_base = new CodeBase(
            [], // $this->class_name_list,
            [], // $this->interface_name_list,
            [], // $this->trait_name_list,
            [], // $this->const_name_list,
            []  // $this->function_name_list
        );
    }

    public function testClassInCodeBase()
    {

        $this->contextForCode("
            Class A {}
        ");

        self::assertTrue(
            $this->code_base->hasClassWithFQSEN(
                FullyQualifiedClassName::fromFullyQualifiedString('A')
            )
        );
    }

    public function testNamespaceClassInCodeBase()
    {
        $this->contextForCode("
            namespace A;
            Class B {}
        ");

        self::assertTrue(
            $this->code_base->hasClassWithFQSEN(
                FullyQualifiedClassName::fromFullyQualifiedString('\A\B')
            )
        );
    }

    public function testMethodInCodeBase()
    {
        $this->contextForCode("
            namespace A;
            Class B {
                public function c() {
                    return 42;
                }
            }
        ");

        $class_fqsen =
            FullyQualifiedClassName::fromFullyQualifiedString('\A\B');

        self::assertTrue(
            $this->code_base->hasClassWithFQSEN($class_fqsen),
            "Class with FQSEN $class_fqsen not found"
        );

        $clazz =
            $this->code_base->getClassByFQSEN($class_fqsen);

        self::assertTrue(
            $clazz->hasMethodWithName($this->code_base, 'c'),
            "Method with FQSEN not found"
        );
    }

    /**
     * Get a Context after parsing the given
     * bit of code.
     */
    private function contextForCode(
        string $code_stub
    ) : Context {

        return
            Analysis::parseNodeInContext(
                $this->code_base,
                new Context(),
                \ast\parse_code(
                    '<?php ' . $code_stub,
                    Config::AST_VERSION
                )
            );
    }
}
