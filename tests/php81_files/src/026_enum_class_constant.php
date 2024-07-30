<?php

enum A26 {
	case Single;
}

class B26 {
	/** @var A26 */
	public const Valid = A26::Single;
}

echo B26::Valid->name, "\n";
