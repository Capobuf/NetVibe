<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EnvEditor;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EnvEditorTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/netvibe_env_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // restore perms so cleanup works
        @chmod($this->tmpDir, 0755);
        File::deleteDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function test_set_keys_creates_backup_and_writes_atomically()
    {
        $envPath = $this->tmpDir . '/.env';
        file_put_contents($envPath, "EXISTING=1\n");

        $editor = new EnvEditor($envPath);

        $this->assertTrue($editor->setKeys(['FOO' => 'bar', 'EXISTING' => 'changed']));

        // backup exists
        $this->assertFileExists($envPath . '.bak');

        $contents = file_get_contents($envPath);
        $this->assertStringContainsString('FOO=bar', $contents);
        $this->assertStringContainsString('EXISTING=changed', $contents);
    }

    public function test_set_key_escapes_and_quotes_special_values()
    {
        $envPath = $this->tmpDir . '/.env';

        $editor = new EnvEditor($envPath);

        $editor->setKey('COMPLEX', 'value with spaces "quotes" and \\backslashes');

        $contents = file_get_contents($envPath);

        $this->assertStringContainsString('COMPLEX="value with spaces \\"quotes\\" and \\\\backslashes"', $contents);
    }

    public function test_set_key_throws_on_write_permission_error()
    {
        $envPath = $this->tmpDir . '/.env';
        file_put_contents($envPath, "OK=1\n");
        // create a test double that simulates atomic replace failure
        $editor = new class($envPath) extends EnvEditor {
            protected function atomicReplace(string $tmp, string $dest): bool
            {
                return false;
            }
        };

        $this->expectException(\RuntimeException::class);

        $editor->setKey('SHOULD_FAIL', 'x');
    }
}
