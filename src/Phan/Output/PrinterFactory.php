<?php

declare(strict_types=1);

namespace Phan\Output;

use Phan\Output\Printer\CheckstylePrinter;
use Phan\Output\Printer\CodeClimatePrinter;
use Phan\Output\Printer\CSVPrinter;
use Phan\Output\Printer\GitlabPrinter;
use Phan\Output\Printer\HTMLPrinter;
use Phan\Output\Printer\JSONPrinter;
use Phan\Output\Printer\PlainTextPrinter;
use Phan\Output\Printer\PylintPrinter;
use Phan\Output\Printer\VerbosePlainTextPrinter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class PrinterFactory
 * Subject of future refactoring to be a bit more extensible
 */
class PrinterFactory
{

    /**
     * @return list<string> the supported types of Printers
     */
    public function getTypes(): array
    {
        return [
            'checkstyle',
            'codeclimate',
            'csv',
            'gitlab',
            'html',
            'json',
            'pylint',
            'text',
            'verbose',
        ];
    }

    /**
     * Return an IssuePrinterInterface of type $type that outputs issues to $output
     * @param ?string $type the configured type of printer
     */
    public function getPrinter(?string $type, OutputInterface $output): IssuePrinterInterface
    {
        switch ($type) {
            case 'checkstyle':
                $printer = new CheckstylePrinter();
                break;
            case 'codeclimate':
                $printer = new CodeClimatePrinter();
                break;
            case 'csv':
                $printer = new CSVPrinter();
                break;
            case 'gitlab':
                $printer = new GitlabPrinter();
                break;
            case 'html':
                $printer = new HTMLPrinter();
                break;
            case 'json':
                $printer = new JSONPrinter();
                break;
            case 'pylint':
                $printer = new PylintPrinter();
                break;
            case 'verbose':
                $printer = new VerbosePlainTextPrinter();
                break;
            default:
                $printer = new PlainTextPrinter();
                break;
        }

        $printer->configureOutput($output);

        return $printer;
    }
}
