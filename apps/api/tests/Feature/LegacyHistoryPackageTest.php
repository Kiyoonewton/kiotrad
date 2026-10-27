<?php

namespace Tests\Feature;

use App\Infrastructure\Provider\LegacyHistoryPackage;
use RuntimeException;
use Tests\TestCase;

class LegacyHistoryPackageTest extends TestCase
{
    public function test_package_hashes_are_verified_before_reading_database(): void
    {
        $folder = sys_get_temp_dir().'/history-package-'.bin2hex(random_bytes(8));
        mkdir($folder.'/files', 0700, true);
        $body = '{"synthetic":true}';
        $hash = hash('sha256', $body);
        file_put_contents($folder.'/plan.json', '{}');
        file_put_contents($folder.'/files/'.$hash.'.json', $body);
        file_put_contents($folder.'/manifest.json', json_encode([
            'package_version' => 'legacy-history-package-1.0.0', 'plan_sha256' => hash('sha256', '{}'),
            'files' => [['file' => $hash.'.json', 'sha256' => $hash]],
        ], JSON_THROW_ON_ERROR));
        try {
            $this->assertCount(1, app(LegacyHistoryPackage::class)->entries($folder));
            file_put_contents($folder.'/files/'.$hash.'.json', 'tampered');
            $this->expectException(RuntimeException::class);
            app(LegacyHistoryPackage::class)->entries($folder);
        } finally {
            unlink($folder.'/files/'.$hash.'.json');
            unlink($folder.'/plan.json');
            unlink($folder.'/manifest.json');
            rmdir($folder.'/files');
            rmdir($folder);
        }
    }
}
