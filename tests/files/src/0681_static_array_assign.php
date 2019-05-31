<?php

class X681 implements ArrayAccess {
    /**
     * @param string $key
     */
    public function offsetExists($key) {
        return true;
    }

    /**
     * @param string $key
     */
    public function offsetGet($key) {
        return 'X';
    }

    /**
     * @param string $key
     * @param string $value
     */
    public function offsetSet($key, $value) {
        echo "Stub to set $key to $value\n";
    }

    /**
     * @param string $key
     */
    public function offsetUnset($key) {
        echo "Stub to unset $key\n";
    }

    public function testGetSet() {
        $this['field'] = 'X';
        var_export($this['otherField']);
    }
}
