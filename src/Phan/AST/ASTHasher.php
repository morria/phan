<?php declare(strict_types=1);
namespace Phan\AST;

use ast\Node;

use function md5;
use function is_int;
use function is_string;

/**
 * This converts a PHP AST Node into a hash.
 * This ignores line numbers and spacing.
 */
class ASTHasher
{
    /**
     * @return string a 16-byte binary key
     */
    public static function hash_key($node)
    {
        if (is_string($node)) {
            return md5('s' . $node, true);
        }
        // Both 2.0 and 2 cast to the string '2'
        if (is_int($node)) {
            return md5((string) $node, true);
        }
        return md5('f' . $node, true);
    }

    /**
     * @return string a 16-byte binary key
     */
    public static function hash($node)
    {
        if (!($node instanceof Node)) {
            // hash_key
            if (is_string($node)) {
                return md5('s' . $node, true);
            }
            if (is_int($node)) {
                return md5((string) $node, true);
            }
            return md5('f' . $node, true);
        }
        // @phan-suppress-next-line PhanUndeclaredProperty
        return $node->hash ?? ($node->hash = self::compute_hash($node));
    }

    /**
     * @return string a 16-byte binary key
     */
    private static function compute_hash($node)
    {
        $str = 'N' . $node->kind . ':' . ($node->flags & 0xfffff);
        foreach ($node->children as $key => $child) {
            // added in PhanAnnotationAdder
            if ($key === 'phan_nf') {
                continue;
            }
            $str .= self::hash_key($key);
            $str .= self::hash($child);
        }
        return md5($str, true);
    }
}
