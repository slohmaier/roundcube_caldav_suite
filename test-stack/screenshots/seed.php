<?php
/**
 * Fills Radicale with a rich demo week:
 * several colored calendars, all-day events, double bookings, recurring events
 * and events with Apple travel time. Runs INSIDE the Roundcube container
 * (docker compose exec -T rc-test-roundcube php < seed.php), uses the mounted
 * plugin (vendor/ + CalendarBackend).
 */

require '/var/www/html/plugins/caldav_suite/vendor/autoload.php';

use Sabre\DAV\Client;
use Slohmaier\CalDAVSuite\CalendarBackend;
use Slohmaier\CalDAVSuite\TaskBackend;

$base = 'http://rc-test-radicale:5232/test';
$client = new Client(['baseUri' => $base, 'userName' => 'test', 'password' => 'test']);
$cb = new CalendarBackend();

// --- Calendars (path, name, Apple color) ---
$calendars = [
    ['work',    'Work',    '#e74c3c'],
    ['private', 'Private', '#3498db'],
    ['family',  'Family',  '#27ae60'],
    ['sport',   'Sport',   '#f39c12'],
];
foreach ($calendars as [$path, $name, $color]) {
    $body = '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav" xmlns:A="http://apple.com/ns/ical/">'
        . '<D:set><D:prop>'
        . '<D:displayname>' . htmlspecialchars($name) . '</D:displayname>'
        . '<A:calendar-color>' . $color . '</A:calendar-color>'
        . '<C:supported-calendar-component-set><C:comp name="VEVENT"/></C:supported-calendar-component-set>'
        . '</D:prop></D:set></C:mkcalendar>';
    try {
        $client->request('MKCALENDAR', "$base/$path/", $body, ['Content-Type' => 'application/xml']);
    } catch (\Throwable $e) { /* already exists -> ignore */ }
}

// Task list (VTODO) and address book (CardDAV) must exist for the demo data below.
try {
    $client->request('MKCALENDAR', "$base/tasks/",
        '<?xml version="1.0" encoding="utf-8"?>'
        . '<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
        . '<D:set><D:prop><D:displayname>Tasks</D:displayname>'
        . '<C:supported-calendar-component-set><C:comp name="VTODO"/></C:supported-calendar-component-set>'
        . '</D:prop></D:set></C:mkcalendar>',
        ['Content-Type' => 'application/xml']);
} catch (\Throwable $e) { /* already exists */ }

try {
    $client->request('MKCOL', "$base/contacts/",
        '<?xml version="1.0"?><create xmlns="DAV:" xmlns:CR="urn:ietf:params:xml:ns:carddav">'
        . '<set><prop><resourcetype><collection/><CR:addressbook/></resourcetype>'
        . '<displayname>Contacts</displayname></prop></set></create>',
        ['Content-Type' => 'application/xml']);
} catch (\Throwable $e) { /* already exists */ }

// --- Helpers, everything relative to THIS week's Monday ---
$monday = new DateTimeImmutable('monday this week');
$day = fn(int $offset, string $time = '') => trim($monday->modify("+$offset days")->format('Y-m-d') . ' ' . $time);

$count = 0;
$put = function (string $cal, array $args) use ($client, $cb, $base, &$count) {
    $ical = $cb->buildICalEvent($args);
    $url = "$base/$cal/demo-" . bin2hex(random_bytes(6)) . '.ics';
    $client->request('PUT', $url, $ical, ['Content-Type' => 'text/calendar; charset=utf-8']);
    $count++;
};

