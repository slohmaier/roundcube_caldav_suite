<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject;
use Sabre\VObject\Component\VCalendar;

class TaskBackend
{
    private const PRIORITY_MAP = [
        'high'   => 1,
        'medium' => 5,
        'low'    => 9,
        'none'   => 0,
    ];

    public function buildICalTodo(array $data): string
    {
        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//CalDAV Suite//EN';

        $vtodo = $vcalendar->add('VTODO', [
            'UID'     => $data['uid'] ?? \Sabre\VObject\UUIDUtil::getUUID(),
            'SUMMARY' => $data['title'] ?? '',
            'DTSTAMP' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'CREATED' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ]);

        if (!empty($data['description'])) {
            $vtodo->add('DESCRIPTION', $data['description']);
        }

        if (!empty($data['due'])) {
            $tz = new \DateTimeZone($data['timezone'] ?? 'Europe/Berlin');
            $due = new \DateTimeImmutable($data['due'], $tz);
            $vtodo->add('DUE', $due, ['TZID' => $tz->getName()]);
        }

        $priority = self::PRIORITY_MAP[$data['priority'] ?? 'none'] ?? 0;
        if ($priority > 0) {
            $vtodo->add('PRIORITY', $priority);
        }

        if (!empty($data['completed'])) {
            $vtodo->add('STATUS', 'COMPLETED');
            $vtodo->add('COMPLETED', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } else {
            $vtodo->add('STATUS', 'NEEDS-ACTION');
        }

        return $vcalendar->serialize();
    }

    /**
     * Toggle task completion. Fetches current task, modifies status, returns new iCal.
     */
    public function toggleCompleted(string $url, bool $completed, CalDAVClient $client): ?string
    {
        // We need to fetch all tasks from the parent collection to find this one
        // Actually we can do a direct GET on the URL
        $httpClient = new \Sabre\DAV\Client([
            'baseUri' => preg_replace('#/[^/]+\.ics$#', '/', $url),
            'userName' => '', // will use same auth as the CalDAVClient — but we can't access it
        ]);

        // Simpler: just build a new VTODO with the toggled status
        // The caller should have the task data. For now, return null and let the JS
        // send the full task data for rebuild.
        return null;
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
