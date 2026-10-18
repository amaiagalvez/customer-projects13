<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class TimelineTest extends TestCase
{
    public function test_an_active_project_of_an_archived_entity_still_shows_up(): void
    {
        $entity = Entity::factory()->archived()->create(['name' => 'Sleeping entity']);
        Project::factory()->for($entity)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping entity');
    }

    public function test_searching_by_entity_name_shows_its_projects_and_their_epics(): void
    {
        $entity = Entity::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($entity)->create(['name' => 'Warehouse move']);
        Epic::factory()->for($project)->create(['name' => 'Shelf audit']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move')
            ->assertSee('Shelf audit');
    }
}
