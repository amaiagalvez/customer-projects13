<?php

namespace EntityProjects13\Tests\Unit\Integrations;

use Entities13\Models\Entity;
use EntityProjects13\Integrations\EntityCountsIntegration;
use EntityProjects13\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

class EntityCountsIntegrationTest extends TestCase
{
    public function test_row_returns_empty_array_for_non_entity_model(): void
    {
        $integration = new EntityCountsIntegration;

        // Test with a plain model instance (not a Entity)
        $model = new class extends Model
        {
            protected $table = 'test';
        };

        $result = $integration->row($model);

        $this->assertSame([], $result);
    }

    public function test_row_returns_counts_for_entity(): void
    {
        $integration = new EntityCountsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity']);
        // Set the counts as if they came from the query
        $entity->projects_count = 5;
        $entity->epics_count = 3;
        $entity->comments_count = 2;
        $entity->projects_exists = true;

        $result = $integration->row($entity);

        $this->assertSame([
            'projectsCount' => 5,
            'projectsUrl' => route('projects.index', ['search' => 'Test Entity']),
            'epicsCount' => 3,
            'commentsCount' => 2,
            'blocks_delete' => true,
        ], $result);
    }

    public function test_row_returns_null_projects_url_when_zero_projects(): void
    {
        $integration = new EntityCountsIntegration;

        $entity = Entity::factory()->make(['id' => 1, 'name' => 'Test Entity']);
        $entity->projects_count = 0;
        $entity->epics_count = 0;
        $entity->comments_count = 0;
        $entity->projects_exists = false;

        $result = $integration->row($entity);

        $this->assertSame([
            'projectsCount' => 0,
            'projectsUrl' => null,
            'epicsCount' => 0,
            'commentsCount' => 0,
            'blocks_delete' => false,
        ], $result);
    }
}
