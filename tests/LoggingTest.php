<?php
namespace Falco\Tests;

use Falco\Logging\Logger;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    public function testEmitsJsonLine(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream);
        $log->info('hello', ['code' => 7]);
        rewind($stream);
        $line = json_decode(stream_get_contents($stream), true);
        $this->assertSame('info', $line['level']);
        $this->assertSame('hello', $line['message']);
        $this->assertSame(7, $line['code']);
    }

    public function testFiltersByLevel(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream, 'error');
        $log->info('noise');
        $log->error('boom');
        rewind($stream);
        $this->assertSame('boom', json_decode(stream_get_contents($stream), true)['message']);
    }

    public function testStringifiesNonScalars(): void
    {
        $stream = fopen('php://memory', 'w+');
        $log = new Logger($stream);
        $log->info('ctx', ['obj' => (object)['x' => 1]]);
        rewind($stream);
        $this->assertSame(['x' => 1], json_decode(stream_get_contents($stream), true)['obj']);
    }
}