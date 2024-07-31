<?php

enum A26 {
	case Single;
}

class B26 {
	/** @var A26 */
	public const Valid = A26::Single;

    /** @var array<int|string, array<int, string|A26>> */
    public const AlsoValid = [ [ A26::Single ] ];
}

echo B26::Valid->name, "\n";
echo B26::AlsoValid[0][0]->name, "\n";
