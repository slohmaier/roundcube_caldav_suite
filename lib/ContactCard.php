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
        $record = [
            'ID' => md5($this->url),
            'name' => $this->getDisplayName(),
            'firstname' => $this->getFirstName(),
            'surname' => $this->getLastName(),
            'organization' => $this->getOrganization() ?? '',
            '_url' => $this->url,
            '_etag' => $this->etag,
            '_raw' => $this->rawData,
        ];

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
