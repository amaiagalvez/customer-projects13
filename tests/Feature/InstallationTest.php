<?php

namespace EntityProjects13\Tests\Feature;

use EntityProjects13\Integrations\EntityCountsIntegration;
use EntityProjects13\Integrations\EntityProjectsIntegration;
use EntityProjects13\Tests\TestCase;
use entities13\Contracts\EntityIntegration;
use entities13\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Projects13\Contracts\ProjectDisplayIntegration;
use Projects13\Contracts\ProjectQueryIntegration;
use Projects13\Contracts\ProjectValidationIntegration;

/**
 * Installing this package replaces what both base packages bound by default. If the order in which
 * Composer's auto-discovery happens to register the providers ever changed, these two bindings are
 * what would silently fall back to the null implementations.
 */
class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_integration_replaces_the_project_null_implementation(): void
    {
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectValidationIntegration::class));
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectQueryIntegration::class));
        $this->assertInstanceOf(EntityProjectsIntegration::class, app(ProjectDisplayIntegration::class));
    }

    public function test_the_integration_replaces_the_entity_null_implementation(): void
    {
        $this->assertInstanceOf(EntityCountsIntegration::class, app(EntityIntegration::class));
    }

    public function test_a_project_may_only_hang_from_a_entity_that_exists(): void
    {
        $this->assertDatabaseHas('CUM_entities', ['id' => Entity::factory()->create()->id]);
    }

    public function test_the_column_and_its_foreign_key_reach_the_projects_table(): void
    {
        $this->assertTrue(Schema::hasColumn('PRO_projects', 'entity_id'));

        $keys = array_values(array_filter(
            Schema::getForeignKeys('PRO_projects'),
            static fn(array $key): bool => $key['columns'] === ['entity_id'],
        ));

        $this->assertNotEmpty($keys, 'the foreign key has to be enforced, not just declared');
        $this->assertSame('CUM_entities', $keys[0]['foreign_table']);
        $this->assertSame(['id'], $keys[0]['foreign_columns']);
    }
}
