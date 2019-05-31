<?php declare(strict_types=1);

namespace Phan\Tests\Library;

use Phan\Library\Tuple1;
use Phan\Library\Tuple2;
use Phan\Tests\BaseTest;

/**
 * Unit tests of Tuple
 */
final class TupleTest extends BaseTest
{
    public function testSimple1() : void
    {
        $x = new Tuple1('value');
        $this->assertSame('value', $x->_0);
        $this->assertSame(['value'], $x->toArray());
        $this->assertSame(1, $x->arity());
    }

    public function testSimple2() : void
    {
        $x = new Tuple2('value', 42);
        $this->assertSame('value', $x->_0);
        $this->assertSame(42, $x->_1);
        $this->assertSame(['value', 42], $x->toArray());
        $this->assertSame(2, $x->arity());
    }
}
