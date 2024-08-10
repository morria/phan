<?php

function example3(float ...$values) : int {
    if (array_is_list($values)) {
        return $values;
    }
    return $values;
}
example3(2.0, foo: 1.0);
function example3b(?array $values = null) : int {
    if (array_is_list($values)) {  // this line will throw for null so the negation is non-empty-associative-array
        return $values;
    }
    return $values;
}

var_export(array_is_list([1, 2]));
var_export(array_is_list([]));
var_export(array_is_list(['key' => 'value']));
