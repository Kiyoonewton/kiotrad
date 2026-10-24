<?php

namespace App\Infrastructure\Provider;

interface CollectorState
{
    public function read(string $competition): array;

    public function write(string $competition, array $state): void;
}
