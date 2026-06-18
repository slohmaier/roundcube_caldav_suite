<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\CardDAVAddressbook;
use Slohmaier\CalDAVSuite\CardDAVClient;
use Slohmaier\CalDAVSuite\ContactCard;

class CardDAVAddressbookTest extends TestCase
{
    private string $bookUrl = 'http://srv/test/contacts/';
    private string $contactUrl = 'http://srv/test/contacts/c1.vcf';
    private string $contactEtag = 'ETAG-1';

    /** @var array<string,mixed>|null */
    private ?array $put = null;

    private function makeCard(string $vcf): ContactCard
    {
        /** @var VCard $vcard */
        $vcard = Reader::read($vcf);
        return new ContactCard($this->contactUrl, $this->contactEtag, $vcard, $vcf);
    }

    private function bookWithContact(string $vcf): CardDAVAddressbook
    {
        $card = $this->makeCard($vcf);
        $client = $this->createMock(CardDAVClient::class);
        $client->method('getContacts')->willReturn([$card]);
        $this->put = null;
        $client->method('putContact')->willReturnCallback(function ($url, $data, $etag = null) {
            $this->put = ['url' => $url, 'data' => $data, 'etag' => $etag];
            return true;
        });
        return new CardDAVAddressbook('caldav_x', 'Test', $client, $this->bookUrl);
    }

    private function defaultVcf(): string
    {
        return "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:U1\r\nFN:Alt Name\r\nN:Name;Alt;;;\r\n"
            . "EMAIL;TYPE=HOME:alt@example.com\r\nTEL;TYPE=HOME:+49111\r\nORG:Alte Firma\r\nEND:VCARD";
    }

    private function savedVcard(): VCard
    {
        $this->assertNotNull($this->put, 'putContact wurde nicht aufgerufen');
        /** @var VCard $v */
        $v = Reader::read($this->put['data']);
        return $v;
    }

    // ---- update: Name ----

    public function testUpdateMapsNameToFn(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $res = $ab->update(md5($this->contactUrl), ['name' => 'Neuer Name', 'firstname' => 'Neu', 'surname' => 'Name']);
        $this->assertSame(md5($this->contactUrl), $res);
        $this->assertSame('Neuer Name', (string) $this->savedVcard()->FN);
    }

