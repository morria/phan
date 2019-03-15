<?php declare(strict_types=1);

namespace Phan\Tests;

use Phan\Config;
use PHPUnit\Framework\TestCase;

/**
 * Any common initialization or configuration should go here
 * (E.g. this changes https://phpunit.de/manual/current/en/fixtures.html#fixtures.global-state for some classes)
 */
abstract class BaseTest extends TestCase
{
    /**
     * @return void
     * @suppress PhanAccessMethodInternal
     */
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        // Need more than 1G to generate code coverage reports
        \ini_set('memory_limit', '2G');
        \chdir(\dirname(__DIR__, 2));
        Config::reset();
    }

    /**
     * Needed to prevent phpunit from backing up these private static variables.
     * See https://phpunit.de/manual/current/en/fixtures.html#fixtures.global-state
     *
     * @suppress PhanReadOnlyProtectedProperty, UnusedSuppression read by phpunit framework
     */
    protected $backupStaticAttributesBlacklist = [
        'Phan\AST\PhanAnnotationAdder' => [
            'closures_for_kind',
        ],
        'Phan\AST\ASTReverter' => [
            'closure_map',
            'noop',
        ],
        'Phan\Language\Type' => [
            'canonical_object_map',
            'internal_fn_cache',
        ],
        'Phan\Language\Type\LiteralIntType' => [
            'nullable_int_type',
            'non_nullable_int_type',
        ],
        'Phan\Language\Type\LiteralStringType' => [
            'nullable_string_type',
            'non_nullable_string_type',
        ],
        'Phan\Language\UnionType' => [
            'empty_instance',
        ],
        // Back this up because it takes 306 ms.
        'Phan\Tests\Language\UnionTypeTest' => [
            'code_base',
        ],
        'Phan\Tests\Plugin\Internal\MethodSearcherPluginTest' => [
            'code_base',
        ],
    ];
}
