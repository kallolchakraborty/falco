<?php // src/Validation/ValidationException.php
namespace Falco\Validation;

final class ValidationException extends \Exception
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }
}