// ===== Monday — double booking + travel time =====
$put('work', ['title' => 'Daily Standup', 'start' => $day(0, '09:00'), 'end' => $day(0, '09:15'),
    'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'categories' => ['Team']]);
$put('private', ['title' => 'Eye doctor', 'start' => $day(0, '09:00'), 'end' => $day(0, '10:30'),
    'location' => 'City Eye Clinic', 'location_geo' => '48.1100,11.4660',
    'travel_mode' => 'auto', 'reminder_minutes' => 30]); // double booking with standup + travel time
$put('work', ['title' => 'Project review', 'start' => $day(0, '14:00'), 'end' => $day(0, '15:30'),
    'location' => 'Room 3.14']);
$put('family', ['title' => 'Pick up the kids', 'start' => $day(0, '16:00'), 'end' => $day(0, '16:30'),
    'travel_mode' => '15', 'location' => 'Sunny Kindergarten']);

// ===== Tuesday — multi-day all-day event (trade fair) + events =====
$put('work', ['title' => 'Trade fair Berlin', 'start' => $day(1), 'end' => $day(3), 'allday' => true,
    'categories' => ['Travel']]); // Tue–Thu, appears in the all-day band
$put('private', ['title' => 'Dentist', 'start' => $day(1, '11:00'), 'end' => $day(1, '12:00'),
    'location' => 'Dr. Miller, Downtown', 'travel_mode' => '30', 'location_geo' => '48.1620,11.5810']);
$put('sport', ['title' => 'Running training', 'start' => $day(1, '18:30'), 'end' => $day(1, '19:30'),
    'rrule' => 'FREQ=WEEKLY;BYDAY=TU,TH']);

// ===== Wednesday — triple overlap =====
$put('work', ['title' => 'Sprint planning', 'start' => $day(2, '10:00'), 'end' => $day(2, '12:00')]);
$put('family', ['title' => 'Handyman appointment', 'start' => $day(2, '10:30'), 'end' => $day(2, '11:30')]);
$put('private', ['title' => 'Haircut', 'start' => $day(2, '11:00'), 'end' => $day(2, '12:00')]);
$put('work', ['title' => '1:1 with boss', 'start' => $day(2, '15:00'), 'end' => $day(2, '15:30')]);

// ===== Thursday — calls + overlap =====
$put('work', ['title' => 'Customer call ACME', 'start' => $day(3, '09:30'), 'end' => $day(3, '10:30'),
    'location' => 'Online']);
$put('family', ['title' => 'Parent-teacher meeting', 'start' => $day(3, '10:00'), 'end' => $day(3, '11:00'),
    'travel_mode' => '20', 'location' => 'High School']);
$put('private', ['title' => 'Lunch with Anna', 'start' => $day(3, '12:30'), 'end' => $day(3, '14:00'),
    'location' => 'Cafe Central']);

// ===== Friday — all-day + evening event =====
$put('family', ['title' => "Grandma's birthday", 'start' => $day(4), 'end' => $day(4), 'allday' => true]);
$put('work', ['title' => 'Retrospective', 'start' => $day(4, '13:00'), 'end' => $day(4, '14:00')]);
$put('private', ['title' => 'After-work drinks', 'start' => $day(4, '17:30'), 'end' => $day(4, '19:30'),
    'location' => 'The Old Brewery', 'travel_mode' => 'auto', 'location_geo' => '48.1390,11.5660']);

// ===== Weekend =====
$put('sport', ['title' => 'Soccer match', 'start' => $day(5, '10:00'), 'end' => $day(5, '12:00'),
    'location' => 'East Sports Field', 'travel_mode' => 'auto', 'location_geo' => '48.1700,11.6200',
    'reminder_minutes' => 60]);
$put('family', ['title' => 'Brunch with parents', 'start' => $day(6, '11:00'), 'end' => $day(6, '14:00'),
    'travel_mode' => '45', 'location' => 'Springfield']);
$put('private', ['title' => 'Cinema', 'start' => $day(6, '20:00'), 'end' => $day(6, '22:30')]);

// ===== Tasks (VTODO) in the task list =====
$tb = new TaskBackend();
$putTask = function (array $args) use ($client, $tb, $base) {
    $ical = $tb->buildICalTodo($args);
    $url = "$base/tasks/demo-" . bin2hex(random_bytes(6)) . '.ics';
    $client->request('PUT', $url, $ical, ['Content-Type' => 'text/calendar; charset=utf-8']);
};

// Due date relative to Monday (due => YYYY-MM-DD)
$due = fn(int $offset) => $monday->modify("+$offset days")->format('Y-m-d');
$putTask(['title' => 'Submit tax return', 'due' => $due(1), 'priority' => 'high', 'description' => 'Collect the documents and hand them in.']);
$putTask(['title' => 'Plan the week', 'due' => $due(2), 'priority' => 'medium']);
$putTask(['title' => 'Buy a gift for grandma', 'due' => $due(4), 'priority' => 'medium']);
$putTask(['title' => 'Make a dentist appointment', 'due' => $due(-1), 'priority' => 'high', 'description' => 'Check-up + professional cleaning.']);
$putTask(['title' => 'Write a shopping list', 'due' => $due(3), 'priority' => 'low', 'location' => 'Supermarket']);

// ===== Contacts (vCard) in the address book =====
$contacts = [
    ['Anna', 'Becker', 'anna.becker@example.com', 'Example GmbH'],
    ['Max', 'Sample', 'max.sample@example.com', ''],
    ['Julia', 'Schmidt', 'julia.schmidt@example.org', 'Agency XY'],
    ['Thomas', 'Miller', 't.miller@example.net', ''],
];
foreach ($contacts as [$first, $last, $email, $org]) {
    $uid = \Sabre\VObject\UUIDUtil::getUUID();
    $fn = "$first $last";
    $vcard = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:$uid\r\nFN:$fn\r\nN:$last;$first;;;\r\nEMAIL:$email\r\n";
    if ($org) $vcard .= "ORG:$org\r\n";
    $vcard .= "END:VCARD\r\n";
    $client->request('PUT', "$base/contacts/$uid.vcf", $vcard, ['Content-Type' => 'text/vcard; charset=utf-8']);
}

echo "Seed done: $count events in " . count($calendars) . " calendars (week from " . $monday->format('d.m.Y') . "), tasks and contacts added.\n";
