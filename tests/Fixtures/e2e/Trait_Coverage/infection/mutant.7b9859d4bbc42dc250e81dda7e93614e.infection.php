<?php

namespace Trait_Coverage;

trait SourceTrait
{
    public function world()
    {
        return ' World!';
    }
    public function add($num1, $num2, $value = false) : int
    {
        if ($value) {
            return $num1 + $num2;
        }
        return 0;
    }
}