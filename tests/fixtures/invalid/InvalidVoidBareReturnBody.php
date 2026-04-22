<?php

namespace App;

class InvalidVoidBareReturnBody
{
    public function inlineEmpty(): void
    {
        return;
    }
}
