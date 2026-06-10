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
            $data['start'] = $this->getStart()?->format('c');
            $data['end'] = $this->getEnd()?->format('c');
            $data['allDay'] = $this->isAllDay();
        }

        if ($this->component->name === 'VTODO') {
            $data['due'] = $this->getDue()?->format('c');
            $data['completed'] = $this->isCompleted();
            $data['priority'] = $this->getPriority();
            $data['status'] = $this->getStatus();
        }

        return $data;
    }
}
