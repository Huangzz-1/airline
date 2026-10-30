<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.simulator_enabled' => true]);
        config(['airline.sample_data' => true]);
        config(['payments.mode' => 'gateway']);
        foreach (['ops', 'mysql', 'finance'] as $node) {
            if (config("database.connections.$node.database") !== 'airline_test' || config("database.connections.$node.username") !== 'airline_test') {
                throw new \RuntimeException('Refusing to modify a non-test database');
            }
            Schema::connection($node)->disableForeignKeyConstraints();
            foreach (Schema::connection($node)->getTableListing('airline_test', schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::connection($node)->table($table)->truncate();
                }
            }
            Schema::connection($node)->enableForeignKeyConstraints();
        }
        $this->seed();
    }
}
