<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject;
use Slohmaier\CalDAVSuite\CalendarObject;

class CalendarObjectTest extends TestCase
{
    private function makeEvent(string $ics): CalendarObject
    {
        $vcalendar = VObject\Reader::read($ics);
        return new CalendarObject(
            url: '/cal/test.ics',
            etag: 'abc123',
            component: $vcalendar->VEVENT,
            vcalendar: $vcalendar,
            rawData: $ics,
        );
    }

    private function makeTodo(string $ics): CalendarObject
    {
        $vcalendar = VObject\Reader::read($ics);
        return new CalendarObject(
            url: '/tasks/test.ics',
            etag: 'def456',
            component: $vcalendar->VTODO,
            vcalendar: $vcalendar,
            rawData: $ics,
        );
    }

    public function testEventBasicProperties(): void
    {
        $obj = $this->makeEvent("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test-1\r\nSUMMARY:Zahnarzt\r\nLOCATION:Praxis Dr. Müller\r\nDESCRIPTION:Kontrolle\r\nDTSTART:20260723T090000Z\r\nDTEND:20260723T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

        $this->assertEquals('test-1', $obj->getUid());
        $this->assertEquals('Zahnarzt', $obj->getSummary());
        $this->assertEquals('Praxis Dr. Müller', $obj->getLocation());
        $this->assertEquals('Kontrolle', $obj->getDescription());
        $this->assertFalse($obj->isAllDay());
        $this->assertEquals('abc123', $obj->etag);
    }

    public function testEventAllDay(): void
    {
        $obj = $this->makeEvent("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test-2\r\nSUMMARY:Urlaub\r\nDTSTART;VALUE=DATE:20260801\r\nDTEND;VALUE=DATE:20260815\r\nEND:VEVENT\r\nEND:VCALENDAR");

        $this->assertTrue($obj->isAllDay());
        $this->assertEquals('Urlaub', $obj->getSummary());
    }

    public function testEventTimezone(): void
    {
        $obj = $this->makeEvent("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test-3\r\nSUMMARY:Meeting\r\nDTSTART;TZID=Europe/Berlin:20260723T110000\r\nDTEND;TZID=Europe/Berlin:20260723T120000\r\nEND:VEVENT\r\nEND:VCALENDAR");

        $start = $obj->getStart();
        $this->assertNotNull($start);
        $this->assertEquals('11', $start->format('H'));
    }

    public function testEventMissingSummary(): void
    {
        $obj = $this->makeEvent("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test-4\r\nDTSTART:20260723T090000Z\r\nDTEND:20260723T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

        $this->assertEquals('(Ohne Titel)', $obj->getSummary());
    }

    public function testTodoBasicProperties(): void
    {
        $obj = $this->makeTodo("BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nUID:todo-1\r\nSUMMARY:Einkaufen\r\nDUE:20260715T180000Z\r\nPRIORITY:1\r\nSTATUS:NEEDS-ACTION\r\nEND:VTODO\r\nEND:VCALENDAR");

        $this->assertEquals('todo-1', $obj->getUid());
        $this->assertEquals('Einkaufen', $obj->getSummary());
        $this->assertFalse($obj->isCompleted());
        $this->assertEquals(1, $obj->getPriority());
        $this->assertEquals('NEEDS-ACTION', $obj->getStatus());
        $this->assertNotNull($obj->getDue());
    }

    public function testTodoCompleted(): void
    {
        $obj = $this->makeTodo("BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nUID:todo-2\r\nSUMMARY:Fertig\r\nSTATUS:COMPLETED\r\nCOMPLETED:20260710T120000Z\r\nEND:VTODO\r\nEND:VCALENDAR");

        $this->assertTrue($obj->isCompleted());
    }

    public function testToArrayEvent(): void
    {
        $obj = $this->makeEvent("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:test-5\r\nSUMMARY:Test\r\nDTSTART:20260723T090000Z\r\nDTEND:20260723T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR");

        $arr = $obj->toArray();
        $this->assertEquals('test-5', $arr['uid']);
        $this->assertEquals('Test', $arr['summary']);
        $this->assertArrayHasKey('start', $arr);
        $this->assertArrayHasKey('end', $arr);
        $this->assertArrayHasKey('allDay', $arr);
        $this->assertFalse($arr['allDay']);
    }

    public function testToArrayTodo(): void
    {
        $obj = $this->makeTodo("BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nUID:todo-3\r\nSUMMARY:Task\r\nPRIORITY:5\r\nEND:VTODO\r\nEND:VCALENDAR");

        $arr = $obj->toArray();
        $this->assertEquals('todo-3', $arr['uid']);
        $this->assertArrayHasKey('completed', $arr);
        $this->assertArrayHasKey('priority', $arr);
        $this->assertEquals(5, $arr['priority']);
    }
}
