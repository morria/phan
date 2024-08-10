<?php

declare(strict_types=1);

namespace Phan\Tests\AST;

use AssertionError;
use Phan\AST\ASTReverter;
use Phan\Config;
use Phan\Tests\BaseTest;

/**
 * Tests of ASTReverter converting a Node to a compact string representation of that node
 */
final class ASTReverterTest extends BaseTest
{
    /**
     * @param string $snippet
     * @dataProvider revertShorthandProvider
     */
    public function testRevertShorthand(string $snippet, ?string $expected = null): void
    {
        $expected = $expected ?? $snippet;
        $file_contents = '<' . '?php ' . $snippet . ';';
        $statements = \ast\parse_code($file_contents, Config::AST_VERSION);
        $this->assertSame(1, \count($statements->children));
        $snippet_node = $statements->children[0];
        if ($snippet_node === null) {
            throw new AssertionError("invalid first statement in statement list");
        }

        $reverter = new ASTReverter();
        $this->assertSame($expected, $reverter->toShortString($snippet_node));
    }

    /**
     * @return list<array{0:string}>
     */
    public function revertShorthandProvider(): array
    {
        return [
            ["'2'"],
            ['"a\tc\nb"'],
            ['"\0"', '"\x00"'],
            ['"\07"', '"\x07"'],
            ['"\0072"', '"\x072"'],
            ['2'],
            ['false'],
            ['null'],
            ['NULL'],
            ['PHP_VERSION_ID'],
            ['\\PHP_VERSION_ID'],
            ['namespace\\PHP_VERSION_ID'],
            ['array(2,3=>4)', '[2,3=>4]'],
            ["['x'=>'var']"],
            ['[2]'],
        ];
    }
}
