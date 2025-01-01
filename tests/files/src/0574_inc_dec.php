<?php
/**
 * @param object $x
 * @param resource $r
 */
function testIncDec($x, array $y, iterable $it, $r, ?stdClass $s = null) {
    ++$x;
    echo strlen($x);
    --$y;
    echo strlen($y);
    ++$it;
    echo strlen($it);
    $r++;
    echo strlen($r);
    $null = null;
    $null++;
    echo strlen($null);
    $false = false;
    ++$false;
    echo strlen($false);
    $true = true;
    ++$true;
    echo strlen($true);
    $bool = rand(0,1) > 0;
    ++$bool;
    echo strlen($bool);
    $s--;
    echo strlen($s);
    $v = 2;
    ++$v;
    echo strlen($v);
}
/** @param object $x
 * @param resource $r
 */
function testIncDec2($x, array $y, iterable $it, $r, stdClass $s = null, stdClass $s2 = null) {
    echo strlen(++$x);
    echo strlen(--$y);
    echo strlen(++$it);
    echo strlen(++$r);
    $null = null;
    echo strlen($null++);  // warn about null
    $null = null;
    echo strlen(++$null);  // warn about int
    $false = false;
    echo strlen(++$false);
    $true = true;
    echo strlen(++$true);
    $bool = rand(0,1) > 0;
    echo strlen(--$bool);
    echo strlen(--$s);
    echo strlen($s2--);  // TODO: could normalize
    $v = 2;
    echo strlen(++$v);
    $v = 2;
    echo strlen($v++);
    $f = 2.5;
    echo strlen(++$f);
    $f = 3.25;
    $f++;
    echo strlen($f);  // This is 4.25
}
