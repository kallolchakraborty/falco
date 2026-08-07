<?php
namespace Falco\Tests;

use Falco\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testGetReturnsDefault(): void
    {
        $c = new Config(['a' => 1]);
        $this->assertSame(1, $c->get('a'));
        $this->assertNull($c->get('missing'));
        $this->assertSame(2, $c->get('missing', 2));
    }
}