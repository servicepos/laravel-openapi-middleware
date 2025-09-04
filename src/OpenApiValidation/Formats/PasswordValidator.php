<?php


namespace C1st\Middleware\OpenApiValidation\Formats;

use Opis\JsonSchema\Format;

class PasswordValidator implements Format
{
    public function validate($data) : bool
    {
        return is_string($data);
    }
}
