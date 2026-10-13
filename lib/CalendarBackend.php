<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class CalendarBackend
{
    /**
     * Build a new event from scratch (create).
     *
     * @param array<string, mixed> $data
     */
    public function buildICalEvent(array $data): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//CalDAV Suite//EN';

        // Replace an empty uid (new event) with a fresh UUID, otherwise Radicale replies 400.
        $uid = (isset($data['uid']) && !empty($data['uid'])) ? $data['uid'] : \Sabre\VObject\UUIDUtil::getUUID();
        $vevent = $vcalendar->add('VEVENT', [
            'UID' => $uid,
        ]);
        if (!array_key_exists('title', $data)) {
            $vevent->SUMMARY = '';
        }

        $this->applyEvent($vevent, $data);

        return $vcalendar->serialize();
    }

    /**
     * Update an existing event WITHOUT losing unhandled fields
     * (RRULE, ATTENDEE, ORGANIZER, SEQUENCE, EXDATE, custom X- props are preserved).
     * Only the fields present in $data are touched.
     *
     * @param array<string, mixed> $data
     */
    public function updateICalEvent(string $existingICal, array $data): ?string
    {
        $vcalendar = Reader::read($existingICal);
        if (!isset($vcalendar->VEVENT)) {
            return null;
        }
        $this->applyEvent($vcalendar->VEVENT, $data);
        return $vcalendar->serialize();
    }

    /**
     * Apply Roundcube/JS data to a VEVENT component.
     * Required fields (title/start/end) are set if present; optional fields ONLY
     * if their key exists in $data (empty value = remove). This way, fields the
     * form does not know about are not lost on edit.
     *
     * @param \Sabre\VObject\Component $vevent
     * @param array<string, mixed>     $data
     */
    private function applyEvent($vevent, array $data): void
    {
        if (array_key_exists('uid', $data) && !empty($data['uid'])) {
            $vevent->UID = $data['uid'];
        }
        if (array_key_exists('title', $data)) {
            $vevent->SUMMARY = (string) $data['title'];
        }

        // Start / end (+ all-day + timezone)
        if (array_key_exists('start', $data) && array_key_exists('end', $data)) {
            $vevent->remove('DTSTART');
            $vevent->remove('DTEND');
            $tz = new \DateTimeZone($data['timezone'] ?? 'Europe/Berlin');
            if (!empty($data['allday'])) {
                $start = new \DateTimeImmutable($data['start'], $tz);
                $end   = new \DateTimeImmutable($data['end'], $tz);
                $vevent->add('DTSTART', $start->format('Ymd'), ['VALUE' => 'DATE']);
                $vevent->add('DTEND', $end->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);
            } else {
                $start = new \DateTimeImmutable($data['start'], $tz);
                $end   = new \DateTimeImmutable($data['end'], $tz);
                $vevent->add('DTSTART', $start, ['TZID' => $tz->getName()]);
                $vevent->add('DTEND', $end, ['TZID' => $tz->getName()]);
            }
        }

        $this->setOrRemove($vevent, 'LOCATION', $data, 'location');
        $this->setOrRemove($vevent, 'DESCRIPTION', $data, 'description');
        $this->setOrRemove($vevent, 'URL', $data, 'url');
        $this->setOrRemove($vevent, 'STATUS', $data, 'status');
        $this->setOrRemove($vevent, 'TRANSP', $data, 'transp');
        $this->setOrRemove($vevent, 'CLASS', $data, 'class');

        // Recurrence (RRULE) — as a string, e.g. "FREQ=WEEKLY;BYDAY=MO,WE"
        if (array_key_exists('rrule', $data)) {
            $vevent->remove('RRULE');
            if (!empty($data['rrule'])) {
                $vevent->add('RRULE', $data['rrule']);
            }
        }

        // Categories
        if (array_key_exists('categories', $data)) {
            $vevent->remove('CATEGORIES');
            $cats = $this->normalizeList($data['categories']);
            if ($cats) {
                $vevent->add('CATEGORIES', $cats);
            }
        }

        // Apple Travel Time
        if (array_key_exists('travel_mode', $data)) {
            $vevent->remove('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR');
            $vevent->remove('X-APPLE-TRAVEL-DURATION');
            $mode = $data['travel_mode'];
            if ($mode === 'auto') {
                $vevent->add('X-APPLE-TRAVEL-ADVISORY-BEHAVIOR', 'AUTOMATIC');
            } elseif (is_numeric($mode) && (int) $mode > 0) {
                $vevent->add('X-APPLE-TRAVEL-DURATION', 'PT' . (int) $mode . 'M', ['VALUE' => 'DURATION']);
            }
        }

        // Structured geo location (Apple Maps travel time)
        if (array_key_exists('location_geo', $data)) {
            $vevent->remove('GEO');
            $vevent->remove('X-APPLE-STRUCTURED-LOCATION');
            if (!empty($data['location_geo'])) {
                $geo = $data['location_geo']; // "lat,lng"
                $locName = $data['location'] ?? '';
                $vevent->add('X-APPLE-STRUCTURED-LOCATION', 'geo:' . $geo, [
                    'VALUE'                  => 'URI',
                    'X-APPLE-RADIUS'         => '70',
                    'X-APPLE-REFERENCEFRAME' => '1',
                    'X-TITLE'                => $locName,
                ]);
                $vevent->add('GEO', str_replace(',', ';', $geo));
            }
        }

        // Reminder (VALARM)
        if (array_key_exists('reminder_minutes', $data)) {
            $vevent->remove('VALARM');
            if (!empty($data['reminder_minutes'])) {
                $alarm = $vevent->add('VALARM');
                $alarm->add('ACTION', 'DISPLAY');
                $alarm->add('DESCRIPTION', $data['title'] ?? (string) ($vevent->SUMMARY ?? 'Reminder'));
                $alarm->add('TRIGGER', '-PT' . (int) $data['reminder_minutes'] . 'M');
            }
        }

        $vevent->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param \Sabre\VObject\Component $comp
     * @param array<string, mixed>     $data
     */
    private function setOrRemove($comp, string $prop, array $data, string $key): void
    {
        if (!array_key_exists($key, $data)) {
            return;
        }
        $comp->remove($prop);
        $v = is_array($data[$key]) ? $data[$key] : trim((string) $data[$key]);
        if ($v !== '' && $v !== []) {
            $comp->add($prop, $v);
        }
    }

    /**
     * List from an array or a comma-separated string.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeList($value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', $list), fn ($v) => $v !== ''));
    }
}
