<?php

namespace EntityProjects13\Tests\Unit\Integrations;

use entities13\Models\Entity;
use EntityProjects13\Integrations\EntityProjectsIntegration;
use EntityProjects13\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EntityProjectsIntegrationTest extends TestCase
{
    public function test_row_returns_empty_array_for_non_project_nor_epic_model(): void
    {
        $integration = new EntityProjectsIntegration;

        // Test with a plain model instance (not a Project or Epic)
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = $integration->row($model);

        $this->assertSame([], $result);
    }

    public function test_row_returns_entity_data_for_project(): void
    {
        $integration = new EntityProjectsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity']);
        $project = Project::factory()->for($entity)->make(['id' => 1, 'name' => 'Test Project']);
        // Set the relation to avoid loading
        $project->setRelation('entity', $entity);

        $result = $integration->row($project);

        $this->assertSame([
            'entity' => 'Test Entity',
            'entity_id' => 1,
            'entity_name' => 'Test Entity',
            'entity_active' => true,
        ], $result);
    }

    public function test_row_marks_an_archived_entity_for_the_project_form(): void
    {
        $integration = new EntityProjectsIntegration;

        $entity = Entity::factory()->archived()->make(['id' => 1, 'name' => 'Archived Entity']);
        $project = Project::factory()->for($entity)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('entity', $entity);

        $this->assertFalse($integration->row($project)['entity_active']);
    }

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

    public function test_row_returns_empty_entity_fields_when_project_has_no_entity(): void
    {
        $integration = new EntityProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'entity_id' => null]);
        $project->setRelation('entity', null);

        $result = $integration->row($project);

        $this->assertSame([
            'entity' => null,
            'entity_id' => null,
            'entity_name' => null,
            'entity_active' => null,
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

    public function test_label_returns_project_name_with_entity(): void
    {
        $integration = new EntityProjectsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity']);
        $project = Project::factory()->for($entity)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('entity', $entity);

        $result = $integration->label($project);

        $this->assertSame('Test Project (Test Entity)', $result);
    }

    public function test_label_returns_project_name_without_entity_when_missing(): void
    {
        $integration = new EntityProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'entity_id' => null]);
        $project->setRelation('entity', null);

        $result = $integration->label($project);

        $this->assertSame('Test Project ()', $result);
    }

    public function test_trail_returns_entity_name_for_project(): void
    {
        $integration = new EntityProjectsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity']);
        $project = Project::factory()->for($entity)->make(['id' => 1, 'name' => 'Test Project']);
        $project->setRelation('entity', $entity);

        $result = $integration->trail($project);

        $this->assertSame(['Test Entity'], $result);
    }

    public function test_trail_returns_empty_array_when_no_entity(): void
    {
        $integration = new EntityProjectsIntegration;

        $project = Project::factory()->make(['id' => 1, 'name' => 'Test Project', 'entity_id' => null]);
        $project->setRelation('entity', null);

        $result = $integration->trail($project);

        $this->assertSame([], $result);
    }

    public function test_has_active_related_entities_checks_for_active_entities(): void
    {
        $integration = new EntityProjectsIntegration;

        // Test when no active entities exist
        Entity::factory()->create(['active' => false]);
        $result = $integration->hasActiveRelatedEntities();
        $this->assertFalse($result);

        // Test when active entities exist
        Entity::factory()->create(['active' => true]);
        $result = $integration->hasActiveRelatedEntities();
        $this->assertTrue($result);
    }

    public function test_no_related_entities_banner_returns_expected_structure(): void
    {
        $integration = new EntityProjectsIntegration;

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }

    public function test_no_related_entities_banner_returns_null_when_active_entities_exist(): void
    {
        $integration = new EntityProjectsIntegration;
        Entity::factory()->create(['active' => true]);

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertNull($result);
    }

    public function test_no_related_entities_banner_returns_banner_when_no_active_entities(): void
    {
        $integration = new EntityProjectsIntegration;
        Entity::factory()->create(['active' => false]);

        $result = $integration->noRelatedEntitiesBanner();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('action_label', $result);
        $this->assertArrayHasKey('action_url', $result);
    }
}
