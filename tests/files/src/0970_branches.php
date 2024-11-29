<?php

function unused() {
	$var = 1;
}

function overwritten_no_branch() {
	$var = 1;
	$var = 2;
	return $var;
}

function overwritten_if() {
	$var = 1;
	if (rand(0,1)) {
		$var = 2;
	} else {
		$var = 3;
	}
	return $var;
}

function overwritten_switch() {
	$var = 1;
	switch(rand(0,1)) {
		case 0:
			$var = 2;
			break;

		case 1:
			$var = 3;
			break;

		default:
			$var = 4;
			break;
	}
	return $var;
}

function overwritten_conditional() {
	$var = 1;
	(rand(0,1) == 1) ? $var = 2 : $var = 3;
	return $var;
}

function overwritten_try_catch() {
	$var = 1;
	try {
		$var = 2;
	} catch (Exception $_) {
		return;
	}
	return $var;
}