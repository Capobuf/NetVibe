<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scanners\FpingWrapper;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

class FpingWrapperTest extends TestCase
{
    public function test_scan_returns_parsed_addresses_on_success()
    {
        Process::fake([
            '*' => Process::result(output: "192.168.1.5\n192.168.1.10\n", exitCode: 0),
        ]);

        $wrapper = new FpingWrapper();

        $result = $wrapper->scan('192.168.1.0/24');

        $this->assertSame(['192.168.1.5', '192.168.1.10'], $result);

        Process::assertRan(function ($process, $result) {
            $expected = 'fping -a -g -r 1 -t 200 192.168.1.0/24';
            if (is_array($process->command)) {
                return implode(' ', $process->command) === $expected;
            }

            return $process->command === $expected;
        });
    }

    public function test_scan_handles_exit_code_one_and_parses_partial_output()
    {
        Process::fake([
            '*' => Process::result(output: "192.168.1.5\n192.168.1.20\n", exitCode: 1),
        ]);

        $wrapper = new FpingWrapper();

        $result = $wrapper->scan('10.0.0.0/24');

        $this->assertSame(['192.168.1.5', '192.168.1.20'], $result);

        Process::assertRan(function ($process, $result) {
            $expected = 'fping -a -g -r 1 -t 200 10.0.0.0/24';
            if (is_array($process->command)) {
                return implode(' ', $process->command) === $expected;
            }

            return $process->command === $expected;
        });
    }

    public function test_scan_throws_on_fatal_exit_codes()
    {
        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'invalid cidr', exitCode: 3),
        ]);

        $this->expectException(RuntimeException::class);

        $wrapper = new FpingWrapper();

        $wrapper->scan('invalid');
    }
}
