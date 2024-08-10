<?php

class HasGetter780 {
    private $propName = 'default';

    public function getPropName() : string {
        return $this->propName;
    }

    public function setPropName(string $value) : void {
        $this->propName = $value;;
    }
    public function badMethod(string $unused_value) : void {
        'x';
    }
    public function badMethod2(?string $unused_value = null) : string {
        0;
    }
    public function badMethod3() : string {
        return 'this'->propName;
    }
    public function badMethod4() : string {
        $this->propName = func_get_arg(0);  // not analyzable
    }
}
function test() {
    $g = new HasGetter780();
    echo $g->propName;
    $g->propName = 'newValue';
}
