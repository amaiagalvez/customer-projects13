<?php

namespace EntityProjects13\Tests\Feature;

use Entities13\Models\Entity;
use Entities13\Queries\Entities\EntityListQuery;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Queries\Epics\EpicListQuery;
use Projects13\Transformers\EpicListTransformer;

class EntityProjectEpicIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_entity_with_project_and_epic_shows_correct_counts(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal', 'active' => true]);
        Epic::factory()->for($project)->create(['name' => 'First slice', 'active' => true]);

        $query = app(EntityListQuery::class);
        $record = $query->active('')->items()[0];

        $this->assertSame(1, (int) $record->getAttribute('projects_count'));
        $this->assertSame(1, (int) $record->getAttribute('epics_count'));
    }

    public function test_entity_search_by_project_name(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        Epic::factory()->for($project)->create(['name' => 'First slice']);

        $this->get(route('entities.index', ['search' => 'Website renewal']))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_epic_shows_entity(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);
        $epic = Epic::factory()->for($project)->create(['name' => 'First slice']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_entity_can_have_multiple_projects(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['name' => 'North client']);

        Project::factory()->for($entity)->create(['name' => 'Project A']);
        Project::factory()->for($entity)->create(['name' => 'Project B']);

        $query = app(EntityListQuery::class);
        $all = $query->active('');

        $this->assertSame(1, $all->total());
        $this->assertSame([$entity->id], $all->pluck('id')->all());
        $this->assertSame(2, (int) $all->items()[0]->getAttribute('projects_count'));
    }

    public function test_epic_with_project(): void
    {
        $this->signIn();
        $entity1 = Entity::factory()->create(['name' => 'Entity A']);
        $entity2 = Entity::factory()->create(['name' => 'Entity B']);

        $project1 = Project::factory()->for($entity1)->create(['name' => 'Project A']);
        $project2 = Project::factory()->for($entity2)->create(['name' => 'Project B']);

        $epic1 = Epic::factory()->for($project1)->create(['name' => 'Epic under entity A']);
        $epic2 = Epic::factory()->for($project2)->create(['name' => 'Epic under entity B']);

        $list = app(EpicListTransformer::class)->active(
            app(EpicListQuery::class)->active(''),
            '',
        );
        $this->assertIsArray($list['rows']);
        $rows = collect($list['rows'])->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertSame('Project A', $rows[$epic1->id]['project']);
        $this->assertSame('Entity A', $rows[$epic1->id]['entity']);
        $this->assertSame('Project B', $rows[$epic2->id]['project']);
        $this->assertSame('Entity B', $rows[$epic2->id]['entity']);
    }
}
