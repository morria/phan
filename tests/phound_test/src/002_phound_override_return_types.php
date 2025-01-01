<?php

class Animal {}

class Cat extends Animal {
    public function shedFur() {}
}

class Dog extends Animal {
    public function shedFur() {}
}

/* @phan-suppress-next-line PhanUnreferencedClass */
class Vegetable {}

/* @phan-suppress-next-line PhanUnreferencedClass */
class PhoundOverrideReturnTypesTest {

    public function getAnimal(): Animal {
        return random_int(0, 10) > 5 ? new Cat : new Dog;
    }

    /**
     * This method has an incorrect phpdoc @return annotation.
     * @return Vegetable
     */
    public function getAnimal2() {
        return new Cat; // @phan-suppress-current-line PhanTypeMismatchReturnProbablyReal
    }

    /* @phan-suppress-next-line PhanUnreferencedPublicMethod */
    public function run(): void {
        $animal1 = $this->getAnimal();
        $animal1->shedFur(); // @phan-suppress-current-line PhanUndeclaredMethod Call to undeclared method \Animal::shedFur

        $animal2 = $this->getAnimal2();
        $animal2->shedFur(); // @phan-suppress-current-line PhanUndeclaredMethod Call to undeclared method \Vegetable::shedFur
    }

    public function plant(Vegetable $v): void {
        var_dump($v);
    }

}
