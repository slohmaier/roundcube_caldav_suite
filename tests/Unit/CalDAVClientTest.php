<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slohmaier\CalDAVSuite\CalDAVClient;

class CalDAVClientTest extends TestCase
{
    public function testConstructorAcceptsUrl(): void
    {
        $client = new CalDAVClient('https://example.com/dav/', 'user', 'pass');
        $this->assertInstanceOf(CalDAVClient::class, $client);
    }

    public function testConstructorTrimsTrailingSlash(): void
    {
        $client = new CalDAVClient('https://example.com/dav/', 'user', 'pass');
        $this->assertInstanceOf(CalDAVClient::class, $client);
    }
}
