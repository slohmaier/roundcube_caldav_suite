<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\DAV\Client;
use Sabre\VObject;

class CardDAVClient
{
    private Client $client;
    private string $baseUrl;

    public function __construct(string $baseUrl, string $username, string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->client = new Client([
            'baseUri' => $this->baseUrl,
            'userName' => $username,
            'password' => $password,
        ]);
    }

    /**
     * Discover all addressbooks via principal + addressbook-home-set discovery.
     * Falls back to Depth-1 scan on base URL and user principal path.
     * @return array<string, array{url: string, displayName: string}>
     */
    public function discoverAddressbooks(): array
    {
        $searchUrls = [$this->baseUrl];

        try {
            $principalUrl = $this->findCurrentUserPrincipal($this->baseUrl);
            if ($principalUrl) {
                $abHome = $this->findAddressbookHome($principalUrl);
                if ($abHome) {
                    $searchUrls = [$abHome];
                } else {
                    array_unshift($searchUrls, $principalUrl);
                }
            }
        } catch (\Exception $e) {
            // fall through to scan
        }

        $props = ['{DAV:}resourcetype', '{DAV:}displayname'];
        $books = [];

        foreach ($searchUrls as $url) {
            try {
                $responses = $this->client->propFind($url, $props, 1);
            } catch (\Exception $e) {
                continue;
            }

            foreach ($responses as $href => $propSet) {
                $fullUrl = $this->resolveUrl($url, $href);
                if ($fullUrl === $url || $fullUrl === rtrim($url, '/') || $fullUrl . '/' === $url) {
                    continue;
                }

                $resourceType = $propSet['{DAV:}resourcetype'] ?? null;
                if (!($resourceType instanceof \Sabre\DAV\Xml\Property\ResourceType)) {
                    continue;
                }
                if (!$resourceType->is('{urn:ietf:params:xml:ns:carddav}addressbook')) {
                    continue;
                }

                $displayName = $propSet['{DAV:}displayname'] ?? basename(rtrim($href, '/'));
                $books[$fullUrl] = [
                    'url' => $fullUrl,
                    'displayName' => is_string($displayName) ? $displayName : basename(rtrim($href, '/')),
                ];
            }

            if (!empty($books)) break;
        }

        return $books;
    }

    private function findCurrentUserPrincipal(string $url): ?string
    {
        try {
            $response = $this->client->propFind($url, ['{DAV:}current-user-principal']);
        } catch (\Exception $e) {
            return null;
        }

        $principal = $response['{DAV:}current-user-principal'] ?? null;
        if (is_array($principal) && isset($principal[0]['value'])) {
            return $this->resolveUrl($url, $principal[0]['value']);
        }
        if (is_string($principal)) {
            return $this->resolveUrl($url, $principal);
        }
        return null;
    }

    private function findAddressbookHome(string $principalUrl): ?string
    {
        try {
            $response = $this->client->propFind($principalUrl, [
                '{urn:ietf:params:xml:ns:carddav}addressbook-home-set',
            ]);
        } catch (\Exception $e) {
            return null;
        }

        $home = $response['{urn:ietf:params:xml:ns:carddav}addressbook-home-set'] ?? null;
        if (is_array($home) && isset($home[0]['value'])) {
            return $this->resolveUrl($principalUrl, $home[0]['value']);
        }
        if (is_string($home)) {
            return $this->resolveUrl($principalUrl, $home);
        }
        return null;
    }

    /**
     * Get all contacts from an addressbook.
     * @return ContactCard[]
     */
    public function getContacts(string $addressbookUrl): array
    {
        $body = '<?xml version="1.0" encoding="utf-8" ?>
<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">
  <D:prop>
    <D:getetag/>
    <C:address-data/>
  </D:prop>
</C:addressbook-query>';

        $response = $this->client->request('REPORT', $addressbookUrl, $body, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Depth' => '1',
        ]);

        if ($response['statusCode'] !== 207) {
            return [];
        }

        $contacts = [];
        $parsed = $this->client->parseMultiStatus($response['body']);

        foreach ($parsed as $href => $propSet) {
            $cardData = $propSet[200]['{urn:ietf:params:xml:ns:carddav}address-data'] ?? null;
            $etag = $propSet[200]['{DAV:}getetag'] ?? null;

            if (!$cardData) {
                continue;
            }

            try {
                $vcard = VObject\Reader::read($cardData);
                $fullUrl = $this->resolveUrl($addressbookUrl, $href);
                $contacts[] = new ContactCard(
                    url: $fullUrl,
                    etag: is_string($etag) ? trim($etag, '"') : null,
                    vcard: $vcard,
                    rawData: $cardData,
                );
            } catch (\Exception $e) {
                continue;
            }
        }

        return $contacts;
    }

    /**
     * Create or update a contact.
     */
    public function putContact(string $url, string $vcardData, ?string $etag = null): bool
    {
        $headers = ['Content-Type' => 'text/vcard; charset=utf-8'];
        if ($etag) {
            $headers['If-Match'] = '"' . trim($etag, '"') . '"';  // ETag gequotet (Radicale verlangt Quotes)
        }

        $response = $this->client->request('PUT', $url, $vcardData, $headers);
        return in_array($response['statusCode'], [201, 204]);
    }

    /**
     * Delete a contact.
     */
    public function deleteContact(string $url, ?string $etag = null): bool
    {
        $headers = [];
        if ($etag) {
            $headers['If-Match'] = '"' . trim($etag, '"') . '"';  // ETag gequotet (Radicale verlangt Quotes)
        }

        $response = $this->client->request('DELETE', $url, null, $headers);
        return in_array($response['statusCode'], [200, 204]);
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
