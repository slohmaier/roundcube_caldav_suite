<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\CardDAVAddressbook;
use Slohmaier\CalDAVSuite\CardDAVClient;
use Slohmaier\CalDAVSuite\ContactCard;

class ContactFieldsTest extends TestCase
{
    private string $url = 'http://srv/c.vcf';

    // ===== READ (ContactCard::toRcubeRecord) =====

    private function read(string $vcf): array
    {
        /** @var VCard $vcard */
        $vcard = Reader::read($vcf);
        return (new ContactCard($this->url, 'e1', $vcard, $vcf))->toRcubeRecord();
    }

    public function testReadAllNameParts(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:Dr. Max Otto Mustermann Jr.\nN:Mustermann;Max;Otto;Dr.;Jr.\nEND:VCARD");
        $this->assertSame('Mustermann', $r['surname']);
        $this->assertSame('Max', $r['firstname']);
        $this->assertSame('Otto', $r['middlename']);
        $this->assertSame('Dr.', $r['prefix']);
        $this->assertSame('Jr.', $r['suffix']);
    }

    public function testReadNicknameJobtitleDepartment(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\nNICKNAME:Maxi\nTITLE:Chefarzt\nORG:Klinik;Endokrinologie\nEND:VCARD");
        $this->assertSame('Maxi', $r['nickname']);
        $this->assertSame('Chefarzt', $r['jobtitle']);
        $this->assertSame('Klinik', $r['organization']);
        $this->assertSame('Endokrinologie', $r['department']);
    }

    public function testReadBirthdayAndAnniversary(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\nBDAY:1980-05-17\nANNIVERSARY:2010-06-20\nEND:VCARD");
        $this->assertSame('1980-05-17', $r['birthday']);
        $this->assertSame('2010-06-20', $r['anniversary']);
    }

    public function testReadNotes(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\nNOTE:Wichtiger Kontakt\\nzweite Zeile\nEND:VCARD");
        $this->assertSame("Wichtiger Kontakt\nzweite Zeile", $r['notes']);
    }

    public function testReadWebsite(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\nURL;TYPE=WORK:https://firma.de\nEND:VCARD");
        $this->assertSame(['https://firma.de'], $r['website:work']);
    }

    public function testReadAddress(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\nADR;TYPE=HOME:;;Hauptstr 1;München;Bayern;80331;Deutschland\nEND:VCARD");
        $this->assertArrayHasKey('address:home', $r);
        $adr = $r['address:home'][0];
        $this->assertSame('Hauptstr 1', $adr['street']);
        $this->assertSame('München', $adr['locality']);
        $this->assertSame('Bayern', $adr['region']);
        $this->assertSame('80331', $adr['zipcode']);
        $this->assertSame('Deutschland', $adr['country']);
    }

    public function testReadMultipleAddresses(): void
    {
        $r = $this->read("BEGIN:VCARD\nVERSION:3.0\nFN:X\n"
            . "ADR;TYPE=HOME:;;Wohnweg 1;Berlin;;10115;DE\n"
            . "ADR;TYPE=WORK:;;Bürostr 2;Hamburg;;20095;DE\nEND:VCARD");
        $this->assertSame('Wohnweg 1', $r['address:home'][0]['street']);
        $this->assertSame('Bürostr 2', $r['address:work'][0]['street']);
    }

    // ===== WRITE / ROUND-TRIP (CardDAVAddressbook::update) =====

    private array $put = [];

    private function bookWith(string $vcf): CardDAVAddressbook
    {
        $vcard = Reader::read($vcf);
        $card = new ContactCard($this->url, 'E1', $vcard, $vcf);
        $client = $this->createMock(CardDAVClient::class);
        $client->method('getContacts')->willReturn([$card]);
        $this->put = [];
        $client->method('putContact')->willReturnCallback(function ($u, $d, $e = null) {
            $this->put = ['url' => $u, 'data' => $d, 'etag' => $e];
            return true;
        });
        return new CardDAVAddressbook('caldav_x', 'T', $client, 'http://srv/');
    }

    private function saved(): VCard
    {
        return Reader::read($this->put['data']);
    }

    public function testWriteNicknameJobtitleDepartment(): void
    {
        $ab = $this->bookWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD");
        $ab->update(md5($this->url), [
            'name' => 'Max', 'nickname' => 'Maxi', 'jobtitle' => 'Chefarzt',
            'organization' => 'Klinik', 'department' => 'Endo',
        ]);
        $v = $this->saved();
        $this->assertSame('Maxi', (string) $v->NICKNAME);
        $this->assertSame('Chefarzt', (string) $v->TITLE);
        $this->assertSame(['Klinik', 'Endo'], $v->ORG->getParts());
    }

