<?php

namespace App\Infrastructure\Diagnostics;

use RuntimeException;

final class RawAuditSelection
{
    public function load(string $path, string $database): array
    {
        $input = fopen($path, 'r');
        if ($input === false) {
            throw new RuntimeException('Previous audit report is unreadable.');
        }
        $files = [];
        $start = $summary = null;
        try {
            while (($line = fgets($input)) !== false) {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($row['type'] ?? '') === 'start') {
                    $start = $row;
                } elseif (($row['type'] ?? '') === 'summary') {
                    $summary = $row;
                } elseif (($row['type'] ?? '') === 'rejection') {
                    $name = $row['file'] ?? '';
                    $hash = $row['compressed_sha256'] ?? '';
                    if (! is_string($name) || $name === '' || isset($files[$name]) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                        throw new RuntimeException('Invalid or duplicate source identity in previous audit.');
                    }
                    $files[$name] = $hash;
                }
            }
        } finally {
            fclose($input);
        }
        if (($start['database'] ?? '') !== $database || ($summary['database'] ?? '') !== $database
            || ($summary['mode'] ?? '') !== 'raw_document_revalidation' || ($summary['complete'] ?? false) !== true
            || ($summary['database_writes'] ?? null) !== false || ($summary['counts']['rejected_now'] ?? 0) !== count($files)) {
            throw new RuntimeException('Use a complete audit report for the same active local database.');
        }

        return $files;
    }
}
