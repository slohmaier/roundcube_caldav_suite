<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class CalendarBackend
{
    /**
     * Neues Event von Grund auf bauen (Create).
     */
    public function buildICalEvent(array $data): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//CalDAV Suite//EN';

        $vevent = $vcalendar->add('VEVENT', [
            'UID' => $data['uid'] ?? \Sabre\VObject\UUIDUtil::getUUID(),
        ]);
        if (!array_key_exists('title', $data)) {
            $vevent->SUMMARY = '';
        }

        $this->applyEvent($vevent, $data);

        return $vcalendar->serialize();
    }

    /**
     * Bestehendes Event aktualisieren OHNE nicht behandelte Felder zu verlieren
     * (RRULE, ATTENDEE, ORGANIZER, SEQUENCE, EXDATE, eigene X-Props bleiben erhalten).
     * Nur die in $data enthaltenen Felder werden angefasst.
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
     * Roundcube/JS-Daten auf eine VEVENT-Komponente anwenden.
     * Pflichtfelder (Titel/Start/Ende) werden gesetzt wenn vorhanden; optionale
     * Felder NUR wenn ihr Key in $data vorkommt (leerer Wert = entfernen). So gehen
     * beim Edit Felder, die das Formular nicht kennt, nicht verloren.
     */
    private function applyEvent($vevent, array $data): void
    {
        if (array_key_exists('uid', $data) && !empty($data['uid'])) {
            $vevent->UID = $data['uid'];
        }
        if (array_key_exists('title', $data)) {
            $vevent->SUMMARY = (string) $data['title'];
        }

        // Start / Ende (+ Ganztags + Zeitzone)
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

        // Recurrence (RRULE) — als String, z.B. "FREQ=WEEKLY;BYDAY=MO,WE"
        if (array_key_exists('rrule', $data)) {
            $vevent->remove('RRULE');
            if (!empty($data['rrule'])) {
                $vevent->add('RRULE', $data['rrule']);
            }
        }

        // Kategorien
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

        // Strukturierte Geo-Location (Apple Maps Fahrtzeit)
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

        // Erinnerung (VALARM)
        if (array_key_exists('reminder_minutes', $data)) {
            $vevent->remove('VALARM');
            if (!empty($data['reminder_minutes'])) {
                $alarm = $vevent->add('VALARM');
                $alarm->add('ACTION', 'DISPLAY');
                $alarm->add('DESCRIPTION', $data['title'] ?? (string) ($vevent->SUMMARY ?? 'Erinnerung'));
                $alarm->add('TRIGGER', '-PT' . (int) $data['reminder_minutes'] . 'M');
            }
        }

        $vevent->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

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

    /** Liste aus Array oder kommaseparierter Zeichenkette. */
    private function normalizeList($value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', $list), fn($v) => $v !== ''));
    }
}
