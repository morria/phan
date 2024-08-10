<?php

class Example664 {
    /** @var ?string */
    public $x;

    public function __construct(?string $v = null) {
        $this->x = $v;
    }

    public function test() {
        if (isset($this->x)) {
            // should infer int
            echo intdiv($this->x, 2);
        } else {
            // should infer null
            echo intdiv($this->x, 2);
        }
    }
}