    public function testWriteBirthdayNormalises(): void
    {
        $ab = $this->bookWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD");
        $ab->update(md5($this->url), ['name' => 'X', 'birthday' => '17.05.1980']);
        $this->assertSame('1980-05-17', (string) $this->saved()->BDAY);
    }

    public function testWriteAddress(): void
    {
        $ab = $this->bookWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD");
        $ab->update(md5($this->url), [
            'name' => 'X',
            'address:work' => [[
                'street' => 'Bürostr 2', 'locality' => 'Hamburg',
                'region' => '', 'zipcode' => '20095', 'country' => 'DE',
            ]],
        ]);
        $v = $this->saved();
        $parts = $v->ADR->getParts();
        $this->assertSame('Bürostr 2', $parts[2]);
        $this->assertSame('Hamburg', $parts[3]);
        $this->assertSame('20095', $parts[5]);
        $this->assertSame('DE', $parts[6]);
        $this->assertSame('WORK', (string) $v->ADR['TYPE']);
    }

    public function testWriteWebsiteAndNotes(): void
    {
        $ab = $this->bookWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD");
        $ab->update(md5($this->url), [
            'name' => 'X', 'website:homepage' => ['https://x.de'], 'notes' => 'Notiz',
        ]);
        $v = $this->saved();
        $this->assertSame('https://x.de', (string) $v->URL);
        $this->assertSame('Notiz', (string) $v->NOTE);
    }

    public function testEditPreservesUnknownProps(): void
    {
        // Bestehende Karte mit PHOTO + CATEGORIES + X-ABSHOWAS -> beim Edit erhalten
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Alt\r\nEMAIL;TYPE=HOME:a@x.de\r\n"
            . "PHOTO;ENCODING=b;TYPE=JPEG:Zm9vYmFy\r\nCATEGORIES:VIP\r\nX-ABSHOWAS:COMPANY\r\nEND:VCARD";
        $ab = $this->bookWith($vcf);
        $ab->update(md5($this->url), ['name' => 'Neu', 'email:home' => ['a@x.de']]);
        $v = $this->saved();
        $this->assertSame('Neu', (string) $v->FN);
        $this->assertTrue(isset($v->PHOTO), 'PHOTO muss erhalten bleiben');
        $this->assertTrue(isset($v->CATEGORIES), 'CATEGORIES muss erhalten bleiben');
        $this->assertSame('COMPANY', (string) $v->{'X-ABSHOWAS'});
    }

    public function testFullRoundTrip(): void
    {
        // schreiben -> als neue Karte lesen -> alle Felder zurueck
        $ab = $this->bookWith("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:X\r\nEND:VCARD");
        $ab->update(md5($this->url), [
            'name' => 'Dr. Anna Meier', 'firstname' => 'Anna', 'surname' => 'Meier',
            'prefix' => 'Dr.', 'nickname' => 'Anni', 'jobtitle' => 'Ärztin',
            'organization' => 'Praxis', 'department' => 'Innere',
            'email:work' => ['anna@praxis.de'], 'phone:cell' => ['+4915100'],
            'address:work' => [['street' => 'Wegstr 3', 'locality' => 'Köln', 'region' => '', 'zipcode' => '50667', 'country' => 'DE']],
            'website:work' => ['https://praxis.de'], 'birthday' => '1975-03-08', 'notes' => 'Hausärztin',
        ]);
        $card = new ContactCard($this->url, 'E2', $this->saved(), $this->put['data']);
        $r = $card->toRcubeRecord();
        $this->assertSame('Anna', $r['firstname']);
        $this->assertSame('Dr.', $r['prefix']);
        $this->assertSame('Anni', $r['nickname']);
        $this->assertSame('Ärztin', $r['jobtitle']);
        $this->assertSame('Praxis', $r['organization']);
        $this->assertSame('Innere', $r['department']);
        $this->assertSame(['anna@praxis.de'], $r['email:work']);
        $this->assertSame(['+4915100'], $r['phone:cell']);
        $this->assertSame('Köln', $r['address:work'][0]['locality']);
        $this->assertSame(['https://praxis.de'], $r['website:work']);
        $this->assertSame('1975-03-08', $r['birthday']);
        $this->assertSame('Hausärztin', $r['notes']);
    }
}
