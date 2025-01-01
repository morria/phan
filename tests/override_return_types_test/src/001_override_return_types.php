<?php

class Animal {}

class Cat extends Animal {}

class Dog extends Animal {}

/* @phan-suppress-next-line PhanUnreferencedClass */
class Vegetable {}

/* @phan-suppress-next-line PhanUnreferencedClass */
class OverrideReturnTypesTest {

    public function getAnimal(): Animal {
        return random_int(0, 10) > 5 ? new Cat : new Dog;
    }

    /**
     * This method has an incorrect phpdoc @return annotation.
     * @return Vegetable
     */
    public function getAnimal2() {
        return new Cat;
    }

    /* @phan-suppress-next-line PhanUnreferencedPublicMethod */
    public function run(): void {
        $this->plant($this->getAnimal());
        $this->plant($this->getAnimal2());
    }

    public function plant(Vegetable $v): void {
        var_dump($v);
    }

}
