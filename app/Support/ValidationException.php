<?php

namespace App\Support;

use RuntimeException;

class ValidationException extends RuntimeException
{
    private array $errors;

    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
