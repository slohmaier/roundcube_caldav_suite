<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slohmaier\CalDAVSuite\Collection;

class CollectionTest extends TestCase
{
    public function testSupportsEvents(): void
    {
        $cal = new Collection('/cal/', 'Test', ['VEVENT']);
        $this->assertTrue($cal->supportsEvents());
        $this->assertFalse($cal->supportsTodos());
    }

    public function testSupportsTodos(): void
    {
        $tasks = new Collection('/tasks/', 'Tasks', ['VTODO']);
        $this->assertFalse($tasks->supportsEvents());
        $this->assertTrue($tasks->supportsTodos());
    }

    public function testSupportsBoth(): void
    {
        $both = new Collection('/both/', 'Both', ['VEVENT', 'VTODO']);
        $this->assertTrue($both->supportsEvents());
        $this->assertTrue($both->supportsTodos());
    }

    public function testDefaultComponentsBoth(): void
    {
        $col = new Collection('/default/', 'Default');
        $this->assertTrue($col->supportsEvents());
        $this->assertTrue($col->supportsTodos());
    }

    public function testGetIdIsDeterministic(): void
    {
        $col1 = new Collection('/cal/', 'Test');
        $col2 = new Collection('/cal/', 'Different Name');
        $this->assertEquals($col1->getId(), $col2->getId());
    }

    public function testGetIdDiffersForDifferentUrls(): void
    {
        $col1 = new Collection('/cal1/', 'Test');
        $col2 = new Collection('/cal2/', 'Test');
        $this->assertNotEquals($col1->getId(), $col2->getId());
    }
}
