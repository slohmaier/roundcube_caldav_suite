<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Component\VCard;

class ContactCard
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $etag,
        public readonly VCard $vcard,
        public readonly string $rawData,
    ) {}

    public function getUid(): ?string
    {
        return isset($this->vcard->UID) ? (string)$this->vcard->UID : null;
    }

    public function getDisplayName(): string
    {
        if (isset($this->vcard->FN) && trim((string)$this->vcard->FN) !== '') {
            return (string)$this->vcard->FN;
        }
        $parts = [];
        if (isset($this->vcard->N)) {
            $n = $this->vcard->N->getParts();
            if (!empty($n[1])) $parts[] = $n[1]; // given
            if (!empty($n[0])) $parts[] = $n[0]; // family
        }
        if ($parts) {
            return implode(' ', $parts);
        }
        // Firmenkontakte (Apple X-ABSHOWAS:COMPANY): Name aus ORG ableiten,
        // sonst leerer FN -> Eintrag sortiert vor allen Namen.
        if (isset($this->vcard->ORG) && trim((string)$this->vcard->ORG) !== '') {
            return (string)$this->vcard->ORG;
        }
        return '(Ohne Name)';
    }

    public function getFirstName(): string
    {
        if (isset($this->vcard->N)) {
            $n = $this->vcard->N->getParts();
            return $n[1] ?? '';
        }
        return '';
    }

    public function getLastName(): string
    {
        if (isset($this->vcard->N)) {
            $n = $this->vcard->N->getParts();
            return $n[0] ?? '';
        }
        return '';
    }

    public function getEmail(): ?string
    {
        return isset($this->vcard->EMAIL) ? (string)$this->vcard->EMAIL : null;
    }

    public function getEmails(): array
    {
        $emails = [];
        if (isset($this->vcard->EMAIL)) {
            foreach ($this->vcard->EMAIL as $email) {
                $emails[] = (string)$email;
            }
        }
        return $emails;
    }

    public function getPhone(): ?string
    {
        return isset($this->vcard->TEL) ? (string)$this->vcard->TEL : null;
    }

    public function getOrganization(): ?string
    {
        return isset($this->vcard->ORG) ? (string)$this->vcard->ORG : null;
    }

    /**
     * Convert to Roundcube contact record format.
     */
    public function toRcubeRecord(): array
    {
        $n = isset($this->vcard->N) ? $this->vcard->N->getParts() : [];
        $org = isset($this->vcard->ORG) ? $this->vcard->ORG->getParts() : [];

        $record = [
            'ID' => md5($this->url),
            'name' => $this->getDisplayName(),
            'firstname' => $n[1] ?? '',
            'surname' => $n[0] ?? '',
            'middlename' => $n[2] ?? '',
            'prefix' => $n[3] ?? '',
            'suffix' => $n[4] ?? '',
            'organization' => $org[0] ?? '',
            '_url' => $this->url,
            '_etag' => $this->etag,
            '_raw' => $this->rawData,
        ];

        if (!empty($org[1])) {
            $record['department'] = $org[1];
        }
        if (isset($this->vcard->NICKNAME) && (string)$this->vcard->NICKNAME !== '') {
            $record['nickname'] = (string)$this->vcard->NICKNAME;
        }
        if (isset($this->vcard->TITLE) && (string)$this->vcard->TITLE !== '') {
            $record['jobtitle'] = (string)$this->vcard->TITLE;
        }
        if (isset($this->vcard->NOTE) && (string)$this->vcard->NOTE !== '') {
            $record['notes'] = (string)$this->vcard->NOTE;
        }
        if (isset($this->vcard->BDAY) && (string)$this->vcard->BDAY !== '') {
            $record['birthday'] = (string)$this->vcard->BDAY;
        }
        if (isset($this->vcard->ANNIVERSARY) && (string)$this->vcard->ANNIVERSARY !== '') {
            $record['anniversary'] = (string)$this->vcard->ANNIVERSARY;
        }

        // Emails mit Subtype-Keys (email:work / email:home / ...), damit
        // Roundcube das richtige Label zeigt UND der Subtype round-trippt.
        if (isset($this->vcard->EMAIL)) {
            foreach ($this->vcard->EMAIL as $email) {
                $val = trim((string)$email);
                if ($val === '') continue;
                $record['email:' . $this->subtypeOf($email, 'home')][] = $val;
            }
        }

        // Telefonnummern analog (phone:work / phone:home / phone:cell / ...)
        if (isset($this->vcard->TEL)) {
            foreach ($this->vcard->TEL as $tel) {
                $val = trim((string)$tel);
                if ($val === '') continue;
                $record['phone:' . $this->subtypeOf($tel, 'home')][] = $val;
            }
        }

        // Webseiten (website:subtype)
        if (isset($this->vcard->URL)) {
            foreach ($this->vcard->URL as $url) {
                $val = trim((string)$url);
                if ($val === '') continue;
                $record['website:' . $this->subtypeOf($url, 'homepage')][] = $val;
            }
        }

        // Instant Messaging (im:subtype) — Subtype aus X-SERVICE-TYPE oder TYPE
        if (isset($this->vcard->IMPP)) {
            foreach ($this->vcard->IMPP as $impp) {
                $val = trim((string)$impp);
                if ($val === '') continue;
                $sub = isset($impp['X-SERVICE-TYPE'])
                    ? strtolower((string)$impp['X-SERVICE-TYPE'])
                    : $this->subtypeOf($impp, 'jabber');
                $record['im:' . $sub][] = $val;
            }
        }

        // Adressen (address:subtype mit Roundcube-Childs)
        if (isset($this->vcard->ADR)) {
            foreach ($this->vcard->ADR as $adr) {
                $p = $adr->getParts();
                $entry = [
                    'street'   => $p[2] ?? '',
                    'locality' => $p[3] ?? '',
                    'region'   => $p[4] ?? '',
                    'zipcode'  => $p[5] ?? '',
                    'country'  => $p[6] ?? '',
                ];
                if (trim(implode('', $entry)) === '') continue;
                $record['address:' . $this->subtypeOf($adr, 'home')][] = $entry;
            }
        }

        return $record;
    }

    /**
     * Roundcube-Subtype aus dem vCard-TYPE-Parameter ableiten (work/home/cell/...).
     * Generische Marker (internet/voice/pref) werden uebersprungen.
     */
    private function subtypeOf($prop, string $default): string
    {
        $param = $prop['TYPE'] ?? null;
        if ($param !== null) {
            foreach ($param->getParts() as $t) {
                $t = strtolower(trim($t));
                if ($t === '' || in_array($t, ['internet', 'voice', 'pref'], true)) {
                    continue;
                }
                return $t;
            }
        }
        return $default;
    }
}
