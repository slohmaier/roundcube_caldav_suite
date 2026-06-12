<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Component\VCalendar;

class CalendarBackend
{
    public function buildICalEvent(array $data): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//CalDAV Suite//EN';

        $vevent = $vcalendar->add('VEVENT', [
            'UID'     => $data['uid'] ?? \Sabre\VObject\UUIDUtil::getUUID(),
            'SUMMARY' => $data['title'] ?? '',
            'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);

        if (!empty($data['location'])) {
            $vevent->add('LOCATION', $data['location']);
        }
        if (!empty($data['description'])) {
            $vevent->add('DESCRIPTION', $data['description']);
        }

        // Apple Travel Time
        if (!empty($data['travel_mode'])) {
            if ($data['travel_mode'] === 'auto') {
                $vevent->add('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR', 'AUTOMATIC');
            } elseif (is_numeric($data['travel_mode'])) {
                $minutes = (int)$data['travel_mode'];
                $vevent->add('X-APPLE-TRAVEL-DURATION', 'PT' . $minutes . 'M', ['VALUE' => 'DURATION']);
            }
        }

        // Structured location (geo coordinates for Apple Maps travel time)
        if (!empty($data['location_geo'])) {
            $geo = $data['location_geo']; // "lat,lng"
            $locName = $data['location'] ?? '';
            $vevent->add('X-APPLE-STRUCTURED-LOCATION',
                'geo:' . $geo,
                [
                    'VALUE'                    => 'URI',
                    'X-APPLE-RADIUS'           => '70',
                    'X-APPLE-REFERENCEFRAME'   => '1',
                    'X-TITLE'                  => $locName,
                ]
            );
            $vevent->add('GEO', str_replace(',', ';', $geo));
        }

        // Reminder/Alarm
        if (!empty($data['reminder_minutes'])) {
            $alarm = $vevent->add('VALARM');
            $alarm->add('ACTION', 'DISPLAY');
            $alarm->add('DESCRIPTION', $data['title'] ?? 'Erinnerung');
            $alarm->add('TRIGGER', '-PT' . (int)$data['reminder_minutes'] . 'M');
        }

        $tz = new \DateTimeZone($data['timezone'] ?? 'Europe/Berlin');

        if (!empty($data['allday'])) {
            $start = new \DateTimeImmutable($data['start'], $tz);
            $end = new \DateTimeImmutable($data['end'], $tz);
            $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
            $vevent->add('DTEND', $end->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);
        } else {
            $start = new \DateTimeImmutable($data['start'], $tz);
            $end = new \DateTimeImmutable($data['end'], $tz);
            $vevent->add('DTSTART', $start, ['TZID' => $tz->getName()]);
            $vevent->add('DTEND', $end, ['TZID' => $tz->getName()]);
        }

        return $vcalendar->serialize();
    }
}
