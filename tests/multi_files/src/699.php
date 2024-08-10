<?php
function f(int $p) {}
function g(?int $p = null) {
    f($p);
}
