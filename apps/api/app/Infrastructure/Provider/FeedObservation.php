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
    ) {}

    public function evidence(): array
    {
        return [
            'url' => $this->url,
            'requested_at' => $this->requestedAt->toIso8601String(),
            'received_at' => $this->receivedAt->toIso8601String(),
            'payload' => $this->payload,
        ];
    }
}
