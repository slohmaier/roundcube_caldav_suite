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

        $this->assertStringContainsString('X-APPLE-TRAVEL-DURATION:PT45M', $ical);
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
}
