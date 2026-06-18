<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\CalDAVClient;
use Slohmaier\CalDAVSuite\TaskBackend;

class TaskBackendTest extends TestCase
{
    private TaskBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new TaskBackend();
    }

    /** Parse the built iCal back into a VTODO for structural assertions. */
    private function parseTodo(string $ical): \Sabre\VObject\Component
    {
        $vcal = Reader::read($ical);
        $this->assertTrue(isset($vcal->VTODO), 'iCal enthaelt kein VTODO');
        return $vcal->VTODO;
    }

    // ---- buildICalTodo: Grundgeruest ----

    public function testBuildBasicTodo(): void
    {
        $ical = $this->backend->buildICalTodo(['title' => 'Einkaufen']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ical);
        $todo = $this->parseTodo($ical);
        $this->assertSame('Einkaufen', (string) $todo->SUMMARY);
        $this->assertSame('NEEDS-ACTION', (string) $todo->STATUS);
        $this->assertTrue(isset($todo->UID));
        $this->assertTrue(isset($todo->DTSTAMP));
        $this->assertTrue(isset($todo->CREATED));
    }

    public function testGeneratesUidWhenMissing(): void
    {
        $a = $this->parseTodo($this->backend->buildICalTodo(['title' => 'A']));
        $b = $this->parseTodo($this->backend->buildICalTodo(['title' => 'B']));
        $this->assertNotEmpty((string) $a->UID);
        $this->assertNotSame((string) $a->UID, (string) $b->UID, 'UIDs muessen eindeutig sein');
    }

    public function testPreservesGivenUid(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo([
            'uid' => 'FIX-UID-123', 'title' => 'X',
        ]));
        $this->assertSame('FIX-UID-123', (string) $todo->UID);
    }

    public function testEmptyTitleProducesEmptySummary(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo([]));
        $this->assertSame('', (string) $todo->SUMMARY);
    }

    // ---- Prioritaeten ----

    public function testPriorityHigh(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'priority' => 'high']));
        $this->assertSame('1', (string) $todo->PRIORITY);
    }

    public function testPriorityMedium(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'priority' => 'medium']));
        $this->assertSame('5', (string) $todo->PRIORITY);
    }

    public function testPriorityLow(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'priority' => 'low']));
        $this->assertSame('9', (string) $todo->PRIORITY);
    }

    public function testPriorityNoneOmitsProperty(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'priority' => 'none']));
        $this->assertFalse(isset($todo->PRIORITY), 'PRIORITY=none darf keine PRIORITY-Property erzeugen');
    }

    public function testPriorityUnknownFallsBackToNone(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'priority' => 'bogus']));
        $this->assertFalse(isset($todo->PRIORITY));
    }

    public function testPriorityMissingOmitsProperty(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T']));
        $this->assertFalse(isset($todo->PRIORITY));
    }

    // ---- Status / Completed ----

    public function testCompletedSetsStatusAndTimestamp(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'completed' => true]));
        $this->assertSame('COMPLETED', (string) $todo->STATUS);
        $this->assertTrue(isset($todo->COMPLETED));
    }

    public function testNotCompletedSetsNeedsAction(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'completed' => false]));
        $this->assertSame('NEEDS-ACTION', (string) $todo->STATUS);
        $this->assertFalse(isset($todo->COMPLETED));
    }

    // ---- Faelligkeit ----

    public function testDueDate(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo([
            'title' => 'T', 'due' => '2026-06-25 09:00', 'timezone' => 'Europe/Berlin',
        ]));
        $this->assertTrue(isset($todo->DUE));
        $this->assertStringContainsString('20260625T090000', (string) $todo->DUE->serialize());
    }

    public function testNoDueOmitsProperty(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T']));
        $this->assertFalse(isset($todo->DUE));
    }

    public function testDescription(): void
    {
        $todo = $this->parseTodo($this->backend->buildICalTodo([
            'title' => 'T', 'description' => 'Mehr Details hier',
        ]));
        $this->assertSame('Mehr Details hier', (string) $todo->DESCRIPTION);
    }

    // ---- Sonderzeichen / Edge-Cases ----

    public function testSpecialCharsInTitleRoundTrip(): void
    {
        $title = 'Kauf: Milch, Eier; Brot \\ "Käse" & Öl — 100% 😀';
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => $title]));
        $this->assertSame($title, (string) $todo->SUMMARY);
    }

    public function testNewlinesInDescriptionRoundTrip(): void
    {
        $desc = "Zeile 1\nZeile 2\nZeile 3";
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'description' => $desc]));
        $this->assertSame($desc, (string) $todo->DESCRIPTION);
    }

    public function testCommaAndSemicolonInDescriptionRoundTrip(): void
    {
        $desc = 'a,b;c\\d';
        $todo = $this->parseTodo($this->backend->buildICalTodo(['title' => 'T', 'description' => $desc]));
        $this->assertSame($desc, (string) $todo->DESCRIPTION);
    }

    // ---- buildToggled ----

    public function testBuildToggledToCompleted(): void
    {
        $ical = $this->backend->buildToggled(['title' => 'T', 'uid' => 'U1'], true);
        $todo = $this->parseTodo($ical);
        $this->assertSame('COMPLETED', (string) $todo->STATUS);
        $this->assertSame('U1', (string) $todo->UID);
    }

    public function testBuildToggledToOpen(): void
    {
        $todo = $this->parseTodo($this->backend->buildToggled(['title' => 'T'], false));
        $this->assertSame('NEEDS-ACTION', (string) $todo->STATUS);
    }

    // ---- toggleCompleted (mit gemocktem Client) ----

    public function testToggleCompletedPreservesOtherFields(): void
    {
        $existing = $this->backend->buildICalTodo([
            'uid' => 'KEEP-1', 'title' => 'Wichtige Aufgabe',
            'due' => '2026-07-01 09:00', 'priority' => 'high',
            'description' => 'Bitte nicht verlieren',
        ]);
        $client = $this->createMock(CalDAVClient::class);
        $client->method('getObject')->willReturn(Reader::read($existing));

        $ical = $this->backend->toggleCompleted('http://srv/t.ics', true, $client);
        $this->assertNotNull($ical);
        $todo = $this->parseTodo($ical);
        $this->assertSame('COMPLETED', (string) $todo->STATUS);
        $this->assertTrue(isset($todo->COMPLETED));
        // andere Felder erhalten
        $this->assertSame('Wichtige Aufgabe', (string) $todo->SUMMARY);
        $this->assertSame('KEEP-1', (string) $todo->UID);
        $this->assertSame('1', (string) $todo->PRIORITY);
        $this->assertSame('Bitte nicht verlieren', (string) $todo->DESCRIPTION);
        $this->assertTrue(isset($todo->DUE));
    }

    public function testToggleBackToOpenRemovesCompleted(): void
    {
        $existing = $this->backend->buildICalTodo(['uid' => 'U', 'title' => 'T', 'completed' => true]);
        $client = $this->createMock(CalDAVClient::class);
        $client->method('getObject')->willReturn(Reader::read($existing));

        $todo = $this->parseTodo($this->backend->toggleCompleted('http://srv/t.ics', false, $client));
        $this->assertSame('NEEDS-ACTION', (string) $todo->STATUS);
        $this->assertFalse(isset($todo->COMPLETED));
    }

    public function testToggleReturnsNullWhenObjectMissing(): void
    {
        $client = $this->createMock(CalDAVClient::class);
        $client->method('getObject')->willReturn(null);
        $this->assertNull($this->backend->toggleCompleted('http://srv/x.ics', true, $client));
    }

    public function testToggleReturnsNullWhenNoVtodo(): void
    {
        // VCALENDAR mit VEVENT statt VTODO
        $vcal = new \Sabre\VObject\Component\VCalendar();
        $vcal->add('VEVENT', ['UID' => 'E', 'SUMMARY' => 'event']);
        $client = $this->createMock(CalDAVClient::class);
        $client->method('getObject')->willReturn($vcal);
        $this->assertNull($this->backend->toggleCompleted('http://srv/e.ics', true, $client));
    }

    public function testToggleDoesNotDuplicateStatus(): void
    {
        $existing = $this->backend->buildICalTodo(['uid' => 'U', 'title' => 'T', 'completed' => true]);
        $client = $this->createMock(CalDAVClient::class);
        $client->method('getObject')->willReturn(Reader::read($existing));
        $ical = $this->backend->toggleCompleted('http://srv/t.ics', true, $client);
        // genau eine STATUS-Property (kein Doppeln durch das Toggle)
        $this->assertSame(1, substr_count($ical, 'STATUS:'));
        $this->assertSame(1, substr_count($ical, 'STATUS:COMPLETED'));
    }

    // ---- sortTasks ----

    public function testSortByDue(): void
    {
        $tasks = [
            ['due' => '2026-07-10'], ['due' => '2026-07-01'], ['due' => null],
        ];
        $sorted = $this->backend->sortTasks($tasks, 'due');
        $this->assertSame('2026-07-01', $sorted[0]['due']);
        $this->assertSame('2026-07-10', $sorted[1]['due']);
        $this->assertNull($sorted[2]['due'], 'Tasks ohne Faelligkeit ans Ende');
    }

    public function testSortByPriority(): void
    {
        $tasks = [
            ['priority' => 0], ['priority' => 9], ['priority' => 1],
        ];
        $sorted = $this->backend->sortTasks($tasks, 'priority');
        $this->assertSame(1, $sorted[0]['priority']);
        $this->assertSame(9, $sorted[1]['priority']);
        $this->assertSame(0, $sorted[2]['priority'], 'Prio 0 (keine) ans Ende');
    }
}
