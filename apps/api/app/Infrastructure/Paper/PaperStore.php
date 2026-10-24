<?php

namespace App\Infrastructure\Paper;

interface PaperStore
{
    public function read(): ?array;

    public function create(array $state): bool;

    public function replace(int $revision, array $state): bool;
}
