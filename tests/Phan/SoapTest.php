<?php declare(strict_types=1);

namespace Phan\Tests;

/**
 * Unit tests for the expected behavior when Phan analyzes code using the SOAP extension.
 *
 * @requires extension soap
 */
final class SoapTest extends AbstractPhanFileTest
{

    /**
     * This reads all files in `tests/files/src`, runs
     * the analyzer on each and compares the output
     * to the files' counterpart in
     * `tests/files/expected`
     *
     * @param string[] $test_file_list
     * @param string $expected_file_path
     *
     * @dataProvider getTestFiles
     */
    public function testFiles(array $test_file_list, string $expected_file_path, ?string $config_file_path = null) : void
    {
        parent::testFiles($test_file_list, $expected_file_path, $config_file_path);
    }

    /**
     * @suppress PhanUndeclaredConstant
     */
    public function getTestFiles() : array
    {
        return $this->scanSourceFilesDir(\SOAP_TEST_FILE_DIR, \SOAP_EXPECTED_DIR);
    }
}
