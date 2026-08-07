<?php // tests/SwooleRuntimeTest.php
namespace Falco\Tests;

use Falco\Runtime\SwooleRuntime;
use PHPUnit\Framework\TestCase;

final class SwooleRuntimeTest extends TestCase
{
    public function testRequiresSwooleExtension(): void
    {
        $this->expectException(\RuntimeException::class);
        (new SwooleRuntime())->serve(new \Falco\App(docs: false));
    }
}