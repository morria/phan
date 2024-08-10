<?php

declare(strict_types=1);

namespace Phan\Tests\Language\Element;

use Phan\CodeBase;
use Phan\Language\Element\Comment;
use Phan\Language\Element\GlobalConstant;
use Phan\Language\Element\MarkupDescription;
use Phan\Tests\BaseTest;

/**
 * Unit tests of MarkupDescription functionality
 */
final class MarkupDescriptionTest extends BaseTest
{
    /**
     * @dataProvider extractDocCommentProvider
     */
    public function testExtractDocComment(string $expected, string $doc_comment, ?int $category = null): void
    {
        // @phan-suppress-next-line PhanAccessMethodInternal
        $this->assertSame($expected, MarkupDescription::extractDocComment($doc_comment, $category));
    }

    /**
     * @return list<array{0:string,1:string,2?:int}>
     */
    public function extractDocCommentProvider(): array
    {
        return [
            [
                'A description goes here',
                '/** A description goes here */',
            ],
            [
                '',
                '/** @param T $x A parameter annotation goes here */',
                Comment::ON_METHOD,
            ],
            [
                '',
                '/** @var T $x A local variable annotation of a function goes here*/',
                Comment::ON_METHOD,
            ],
            [
                '`@var array<string,array<int,mixed>>` An annotation of a property goes here',
                '/** @var array<string,array<int,mixed>> An annotation of a property goes here */',
                Comment::ON_PROPERTY,
            ],
            // Allow the description of (at)var to be a summary for the property if there is no earlier summary
            [
                <<<'EOT'
`@var MyClass` A annotation of a constant goes here

Rest of this comment
EOT
                ,
                <<<'EOT'
/**
 * @var MyClass A annotation of a constant goes here
 *
 * Rest of this comment
 */
EOT
                ,
                Comment::ON_CONST,
            ],
            // Preserve leading whitespace when parsing the comment description
            [
                <<<'EOT'
A description goes here

Rest of this description

-  Example markup list
   Rest of that list
EOT
                ,
            <<<'EOT'
/**
 * A description goes here
 *
 * Rest of this description
 *
 * -  Example markup list
 *    Rest of that list
 */
EOT
            ],
            // Preserve leading whitespace when parsing markup after (at)return
            [
                <<<'EOT'
`@return int`

Rest of this description

-  Example markup list
   Rest of that list
EOT
                ,
            <<<'EOT'
/**
 * @return int
 *
 * Rest of this description
 *
 * -  Example markup list
 *    Rest of that list
 *
 * @internal
 */
EOT
                ,
                Comment::ON_METHOD
            ],
            // Only parse (at)return on comments of function-likes
            [
                ''
                ,
            <<<'EOT'
/**
 * @return int
 *
 * Rest of this description
 */
EOT
                ,
                Comment::ON_PROPERTY
            ],
            // Parse summaries on adjacent lines
            [
                <<<'EOT'
`@return int`
Rest of this description
EOT
                ,
            <<<'EOT'
/**
 * @return int
 * Rest of this description
 *
 * @internal
 */
EOT
                ,
                Comment::ON_FUNCTION
            ],
            // Treat informative (at)return as function-like summaries.
            [
                '`@return int` positive',
            <<<'EOT'
/**
 * @return int positive
 */
EOT
                ,
                Comment::ON_FUNCTION
            ],
            // Treat informative single-line (at)return as function-like summaries.
            [
                '`@return int` positive',
                '/**   @return int positive */',
                Comment::ON_METHOD
            ],
            [
                "`@return float`\npositive value",
                <<<'EOT'
/**
 * @param int $x
 * @return float
 * positive value
 */
EOT
                ,
                Comment::ON_METHOD
            ],
            // Check that it does not fail completely for invalid phpdoc with multiple `(at)return` statements
            [
                "`@return float`\npositive value.",
                <<<'EOT'
/**
 * @param int $x
 * @return float
 * positive value.
 * @internal
 * @return false
 * on failure.
 */
EOT
                ,
                Comment::ON_METHOD
            ],
            [
                '`@return int` self::MY_ENUM_* description',
                '/**   @return int self::MY_ENUM_* description */',
                Comment::ON_METHOD
            ],
            // Don't treat uninformative (at)return as function-like summaries.
            [
                '',
            <<<'EOT'
/**
 * @return string|false
 */
EOT
                ,
                Comment::ON_FUNCTION
            ],
        ];
    }

    /**
     * @dataProvider getDocCommentWithoutWhitespaceProvider
     */
    public function testGetDocCommentWithoutWhitespace(string $expected, string $doc_comment): void
    {
        $this->assertSame($expected, MarkupDescription::getDocCommentWithoutWhitespace($doc_comment));
    }

    /**
     * @return list<array{0:string,1:string}>
     */
    public function getDocCommentWithoutWhitespaceProvider(): array
    {
        return [
            [
                '',
                '/** */',
            ],
            [
                '',
                <<<'EOT'
/**
 *
 *
 *
 */
EOT
            ],
            [
                '* A description goes here',
                '/** A description goes here */',
            ],
            [
                '* @param T $x A parameter annotation goes here',
                '/** @param T $x A parameter annotation goes here */',
            ],
            [
                '* @var T $x A local variable annotation of a function goes here',
                '/** @var T $x A local variable annotation of a function goes here*/',
            ],
            [
                <<<'EOT'
* @var MyClass A annotation of a constant goes here
*
* Rest of this comment
EOT
                ,
                <<<'EOT'
/**
 * @var MyClass A annotation of a constant goes here
 *
 * Rest of this comment
 */
EOT
                ,
            ],
            // Preserve leading whitespace when parsing the comment description
            [
                <<<'EOT'
* A description goes here
*
* Rest of this description

*
* -  Example markup list
*    Rest of that list
EOT
                ,
            <<<'EOT'
/**
 *
 *
 *
 * A description goes here
 *
 * Rest of this description

 *
 * -  Example markup list
 *    Rest of that list
 *
 *
 */
EOT
            ],
        ];
    }

    /**
     * @throws \Exception
     */
    public function testDescriptionForBuiltinConstant(): void
    {
        $const = GlobalConstant::fromGlobalConstantName('STDERR');
        $expected = <<<EOT
```php
const STDERR = resource(stream)
```

An already opened stream to *stderr* (standard error).
EOT;
        $this->assertSame($expected, MarkupDescription::buildForElement($const, new CodeBase([], [], [], [], [])));
    }
}
