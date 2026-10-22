<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_project_of_an_archived_entity_still_shows_up(): void
    {
        $entity = Entity::factory()->archived()->create(['name' => 'Sleeping entity']);
        Project::factory()->for($entity)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping entity');
    }

    public function test_searching_finds_a_record_by_the_name_of_the_record_that_owns_it(): void
    {
        $entity = Entity::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($entity)->create(['name' => 'Warehouse move']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('planning', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move');
    }
}
