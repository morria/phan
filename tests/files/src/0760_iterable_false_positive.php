<?php

function iterable_possibly_false(iterable $x, iterable $y = [], ?iterable $z = null, ?Traversable $w = null) {
    if ($x) {  // should not emit RedundantCondition
        if (is_iterable($x)) {
            echo "Still an iterable\n";
        }
    }
    if (is_iterable($y)) { // should warn
        if (!$y) {  // should not warn
            echo "An empty iterable\n";
        }
    }
    if ($z) {  // should not emit RedundantCondition
        if (is_iterable($z)) {
            echo "Still an iterable\n";
        }
    }
    if ($w && is_iterable($w)) {
        '@phan-debug-var $w';
        var_export(is_object($w));
    }
}
function resource_redundant($x) {
    if (is_resource($x)) {
        if (is_resource($x)) {
            echo "We require additional pylons\n";
        }
    }
}
