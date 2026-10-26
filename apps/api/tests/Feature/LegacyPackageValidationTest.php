<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegacyPackageValidationTest extends TestCase
{
    public function test_tampered_package_is_rejected_before_database_access(): void
    {
        $folder = sys_get_temp_dir().'/legacy-package-'.bin2hex(random_bytes(6));
        mkdir($folder.'/files', 0700, true);
        file_put_contents($folder.'/files/test.json', '{}');
        file_put_contents($folder.'/manifest.json', json_encode(['files' => [['file' => 'test.json', 'sha256' => str_repeat('0', 64)]]]));
        try {
            $this->artisan('provider:import-legacy-odds', ['folder' => $folder.'/files'])
                ->expectsOutputToContain('Package checksum mismatch')->assertExitCode(1);
        } finally {
            unlink($folder.'/files/test.json');
            unlink($folder.'/manifest.json');
            rmdir($folder.'/files');
            rmdir($folder);
        }
    }

    public function test_incomplete_upload_is_rejected_before_any_import(): void
    {
        $folder = sys_get_temp_dir().'/legacy-package-'.bin2hex(random_bytes(6));
        mkdir($folder.'/files', 0700, true);
        file_put_contents($folder.'/manifest.json', json_encode(['files' => [['file' => 'missing.json', 'sha256' => str_repeat('0', 64)]]]));
        try {
            $this->artisan('provider:import-legacy-odds', ['folder' => $folder.'/files'])
                ->expectsOutputToContain('Package files are missing')->assertExitCode(1);
        } finally {
            unlink($folder.'/manifest.json');
            rmdir($folder.'/files');
            rmdir($folder);
        }
    }
}
