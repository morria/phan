<?php

declare(strict_types=1);

namespace Phan\Output\Printer;

use Phan\Issue;
use Phan\IssueInstance;
use Phan\Tests\BaseTest;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests that GitlabPrinter converts `IssueInstance`s to the expected
 * JSON output for the Gitlab Code Quality Widget.
 *
 * @group output-printer
 * @group gitlab
 */
final class GitlabPrinterTest extends BaseTest
{

    /**
     * @covers \Phan\Output\Printer\GitlabPrinter
     * @covers \Phan\IssueInstance
     * @covers \Phan\Issue
     */
    public function testPrintOutput(): void
    {
        $output = new BufferedOutput();

        $printer = new GitlabPrinter();
        $printer->configureOutput($output);
        $printer->print(new IssueInstance(Issue::fromType(Issue::UndeclaredVariableDim), 'dim.php', 10, ['varName']));
        $printer->print(new IssueInstance(Issue::fromType(Issue::SyntaxError), 'test.php', 1, ['fake error']));
        $printer->flush();

        // phpcs:ignore
        $expected_output = '[{"description":"PhanUndeclaredVariableDim Variable $varName was undeclared, but array fields are being added to it.","check_name":"PhanUndeclaredVariableDim","fingerprint":"74f09361fae578de70a4ddd042ac7441","severity":"info","location":{"path":"dim.php","lines":{"begin":10}}},{"description":"PhanSyntaxError fake error","check_name":"PhanSyntaxError","fingerprint":"f3fb5a2b655793fc8c79cb6a44f214ca","severity":"critical","location":{"path":"test.php","lines":{"begin":1}}}]' . "\n";

        $this->assertSame($expected_output, $output->fetch());
    }
}
