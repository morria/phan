<?php declare(strict_types=1);

namespace Phan\Tests\Exception;

use Phan\Exception\IssueException;
use Phan\Issue;
use Phan\IssueInstance;
use Phan\Tests\BaseTest;

/**
 * Tests of IssueException
 */
final class IssueExceptionTest extends BaseTest
{
    public function testToString() : void
    {
        $issue = new Issue(
            "PhanPlaceholderIssue",
            Issue::CATEGORY_GENERIC,
            Issue::SEVERITY_LOW,
            "Placeholder {TYPE}",
            Issue::REMEDIATION_D,
            9921
        );
        $issue_instance = new IssueInstance($issue, "issue_exception_test.php", 11, ['string']);
        $exception = new IssueException($issue_instance);
        $stringified = (string)$exception;
        $this->assertContains('IssueException at ', $stringified);
        $this->assertContains('issue_exception_test.php:11 Placeholder string', $stringified);
        $this->assertContains(__FUNCTION__, $stringified);
    }
}
