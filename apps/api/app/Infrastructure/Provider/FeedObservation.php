<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;

final readonly class FeedObservation
{
    public function __construct(
        public string $url,
        public CarbonImmutable $requestedAt,
        public CarbonImmutable $receivedAt,
        public array $document,
        public array $payload,
        public array $provenance = [],
    ) {}

    public function evidence(): array
    {
        $legacy = ($this->provenance['origin'] ?? null) === 'legacy_file';

        return [
            'url' => $this->url,
            'requested_at' => $legacy ? null : $this->requestedAt->toIso8601String(),
            'received_at' => $legacy ? null : $this->receivedAt->toIso8601String(),
            'http_status' => $legacy ? null : ($this->document['_http_status'] ?? 200),
            'payload' => $this->payload,
            ...($legacy ? ['provenance' => $this->provenance, 'imported_at' => $this->provenance['imported_at']] : []),
        ];
    }
}
