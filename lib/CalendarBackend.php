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
