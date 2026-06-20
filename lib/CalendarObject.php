<?php

namespace Slohmaier\CalDAVSuite;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component;

class CalendarObject
{
    public function __construct(
        public readonly string $url,
        public readonly ?string $etag,
        public readonly Component $component,
        public readonly VCalendar $vcalendar,
        public readonly string $rawData,
    ) {}

    public function getUid(): ?string
    {
        return isset($this->component->UID) ? (string)$this->component->UID : null;
    }

    public function getSummary(): string
    {
        return isset($this->component->SUMMARY) ? (string)$this->component->SUMMARY : '(Ohne Titel)';
    }

    public function getDescription(): ?string
    {
        return isset($this->component->DESCRIPTION) ? (string)$this->component->DESCRIPTION : null;
    }

    public function getLocation(): ?string
    {
        return isset($this->component->LOCATION) ? (string)$this->component->LOCATION : null;
    }

    public function getStart(): ?\DateTimeInterface
    {
        return isset($this->component->DTSTART) ? $this->component->DTSTART->getDateTime() : null;
    }

    public function getEnd(): ?\DateTimeInterface
    {
        return isset($this->component->DTEND) ? $this->component->DTEND->getDateTime() : null;
    }

    public function isAllDay(): bool
    {
        if (!isset($this->component->DTSTART)) {
            return false;
        }
        return !$this->component->DTSTART->hasTime();
    }

    // Task-specific methods

    public function getDue(): ?\DateTimeInterface
    {
        return isset($this->component->DUE) ? $this->component->DUE->getDateTime() : null;
    }

    public function isCompleted(): bool
    {
        $status = isset($this->component->STATUS) ? (string)$this->component->STATUS : '';
        return $status === 'COMPLETED' || isset($this->component->COMPLETED);
    }

    public function getPriority(): int
    {
        return isset($this->component->PRIORITY) ? (int)(string)$this->component->PRIORITY : 0;
    }

    public function getStatus(): ?string
    {
        return isset($this->component->STATUS) ? (string)$this->component->STATUS : null;
    }

    public function getTravelMode(): ?string
    {
        if (isset($this->component->{'X-APPLE-TRAVEL-ADVISORY-BEHAVIOR'})) {
            $val = (string)$this->component->{'X-APPLE-TRAVEL-ADVISORY-BEHAVIOR'};
            if (strtoupper($val) === 'AUTOMATIC') {
                return 'auto';
            }
        }
        if (isset($this->component->{'X-APPLE-TRAVEL-DURATION'})) {
            $duration = (string)$this->component->{'X-APPLE-TRAVEL-DURATION'};
            if (preg_match('/PT(\d+)M/', $duration, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    public function getGeo(): ?string
    {
        if (isset($this->component->GEO)) {
            return str_replace(';', ',', (string)$this->component->GEO);
        }
        if (isset($this->component->{'X-APPLE-STRUCTURED-LOCATION'})) {
            $val = (string)$this->component->{'X-APPLE-STRUCTURED-LOCATION'};
            if (preg_match('/geo:([\d.+-]+)[,;]([\d.+-]+)/', $val, $m)) {
                return $m[1] . ',' . $m[2];
            }
        }
        return null;
    }

    public function getReminderMinutes(): ?string
    {
        foreach ($this->component->getComponents() as $child) {
            if ($child->name === 'VALARM' && isset($child->TRIGGER)) {
                $trigger = (string)$child->TRIGGER;
                if (preg_match('/^-?PT?(\d+)([MHDS])/i', $trigger, $m)) {
                    $val = (int)$m[1];
                    $unit = strtoupper($m[2]);
                    if ($unit === 'H') $val *= 60;
                    if ($unit === 'D') $val *= 1440;
                    return (string)$val;
                }
                if ($trigger === 'P0D' || $trigger === 'PT0S' || $trigger === '-PT0M') {
                    return '0';
                }
            }
        }
        return null;
    }

    public function getRrule(): ?string
    {
        return isset($this->component->RRULE) ? (string)$this->component->RRULE : null;
    }

    /** @return string[] */
    public function getCategories(): array
    {
        if (!isset($this->component->CATEGORIES)) {
            return [];
        }
        $out = [];
        foreach ($this->component->CATEGORIES as $cat) {
            foreach ($cat->getParts() as $part) {
                if (trim((string)$part) !== '') {
                    $out[] = (string)$part;
                }
            }
        }
        return $out;
    }

    public function getUrl(): ?string
    {
        return isset($this->component->URL) ? (string)$this->component->URL : null;
    }

    public function getPercentComplete(): ?int
    {
        return isset($this->component->{'PERCENT-COMPLETE'})
            ? (int)(string)$this->component->{'PERCENT-COMPLETE'}
            : null;
    }

    /**
     * Serialize to array for JSON API responses.
     */
    public function toArray(): array
    {
        $data = [
            'url' => $this->url,
            'etag' => $this->etag,
            'uid' => $this->getUid(),
            'summary' => $this->getSummary(),
            'description' => $this->getDescription(),
            'location' => $this->getLocation(),
        ];

        if ($this->component->name === 'VEVENT') {
            if ($this->isAllDay()) {
                // All-day: send pure dates (Y-m-d) to avoid timezone shift in browser.
                // DTEND bleibt EXKLUSIV (Folgetag) -- getEventsForDate im Client rechnet
                // damit. Der Edit-Dialog rechnet fuers Anzeigen -1 Tag (inklusiv).
                $data['start'] = $this->getStart()?->format('Y-m-d');
                $data['end'] = $this->getEnd()?->format('Y-m-d');
            } else {
                $data['start'] = $this->getStart()?->format('c');
                $data['end'] = $this->getEnd()?->format('c');
            }
            $data['allDay'] = $this->isAllDay();
            $data['travel_mode'] = $this->getTravelMode();
            $data['location_geo'] = $this->getGeo();
            $data['reminder_minutes'] = $this->getReminderMinutes();
            $data['rrule'] = $this->getRrule();
            $data['categories'] = $this->getCategories();
            $data['link'] = $this->getUrl();
            $data['status'] = $this->getStatus();
        }

        if ($this->component->name === 'VTODO') {
            $data['start'] = $this->getStart()?->format('c');
            $data['due'] = $this->getDue()?->format('c');
            $data['completed'] = $this->isCompleted();
            $data['priority'] = $this->getPriority();
            $data['status'] = $this->getStatus();
            $data['percent_complete'] = $this->getPercentComplete();
            $data['categories'] = $this->getCategories();
            $data['rrule'] = $this->getRrule();
            $data['link'] = $this->getUrl();
        }

        return $data;
    }
}
