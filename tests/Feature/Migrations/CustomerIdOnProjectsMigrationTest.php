<?php

namespace EntityProjects13\Tests\Feature\Migrations;

use EntityProjects13\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EntityIdOnProjectsMigrationTest extends TestCase
{
    public function test_entity_id_migration_can_be_rolled_back(): void
    {
        $connectionName = 'entity_projects13_entity_id_migration_test';
        $originalDefault = config('database.default');
        $sqliteConnection = config('database.connections.sqlite');

        if (! is_array($sqliteConnection)) {
            throw new \RuntimeException('The SQLite test connection is not configured.');
        }

        config([
            "database.connections.{$connectionName}" => array_merge($sqliteConnection, [
                'database' => ':memory:',
            ]),
            'database.default' => $connectionName,
        ]);

        DB::purge($connectionName);

        try {
            // Create the projects table first (as it would exist before this migration)
            Schema::create('PRO_projects', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->softDeletes();
                $table->timestamps();
            });

            // Create the entities table for the foreign key
            Schema::create('CUM_entities', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->softDeletes();
                $table->timestamps();
            });

            $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_11_000000_add_entity_id_to_projects_table.php';
            $migration->up();

            $this->assertTrue(Schema::hasColumn('PRO_projects', 'entity_id'));

            $keys = array_values(array_filter(
                Schema::getForeignKeys('PRO_projects'),
                static fn(array $key): bool => $key['columns'] === ['entity_id'],
            ));

            $this->assertNotEmpty($keys, 'the foreign key has to be enforced, not just declared');
            $this->assertSame('CUM_entities', $keys[0]['foreign_table']);
            $this->assertSame(['id'], $keys[0]['foreign_columns']);

            $migration->down();

            $this->assertFalse(Schema::hasColumn('PRO_projects', 'entity_id'));
        } finally {
            DB::purge($connectionName);
            config([
                'database.default' => $originalDefault,
                "database.connections.{$connectionName}" => null,
            ]);
        }
    }
}
