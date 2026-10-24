<?php

namespace App\Infrastructure\Provider;

interface CollectionStore
{
    public function save(array $report): string;
}
