<?php // src/HttpException.php
namespace Falco;

/**
 * Throw from a handler/middleware to produce a JSON error response with the
 * given status code, e.g. `throw new HttpException(401, 'Not authenticated')`.
 */
class HttpException extends \Exception
{
    public function __construct(public readonly int $statusCode, string $detail)
    {
        parent::__construct($detail);
    }
}
