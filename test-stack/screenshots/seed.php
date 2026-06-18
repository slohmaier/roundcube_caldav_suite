<?php
/**
 * Befuellt Radicale mit einer prall gefuellten Demo-Woche:
 * mehrere farbige Kalender, Ganztagstermine, Doppelbelegungen, Serientermine
 * und Termine mit Apple-Fahrtzeit. Laeuft IM Roundcube-Container
 * (docker compose exec -T rc-test-roundcube php < seed.php), nutzt das gemountete
 * Plugin (vendor/ + CalendarBackend).
 */

require '/var/www/html/plugins/caldav_suite/vendor/autoload.php';

use Sabre\DAV\Client;
use Slohmaier\CalDAVSuite\CalendarBackend;

$base = 'http://rc-test-radicale:5232/test';
$client = new Client(['baseUri' => $base, 'userName' => 'test', 'password' => 'test']);
$cb = new CalendarBackend();

// --- Kalender (Pfad, Name, Apple-Farbe) ---
$calendars = [
    ['arbeit',  'Arbeit',  '#e74c3c'],
    ['privat',  'Privat',  '#3498db'],
    ['familie', 'Familie', '#27ae60'],
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
    } catch (\Throwable $e) { /* existiert schon -> egal */ }
}

// --- Hilfsfunktionen, alles relativ zum Montag DIESER Woche ---
$monday = new DateTimeImmutable('monday this week');
$day = fn(int $offset, string $time = '') => trim($monday->modify("+$offset days")->format('Y-m-d') . ' ' . $time);

$count = 0;
$put = function (string $cal, array $args) use ($client, $cb, $base, &$count) {
    $ical = $cb->buildICalEvent($args);
    $url = "$base/$cal/demo-" . bin2hex(random_bytes(6)) . '.ics';
    $client->request('PUT', $url, $ical, ['Content-Type' => 'text/calendar; charset=utf-8']);
    $count++;
};

// ===== Montag — Doppelbelegung + Fahrtzeit =====
$put('arbeit', ['title' => 'Daily Standup', 'start' => $day(0, '09:00'), 'end' => $day(0, '09:15'),
    'rrule' => 'FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR', 'categories' => ['Team']]);
$put('privat', ['title' => 'Augenarzt', 'start' => $day(0, '09:00'), 'end' => $day(0, '10:30'),
    'location' => 'LMU Augenklinik, München', 'location_geo' => '48.1100,11.4660',
    'travel_mode' => 'auto', 'reminder_minutes' => 30]); // Doppelbelegung mit Standup + Fahrtzeit
$put('arbeit', ['title' => 'Projekt-Review', 'start' => $day(0, '14:00'), 'end' => $day(0, '15:30'),
    'location' => 'Raum 3.14']);
$put('familie', ['title' => 'Kinder abholen', 'start' => $day(0, '16:00'), 'end' => $day(0, '16:30'),
    'travel_mode' => '15', 'location' => 'Kita Sonnenschein']);

// ===== Dienstag — Ganztags (Messe) über mehrere Tage + Termine =====
$put('arbeit', ['title' => 'Messe Berlin', 'start' => $day(1), 'end' => $day(3), 'allday' => true,
    'categories' => ['Reise']]); // Di–Do, erscheint im Ganztags-Band
$put('privat', ['title' => 'Zahnarzt', 'start' => $day(1, '11:00'), 'end' => $day(1, '12:00'),
    'location' => 'Dr. Müller, Schwabing', 'travel_mode' => '30', 'location_geo' => '48.1620,11.5810']);
$put('sport', ['title' => 'Lauftraining', 'start' => $day(1, '18:30'), 'end' => $day(1, '19:30'),
    'rrule' => 'FREQ=WEEKLY;BYDAY=TU,TH']);

// ===== Mittwoch — Dreifach-Überlappung =====
$put('arbeit', ['title' => 'Sprint Planning', 'start' => $day(2, '10:00'), 'end' => $day(2, '12:00')]);
$put('familie', ['title' => 'Handwerker Termin', 'start' => $day(2, '10:30'), 'end' => $day(2, '11:30')]);
$put('privat', ['title' => 'Friseur', 'start' => $day(2, '11:00'), 'end' => $day(2, '12:00')]);
$put('arbeit', ['title' => '1:1 mit Chef', 'start' => $day(2, '15:00'), 'end' => $day(2, '15:30')]);

// ===== Donnerstag — Calls + Überlappung =====
$put('arbeit', ['title' => 'Kundencall ACME', 'start' => $day(3, '09:30'), 'end' => $day(3, '10:30'),
    'location' => 'Online']);
$put('familie', ['title' => 'Elterngespräch Schule', 'start' => $day(3, '10:00'), 'end' => $day(3, '11:00'),
    'travel_mode' => '20', 'location' => 'Gymnasium']);
$put('privat', ['title' => 'Mittagessen mit Anna', 'start' => $day(3, '12:30'), 'end' => $day(3, '14:00'),
    'location' => 'Café Glück']);

// ===== Freitag — Ganztags + Abendtermin =====
$put('familie', ['title' => 'Geburtstag Oma', 'start' => $day(4), 'end' => $day(4), 'allday' => true]);
$put('arbeit', ['title' => 'Retrospektive', 'start' => $day(4, '13:00'), 'end' => $day(4, '14:00')]);
$put('privat', ['title' => 'Feierabendbier', 'start' => $day(4, '17:30'), 'end' => $day(4, '19:30'),
    'location' => 'Augustiner', 'travel_mode' => 'auto', 'location_geo' => '48.1390,11.5660']);

// ===== Wochenende =====
$put('sport', ['title' => 'Fußballspiel', 'start' => $day(5, '10:00'), 'end' => $day(5, '12:00'),
    'location' => 'Sportplatz Ost', 'travel_mode' => 'auto', 'location_geo' => '48.1700,11.6200',
    'reminder_minutes' => 60]);
$put('familie', ['title' => 'Brunch bei den Eltern', 'start' => $day(6, '11:00'), 'end' => $day(6, '14:00'),
    'travel_mode' => '45', 'location' => 'Augsburg']);
$put('privat', ['title' => 'Kino', 'start' => $day(6, '20:00'), 'end' => $day(6, '22:30')]);

echo "Seed fertig: $count Termine in " . count($calendars) . " Kalendern (Woche ab " . $monday->format('d.m.Y') . ").\n";
