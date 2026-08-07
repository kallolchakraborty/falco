<?php // src/HttpException.php
namespace Falco;

class HttpException extends \Exception
{
    public function __construct(public readonly int $statusCode, string $detail)
    {
        parent::__construct($detail);
    }
}
