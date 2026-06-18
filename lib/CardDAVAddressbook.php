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
    public $result = null;
    public $coltypes = [
        'name', 'firstname', 'surname', 'middlename', 'prefix', 'suffix',
        'nickname', 'jobtitle', 'organization', 'department',
        'email', 'phone', 'address', 'website', 'im',
        'birthday', 'anniversary', 'notes',
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
        $vcard = new \Sabre\VObject\Component\VCard(['VERSION' => '3.0']);
        $uid = \Sabre\VObject\UUIDUtil::getUUID();
        $vcard->add('UID', $uid);

        $this->applySaveData($vcard, $save_data);

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
                $this->applySaveData($vcard, $save_data);

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

    /**
     * Map Roundcube save_data (incl. subtype keys like "email:home", "phone:cell")
     * onto a vCard. Used by insert() and update().
     */
    private function applySaveData($vcard, array $save_data): void
    {
        // --- Name (FN + strukturierter N) ---
        $name = trim((string) ($save_data['name'] ?? ''));
        if ($name === '') {
            $composed = trim(preg_replace('/\s+/', ' ',
                ($save_data['prefix'] ?? '') . ' ' . ($save_data['firstname'] ?? '') . ' '
                . ($save_data['middlename'] ?? '') . ' ' . ($save_data['surname'] ?? '') . ' '
                . ($save_data['suffix'] ?? '')));
            $name = $composed !== '' ? $composed : trim((string) ($save_data['organization'] ?? ''));
        }
        $vcard->FN = $name;
        $vcard->N = [
            $save_data['surname'] ?? '',
            $save_data['firstname'] ?? '',
            $save_data['middlename'] ?? '',
            $save_data['prefix'] ?? '',
            $save_data['suffix'] ?? '',
        ];

        // --- Spitzname ---
        $vcard->remove('NICKNAME');
        if (!empty($save_data['nickname'])) {
            $vcard->add('NICKNAME', $save_data['nickname']);
        }

        // --- Jobtitel ---
        $vcard->remove('TITLE');
        if (!empty($save_data['jobtitle'])) {
            $vcard->add('TITLE', $save_data['jobtitle']);
        }

        // --- Organisation;Abteilung (strukturiertes ORG) ---
        $vcard->remove('ORG');
        $org  = trim((string) ($save_data['organization'] ?? ''));
        $dept = trim((string) ($save_data['department'] ?? ''));
        if ($org !== '' || $dept !== '') {
            $vcard->add('ORG', $dept !== '' ? [$org, $dept] : [$org]);
        }

        // --- EMAIL (email / email:home / email:work / ...) ---
        $vcard->remove('EMAIL');
        foreach ($this->collectSubtyped($save_data, 'email') as [$subtype, $value]) {
            $prop = $vcard->add('EMAIL', $value);
            if ($subtype !== '') {
                $prop['TYPE'] = strtoupper($subtype);
            }
        }

        // --- TEL (phone / phone:home / phone:cell / ...) ---
        $vcard->remove('TEL');
        foreach ($this->collectSubtyped($save_data, 'phone') as [$subtype, $value]) {
            $prop = $vcard->add('TEL', $value);
            if ($subtype !== '') {
                $prop['TYPE'] = strtoupper($subtype);
            }
        }

        // --- ADR (Adresse, strukturiert; address:home / address:work) ---
        $vcard->remove('ADR');
        foreach ($this->collectSubtyped($save_data, 'address') as [$subtype, $value]) {
            if (!is_array($value)) {
                continue;
            }
            // vCard ADR: pobox; extended; street; locality; region; postal-code; country
            $adr = [
                '',
                '',
                (string) ($value['street']   ?? ''),
                (string) ($value['locality'] ?? ''),
                (string) ($value['region']   ?? ''),
                (string) ($value['zipcode']  ?? ''),
                (string) ($value['country']  ?? ''),
            ];
            if (trim(implode('', $adr)) === '') {
                continue;
            }
            $prop = $vcard->add('ADR', $adr);
            if ($subtype !== '') {
                $prop['TYPE'] = strtoupper($subtype);
            }
        }

        // --- URL (website / website:homepage / ...) ---
        $vcard->remove('URL');
        foreach ($this->collectSubtyped($save_data, 'website') as [$subtype, $value]) {
            $prop = $vcard->add('URL', $value);
            if ($subtype !== '') {
                $prop['TYPE'] = strtoupper($subtype);
            }
        }

        // --- IMPP (Instant Messaging; im / im:jabber / ...) ---
        $vcard->remove('IMPP');
        foreach ($this->collectSubtyped($save_data, 'im') as [$subtype, $value]) {
            $prop = $vcard->add('IMPP', $value);
            if ($subtype !== '') {
                $prop['X-SERVICE-TYPE'] = $subtype;
            }
        }

        // --- Geburtstag / Jahrestag ---
        $vcard->remove('BDAY');
        if (!empty($save_data['birthday']) && ($d = $this->normalizeDate($save_data['birthday']))) {
            $vcard->add('BDAY', $d);
        }
        $vcard->remove('ANNIVERSARY');
        if (!empty($save_data['anniversary']) && ($d = $this->normalizeDate($save_data['anniversary']))) {
            $vcard->add('ANNIVERSARY', $d);
        }

        // --- Notizen ---
        $vcard->remove('NOTE');
        if (!empty($save_data['notes'])) {
            $vcard->add('NOTE', $save_data['notes']);
        }
    }

    /** Datums-Eingabe (verschiedene Formate) -> YYYY-MM-DD, oder null. */
    private function normalizeDate($value): ?string
    {
        if (is_array($value)) {
            $value = implode('-', $value);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Gather all values for a column from save_data, honouring Roundcube's
     * "<col>:<subtype>" key convention. Returns list of [subtype, value].
     */
    private function collectSubtyped(array $save_data, string $col): array
    {
        $out = [];
        foreach ($save_data as $key => $values) {
            if ($key !== $col && strpos((string) $key, $col . ':') !== 0) {
                continue;
            }
            $subtype = ($key === $col) ? '' : substr((string) $key, strlen($col) + 1);
            foreach ((array) $values as $v) {
                if (is_string($v)) {
                    $v = trim($v);
                }
                if ($v === '' || $v === null) {
                    continue;
                }
                $out[] = [$subtype, $v];
            }
        }
        return $out;
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
