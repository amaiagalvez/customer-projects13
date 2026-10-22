<?php

namespace EntityProjects13\Tests\Unit\Integrations;

use Entities13\Models\Entity;
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
}
