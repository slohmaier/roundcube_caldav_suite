<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\DAV\Client;
use Sabre\VObject;

class CalDAVClient
{
    private Client $client;
    private string $baseUrl;
    private string $username;
    private string $password;

    /** @var array<string, Collection> */
    private array $collections = [];

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;

        $this->client = new Client([
            'baseUri' => $this->baseUrl,
            'userName' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Discover all calendars and task lists from the CalDAV server.
     *
     * @return Collection[]
     */
    public function discover(): array
    {
        $this->collections = [];

        // Step 1: Try direct PROPFIND on baseUrl (works for Radicale)
        $calendars = $this->propfindCollections($this->baseUrl);

        if (!empty($calendars)) {
            $this->collections = $calendars;
            return array_values($this->collections);
        }

        // Step 2: Full discovery via current-user-principal
        $principalUrl = $this->findCurrentUserPrincipal($this->baseUrl);
        if ($principalUrl) {
            $calendarHomeUrl = $this->findCalendarHome($principalUrl);
            if ($calendarHomeUrl) {
                $this->collections = $this->propfindCollections($calendarHomeUrl);
            }
        }

        return array_values($this->collections);
    }

    /**
     * Get all calendars (VEVENT collections).
     *
     * @return Collection[]
     */
    public function getCalendars(): array
    {
        if (empty($this->collections)) {
            $this->discover();
        }
        return array_values(array_filter(
            $this->collections,
            fn(Collection $c) => $c->supportsEvents()
        ));
    }

    /**
     * Get all task lists (VTODO collections).
     *
     * @return Collection[]
     */
    public function getTaskLists(): array
    {
        if (empty($this->collections)) {
            $this->discover();
        }
        return array_values(array_filter(
            $this->collections,
            fn(Collection $c) => $c->supportsTodos()
        ));
    }

    /**
     * Get events from a calendar within a time range.
     *
     * @return CalendarObject[]
     */
    public function getEvents(string $calendarUrl, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="VEVENT">
        <C:time-range start="' . $start->format('Ymd\THis\Z') . '"
                      end="' . $end->format('Ymd\THis\Z') . '"/>
      </C:comp-filter>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>';

        return $this->queryEvents($calendarUrl, $body, $start, $end);
    }

    /**
     * Query and expand recurring events into individual instances.
     * @return CalendarObject[]
     */
    private function queryEvents(string $url, string $body, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $response = $this->client->request('REPORT', $url, $body, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Depth' => '1',
        ]);

        if ($response['statusCode'] !== 207) {
            return [];
        }

        $objects = [];
        $parsed = $this->client->parseMultiStatus($response['body']);

        foreach ($parsed as $href => $propSet) {
            $calData = $propSet[200]['{urn:ietf:params:xml:ns:caldav}calendar-data'] ?? null;
            $etag = $propSet[200]['{DAV:}getetag'] ?? null;

            if (!$calData) {
                continue;
            }

            try {
                $vcalendar = VObject\Reader::read($calData);
                $fullUrl = $this->resolveUrl($url, $href);
                $etagClean = is_string($etag) ? trim($etag, '"') : null;

                $vevent = $vcalendar->VEVENT ?? null;
                if (!$vevent) continue;

                if (isset($vevent->RRULE)) {
                    $uid = (string)($vevent->UID ?? '');
                    if (!$uid) continue;

                    $it = new VObject\Recur\EventIterator($vcalendar, $uid);
                    $it->fastForward($start);
                    $limit = 200;
                    while ($it->valid() && $it->getDTStart() < $end && $limit-- > 0) {
                        $instance = $it->getEventObject();
                        $objects[] = new CalendarObject(
                            url: $fullUrl,
                            etag: $etagClean,
                            component: $instance,
                            vcalendar: $vcalendar,
                            rawData: $calData,
                        );
                        $it->next();
                    }
                } else {
                    $objects[] = new CalendarObject(
                        url: $fullUrl,
                        etag: $etagClean,
                        component: $vevent,
                        vcalendar: $vcalendar,
                        rawData: $calData,
                    );
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $objects;
    }

    /**
     * Get all tasks from a task list.
     *
     * @return CalendarObject[]
     */
    public function getTasks(string $taskListUrl, bool $includeCompleted = false): array
    {
        $filter = $includeCompleted
            ? '<C:comp-filter name="VTODO"/>'
            : '<C:comp-filter name="VTODO">
                <C:prop-filter name="COMPLETED">
                  <C:is-not-defined/>
                </C:prop-filter>
                <C:prop-filter name="STATUS">
                  <C:text-match negate-condition="yes">COMPLETED</C:text-match>
                </C:prop-filter>
              </C:comp-filter>';

        $body = '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop>
    <D:getetag/>
    <C:calendar-data/>
  </D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      ' . $filter . '
    </C:comp-filter>
  </C:filter>
</C:calendar-query>';

        return $this->queryObjects($taskListUrl, $body, 'VTODO');
    }

    /**
     * Create or update a calendar object (event or task).
     */
    public function putObject(string $url, string $icalData, ?string $etag = null): bool
    {
        $headers = ['Content-Type' => 'text/calendar; charset=utf-8'];
        if ($etag) {
            $headers['If-Match'] = '"' . trim($etag, '"') . '"';  // ETag gequotet (Radicale verlangt Quotes)
        } else {
            $headers['If-None-Match'] = '*';
        }

        $response = $this->client->request('PUT', $url, $icalData, $headers);
        return in_array($response['statusCode'], [201, 204]);
    }

    /**
     * Fetch and parse a single calendar object (.ics).
     */
    public function getObject(string $url): ?\Sabre\VObject\Component\VCalendar
    {
        $response = $this->client->request('GET', $url);
        if (!in_array($response['statusCode'], [200, 207]) || empty($response['body'])) {
            return null;
        }
        $vobj = \Sabre\VObject\Reader::read($response['body']);
        return $vobj instanceof \Sabre\VObject\Component\VCalendar ? $vobj : null;
    }

    /**
     * Delete a calendar object.
     */
    public function deleteObject(string $url, ?string $etag = null): bool
    {
        $headers = [];
        if ($etag) {
            $headers['If-Match'] = '"' . trim($etag, '"') . '"';  // ETag gequotet (Radicale verlangt Quotes)
        }

        $response = $this->client->request('DELETE', $url, null, $headers);
        return in_array($response['statusCode'], [200, 204]);
    }

    /**
     * Create a new calendar or task list collection.
     */
    public function createCollection(string $url, string $displayName, string $type = 'VEVENT'): bool
    {
        $component = $type === 'VTODO' ? 'VTODO' : 'VEVENT';
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:set>
    <D:prop>
      <D:displayname>' . htmlspecialchars($displayName) . '</D:displayname>
      <C:supported-calendar-component-set>
        <C:comp name="' . $component . '"/>
      </C:supported-calendar-component-set>
    </D:prop>
  </D:set>
</C:mkcalendar>';

        $response = $this->client->request('MKCALENDAR', $url, $body, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
        return $response['statusCode'] === 201;
    }

    // --- Private helpers ---

    private function findCurrentUserPrincipal(string $url): ?string
    {
        $response = $this->client->propFind($url, [
            '{DAV:}current-user-principal',
        ], 0);

        $principal = $response['{DAV:}current-user-principal'] ?? null;
        if (is_array($principal) && isset($principal[0]['value'])) {
            return $this->resolveUrl($url, $principal[0]['value']);
        }
        if (is_string($principal)) {
            return $this->resolveUrl($url, $principal);
        }

        return null;
    }

    private function findCalendarHome(string $principalUrl): ?string
    {
        $response = $this->client->propFind($principalUrl, [
            '{urn:ietf:params:xml:ns:caldav}calendar-home-set',
        ], 0);

        $home = $response['{urn:ietf:params:xml:ns:caldav}calendar-home-set'] ?? null;
        if (is_array($home) && isset($home[0]['value'])) {
            return $this->resolveUrl($principalUrl, $home[0]['value']);
        }
        if (is_string($home)) {
            return $this->resolveUrl($principalUrl, $home);
        }

        return null;
    }

    /**
     * @return array<string, Collection>
     */
    private function propfindCollections(string $url): array
    {
        $props = [
            '{DAV:}resourcetype',
            '{DAV:}displayname',
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set',
            '{http://apple.com/ns/ical/}calendar-color',
            '{DAV:}current-user-privilege-set',
        ];

        try {
            $responses = $this->client->propFind($url, $props, 1);
        } catch (\Exception $e) {
            return [];
        }

        $collections = [];
        foreach ($responses as $href => $propSet) {
            if ($href === $url || $href === $url . '/') {
                continue; // Skip the parent
            }

            $resourceType = $propSet['{DAV:}resourcetype'] ?? null;
            if (!$resourceType) {
                continue;
            }

            $isCalendar = false;
            if ($resourceType instanceof \Sabre\DAV\Xml\Property\ResourceType) {
                $isCalendar = $resourceType->is('{urn:ietf:params:xml:ns:caldav}calendar');
            } elseif (is_array($resourceType)) {
                foreach ($resourceType as $type) {
                    if (is_array($type) && ($type['{urn:ietf:params:xml:ns:caldav}calendar'] ?? false)) {
                        $isCalendar = true;
                    }
                }
            }

            if (!$isCalendar) {
                continue;
            }

            $displayName = $propSet['{DAV:}displayname'] ?? basename(rtrim($href, '/'));
            $color = $propSet['{http://apple.com/ns/ical/}calendar-color'] ?? null;
            $components = $this->parseComponentSet(
                $propSet['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] ?? null
            );

            $fullUrl = $this->resolveUrl($url, $href);

            // Wenn die Collection BEIDE Typen unterstuetzt (explizit oder via
            // Default, weil der Server kein supported-calendar-component-set
            // deklariert — z.B. Apple-Reminders/Kalender auf Radicale), per
            // Inhalt verfeinern: VTODO-Listen sollen nicht als Kalender und
            // Kalender nicht als Aufgabenliste erscheinen.
            if (in_array('VEVENT', $components, true) && in_array('VTODO', $components, true)) {
                $detected = [];
                if ($this->collectionHasComponent($fullUrl, 'VEVENT')) {
                    $detected[] = 'VEVENT';
                }
                if ($this->collectionHasComponent($fullUrl, 'VTODO')) {
                    $detected[] = 'VTODO';
                }
                // Nur bei eindeutigem Inhalt verengen; leere oder echt
                // gemischte Collections behalten die Deklaration (beides).
                if (count($detected) === 1) {
                    $components = $detected;
                }
            }

            $collections[$fullUrl] = new Collection(
                url: $fullUrl,
                displayName: is_string($displayName) ? $displayName : basename(rtrim($href, '/')),
                components: $components,
                color: is_string($color) ? $color : null,
            );
        }

        return $collections;
    }

    /**
     * @return string[]
     */
    /**
     * Pruefen ob eine Collection mindestens ein Objekt eines bestimmten
     * Komponententyps (VEVENT/VTODO) enthaelt. Leichtgewichtiger calendar-query
     * REPORT (nur getetag, keine calendar-data).
     */
    private function collectionHasComponent(string $url, string $comp): bool
    {
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
  <D:prop><D:getetag/></D:prop>
  <C:filter>
    <C:comp-filter name="VCALENDAR">
      <C:comp-filter name="' . $comp . '"/>
    </C:comp-filter>
  </C:filter>
</C:calendar-query>';

        try {
            $response = $this->client->request('REPORT', $url, $body, [
                'Depth'        => '1',
                'Content-Type' => 'application/xml; charset=utf-8',
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        if (($response['statusCode'] ?? 0) !== 207) {
            return false;
        }

        // Mindestens eine <response> mit einem .ics-Objekt?
        return (bool) preg_match('#<[^>]*:?response[^>]*>.*?\.ics#is', $response['body'] ?? '');
    }

    private function parseComponentSet(mixed $componentSet): array
    {
        if ($componentSet === null) {
            return ['VEVENT', 'VTODO'];
        }

        if ($componentSet instanceof \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet) {
            return $componentSet->getValue();
        }

        if (is_array($componentSet)) {
            $components = [];
            foreach ($componentSet as $comp) {
                if (is_array($comp)) {
                    // sabre/dav returns: [{name: '{...}comp', attributes: {name: 'VEVENT'}}]
                    if (isset($comp['attributes']['name'])) {
                        $components[] = $comp['attributes']['name'];
                    } elseif (isset($comp['name']) && !str_contains($comp['name'], '{')) {
                        $components[] = $comp['name'];
                    }
                } elseif (is_string($comp)) {
                    $components[] = $comp;
                }
            }
            return $components ?: ['VEVENT', 'VTODO'];
        }

        return ['VEVENT', 'VTODO'];
    }

    /**
     * @return CalendarObject[]
     */
    private function queryObjects(string $url, string $body, string $componentType): array
    {
        $response = $this->client->request('REPORT', $url, $body, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Depth' => '1',
        ]);

        if ($response['statusCode'] !== 207) {
            return [];
        }

        $objects = [];
        $parsed = $this->client->parseMultiStatus($response['body']);

        foreach ($parsed as $href => $propSet) {
            $calData = $propSet[200]['{urn:ietf:params:xml:ns:caldav}calendar-data'] ?? null;
            $etag = $propSet[200]['{DAV:}getetag'] ?? null;

            if (!$calData) {
                continue;
            }

            try {
                $vcalendar = VObject\Reader::read($calData);
                $component = $vcalendar->{$componentType} ?? null;
                if (!$component) {
                    continue;
                }

                $fullUrl = $this->resolveUrl($url, $href);
                $objects[] = new CalendarObject(
                    url: $fullUrl,
                    etag: is_string($etag) ? trim($etag, '"') : null,
                    component: $component,
                    vcalendar: $vcalendar,
                    rawData: $calData,
                );
            } catch (\Exception $e) {
                // Skip unparseable objects
                continue;
            }
        }

        return $objects;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        if (str_starts_with($relative, '/')) {
            return "{$scheme}://{$host}{$port}{$relative}";
        }

        $basePath = rtrim($parsed['path'] ?? '/', '/');
        return "{$scheme}://{$host}{$port}{$basePath}/{$relative}";
    }
}
