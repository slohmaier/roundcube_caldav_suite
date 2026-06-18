<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\CalendarObject;
use Slohmaier\CalDAVSuite\TaskBackend;

class TaskFieldsTest extends TestCase
{
    private TaskBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new TaskBackend();
    }

    private function vtodo(string $ical): \Sabre\VObject\Component
    {
        return Reader::read($ical)->VTODO;
    }

    private function asObject(string $ical): CalendarObject
    {
        $vcal = Reader::read($ical);
        return new CalendarObject('http://srv/t.ics', 't1', $vcal->VTODO, $vcal, $ical);
    }

    public function testDtStart(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo([
            'title' => 'T', 'start' => '2026-07-01 08:00', 'timezone' => 'Europe/Berlin',
        ]));
        $this->assertTrue(isset($todo->DTSTART));
        $this->assertStringContainsString('20260701T080000', (string) $todo->DTSTART->serialize());
    }

    public function testPercentComplete(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo(['title' => 'T', 'percent_complete' => 40]));
        $this->assertSame('40', (string) $todo->{'PERCENT-COMPLETE'});
    }

    public function testPercentCompleteZeroOmitted(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo(['title' => 'T', 'percent_complete' => 0]));
        $this->assertFalse(isset($todo->{'PERCENT-COMPLETE'}));
    }

    public function testPercentCompleteCappedAt100(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo(['title' => 'T', 'percent_complete' => 250]));
        $this->assertSame('100', (string) $todo->{'PERCENT-COMPLETE'});
    }

    public function testCategories(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo(['title' => 'T', 'categories' => ['Haus', 'Garten']]));
        $this->assertSame('Haus,Garten', (string) $todo->CATEGORIES);
    }

    public function testRrule(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo(['title' => 'T', 'rrule' => 'FREQ=DAILY']));
        $this->assertSame('FREQ=DAILY', (string) $todo->RRULE);
    }

    public function testUrlAndLocation(): void
    {
        $todo = $this->vtodo($this->backend->buildICalTodo([
            'title' => 'T', 'url' => 'https://x.de/t', 'location' => 'Büro',
        ]));
        $this->assertSame('https://x.de/t', (string) $todo->URL);
        $this->assertSame('Büro', (string) $todo->LOCATION);
    }

    public function testToArrayExposesNewFields(): void
    {
        $ical = $this->backend->buildICalTodo([
            'title' => 'T', 'percent_complete' => 30, 'categories' => ['A'], 'rrule' => 'FREQ=WEEKLY',
            'due' => '2026-07-10 09:00',
        ]);
        $arr = $this->asObject($ical)->toArray();
        $this->assertSame(30, $arr['percent_complete']);
        $this->assertSame(['A'], $arr['categories']);
        $this->assertSame('FREQ=WEEKLY', $arr['rrule']);
    }

    // ---- Fetch-Merge ----

    private function existingTodo(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:TODO-1\r\nSUMMARY:Serie-Task\r\n"
            . "DUE;TZID=Europe/Berlin:20260710T090000\r\nPRIORITY:1\r\nRRULE:FREQ=WEEKLY\r\n"
            . "RELATED-TO:PARENT-XYZ\r\nX-CUSTOM-BAR:halt-mich\r\nSTATUS:NEEDS-ACTION\r\n"
            . "END:VTODO\r\nEND:VCALENDAR";
    }

    public function testEditPreservesRruleAndUnknownProps(): void
    {
        $ical = $this->backend->updateICalTodo($this->existingTodo(), ['title' => 'Neu']);
        $todo = $this->vtodo($ical);
        $this->assertSame('Neu', (string) $todo->SUMMARY);
        $this->assertSame('FREQ=WEEKLY', (string) $todo->RRULE);
        $this->assertSame('PARENT-XYZ', (string) $todo->{'RELATED-TO'});
        $this->assertSame('halt-mich', (string) $todo->{'X-CUSTOM-BAR'});
        $this->assertSame('1', (string) $todo->PRIORITY);
    }

    public function testEditPreservesPriorityWhenNotProvided(): void
    {
        $ical = $this->backend->updateICalTodo($this->existingTodo(), ['completed' => true]);
        $todo = $this->vtodo($ical);
        $this->assertSame('1', (string) $todo->PRIORITY, 'Prioritaet darf beim Abhaken nicht verschwinden');
        $this->assertSame('COMPLETED', (string) $todo->STATUS);
    }

    public function testEditReturnsNullForNonTodo(): void
    {
        $ev = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:E\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $this->assertNull($this->backend->updateICalTodo($ev, ['title' => 'x']));
    }
}
