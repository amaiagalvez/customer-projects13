<?php

namespace EntityProjects13\Tests\Feature\Integrations;

use Entities13\Models\Entity;
use EntityProjects13\Integrations\EntityProjectsIntegration;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EntityProjectsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_returns_entity_data_for_epic(): void
    {
        $integration = new EntityProjectsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity', 'active' => true]);
        $project = Project::factory()->for($entity)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('entity', $entity);

        $epic = Epic::factory()->for($project)->make(['id' => 1, 'name' => 'Test Epic']);
        $epic->setRelation('project', $project);

        $result = $integration->row($epic);

        $this->assertSame([
            'entity' => 'Test Entity',
            'entity_active' => true,
            'project_label' => 'Test Project (Test Entity)',
        ], $result);
    }

    public function test_row_returns_project_label_when_epic_project_has_no_entity(): void
    {
        $integration = new EntityProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'entity_id' => null]);
        $project->setRelation('entity', null);

        $epic = Epic::factory()->for($project)->make(['id' => 1, 'name' => 'Test Epic']);
        $epic->setRelation('project', $project);

        $result = $integration->row($epic);

        // When epic's project has no entity, the label still includes the project name
        $this->assertSame([
            'project_label' => 'Test Project ()',
        ], $result);
    }

    public function test_has_active_related_entities_checks_for_active_entities(): void
    {
        $integration = new EntityProjectsIntegration;

        // Test when no active entities exist
        Entity::factory()->create(['active' => false]);
        $result = $integration->hasActiveRelated();
        $this->assertFalse($result);

        // Test when active entities exist
        Entity::factory()->create(['active' => true]);
        $result = $integration->hasActiveRelated();
        $this->assertTrue($result);
    }

    public function test_no_related_entities_banner_returns_expected_structure(): void
    {
        $integration = new EntityProjectsIntegration;

        $result = $integration->noRelatedBanner();

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }

    public function test_no_related_entities_banner_returns_null_when_active_entities_exist(): void
    {
        $integration = new EntityProjectsIntegration;
        Entity::factory()->create(['active' => true]);

        $result = $integration->noRelatedBanner();

        $this->assertNull($result);
    }

    public function test_no_related_entities_banner_returns_banner_when_no_active_entities(): void
    {
        $integration = new EntityProjectsIntegration;
        Entity::factory()->create(['active' => false]);

        $result = $integration->noRelatedBanner();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }
}
