<?php

namespace Slohmaier\CalDAVSuite;

class Collection
{
    public function __construct(
        public readonly string $url,
        public readonly string $displayName,
        /** @var string[] */
        public readonly array $components = ['VEVENT', 'VTODO'],
        public readonly ?string $color = null,
    ) {}

    public function supportsEvents(): bool
    {
        return in_array('VEVENT', $this->components);
    }

    public function supportsTodos(): bool
    {
        return in_array('VTODO', $this->components);
    }

    public function getId(): string
    {
        return md5($this->url);
    }
}
