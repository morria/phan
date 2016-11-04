<?php
class C1 {
    function f(int $a) {}
}
class C2 extends C1 {
    function f() {}
}
class C3 extends C1 {
    function f(int $b) : int { return 42; }
}
class C4 extends C1 {
    function f(int $a, int $b) {}
}
class C5 extends C1 {
    function f(string $a) {}
}
class C6 extends C1 {
    function f($a) {}
}

class C7 {
    function g() : string { return ''; }
}
class C8 extends C7 {
    function g() : int { return 42; }
}
class C9 extends C7 {
    function g() {}
}

class C10 {
    function h($a) {}
}
class C11 extends C10 {
    function h(int $a) {}
}

class C12 {
    public function __construct(int $a) {}
}
class C13 extends C12 {
    public function __construct(string $b, bool $c) {}
}

class C16 {
    public function &j() {}
}

class C17 extends C16 {
    public function j() {}
}
