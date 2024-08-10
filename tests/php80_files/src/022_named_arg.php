<?php

declare(strict_types=1);

function noargs() {}

function test_named_arg(int $a, ?stdClass $other = null, bool $flag = false) {
    '@phan-debug-var $a, $other, $flag';
}

test_named_arg(a: 1, other: new stdClass(), flag: true);
test_named_arg(a: 1, new stdClass());
test_named_arg(flag: true, a: 1, other: new stdClass());
test_named_arg(flag: 'invalid', a: null, other: true);
test_named_arg(flag: 'invalid', ...$argv);
test_named_arg(1, new stdClass(), true);
var_export(strlen(string: true));
var_export(strlen(string: 'blah', string: true));
var_export(strlen(string: true));
noargs(arg: true);
test_named_arg(a: 1, OTHER: new stdClass(), flat: true);
