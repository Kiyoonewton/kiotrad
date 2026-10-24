<?php

namespace App\Infrastructure\Provider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RawFeedArchive
{
    public function record(string $url, string $requestedAt, string $receivedAt, int $status, string $body): void
    {
        if (! config('provider.archive_raw_responses')) {
            return;
        }
        $record = json_encode([
            'schema_version' => 1,
            'url' => $url,
            'requested_at' => $requestedAt,
            'received_at' => $receivedAt,
            'http_status' => $status,
            'body_sha256' => hash('sha256', $body),
            // Base64 preserves malformed JSON, HTML errors and non-UTF8 bodies exactly.
            'body_base64' => base64_encode($body),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($record, 6);
        $date = CarbonImmutable::parse($receivedAt)->utc()->format('Y/m/d');
        $path = 'provider-evidence/raw/'.$date.'/'.hash('sha256', $record).'.json.gz';
        if ($compressed === false || ! Storage::disk('local')->put($path, $compressed)) {
            throw new RuntimeException('Raw provider response could not be archived; collection must retry.');
        }
    }
}
