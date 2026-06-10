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
        if (isset($this->vcard->FN)) {
            return (string)$this->vcard->FN;
        }
        $parts = [];
        if (isset($this->vcard->N)) {
            $n = $this->vcard->N->getParts();
            if (!empty($n[1])) $parts[] = $n[1]; // given
            if (!empty($n[0])) $parts[] = $n[0]; // family
        }
        return implode(' ', $parts) ?: '(Ohne Name)';
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
            'email' => $this->getEmail(),
            'organization' => $this->getOrganization() ?? '',
            '_url' => $this->url,
            '_etag' => $this->etag,
            '_raw' => $this->rawData,
        ];

        // Multiple emails
        $emails = $this->getEmails();
        if (count($emails) > 1) {
            $record['email'] = $emails;
        }

        // Phone
        if ($phone = $this->getPhone()) {
            $record['phone'] = $phone;
        }

        return $record;
    }
}
