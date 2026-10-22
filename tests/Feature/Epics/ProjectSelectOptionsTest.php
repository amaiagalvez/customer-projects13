<?php

namespace EntityProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class ProjectSelectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_options_search_by_project_or_entity_and_are_limited_to_the_shared_page_size(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create(['name' => 'Shared search entity']);

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Project::factory()->for($entity)->create([
                'name' => sprintf('Project result %02d', $index),
            ]);
        }

        $response = $this->getJson(route('projects.options', ['q' => 'Shared search entity']));

        $response->assertOk()
            ->assertJsonCount(ListQueryBase::PER_PAGE, 'results')
            ->assertJsonPath('results.0.text', 'Project result 01 (Shared search entity)')
            ->assertJsonMissing(['text' => sprintf('Project result %02d (Shared search entity)', $total)]);

        $this->getJson(route('projects.options', ['q' => sprintf('Project result %02d', $total)]))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.text', sprintf('Project result %02d (Shared search entity)', $total));
    }

    public function test_project_options_only_include_active_projects(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create(['name' => 'Active select project']);
        Project::factory()->for($entity)->archived()->create(['name' => 'Inactive select project']);
        Project::factory()->for($entity)->trashed()->create(['name' => 'Deleted select project']);

        $this->getJson(route('projects.options', ['q' => 'select project']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.text', 'Active select project ('.$entity->name.')');
    }
}
