<?php // src/Validation/ValidationException.php
namespace Falco\Validation;

/**
 * Holds FastAPI-style error details: an array of `{loc, msg, type}` objects.
 * Mapped to HTTP 422 by App / ErrorHandlerMiddleware.
 */
final class ValidationException extends \Exception
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed');
    }
}
