<?php

namespace Slohmaier\CalDAVSuite\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Slohmaier\CalDAVSuite\CalDAVClient;

class DiscoveryTest extends TestCase
{
    private static string $baseUrl;
    private static string $user = 'testuser';
    private static string $pass = 'testpass';
    private static CalDAVClient $client;
    private static bool $serverAvailable = false;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = getenv('CALDAV_TEST_URL') ?: 'http://localhost:15232';
        self::$client = new CalDAVClient(
            self::$baseUrl . '/' . self::$user . '/',
            self::$user,
            self::$pass
        );

        // Check if server is available
        try {
            $ch = curl_init(self::$baseUrl);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            self::$serverAvailable = ($code > 0);
        } catch (\Exception $e) {
            self::$serverAvailable = false;
        }

        if (!self::$serverAvailable) {
            return;
        }

        // Create test calendars
        $ch = curl_init(self::$baseUrl . '/' . self::$user . '/test-calendar/');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'MKCALENDAR',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => self::$user . ':' . self::$pass,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_POSTFIELDS => '<?xml version="1.0"?><C:mkcalendar xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:"><D:set><D:prop><D:displayname>Test Calendar</D:displayname></D:prop></D:set></C:mkcalendar>',
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Create test task list
        $ch = curl_init(self::$baseUrl . '/' . self::$user . '/test-tasks/');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'MKCALENDAR',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => self::$user . ':' . self::$pass,
            CURLOPT_HTTPHEADER => ['Content-Type: application/xml'],
            CURLOPT_POSTFIELDS => '<?xml version="1.0"?><C:mkcalendar xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:D="DAV:"><D:set><D:prop><D:displayname>Test Tasks</D:displayname><C:supported-calendar-component-set><C:comp name="VTODO"/></C:supported-calendar-component-set></D:prop></D:set></C:mkcalendar>',
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Add a test event
        $event = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:integration-test-1\r\nSUMMARY:Integration Test Event\r\nDTSTART:20260723T090000Z\r\nDTEND:20260723T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR";
        $ch = curl_init(self::$baseUrl . '/' . self::$user . '/test-calendar/integration-test-1.ics');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => self::$user . ':' . self::$pass,
            CURLOPT_HTTPHEADER => ['Content-Type: text/calendar'],
            CURLOPT_POSTFIELDS => $event,
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Add a test todo
        $todo = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:integration-test-todo-1\r\nSUMMARY:Integration Test Task\r\nDUE:20260730T120000Z\r\nSTATUS:NEEDS-ACTION\r\nPRIORITY:1\r\nEND:VTODO\r\nEND:VCALENDAR";
        $ch = curl_init(self::$baseUrl . '/' . self::$user . '/test-tasks/integration-test-todo-1.ics');
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => self::$user . ':' . self::$pass,
            CURLOPT_HTTPHEADER => ['Content-Type: text/calendar'],
            CURLOPT_POSTFIELDS => $todo,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    protected function setUp(): void
    {
        if (!self::$serverAvailable) {
            $this->markTestSkipped('CalDAV test server not available. Start with: docker compose -f docker-compose.test.yml up -d');
        }
    }

    public function testDiscoverFindsCalendars(): void
    {
        $collections = self::$client->discover();
        $this->assertNotEmpty($collections, 'Should discover at least one collection');
    }

    public function testGetCalendarsReturnsOnlyEventCollections(): void
    {
        $calendars = self::$client->getCalendars();
        foreach ($calendars as $cal) {
            $this->assertTrue($cal->supportsEvents(), "Calendar '{$cal->displayName}' should support events");
        }
    }

    public function testGetTaskListsReturnsOnlyTodoCollections(): void
    {
        $taskLists = self::$client->getTaskLists();
        foreach ($taskLists as $list) {
            $this->assertTrue($list->supportsTodos(), "Task list '{$list->displayName}' should support todos");
        }
    }

    public function testGetEventsReturnsEvents(): void
    {
        $start = new \DateTimeImmutable('2026-01-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-12-31T23:59:59Z');

        $calendars = self::$client->getCalendars();
        $this->assertNotEmpty($calendars);

        $calUrl = $calendars[0]->url;
        $events = self::$client->getEvents($calUrl, $start, $end);
        $this->assertNotEmpty($events, 'Should find the test event');

        $found = false;
        foreach ($events as $event) {
            if ($event->getUid() === 'integration-test-1') {
                $found = true;
                $this->assertEquals('Integration Test Event', $event->getSummary());
            }
        }
        $this->assertTrue($found, 'Should find integration-test-1 event');
    }

    public function testGetTasksReturnsTasks(): void
    {
        $taskLists = self::$client->getTaskLists();
        $this->assertNotEmpty($taskLists);

        $tasks = self::$client->getTasks($taskLists[0]->url);
        $this->assertNotEmpty($tasks, 'Should find the test task');

        $found = false;
        foreach ($tasks as $task) {
            if ($task->getUid() === 'integration-test-todo-1') {
                $found = true;
                $this->assertEquals('Integration Test Task', $task->getSummary());
                $this->assertFalse($task->isCompleted());
                $this->assertEquals(1, $task->getPriority());
            }
        }
        $this->assertTrue($found, 'Should find integration-test-todo-1 task');
    }

    public function testPutAndDeleteEvent(): void
    {
        $calUrl = self::$client->getCalendars()[0]->url;
        $eventUrl = $calUrl . '/crud-test.ics';

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:crud-test\r\nSUMMARY:CRUD Test\r\nDTSTART:20260801T100000Z\r\nDTEND:20260801T110000Z\r\nEND:VEVENT\r\nEND:VCALENDAR";

        // Create
        $this->assertTrue(self::$client->putObject($eventUrl, $ics));

        // Verify exists
        $start = new \DateTimeImmutable('2026-08-01T00:00:00Z');
        $end = new \DateTimeImmutable('2026-08-01T23:59:59Z');
        $events = self::$client->getEvents($calUrl, $start, $end);
        $found = false;
        foreach ($events as $e) {
            if ($e->getUid() === 'crud-test') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Created event should be found');

        // Delete
        $this->assertTrue(self::$client->deleteObject($eventUrl));
    }
}
