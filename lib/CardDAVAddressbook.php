<?php

namespace Slohmaier\CalDAVSuite;

/**
 * Roundcube addressbook implementation backed by CardDAV.
 */
class CardDAVAddressbook extends \rcube_addressbook
{
    public $primary_key = 'ID';
    public $groups = false;
    public $readonly = false;
    public $searchonly = false;
    public $group_id = null;
    public $coltypes = [
        'name', 'firstname', 'surname', 'email', 'phone', 'organization',
    ];

    private string $id;
    private string $name;
    private CardDAVClient $client;
    private string $addressbookUrl;
    private ?array $contacts = null;
    private $filter = null;
    private ?array $searchFilter = null;
    private int $result_count = 0;

    public function __construct(string $id, string $name, CardDAVClient $client, string $addressbookUrl)
    {
        $this->id = $id;
        $this->name = $name;
        $this->client = $client;
        $this->addressbookUrl = $addressbookUrl;
        $this->ready = true;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function set_search_set($filter): void
    {
        $this->filter = $filter;
        if (is_array($filter) && isset($filter['_carddav_search'])) {
            $this->searchFilter = $filter;
        }
    }

    public function get_search_set()
    {
        return $this->filter;
    }

    public function reset(): void
    {
        $this->filter = null;
        $this->searchFilter = null;
        $this->contacts = null;
        $this->result = null;
    }

    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $this->loadContacts();

        $records = [];
        foreach ($this->contacts as $contact) {
            $record = $contact->toRcubeRecord();
            if ($this->searchFilter && !$this->matchSearchFilter($record)) {
                continue;
            }
            $records[] = $record;
        }

        // Sort by display name
        usort($records, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        $result = new \rcube_result_set(count($records));
        foreach ($records as $rec) {
            $result->add($rec);
        }

        $this->result = $result;
        return $result;
    }

    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $this->loadContacts();

        $value_lower = mb_strtolower(is_array($value) ? implode(' ', $value) : $value);
        $allFields = ['name', 'firstname', 'surname', 'email', 'phone', 'organization'];

        $searchFields = is_array($fields) ? $fields : [$fields];
        if ($fields === '*' || in_array('*', $searchFields)) {
            $searchFields = $allFields;
        }

        $this->searchFilter = [
            '_carddav_search' => true,
            'fields' => $searchFields,
            'value' => $value_lower,
        ];
        $this->filter = $this->searchFilter;

        $records = [];
        foreach ($this->contacts as $contact) {
            $record = $contact->toRcubeRecord();
            if ($this->matchSearchFilter($record)) {
                $records[] = $record;
            }
        }

        usort($records, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        $result = new \rcube_result_set(count($records));
        if ($select) {
            foreach ($records as $rec) {
                $result->add($rec);
            }
        }

        $this->result = $result;
        return $result;
    }

    public function count()
    {
        $this->loadContacts();
        return new \rcube_result_set(count($this->contacts));
    }

    public function get_result()
    {
        return $this->result;
    }

    public function get_record($id, $assoc = false)
    {
        $this->loadContacts();

        foreach ($this->contacts as $contact) {
            $record = $contact->toRcubeRecord();
            if ($record['ID'] === $id) {
                $this->result = new \rcube_result_set(1);
                $this->result->add($record);
                return $assoc ? $record : $this->result;
            }
        }

        return $assoc ? [] : new \rcube_result_set();
    }

    public function insert($save_data, $check = false)
    {
        $vcard = new \Sabre\VObject\Component\VCard([
            'FN' => trim(($save_data['firstname'] ?? '') . ' ' . ($save_data['surname'] ?? '')),
            'N' => [
                $save_data['surname'] ?? '',
                $save_data['firstname'] ?? '',
                '', '', '',
            ],
        ]);

        $emails = is_array($save_data['email'] ?? null) ? $save_data['email'] : [$save_data['email'] ?? ''];
        foreach (array_filter($emails) as $email) {
            $vcard->add('EMAIL', $email);
        }
        if (!empty($save_data['phone'])) {
            $vcard->add('TEL', $save_data['phone']);
        }
        if (!empty($save_data['organization'])) {
            $vcard->add('ORG', $save_data['organization']);
        }

        $uid = \Sabre\VObject\UUIDUtil::getUUID();
        $vcard->add('UID', $uid);

        $url = rtrim($this->addressbookUrl, '/') . '/' . $uid . '.vcf';
        if ($this->client->putContact($url, $vcard->serialize())) {
            $this->contacts = null; // invalidate cache
            return md5($url);
        }

        return false;
    }

    public function update($id, $save_data)
    {
        $this->loadContacts();

        foreach ($this->contacts as $contact) {
            $record = $contact->toRcubeRecord();
            if ($record['ID'] === $id) {
                $vcard = $contact->vcard;
                $vcard->FN = trim(($save_data['firstname'] ?? '') . ' ' . ($save_data['surname'] ?? ''));
                $vcard->N = [
                    $save_data['surname'] ?? '',
                    $save_data['firstname'] ?? '',
                    '', '', '',
                ];

                // Remove old emails, add new
                $vcard->remove('EMAIL');
                $emails = is_array($save_data['email'] ?? null) ? $save_data['email'] : [$save_data['email'] ?? ''];
                foreach (array_filter($emails) as $email) {
                    $vcard->add('EMAIL', $email);
                }

                if ($this->client->putContact($record['_url'], $vcard->serialize(), $record['_etag'])) {
                    $this->contacts = null;
                    return $id;
                }
                break;
            }
        }

        return false;
    }

    public function delete($ids, $force = true)
    {
        $this->loadContacts();

        if (!is_array($ids)) $ids = [$ids];
        $deleted = 0;

        foreach ($this->contacts as $contact) {
            $record = $contact->toRcubeRecord();
            if (in_array($record['ID'], $ids)) {
                if ($this->client->deleteContact($record['_url'], $record['_etag'])) {
                    $deleted++;
                }
            }
        }

        if ($deleted) {
            $this->contacts = null;
        }

        return $deleted;
    }

    public function close() {}

    private function loadContacts(): void
    {
        if ($this->contacts !== null) {
            return;
        }
        $this->contacts = $this->client->getContacts($this->addressbookUrl);
    }

    private function matchSearchFilter(array $record): bool
    {
        if (!$this->searchFilter) return true;

        $value = $this->searchFilter['value'];
        $fields = $this->searchFilter['fields'];

        foreach ($fields as $field) {
            $fieldVal = $record[$field] ?? '';
            if (is_array($fieldVal)) {
                foreach ($fieldVal as $v) {
                    if (str_contains(mb_strtolower($v), $value)) {
                        return true;
                    }
                }
            } else {
                if (str_contains(mb_strtolower((string)$fieldVal), $value)) {
                    return true;
                }
            }
        }
        return false;
    }
}
