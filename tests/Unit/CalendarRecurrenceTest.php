<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\CalendarBackend;
use Slohmaier\CalDAVSuite\CalendarObject;

class CalendarRecurrenceTest extends TestCase
{
    private CalendarBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new CalendarBackend();
    }

    private function vevent(string $ical): \Sabre\VObject\Component
    {
        return Reader::read($ical)->VEVENT;
    }

    private function asObject(string $ical): CalendarObject
    {
        $vcal = Reader::read($ical);
        return new CalendarObject('http://srv/e.ics', 'e1', $vcal->VEVENT, $vcal, $ical);
    }

    // ---- Recurrence (RRULE) ----

    public function testCreateWithRrule(): void
    {
        $ev = $this->vevent($this->backend->buildICalEvent([
            'title' => 'Standup', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T09:15',
            'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR',
        ]));
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', (string) $ev->RRULE);
    }

    public function testRruleRoundTripViaToArray(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'rrule' => 'FREQ=MONTHLY;BYMONTHDAY=15',
        ]);
        $this->assertSame('FREQ=MONTHLY;BYMONTHDAY=15', $this->asObject($ical)->toArray()['rrule']);
    }

    // ---- Fetch-Merge: Edit darf nicht behandelte Felder NICHT verlieren ----

    private function existingSeries(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\nBEGIN:VEVENT\r\n"
            . "UID:SERIE-1\r\nSUMMARY:Wochenmeeting\r\n"
            . "DTSTART;TZID=Europe/Berlin:20260701T100000\r\nDTEND;TZID=Europe/Berlin:20260701T110000\r\n"
            . "RRULE:FREQ=WEEKLY;BYDAY=MO\r\nATTENDEE;CN=Bob:mailto:bob@example.com\r\n"
            . "ORGANIZER:mailto:chef@example.com\r\nSEQUENCE:3\r\nX-CUSTOM-FOO:behalte-mich\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";
    }

    public function testEditPreservesRruleAndUnknownProps(): void
    {
        $ical = $this->backend->updateICalEvent($this->existingSeries(), ['title' => 'Umbenannt']);
        $this->assertNotNull($ical);
        $ev = $this->vevent($ical);
        $this->assertSame('Umbenannt', (string) $ev->SUMMARY);          // geaendert
        $this->assertSame('FREQ=WEEKLY;BYDAY=MO', (string) $ev->RRULE);  // erhalten
        $this->assertTrue(isset($ev->ATTENDEE), 'ATTENDEE muss erhalten bleiben');
        $this->assertTrue(isset($ev->ORGANIZER), 'ORGANIZER muss erhalten bleiben');
        $this->assertSame('behalte-mich', (string) $ev->{'X-CUSTOM-FOO'});
        $this->assertSame('SERIE-1', (string) $ev->UID);
    }

    public function testEditCanChangeRrule(): void
    {
        $ical = $this->backend->updateICalEvent($this->existingSeries(), ['rrule' => 'FREQ=DAILY;COUNT=10']);
        $this->assertSame('FREQ=DAILY;COUNT=10', (string) $this->vevent($ical)->RRULE);
    }

    public function testEditCanRemoveRrule(): void
    {
        $ical = $this->backend->updateICalEvent($this->existingSeries(), ['rrule' => '']);
        $this->assertFalse(isset($this->vevent($ical)->RRULE), 'leeres rrule entfernt die Serie');
    }

    public function testEditPreservesTimesWhenNotProvided(): void
    {
        // nur Titel aendern -> DTSTART/DTEND bleiben
        $ev = $this->vevent($this->backend->updateICalEvent($this->existingSeries(), ['title' => 'X']));
        $this->assertStringContainsString('20260701T100000', (string) $ev->DTSTART->serialize());
    }

    public function testEditReturnsNullForNonEvent(): void
    {
        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:T\r\nEND:VTODO\r\nEND:VCALENDAR";
        $this->assertNull($this->backend->updateICalEvent($todo, ['title' => 'x']));
    }

    // ---- Zeitzonen / DST ----

    public function testTimezoneSummerOffsetCEST(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Sommer', 'start' => '2026-07-01T12:00', 'end' => '2026-07-01T13:00',
            'timezone' => 'Europe/Berlin',
        ]);
        // Wallclock 12:00 Berlin im Sommer = 10:00 UTC (+02:00)
        $this->assertSame('2026-07-01T12:00:00+02:00', $this->asObject($ical)->getStart()->format('c'));
    }

    public function testTimezoneWinterOffsetCET(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Winter', 'start' => '2026-01-15T12:00', 'end' => '2026-01-15T13:00',
            'timezone' => 'Europe/Berlin',
        ]);
        // Wallclock 12:00 Berlin im Winter = 11:00 UTC (+01:00)
        $this->assertSame('2026-01-15T12:00:00+01:00', $this->asObject($ical)->getStart()->format('c'));
    }

    public function testTimezoneNewYork(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'NY', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'timezone' => 'America/New_York',
        ]);
        $obj = $this->asObject($ical);
        $this->assertSame('2026-07-01T09:00:00-04:00', $obj->getStart()->format('c')); // EDT
    }

    // ---- Weitere Felder ----

    public function testStatusUrlCategories(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'status' => 'CONFIRMED', 'url' => 'https://example.com/e',
            'categories' => ['Arbeit', 'Wichtig'],
        ]);
        $obj = $this->asObject($ical)->toArray();
        $this->assertSame('CONFIRMED', $obj['status']);
        $this->assertSame('https://example.com/e', $obj['url']);
        $this->assertSame(['Arbeit', 'Wichtig'], $obj['categories']);
    }

    public function testCategoriesFromCommaString(): void
    {
        $ev = $this->vevent($this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'categories' => 'A, B ,C',
        ]));
        $this->assertSame('A,B,C', (string) $ev->CATEGORIES);
    }

    public function testAllDayPreservedViaUpdate(): void
    {
        $existing = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:AD\r\nSUMMARY:Urlaub\r\n"
            . "DTSTART;VALUE=DATE:20260801\r\nDTEND;VALUE=DATE:20260816\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $ical = $this->backend->updateICalEvent($existing, [
            'title' => 'Urlaub 2', 'start' => '2026-08-01', 'end' => '2026-08-20', 'allday' => true,
        ]);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260821', $ical); // end+1 exklusiv
    }
}
