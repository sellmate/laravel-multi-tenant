<?php

namespace Sellmate\Laravel\MultiTenant\Tests\fixtures;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserts one row into `widgets` (tests/fixtures/migrations/ok) on the default connection.
 */
class WidgetSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('insert into widgets default values');
    }
}
