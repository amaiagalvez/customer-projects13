<?php

namespace EntityProjects13\Tests\Feature\Projects;

use App\Models\User;
use entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class EntityFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_trashed_entities_cannot_be_assigned_to_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->trashed()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Project without an active entity',
                'start_date' => '2026-10-31',
                'end_date' => '2026-10-31',
                'entity_id' => $entity->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['entity_id']);
    }

    public function test_a_project_can_keep_its_archived_entity_while_being_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->archived()->create();
        $project = Project::factory()->for($entity)->create();

        $this->from(route('projects.index'))
            ->put(route('projects.update', $project), [
                'name' => 'Renamed project',
                'start_date' => '2026-10-31',
                'end_date' => '2026-10-31',
                'entity_id' => $entity->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'name' => 'Renamed project',
            'entity_id' => $entity->id,
        ]);
    }

    public function test_a_project_cannot_be_moved_to_an_archived_entity(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->entity())->create();
        $archived = Entity::factory()->archived()->create();

        $this->from(route('projects.index'))
            ->put(route('projects.update', $project), [
                'name' => 'Moved project',
                'start_date' => '2026-10-31',
                'end_date' => '2026-10-31',
                'entity_id' => $archived->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['entity_id']);

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'entity_id' => $project->entity_id,
        ]);
    }
}
