<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class TaskBackend
{
    private const PRIORITY_MAP = [
        'high'   => 1,
        'medium' => 5,
        'low'    => 9,
        'none'   => 0,
    ];

    /**
     * Neue Aufgabe von Grund auf bauen (Create).
     */
    public function buildICalTodo(array $data): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//CalDAV Suite//EN';

        $vtodo = $vcalendar->add('VTODO', [
            'UID'     => $data['uid'] ?? \Sabre\VObject\UUIDUtil::getUUID(),
            'CREATED' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);
        if (!array_key_exists('title', $data)) {
            $vtodo->SUMMARY = '';
        }
        if (!array_key_exists('completed', $data)) {
            $vtodo->STATUS = 'NEEDS-ACTION';
        }

        $this->applyTodo($vtodo, $data);

        return $vcalendar->serialize();
    }

    /**
     * Bestehende Aufgabe aktualisieren OHNE nicht behandelte Felder zu verlieren
     * (RRULE, RELATED-TO, eigene X-Props bleiben erhalten). Nur in $data
     * enthaltene Felder werden angefasst.
     */
    public function updateICalTodo(string $existingICal, array $data): ?string
    {
        $vcalendar = Reader::read($existingICal);
        if (!isset($vcalendar->VTODO)) {
            return null;
        }
        $this->applyTodo($vcalendar->VTODO, $data);
        return $vcalendar->serialize();
    }

    private function applyTodo($vtodo, array $data): void
    {
        if (array_key_exists('uid', $data) && !empty($data['uid'])) {
            $vtodo->UID = $data['uid'];
        }
        if (array_key_exists('title', $data)) {
            $vtodo->SUMMARY = (string) $data['title'];
        }

        $this->setOrRemove($vtodo, 'DESCRIPTION', $data, 'description');
        $this->setOrRemove($vtodo, 'LOCATION', $data, 'location');
        $this->setOrRemove($vtodo, 'URL', $data, 'url');

        $tz = new \DateTimeZone($data['timezone'] ?? 'Europe/Berlin');

        if (array_key_exists('due', $data)) {
            $vtodo->remove('DUE');
            if (!empty($data['due'])) {
                $vtodo->add('DUE', new \DateTimeImmutable($data['due'], $tz), ['TZID' => $tz->getName()]);
            }
        }
        if (array_key_exists('start', $data)) {
            $vtodo->remove('DTSTART');
            if (!empty($data['start'])) {
                $vtodo->add('DTSTART', new \DateTimeImmutable($data['start'], $tz), ['TZID' => $tz->getName()]);
            }
        }

        if (array_key_exists('priority', $data)) {
            $vtodo->remove('PRIORITY');
            $p = is_numeric($data['priority'])
                ? (int) $data['priority']
                : (self::PRIORITY_MAP[$data['priority']] ?? 0);
            if ($p > 0) {
                $vtodo->add('PRIORITY', $p);
            }
        }

        if (array_key_exists('percent_complete', $data)) {
            $vtodo->remove('PERCENT-COMPLETE');
            $pc = (int) $data['percent_complete'];
            if ($pc > 0) {
                $vtodo->add('PERCENT-COMPLETE', min(100, $pc));
            }
        }

        if (array_key_exists('categories', $data)) {
            $vtodo->remove('CATEGORIES');
            $cats = $this->normalizeList($data['categories']);
            if ($cats) {
                $vtodo->add('CATEGORIES', $cats);
            }
        }

        if (array_key_exists('rrule', $data)) {
            $vtodo->remove('RRULE');
            if (!empty($data['rrule'])) {
                $vtodo->add('RRULE', $data['rrule']);
            }
        }

        if (array_key_exists('completed', $data)) {
            $vtodo->remove('STATUS');
            $vtodo->remove('COMPLETED');
            if (!empty($data['completed'])) {
                $vtodo->add('STATUS', 'COMPLETED');
                $vtodo->add('COMPLETED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            } else {
                $vtodo->add('STATUS', 'NEEDS-ACTION');
            }
        }

        $vtodo->DTSTAMP = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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

    private function normalizeList($value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_filter(array_map('trim', $list), fn($v) => $v !== ''));
    }

    /**
     * Toggle task completion. Holt die bestehende VTODO, flippt nur den Status
     * und erhaelt alle anderen Felder.
     */
    public function toggleCompleted(string $url, bool $completed, CalDAVClient $client): ?string
    {
        $vcal = $client->getObject($url);
        if (!$vcal || !isset($vcal->VTODO)) {
            return null;
        }

        $vtodo = $vcal->VTODO;
        $vtodo->remove('STATUS');
        $vtodo->remove('COMPLETED');
        $vtodo->remove('PERCENT-COMPLETE');

        if ($completed) {
            $vtodo->add('STATUS', 'COMPLETED');
            $vtodo->add('COMPLETED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $vtodo->add('PERCENT-COMPLETE', 100);
        } else {
            $vtodo->add('STATUS', 'NEEDS-ACTION');
        }

        return $vcal->serialize();
    }

    /**
     * Build a toggled version of a task from its existing data.
     */
    public function buildToggled(array $taskData, bool $completed): string
    {
        $taskData['completed'] = $completed;
        return $this->buildICalTodo($taskData);
    }

    /**
     * Sort tasks by given criteria.
     *
     * @param array $tasks Array of task arrays (from CalendarObject::toArray())
     * @return array Sorted tasks
     */
    public function sortTasks(array $tasks, string $sortBy = 'due'): array
    {
        usort($tasks, function ($a, $b) use ($sortBy) {
            switch ($sortBy) {
                case 'priority':
                    $pa = $a['priority'] ?? 0;
                    $pb = $b['priority'] ?? 0;
                    if ($pa === 0) $pa = 99;
                    if ($pb === 0) $pb = 99;
                    return $pa <=> $pb;

                case 'created':
                    return ($b['created'] ?? '') <=> ($a['created'] ?? '');

                case 'due':
                default:
                    $da = $a['due'] ?? '9999-12-31';
                    $db = $b['due'] ?? '9999-12-31';
                    return $da <=> $db;
            }
        });

        return $tasks;
    }
}
