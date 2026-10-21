<?php

namespace EntityProjects13\Tests\Feature\Projects;

use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;
use Projects13\Queries\Projects\ProjectListQuery;

class ProjectListQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_list_includes_projects_with_trashed_entities(): void
    {
        $entity = Entity::factory()->trashed()->create();
        $project = Project::factory()->for($entity)->create();

        $projects = app(ProjectListQuery::class)->active('');

        $this->assertSame([$project->id], $projects->pluck('id')->all());

        $listedProject = $projects->first();

        if ($listedProject === null) {
            self::fail('The active list must include the project with its trashed entity.');
        }

        $this->assertTrue($listedProject->getRelationValue('entity')->is($entity));
    }

    public function test_entity_filter_matches_projects_of_any_selected_entity(): void
    {
        [$north, $south, $west] = Entity::factory()->count(3)->create();
        $northProject = Project::factory()->for($north)->create();
        $southProject = Project::factory()->for($south)->create();
        Project::factory()->for($west)->create();
        Project::factory()->create(['entity_id' => null]);

        $projects = app(ProjectListQuery::class)->active('', [
            'entity_id' => ['operator' => 'equals', 'value' => [(string) $north->id, (string) $south->id]],
        ]);

        $this->assertEqualsCanonicalizing([$northProject->id, $southProject->id], $projects->pluck('id')->all());
    }

    public function test_entity_filter_excludes_selected_entities_and_finds_projects_without_entity(): void
    {
        [$north, $south] = Entity::factory()->count(2)->create();
        Project::factory()->for($north)->create();
        $southProject = Project::factory()->for($south)->create();
        $orphanProject = Project::factory()->create(['entity_id' => null]);

        $notNorth = app(ProjectListQuery::class)->active('', [
            'entity_id' => ['operator' => 'not_equals', 'value' => [(string) $north->id]],
        ]);
        $withoutEntity = app(ProjectListQuery::class)->active('', [
            'entity_id' => ['operator' => 'is_empty', 'value' => null],
        ]);

        $this->assertSame([$southProject->id], $notNorth->pluck('id')->all());
        $this->assertSame([$orphanProject->id], $withoutEntity->pluck('id')->all());
    }
}
