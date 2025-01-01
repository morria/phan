<?php

class TestPassByRef
{
    public function testRequired(string &$test)
    {
    }

    public function test(string &$test = 'hi')
    {
    }

    public function testNullable(?string &$test = null)
    {
    }

    /**
     * @param string &$test
     */
    public function testPHPDoc(&$test)
    {
    }

    /**
     * @param string $test
     */
    public function testPHPDocWithDefault(&$test = 'defaultStr')
    {
    }

    public function other()
    {
        $this->testRequired($test0);  // warn about PhanTypeMismatchArgument, this causes an error at runtime
        $this->test($test);  // warn about PhanTypeMismatchArgument, this causes an error at runtime
        $this->test();  // don't warn, the default is compatible
        $this->testNullable($test2);  // don't warn, this can be null
        $this->testNullable();  // don't warn, this is optional
        $this->testPHPDoc($test3);  // don't warn, this is just phpdoc
        $this->testPHPDocWithDefault($test4);  // don't warn, this is just phpdoc
    }
}

$instance = new TestPassByRef();
$instance->other();
