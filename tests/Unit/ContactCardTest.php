<?php

namespace Slohmaier\CalDAVSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\Reader;
use Slohmaier\CalDAVSuite\ContactCard;

class ContactCardTest extends TestCase
{
    private function card(string $vcf, string $url = 'http://srv/c.vcf', ?string $etag = 'etag1'): ContactCard
    {
        /** @var VCard $vcard */
        $vcard = Reader::read($vcf);
        return new ContactCard($url, $etag, $vcard, $vcf);
    }

    // ---- Name / Display ----

    public function testDisplayNameFromFn(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:Max Mustermann\nN:Mustermann;Max;;;\nEND:VCARD");
        $this->assertSame('Max Mustermann', $c->getDisplayName());
    }

    public function testDisplayNameFallbackToN(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:\nN:Meier;Anna;;;\nEND:VCARD");
        $this->assertSame('Anna Meier', $c->getDisplayName());
    }

    public function testDisplayNameFallbackToOrgForCompany(): void
    {
        // Apple-Firmenkontakt: leerer FN, Name nur in ORG
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:\nN:;;;;\nORG:Medicover Endokrinologie\nX-ABSHOWAS:COMPANY\nEND:VCARD");
        $this->assertSame('Medicover Endokrinologie', $c->getDisplayName());
    }

    public function testDisplayNameUnnamed(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:\nN:;;;;\nEND:VCARD");
        $this->assertSame('(Ohne Name)', $c->getDisplayName());
    }

    public function testNameRecordSortsCompanyUnderOrg(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:\nN:;;;;\nORG:Zahnarzt Dr. A\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame('Zahnarzt Dr. A', $rec['name']);
    }

    public function testFirstAndLastName(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:Max Mustermann\nN:Mustermann;Max;;;\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame('Max', $rec['firstname']);
        $this->assertSame('Mustermann', $rec['surname']);
    }

    // ---- Email Subtypes ----

    public function testEmailWorkSubtype(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=WORK:job@firma.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['job@firma.de'], $rec['email:work']);
        $this->assertArrayNotHasKey('email:home', $rec);
    }

    public function testEmailHomeSubtype(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=HOME:privat@example.com\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['privat@example.com'], $rec['email:home']);
    }

    public function testEmailNoTypeDefaultsHome(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL:plain@example.com\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['plain@example.com'], $rec['email:home']);
    }

    public function testEmailInternetTypeSkippedUsesNextOrDefault(): void
    {
        // TYPE=INTERNET,WORK -> internet uebersprungen -> work
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=INTERNET,WORK:a@b.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['a@b.de'], $rec['email:work']);
    }

    public function testEmailOnlyInternetDefaultsHome(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=INTERNET:a@b.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['a@b.de'], $rec['email:home']);
    }

    public function testMultipleEmailsSameSubtype(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=WORK:a@firma.de\nEMAIL;TYPE=WORK:b@firma.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['a@firma.de', 'b@firma.de'], $rec['email:work']);
    }

    public function testMultipleEmailsDifferentSubtypes(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=HOME:h@x.de\nEMAIL;TYPE=WORK:w@x.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['h@x.de'], $rec['email:home']);
        $this->assertSame(['w@x.de'], $rec['email:work']);
    }

    public function testEmptyEmailSkipped(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=WORK:\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertArrayNotHasKey('email:work', $rec);
    }

    // ---- Phone Subtypes ----

    public function testPhoneCellSubtype(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nTEL;TYPE=CELL:+4915112345678\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['+4915112345678'], $rec['phone:cell']);
    }

    public function testPhoneHomeAndWork(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nTEL;TYPE=HOME:+4989111\nTEL;TYPE=WORK:+4989222\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['+4989111'], $rec['phone:home']);
        $this->assertSame(['+4989222'], $rec['phone:work']);
    }

    public function testPhoneNoTypeDefaultsHome(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nTEL:+4930999\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['+4930999'], $rec['phone:home']);
    }

    // ---- Organization ----

    public function testOrganization(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:Max\nORG:ACME GmbH\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame('ACME GmbH', $rec['organization']);
    }

    public function testOrganizationEmptyWhenMissing(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:Max\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame('', $rec['organization']);
    }

    // ---- Metadaten ----

    public function testRecordCarriesUrlEtagAndId(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:Max\nEND:VCARD", 'http://srv/abc.vcf', 'E-123');
        $rec = $c->toRcubeRecord();
        $this->assertSame('http://srv/abc.vcf', $rec['_url']);
        $this->assertSame('E-123', $rec['_etag']);
        $this->assertSame(md5('http://srv/abc.vcf'), $rec['ID']);
    }

    // ---- Sonderzeichen ----

    public function testSpecialCharsInNameRoundTrip(): void
    {
        $vcf = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Müller-Lüdenscheidt\\, Jörg\r\nN:Müller-Lüdenscheidt;Jörg;;;\r\nEND:VCARD";
        $c = $this->card($vcf);
        $rec = $c->toRcubeRecord();
        $this->assertStringContainsString('Jörg', $rec['name']);
        $this->assertSame('Müller-Lüdenscheidt', $rec['surname']);
    }

    public function testUmlautEmailDomain(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nFN:X\nEMAIL;TYPE=HOME:test@müller.de\nEND:VCARD");
        $rec = $c->toRcubeRecord();
        $this->assertSame(['test@müller.de'], $rec['email:home']);
    }

    public function testGetUid(): void
    {
        $c = $this->card("BEGIN:VCARD\nVERSION:3.0\nUID:CARD-UID-9\nFN:X\nEND:VCARD");
        $this->assertSame('CARD-UID-9', $c->getUid());
    }
}