    public function testUpdateFnFallbackFromFirstLastWhenNoName(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['firstname' => 'Max', 'surname' => 'Mustermann']);
        $this->assertSame('Max Mustermann', (string) $this->savedVcard()->FN);
    }

    public function testUpdateSetsNStructured(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['firstname' => 'Max', 'surname' => 'Mustermann']);
        $n = $this->savedVcard()->N->getParts();
        $this->assertSame('Mustermann', $n[0]); // family
        $this->assertSame('Max', $n[1]);         // given
    }

    // ---- update: Email Subtypes (DER BUG) ----

    public function testUpdateEmailWorkSubtype(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'email:work' => ['job@firma.de']]);
        $v = $this->savedVcard();
        $this->assertSame('job@firma.de', (string) $v->EMAIL);
        $this->assertSame('WORK', (string) $v->EMAIL['TYPE']);
    }

    public function testUpdateEmailHomeAndWork(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), [
            'name' => 'X',
            'email:home' => ['privat@x.de'],
            'email:work' => ['job@x.de'],
        ]);
        $emails = [];
        foreach ($this->savedVcard()->EMAIL as $e) {
            $emails[(string) $e] = (string) $e['TYPE'];
        }
        $this->assertSame('HOME', $emails['privat@x.de']);
        $this->assertSame('WORK', $emails['job@x.de']);
    }

    public function testUpdateMultipleEmailsSameSubtype(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'email:work' => ['a@x.de', 'b@x.de']]);
        $vals = [];
        foreach ($this->savedVcard()->EMAIL as $e) {
            $vals[] = (string) $e;
        }
        $this->assertSame(['a@x.de', 'b@x.de'], $vals);
    }

    public function testUpdateClearingEmailRemovesIt(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        // kein email-Key -> alle EMAIL entfernt
        $ab->update(md5($this->contactUrl), ['name' => 'X']);
        $this->assertFalse(isset($this->savedVcard()->EMAIL), 'EMAIL haette entfernt werden muessen');
    }

    public function testUpdateEmptyEmailValueSkipped(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'email:work' => ['']]);
        $this->assertFalse(isset($this->savedVcard()->EMAIL));
    }

    public function testUpdateBareEmailKey(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'email' => 'plain@x.de']);
        $this->assertSame('plain@x.de', (string) $this->savedVcard()->EMAIL);
    }

    // ---- update: Phone ----

    public function testUpdatePhoneCellSubtype(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'phone:cell' => ['+4915100']]);
        $v = $this->savedVcard();
        $this->assertSame('+4915100', (string) $v->TEL);
        $this->assertSame('CELL', (string) $v->TEL['TYPE']);
    }

    public function testUpdateClearingPhoneRemovesIt(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X']);
        $this->assertFalse(isset($this->savedVcard()->TEL));
    }

    // ---- update: Organization ----

    public function testUpdateOrganization(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X', 'organization' => 'Neue Firma GmbH']);
        $this->assertSame('Neue Firma GmbH', (string) $this->savedVcard()->ORG);
    }

    public function testUpdateClearingOrganizationRemovesIt(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X']);
        $this->assertFalse(isset($this->savedVcard()->ORG));
    }

    // ---- update: ETag + URL ----

    public function testUpdatePassesEtagAndUrlToPut(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $ab->update(md5($this->contactUrl), ['name' => 'X']);
        $this->assertSame($this->contactUrl, $this->put['url']);
        $this->assertSame($this->contactEtag, $this->put['etag']);
    }

    // ---- update: Sonderzeichen ----

    public function testUpdateSpecialCharsRoundTrip(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $name = 'Müller, Jörg & Söhne; "Chef" \\ 100%';
        $ab->update(md5($this->contactUrl), ['name' => $name, 'organization' => 'Ärzte, Zahn; GmbH']);
        $v = $this->savedVcard();
        $this->assertSame($name, (string) $v->FN);
        $this->assertSame('Ärzte, Zahn; GmbH', (string) $v->ORG);
    }

    // ---- update: nicht gefunden ----

    public function testUpdateUnknownIdReturnsFalse(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $res = $ab->update('nonexistent-id', ['name' => 'X']);
        $this->assertFalse($res);
        $this->assertNull($this->put, 'putContact darf bei unbekanntem Kontakt nicht laufen');
    }

    // ---- insert ----

    public function testInsertBuildsVcardAndReturnsId(): void
    {
        $client = $this->createMock(CardDAVClient::class);
        $this->put = null;
        $client->method('putContact')->willReturnCallback(function ($url, $data, $etag = null) {
            $this->put = ['url' => $url, 'data' => $data, 'etag' => $etag];
            return true;
        });
        $ab = new CardDAVAddressbook('caldav_x', 'Test', $client, $this->bookUrl);
        $res = $ab->insert([
            'name' => 'Neu Kontakt', 'firstname' => 'Neu', 'surname' => 'Kontakt',
            'email:work' => ['neu@firma.de'], 'phone:cell' => ['+4915199'],
            'organization' => 'Startup',
        ]);
        $this->assertNotFalse($res);
        $this->assertSame(md5($this->put['url']), $res);
        $this->assertNull($this->put['etag'], 'insert sendet kein If-Match');

        $v = $this->savedVcard();
        $this->assertSame('Neu Kontakt', (string) $v->FN);
        $this->assertSame('neu@firma.de', (string) $v->EMAIL);
        $this->assertSame('WORK', (string) $v->EMAIL['TYPE']);
        $this->assertSame('+4915199', (string) $v->TEL);
        $this->assertSame('Startup', (string) $v->ORG);
        $this->assertTrue(isset($v->UID), 'UID muss generiert werden');
        $this->assertStringContainsString('VERSION:3.0', $this->put['data']);
    }

    // ---- delete ----

    public function testDeleteCallsDeleteContact(): void
    {
        $card = $this->makeCard($this->defaultVcf());
        $client = $this->createMock(CardDAVClient::class);
        $client->method('getContacts')->willReturn([$card]);
        $delCall = null;
        $client->method('deleteContact')->willReturnCallback(function ($url, $etag = null) use (&$delCall) {
            $delCall = ['url' => $url, 'etag' => $etag];
            return true;
        });
        $ab = new CardDAVAddressbook('caldav_x', 'Test', $client, $this->bookUrl);
        $count = $ab->delete(md5($this->contactUrl));
        $this->assertSame(1, $count);
        $this->assertSame($this->contactUrl, $delCall['url']);
        $this->assertSame($this->contactEtag, $delCall['etag']);
    }

    public function testDeleteUnknownIdReturnsZero(): void
    {
        $card = $this->makeCard($this->defaultVcf());
        $client = $this->createMock(CardDAVClient::class);
        $client->method('getContacts')->willReturn([$card]);
        $client->expects($this->never())->method('deleteContact');
        $ab = new CardDAVAddressbook('caldav_x', 'Test', $client, $this->bookUrl);
        $this->assertSame(0, $ab->delete('unknown'));
    }

    // ---- get_record / list_records ----

    public function testGetRecordReturnsContact(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $rec = $ab->get_record(md5($this->contactUrl), true);
        $this->assertSame('Alt Name', $rec['name']);
        $this->assertSame(md5($this->contactUrl), $rec['ID']);
    }

    public function testGetRecordUnknownReturnsEmpty(): void
    {
        $ab = $this->bookWithContact($this->defaultVcf());
        $rec = $ab->get_record('unknown', true);
        $this->assertSame([], $rec);
    }
}
