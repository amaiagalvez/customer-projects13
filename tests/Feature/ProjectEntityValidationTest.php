<?php

namespace EntityProjects13\Tests\Feature;

use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

/**
 * The acceptance cases the integration owes on its own: what the field may be, and when a entity
 * may no longer be chosen.
 */
class ProjectEntityValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_and_entity_required_fields_are_validated_together(): void
    {
        $this->signIn();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name', 'start_date', 'entity_id']);

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_base_validation_still_rejects_invalid_input_with_a_valid_entity(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Abc',
                'start_date' => '2026-10-01',
                'end_date' => '2026-09-30',
                'entity_id' => $entity->id,
            ])->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name', 'end_date'])
            ->assertSessionDoesntHaveErrors('entity_id');

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_a_project_cannot_be_created_without_a_entity(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
        ])->assertSessionHasErrors('entity_id');

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_a_entity_id_that_is_not_an_integer_is_rejected(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'entity_id' => 'not-an-integer',
        ])->assertSessionHasErrors('entity_id');
    }

    public function test_a_entity_that_does_not_exist_is_rejected(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'entity_id' => 999999,
        ])->assertSessionHasErrors('entity_id');
    }

    public function test_a_soft_deleted_entity_is_rejected(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        $entity->delete();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'entity_id' => $entity->id,
        ])->assertSessionHasErrors('entity_id');
    }

    public function test_an_archived_entity_is_rejected_when_creating(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['active' => false]);

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'entity_id' => $entity->id,
        ])->assertSessionHasErrors('entity_id');
    }

    public function test_an_archived_entity_is_accepted_when_the_project_already_belongs_to_it(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create(['active' => false]);
        $project = Project::factory()->for($entity)->create(['name' => 'Website renewal']);

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'entity_id' => $entity->id,
        ])->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', __('basics13::messages.updated'));

        $this->assertDatabaseHas('PRO_projects', ['id' => $project->id, 'entity_id' => $entity->id]);
    }

    public function test_changing_to_another_archived_entity_is_rejected(): void
    {
        $this->signIn();
        $current = Entity::factory()->create();
        $archived = Entity::factory()->create(['active' => false]);
        $project = Project::factory()->for($current)->create(['name' => 'Website renewal']);

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'entity_id' => $archived->id,
        ])->assertSessionHasErrors('entity_id');

        $this->assertDatabaseHas('PRO_projects', ['id' => $project->id, 'entity_id' => $current->id]);
    }

    public function test_the_entity_is_written_on_create_and_on_change(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        $other = Entity::factory()->create();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'entity_id' => $entity->id,
        ])->assertRedirect(route('projects.index'));

        $project = Project::query()->where('name', 'Website renewal')->firstOrFail();
        $this->assertSame($entity->id, $project->getAttribute('entity_id'));

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'entity_id' => $other->id,
        ])->assertRedirect(route('projects.index'));

        $this->assertSame($other->id, $project->refresh()->getAttribute('entity_id'));
    }

    public function test_the_error_names_the_field_the_user_sees(): void
    {
        $this->signIn();

        $response = $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('entity_id');
        $response->assertSessionHasErrors([
            'entity_id' => __('validation.required', ['attribute' => __('Entity')]),
        ]);
    }
}
