<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseIsolationTest extends TestCase
{
    public function test_test_account_is_confined_to_the_testing_database(): void
    {
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('SQLSTATE[42000]');

        DB::statement('USE netvibe');
    }
}
