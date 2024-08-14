<?php
/**
 * @template TClassName
 * @param array{
 *     class: class-string<TClassName>,
 *     args: array<int, mixed>,
 * } $params
 * @return TClassName
 */
function class_instance_factory(array $params) {
    $class = $params['class'];
    $args = $params['args'];
    return new $class(...$args);
}
$o = class_instance_factory(['class' => stdClass::class, 'args' => []]);
echo $o->method();

/**
 * @template T1
 * @template T2
 * @param array{0:T1, 1:T2} $params
 * @return array{0:T2, 1:T1}
 */
function swap_pair(array $params) : array {
    return [$params[1], $params[0]];
}
echo strlen(swap_pair([rand(0,5), new stdClass()]));
