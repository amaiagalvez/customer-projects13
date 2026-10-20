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
}
