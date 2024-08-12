<?php

enum A26 {
	case Single;
}

class B26 {

    /** @var A26 */
    public const VALID = A26::Single;

    /** @var array<int|string, array<int, string|A26>> */
    public const ALSO_VALID = [ [ A26::Single ] ];

}

class C26 {

    /** @var A26|B26 */
    public const NOT_VALID = A26::Single;

    /** @var B26|A26 */
    public const ALSO_NOT_VALID = A26::Single;

}

echo B26::VALID->name, "\n";
echo B26::ALSO_VALID[0][0]->name, "\n";
echo C26::NOT_VALID->name, "\n";
echo C26::ALSO_NOT_VALID->name, "\n";
