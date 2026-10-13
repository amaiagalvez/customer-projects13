<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Support\Validation\MaxLength;
use CustomerProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EpicInputValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_and_project_are_required(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrors(['name', 'project_id'])
            ->assertSessionDoesntHaveErrors(['start_date', 'end_date']);
    }

    public function test_epic_can_be_created_without_dates(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->post(route('epics.store'), [
            'name' => 'Undated epic',
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', [
            'name' => 'Undated epic',
            'start_date' => null,
            'end_date' => null,
        ]);
    }

    public function test_epic_name_must_have_more_than_three_characters(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Abc', 'project_id' => $project->id])
            ->assertSessionHasErrors([
                'name' => __('validation.min.string', [
                    'attribute' => __('validation.attributes')['name'],
                    'min' => 4,
                ]),
            ]);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_epic_name_cannot_exceed_the_configured_maximum_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => str_repeat('a', MaxLength::string() + 1),
                'project_id' => $project->id,
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_epic_name_must_be_a_string_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => ['Ane Bezeroa'],
                'project_id' => $project->id,
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_epic_name_must_be_unique_within_the_project(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create(['name' => 'Existing epic']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Existing epic', 'project_id' => $epic->project_id])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_epics', 1);
    }

    public function test_store_trims_epic_name_before_unique_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $existingEpic = Epic::factory()->create(['name' => 'Existing epic']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => '  Existing epic  ',
                'project_id' => $existingEpic->project_id,
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_epics', 1);
    }

    public function test_epic_name_can_repeat_in_another_project(): void
    {
        $this->actingAs(User::factory()->create());
        Epic::factory()->create(['name' => 'Existing epic']);
        $otherProject = Project::factory()->for($this->customer())->create();

        $this->post(route('epics.store'), ['name' => 'Existing epic', 'project_id' => $otherProject->id])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('PRO_epics', 2);
    }

    public function test_epic_name_cannot_be_changed_to_another_epic_name_in_the_same_project(): void
    {
        $this->actingAs(User::factory()->create());
        $existingEpic = Epic::factory()->create(['name' => 'Existing epic']);
        $existingProject = $existingEpic->project;

        $epicToUpdate = Epic::factory()->for($existingProject)->create(['name' => 'Epic to update']);

        $this->from(route('epics.index'))
            ->put(route('epics.update', $epicToUpdate), [
                'name' => 'Existing epic',
                'project_id' => $epicToUpdate->project_id,
            ])
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseHas('PRO_epics', ['id' => $epicToUpdate->id, 'name' => 'Epic to update']);
    }

    public function test_epic_name_can_be_kept_when_updating_the_same_epic(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create(['name' => 'Existing epic']);

        $this->put(route('epics.update', $epic), [
            'name' => 'Existing epic',
            'project_id' => $epic->project_id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_update_trims_epic_name_before_persisting(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create(['name' => 'Original epic']);

        $this->put(route('epics.update', $epic), [
            'name' => '  Trimmed epic  ',
            'project_id' => $epic->project_id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'name' => 'Trimmed epic',
        ]);
    }

    #[TestWith(['2026-10-01'])]
    #[TestWith(['2026-09-30'])]
    public function test_end_date_must_be_after_start_date(string $endDate): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => 'Invalid dates',
                'start_date' => '2026-10-01',
                'end_date' => $endDate,
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors(['end_date']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_end_date_requires_a_start_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => 'End without start',
                'end_date' => '2026-10-01',
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors(['start_date']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_start_date_can_be_set_without_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->post(route('epics.store'), [
            'name' => 'Open-ended epic',
            'start_date' => '2026-10-01',
            'project_id' => $project->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', ['name' => 'Open-ended epic', 'end_date' => null]);
    }

    public function test_archived_projects_cannot_receive_epics(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->archived()->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Orphan epic', 'project_id' => $project->id])
            ->assertSessionHasErrors(['project_id']);

        $this->assertDatabaseMissing('PRO_epics', ['name' => 'Orphan epic']);
    }

    /**
     * The project an epic already belongs to stays valid while editing, even once it has been
     * deactivated, so a form that only renames the epic can still be saved.
     */
    public function test_an_epic_can_keep_its_archived_project_while_being_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->archived()->create();
        $epic = Epic::factory()->for($project)->create();

        $this->from(route('epics.index'))
            ->put(route('epics.update', $epic), [
                'name' => 'Renamed epic',
                'project_id' => $project->id,
            ])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'name' => 'Renamed epic',
            'project_id' => $project->id,
        ]);
    }

    public function test_an_epic_cannot_be_moved_to_an_archived_project(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();
        $archived = Project::factory()->for($this->customer())->archived()->create();

        $this->from(route('epics.index'))
            ->put(route('epics.update', $epic), [
                'name' => 'Moved epic',
                'project_id' => $archived->id,
            ])
            ->assertSessionHasErrors(['project_id']);

        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'project_id' => $epic->project_id,
        ]);
    }

    /**
     * Epic names are unique per project, so a name already used in another project is free. Without a
     * project_id the rule falls back to global uniqueness, which still reports the name when it is
     * taken anywhere: it cannot claim the name is free inside a project that was never named.
     */
    public function test_a_missing_project_falls_back_to_global_uniqueness(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->create(['name' => 'Existing epic name']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Existing epic name'])
            ->assertSessionHasErrors(['project_id', 'name']);
    }

    /**
     * A project_id that is not a number is rejected on its own, and the name is checked globally
     * rather than against a project that does not exist.
     */
    public function test_a_non_numeric_project_falls_back_to_global_uniqueness(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->create(['name' => 'Existing epic name']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Existing epic name', 'project_id' => 'not-a-number'])
            ->assertSessionHasErrors(['project_id', 'name']);
    }

    /**
     * The name of a trashed epic is free again, so it must not be reported as taken even when the
     * project is missing.
     */
    public function test_a_missing_project_does_not_conflict_with_a_trashed_epic_name(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->trashed()->create(['name' => 'Trashed epic name']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Trashed epic name'])
            ->assertSessionHasErrors(['project_id'])
            ->assertSessionDoesntHaveErrors(['name']);
    }

    public function test_epic_cannot_belong_to_a_deleted_project(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->trashed()->create();

        $this->from(route('epics.index'))
            ->post(route('epics.store'), ['name' => 'Orphan epic', 'project_id' => $project->id])
            ->assertSessionHasErrors(['project_id']);
    }

    public function test_deleted_epic_name_conflict_can_be_resolved_by_creating_a_new_epic(): void
    {
        $this->actingAs(User::factory()->create());
        $deletedEpic = Epic::factory()->trashed()->create(['name' => 'Deleted epic']);
        $payload = ['name' => 'Deleted epic', 'project_id' => $deletedEpic->project_id];

        $this->post(route('epics.store'), $payload)
            ->assertRedirect(route('epics.index'))
            ->assertSessionHas('deleted_epic_conflict', ['id' => $deletedEpic->id, 'name' => 'Deleted epic']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('epic-name-conflict')
            ->assertSee('epic-conflict-create-new')
            ->assertSee('epic-conflict-restore')
            ->assertSee('aria-labelledby="epic-name-conflict-heading"', false)
            ->assertSee('id="epic-name-conflict-heading"', false);

        $this->assertDatabaseCount('PRO_epics', 1);

        $this->post(route('epics.store'), [...$payload, 'reuse_deleted_name' => '1'])
            ->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', ['name' => 'Deleted epic', 'deleted_at' => null]);
        $this->assertSoftDeleted($deletedEpic);
    }

    public function test_epic_start_date_cannot_be_before_project_start_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01']);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => 'Epic before project',
                'start_date' => '2026-05-01',
                'end_date' => '2026-07-01',
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors(['start_date']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_epic_start_date_can_equal_project_start_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01', 'end_date' => '2026-12-31']);

        $this->post(route('epics.store'), [
            'name' => 'Epic at project start',
            'start_date' => '2026-06-01',
            'end_date' => '2026-07-01',
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', ['name' => 'Epic at project start']);
    }

    public function test_epic_end_date_cannot_be_after_project_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create([
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-31',
        ]);

        $this->from(route('epics.index'))
            ->post(route('epics.store'), [
                'name' => 'Epic after project',
                'start_date' => '2026-11-01',
                'end_date' => '2027-01-01',
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors(['end_date']);

        $this->assertDatabaseCount('PRO_epics', 0);
    }

    public function test_epic_end_date_can_equal_project_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01', 'end_date' => '2026-12-31']);

        $this->post(route('epics.store'), [
            'name' => 'Epic at project end',
            'start_date' => '2026-11-01',
            'end_date' => '2026-12-31',
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', ['name' => 'Epic at project end']);
    }

    public function test_epic_end_date_not_validated_when_project_has_no_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01', 'end_date' => null]);

        $this->post(route('epics.store'), [
            'name' => 'Epic in open-ended project',
            'start_date' => '2026-07-01',
            'end_date' => '2027-06-01',
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', ['name' => 'Epic in open-ended project']);
    }

    public function test_epic_dates_must_be_within_project_when_updating(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
        $epic = Epic::factory()->for($project)->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
        ]);

        $this->from(route('epics.index'))
            ->put(route('epics.update', $epic), [
                'name' => $epic->name,
                'start_date' => '2026-05-01', // Before project start
                'end_date' => '2026-08-01',
                'project_id' => $project->id,
            ])
            ->assertSessionHasErrors(['start_date']);
    }

    public function test_epic_can_be_updated_to_remove_dates(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['start_date' => '2026-06-01', 'end_date' => '2026-12-31']);
        $epic = Epic::factory()->for($project)->create([
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-01',
        ]);

        $this->put(route('epics.update', $epic), [
            'name' => $epic->name,
            'project_id' => $project->id,
            'start_date' => null,
            'end_date' => null,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'start_date' => null,
            'end_date' => null,
        ]);
    }
}
