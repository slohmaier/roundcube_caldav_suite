<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Slohmaier\CalDAVSuite\CalendarBackend;

class CalendarBackendTest extends TestCase
{
    private CalendarBackend $backend;

    protected function setUp(): void
    {
        $this->backend = new CalendarBackend();
    }

    public function testBuildBasicEvent(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Test Event',
            'start' => '2026-07-23T09:00',
            'end'   => '2026-07-23T10:00',
        ]);

        $this->assertStringContainsString('SUMMARY:Test Event', $ical);
        $this->assertStringContainsString('DTSTART', $ical);
        $this->assertStringContainsString('DTEND', $ical);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ical);
    }

    public function testBuildAllDayEvent(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title'  => 'Urlaub',
            'start'  => '2026-08-01',
            'end'    => '2026-08-15',
            'allday' => true,
        ]);

        $this->assertStringContainsString('SUMMARY:Urlaub', $ical);
        $this->assertStringContainsString('VALUE=DATE', $ical);
    }

    public function testBuildEventWithLocation(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title'    => 'Meeting',
            'start'    => '2026-07-23T09:00',
            'end'      => '2026-07-23T10:00',
            'location' => 'Büro München',
        ]);

        $this->assertStringContainsString('LOCATION:Büro München', $ical);
    }

    public function testBuildEventWithAutoTravelTime(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title'       => 'Augenklinik',
            'start'       => '2026-07-23T09:45',
            'end'         => '2026-07-23T11:00',
            'location'    => 'LMU Augenklinik',
            'travel_mode' => 'auto',
        ]);

        $this->assertStringContainsString('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR:AUTOMATIC', $ical);
        $this->assertStringNotContainsString('X-APPLE-TRAVEL-DURATION', $ical);
    }

    public function testBuildEventWithManualTravelTime(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title'       => 'Augenklinik',
            'start'       => '2026-07-23T09:45',
            'end'         => '2026-07-23T11:00',
            'travel_mode' => '45',
        ]);

        $this->assertStringContainsString('X-APPLE-TRAVEL-DURATION', $ical);
        $this->assertStringContainsString('PT45M', $ical);
        $this->assertStringNotContainsString('TRAVEL-ADVISORY-BEHAVIOR', $ical);
    }

    public function testBuildEventNoTravelTime(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Simple',
            'start' => '2026-07-23T09:00',
            'end'   => '2026-07-23T10:00',
        ]);

        $this->assertStringNotContainsString('TRAVEL', $ical);
    }

    public function testBuildEventWithGeoLocation(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title'        => 'Augenklinik',
            'start'        => '2026-07-23T09:45',
            'end'          => '2026-07-23T11:00',
            'location'     => 'LMU Augenklinik',
            'location_geo' => '48.1351,11.5820',
            'travel_mode'  => 'auto',
        ]);

        $this->assertStringContainsString('X-APPLE-STRUCTURED-LOCATION', $ical);
        $this->assertStringContainsString('geo:48.1351', $ical);
        $this->assertStringContainsString('11.5820', $ical);
        $this->assertStringContainsString('X-TITLE=LMU', $ical);
        $this->assertStringContainsString('GEO:48.1351;11.5820', $ical);
    }

    // ---- Erweiterte Edge-Cases ----

    private function parseEvent(string $ical): \Sabre\VObject\Component
    {
        $vcal = \Sabre\VObject\Reader::read($ical);
        $this->assertTrue(isset($vcal->VEVENT), 'kein VEVENT');
        return $vcal->VEVENT;
    }

    public function testGeneratesUidWhenMissing(): void
    {
        $a = $this->parseEvent($this->backend->buildICalEvent(['title' => 'A', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00']));
        $b = $this->parseEvent($this->backend->buildICalEvent(['title' => 'B', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00']));
        $this->assertNotEmpty((string) $a->UID);
        $this->assertNotSame((string) $a->UID, (string) $b->UID);
    }

    public function testPreservesGivenUidOnEdit(): void
    {
        $ev = $this->parseEvent($this->backend->buildICalEvent([
            'uid' => 'EDIT-UID-1', 'title' => 'Geaendert',
            'start' => '2026-07-01T12:00', 'end' => '2026-07-01T13:00',
        ]));
        $this->assertSame('EDIT-UID-1', (string) $ev->UID);
    }

    public function testReminderCreatesValarm(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Termin', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'reminder_minutes' => 30,
        ]);
        $this->assertStringContainsString('BEGIN:VALARM', $ical);
        $this->assertStringContainsString('ACTION:DISPLAY', $ical);
        $this->assertStringContainsString('TRIGGER:-PT30M', $ical);
    }

    public function testNoReminderNoValarm(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'Termin', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]);
        $this->assertStringNotContainsString('VALARM', $ical);
    }

    public function testDescriptionRoundTrip(): void
    {
        $ev = $this->parseEvent($this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'description' => 'Agenda:\nPunkt 1, Punkt 2; Ende',
        ]));
        $this->assertSame('Agenda:\nPunkt 1, Punkt 2; Ende', (string) $ev->DESCRIPTION);
    }

    public function testSpecialCharsInTitleRoundTrip(): void
    {
        $title = 'Café-Meeting: Ümläute, Semikolon; Backslash \\ & 100% 🎉';
        $ev = $this->parseEvent($this->backend->buildICalEvent([
            'title' => $title, 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]));
        $this->assertSame($title, (string) $ev->SUMMARY);
    }

    public function testCustomTimezone(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
            'timezone' => 'America/New_York',
        ]);
        $this->assertStringContainsString('TZID=America/New_York', $ical);
    }

    public function testDefaultTimezoneBerlin(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]);
        $this->assertStringContainsString('TZID=Europe/Berlin', $ical);
    }

    public function testAllDayEndIsExclusivePlusOne(): void
    {
        // allday end 2026-08-15 -> DTEND 20260816 (iCal exklusiv)
        $ical = $this->backend->buildICalEvent([
            'title' => 'Urlaub', 'start' => '2026-08-01', 'end' => '2026-08-15', 'allday' => true,
        ]);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260801', $ical);
        $this->assertStringContainsString('DTEND;VALUE=DATE:20260816', $ical);
    }

    public function testTimedEventHasNoDateValue(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]);
        $this->assertStringContainsString('DTSTART;TZID=', $ical);
        $this->assertStringNotContainsString('VALUE=DATE', $ical);
    }

    public function testEmptyTitleProducesEmptySummary(): void
    {
        $ev = $this->parseEvent($this->backend->buildICalEvent([
            'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]));
        $this->assertSame('', (string) $ev->SUMMARY);
    }

    public function testNoLocationNoLocationProperty(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]);
        $this->assertStringNotContainsString('LOCATION', $ical);
    }

    public function testDtstampAlwaysPresent(): void
    {
        $ical = $this->backend->buildICalEvent([
            'title' => 'T', 'start' => '2026-07-01T09:00', 'end' => '2026-07-01T10:00',
        ]);
        $this->assertStringContainsString('DTSTAMP', $ical);
    }
}
