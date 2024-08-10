<?php

/**
 * @param ?stdClass $x
 */
function test_object(?stdClass $x = null) {
    echo spl_object_hash($x);  // should infer ?stdClass
    if (isset($x->prop)) {
        echo spl_object_hash($x);  // should not warn
    } else {
        echo spl_object_hash($x);  // should warn
    }
}

function test_object_field_isset(?stdClass $nested = null) {
    echo spl_object_hash($nested);  // should infer ?stdClass
    if (isset($nested->prop['a'][rand() % 2])) {
        echo spl_object_hash($nested);  // should not warn
    } else {
        echo spl_object_hash($nested);  // should warn
    }
}

/**
 * @param array{field:?array} $x
 */
function test_isset_nested(array $x) {
    if (isset($x['field'][0])) {
        // Should infer field is non-null
        echo strlen($x['field']);
    }
}
