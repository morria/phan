<?php

declare(strict_types=1);

namespace Phan\Tests\Output\Printer;

use Phan\Issue;
use Phan\IssueInstance;
use Phan\Output\Printer\CodeClimatePrinter;
use Phan\Tests\BaseTest;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests that CodeClimatePrinter converts `IssueInstance`s to the expected codeclimate JSON output
 */
final class CodeClimatePrinterTest extends BaseTest
{

    public function testPrintOutput(): void
    {
        $output = new BufferedOutput();

        $printer = new CodeClimatePrinter();
        $printer->configureOutput($output);
        $printer->print(new IssueInstance(Issue::fromType(Issue::UndeclaredVariableDim), 'dim.php', 10, ['varName']));
        $printer->print(new IssueInstance(Issue::fromType(Issue::SyntaxError), 'test.php', 1, ['fake error']));
        $printer->print(new IssueInstance(Issue::fromType(Issue::UndeclaredMethod), 'undefinedmethod.php', 1, ['\\Foo::bar']));
        $printer->print(new IssueInstance(Issue::fromType(Issue::SyntaxError), 'bad.php', 2, ['fake invalid token error'], null, 3));
        $printer->flush();

        $expected_output = '';
        // phpcs:disable
        $expected_output .= '{"type":"issue","check_name":"PhanUndeclaredVariableDim","description":"Variable $varName was undeclared, but array fields are being added to it.","categories":["Bug Risk"],"severity":"info","location":{"path":"dim.php","lines":{"begin":10,"end":10}}}' . "\x00";
        $expected_output .= '{"type":"issue","check_name":"PhanSyntaxError","description":"fake error","categories":["Bug Risk"],"severity":"critical","location":{"path":"test.php","lines":{"begin":1,"end":1}}}' . "\x00";
        $expected_output .= '{"type":"issue","check_name":"PhanUndeclaredMethod","description":"Call to undeclared method \\\\Foo::bar","categories":["Bug Risk"],"severity":"critical","location":{"path":"undefinedmethod.php","lines":{"begin":1,"end":1}}}' . "\x00";
        $expected_output .= '{"type":"issue","check_name":"PhanSyntaxError","description":"fake invalid token error","categories":["Bug Risk"],"severity":"critical","location":{"path":"bad.php","lines":{"begin":2,"end":2,"column":3}}}' . "\x00";
        // phpcs:enable
        $this->assertSame($expected_output, $output->fetch());
    }

    // Should replace invalid utf-8 with placeholders in the resulting JSON
    public function testPrintInvalidUtf8(): void
    {
        $output = new BufferedOutput();

        $printer = new CodeClimatePrinter();
        $printer->configureOutput($output);
        $printer->print(new IssueInstance(Issue::fromType(Issue::UndeclaredVariableDim), 'dim.php', 10, ["a\x80b"]));
        $expected_output = '{"type":"issue","check_name":"PhanUndeclaredVariableDim","description":"Variable $a�b was undeclared, but array fields are being added to it.","categories":["Bug Risk"],"severity":"info","location":{"path":"dim.php","lines":{"begin":10,"end":10}}}' . "\x00";
        $printer->flush();
        $this->assertSame($expected_output, $output->fetch());
    }
}
