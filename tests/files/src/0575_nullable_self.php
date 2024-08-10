<?php
namespace PhanBug;
trait Foo
{
    /** @var self|null */
    protected $parent;

    /** @var self[] */
    protected $other;

    public function setParent(?self $parent = null)
    {
        $this->parent = $parent;
        $this->parent = null;
        $this->parent = $this;
        $this->parent = 2;
    }

    public function setOther(self $other)
    {
        $this->other = [$other];  // should not warn
        $this->other = [$this];  // should not warn, static is self or a subtype of self
        $this->other = [null];
    }
}
