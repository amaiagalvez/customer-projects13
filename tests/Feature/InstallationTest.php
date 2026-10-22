<?php

namespace EntityProjects13\Tests\Feature;

use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Projects13\Queries\Projects\ProjectListQuery;

/**
 * Installing this package replaces what both base packages bound by default. If the order in which
 * Composer's auto-discovery happens to register the providers ever changed, these two bindings are
 * what would silently fall back to the null implementations.
 */
class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_may_only_hang_from_a_entity_that_exists(): void
    {
        $this->assertDatabaseHas('ENT_entities', ['id' => Entity::factory()->create()->id]);
    }

    public function test_the_column_and_its_foreign_key_reach_the_projects_table(): void
    {
        $this->assertTrue(Schema::hasColumn('PRO_projects', 'entity_id'));

        $keys = array_values(array_filter(
            Schema::getForeignKeys('PRO_projects'),
            static fn (array $key): bool => $key['columns'] === ['entity_id'],
        ));

        $this->assertNotEmpty($keys, 'the foreign key has to be enforced, not just declared');
        $this->assertSame('ENT_entities', $keys[0]['foreign_table']);
        $this->assertSame(['id'], $keys[0]['foreign_columns']);
    }

    public function test_the_project_list_offers_the_entity_filter_with_the_scope_options(): void
    {
        $active = Entity::factory()->create(['name' => 'Active entity']);
        $archived = Entity::factory()->create(['name' => 'Archived entity', 'active' => false]);

        $field = app(ProjectListQuery::class)->searchableFields('active')['entity_id'] ?? null;

        $options = $field['options'] ?? null;

        $this->assertIsCallable($options);
        $this->assertSame([$active->id => 'Active entity'], $options('active'));
        $this->assertSame([$archived->id => 'Archived entity'], $options('archived'));
    }
}
