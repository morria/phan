<?php declare(strict_types=1);
namespace Phan\Library\Hasher;

use Phan\Library\Hasher;

/**
 * Hasher implementation mapping keys to sequential groups (first key to 0, second key to 1, looping back to 0)
 * getGroup() is called exactly once on each string to be hashed.
 */
class Sequential implements Hasher
{
    /** @var int */
    protected $counter;
    /** @var int */
    protected $group_count;

    public function __construct(int $group_count)
    {
        $this->counter = 1;
        $this->group_count = $group_count;
    }

    /**
     * @param string $key (Used by sibling class Consistent) (@phan-unused-param)
     * @return int - an integer between 0 and $this->group_count - 1, inclusive
     */
    public function getGroup(string $key) : int
    {
        return ($this->counter++) % $this->group_count;
    }

    /**
     * Resets counter
     * @return void
     */
    public function reset()
    {
        $this->counter = 1;
    }
}
