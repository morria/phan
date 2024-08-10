<?php
class A {
    public function f(&$output = null) {}
}

class B {
    public function f(?A $p = null) {
        $p = $p ?: new A();
        $p->f($v1);
        $v2 = $v1;  // TODO: Suggestion for PhanUnusedVariable is pointless here
    }
}
