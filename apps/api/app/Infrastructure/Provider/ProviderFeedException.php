<?php

namespace App\Infrastructure\Provider;

use RuntimeException;

final class ProviderFeedException extends RuntimeException
{
    public function __construct(public readonly FeedObservation $observation)
    {
        parent::__construct('Provider returned no usable feed document.');
    }
}
