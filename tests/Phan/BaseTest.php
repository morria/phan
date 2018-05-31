<?php declare(strict_types=1);

namespace Phan\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Any common initialization or configuration should go here
 * (E.g. this changes https://phpunit.de/manual/current/en/fixtures.html#fixtures.global-state for some classes)
 */
abstract class BaseTest extends TestCase
{
    /**
     * Needed to prevent phpunit from backing up these private static variables.
     * See https://phpunit.de/manual/current/en/fixtures.html#fixtures.global-state
     *
     * @suppress PhanReadOnlyProtectedProperty read by phpunit framework
     */
    protected $backupStaticAttributesBlacklist = [
        'Phan\AST\PhanAnnotationAdder' => [
            'closures_for_kind',
        ],
        'Phan\Language\Type' => [
            'canonical_object_map',
            'internal_fn_cache',
        ],
        'Phan\Language\UnionType' => [
            'empty_instance',
        ],
    ];
}
