<?php declare(strict_types=1);

namespace Phan\Tests\Language;

use Phan\Language\Context;
use Phan\Language\FQSEN;
use Phan\Language\FQSEN\FullyQualifiedClassConstantName;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\FQSEN\FullyQualifiedFunctionName;
use Phan\Language\FQSEN\FullyQualifiedGlobalConstantName;
use Phan\Language\FQSEN\FullyQualifiedMethodName;
use Phan\Language\FQSEN\FullyQualifiedPropertyName;
use Phan\Tests\BaseTest;

/**
 * Unit tests of various FQSEN subclasses.
 * @phan-file-suppress PhanThrowTypeAbsentForCall
 */
final class FQSENTest extends BaseTest
{

    /** @var Context the context within which this unit test will run */
    protected $context = null;

    protected function setUp() : void
    {
        // Deliberately not calling parent::setUp()
        $this->context = new Context();
    }

    protected function tearDown() : void
    {
        // Deliberately not calling parent::tearDown()
        // @phan-suppress-next-line PhanTypeMismatchProperty
        $this->context = null;
    }

    public function testFullyQualifiedClassName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedClassName::make('Name\\Space', 'A'),
            '\\Name\\Space\\A'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::make('', 'A'),
            '\\A'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::fromFullyQualifiedString('A'),
            '\\A'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::fromFullyQualifiedString(
                '\\Name\\Space\\A'
            ),
            '\\Name\\Space\\A'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::fromFullyQualifiedString(
                '\\Namespace\\A,1'
            ),
            '\\Namespace\\A,1'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::fromStringInContext(
                '\\Namespace\\A',
                $this->context
            ),
            '\\Namespace\\A'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassName::fromStringInContext(
                'A',
                $this->context
            ),
            '\\A'
        );
    }

    public function testFullyQualifiedMethodName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedMethodName::make(
                FullyQualifiedClassName::make('\\Name\\Space', 'A'),
                'f'
            ),
            '\\Name\\Space\\A::f'
        );

        $this->assertFQSENEqual(
            FullyQualifiedMethodName::fromFullyQualifiedString(
                '\\Name\\A::f'
            ),
            '\\Name\\A::f'
        );

        $this->assertFQSENEqual(
            FullyQualifiedMethodName::fromFullyQualifiedString(
                'Name\\A::f'
            ),
            '\\Name\\A::f'
        );

        $this->assertFQSENEqual(
            FullyQualifiedMethodName::fromFullyQualifiedString(
                '\\Name\\Space\\A::f,2'
            ),
            '\\Name\\Space\\A::f,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedMethodName::fromFullyQualifiedString(
                '\\Name\\Space\\A,1::f,2'
            ),
            '\\Name\\Space\\A,1::f,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedMethodName::fromStringInContext(
                'A::methodName',
                $this->context
            ),
            '\\A::methodName'
        );
    }

    public function testFullyQualifiedPropertyName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::make(
                FullyQualifiedClassName::make('\\Name\\Space', 'A'),
                'p'
            ),
            '\\Name\\Space\\A::p'
        );

        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::fromFullyQualifiedString(
                '\\Name\\A::p'
            ),
            '\\Name\\A::p'
        );

        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::fromFullyQualifiedString(
                'Name\\A::p'
            ),
            '\\Name\\A::p'
        );

        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::fromFullyQualifiedString(
                '\\Name\\Space\\A::p,2'
            ),
            '\\Name\\Space\\A::p,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::fromFullyQualifiedString(
                '\\Name\\Space\\A,1::p,2'
            ),
            '\\Name\\Space\\A,1::p,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedPropertyName::fromStringInContext(
                'A::p',
                $this->context
            ),
            '\\A::p'
        );
    }

    public function testFullyQualifiedClassConstantName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::make(
                FullyQualifiedClassName::make('\\Name\\Space', 'A'),
                'c'
            ),
            '\\Name\\Space\\A::c'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::fromFullyQualifiedString(
                '\\Name\\A::c'
            ),
            '\\Name\\A::c'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::fromFullyQualifiedString(
                'Name\\A::c'
            ),
            '\\Name\\A::c'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::fromFullyQualifiedString(
                '\\Name\\Space\\A::c,2'
            ),
            '\\Name\\Space\\A::c,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::fromFullyQualifiedString(
                '\\Name\\Space\\A,1::c,2'
            ),
            '\\Name\\Space\\A,1::c,2'
        );

        $this->assertFQSENEqual(
            FullyQualifiedClassConstantName::fromStringInContext(
                'A::methodName',
                $this->context
            ),
            '\\A::methodName'
        );
    }

    public function testFullyQualifiedGlobalConstantName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::make(
                '\\Name\\Space',
                'c'
            ),
            '\\Name\\Space\\c'
        );

        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::make(
                '',
                'C'
            ),
            '\\C'
        );

        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::make(
                '',
                'C'
            ),
            '\\C'
        );

        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::fromFullyQualifiedString('\\C'),
            '\\C'
        );

        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::fromStringInContext('C', $this->context),
            '\\C'
        );
    }

    public function testFullyQualifiedFunctionName() : void
    {
        $this->assertFQSENEqual(
            FullyQualifiedFunctionName::make(
                '\\Name\\Space',
                'g'
            ),
            '\\Name\\Space\\g'
        );

        $this->assertFQSENEqual(
            FullyQualifiedFunctionName::make(
                '',
                'g'
            ),
            '\\g'
        );

        $this->assertFQSENEqual(
            FullyQualifiedGlobalConstantName::make(
                '',
                'g'
            ),
            '\\g'
        );

        $this->assertFQSENEqual(
            FullyQualifiedFunctionName::fromFullyQualifiedString('\\g'),
            '\\g'
        );

        $this->assertFQSENEqual(
            FullyQualifiedFunctionName::fromStringInContext('g', $this->context),
            '\\g'
        );
    }

    /**
     * Asserts that a given FQSEN produces the given string
     */
    public function assertFQSENEqual(
        FQSEN $fqsen,
        string $string
    ) : void {
        $this->assertSame($string, (string)$fqsen);
    }
}
