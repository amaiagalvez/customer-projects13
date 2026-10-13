<?php

namespace CustomerProjects13\Tests\Feature\Projects;

use App\Models\User;
use Basics13\Support\Validation\MaxLength;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class ProjectInputValidationTest extends TestCase
{
    use RefreshDatabase;

    private const START_DATE = '2026-10-01';

    private const OPEN_ENDED_PROJECT = 'Open-ended project';

    public function test_name_start_date_and_customer_are_required(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name', 'start_date', 'customer_id']);
    }

    public function test_project_name_must_have_more_than_three_characters(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->followingRedirects()
            ->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Abc',
                'start_date' => self::START_DATE,
                'customer_id' => $customer->id,
            ])
            ->assertSee(__('validation.min.string', [
                'attribute' => __('Name'),
                'min' => 4,
            ]));

        $this->assertDatabaseMissing('PRO_projects', ['name' => 'Abc']);
    }

    public function test_project_name_cannot_exceed_the_configured_maximum_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => str_repeat('a', MaxLength::string() + 1),
                'start_date' => self::START_DATE,
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_project_name_must_be_a_string_over_http(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => ['Ane Bezeroa'],
                'start_date' => self::START_DATE,
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_project_name_must_be_unique(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => $project->name,
                'start_date' => self::START_DATE,
                'customer_id' => $project->customer_id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_projects', 1);
    }

    public function test_store_trims_project_name_before_unique_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $existingProject = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => '  Existing project  ',
                'start_date' => self::START_DATE,
                'customer_id' => $existingProject->customer_id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_projects', 1);
    }

    public function test_project_name_can_be_kept_when_updating_the_same_project(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => self::START_DATE,
            'customer_id' => $project->customer_id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'name' => 'Existing project',
        ]);
    }

    public function test_update_trims_project_name_before_persisting(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['name' => 'Original project']);

        $this->put(route('projects.update', $project), [
            'name' => '  Trimmed project  ',
            'start_date' => self::START_DATE,
            'customer_id' => $project->customer_id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'name' => 'Trimmed project',
        ]);
    }

    public function test_project_name_cannot_be_changed_to_another_projects_name(): void
    {
        $this->actingAs(User::factory()->create());
        $existingProject = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);
        $projectToUpdate = Project::factory()->for($this->customer())->create(['name' => 'Project to update']);

        $this->from(route('projects.index'))
            ->put(route('projects.update', $projectToUpdate), [
                'name' => $existingProject->name,
                'start_date' => self::START_DATE,
                'customer_id' => $projectToUpdate->customer_id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $projectToUpdate->id,
            'name' => 'Project to update',
        ]);
    }

    public function test_project_name_can_be_reused_after_soft_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $deletedProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Deleted project']);
        $customer = Customer::factory()->create();

        $this->post(route('projects.store'), [
            'name' => $deletedProject->name,
            'start_date' => self::START_DATE,
            'customer_id' => $customer->id,
        ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('deleted_project_conflict', [
                'id' => $deletedProject->id,
                'name' => $deletedProject->name,
            ]);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('project-name-conflict')
            ->assertSee('project-conflict-create-new')
            ->assertSee('project-conflict-restore')
            ->assertSee('aria-labelledby="project-name-conflict-heading"', false)
            ->assertSee('id="project-name-conflict-heading"', false)
            ->assertSee(__('A deleted record already uses the name :name.', ['name' => $deletedProject->name]));

        $this->post(route('projects.store'), [
            'name' => $deletedProject->name,
            'start_date' => self::START_DATE,
            'customer_id' => $customer->id,
            'reuse_deleted_name' => '1',
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'name' => 'Deleted project',
            'deleted_at' => null,
        ]);
        $this->assertSoftDeleted($deletedProject);
    }

    public function test_project_can_be_updated_to_a_name_used_only_by_a_deleted_project(): void
    {
        $this->actingAs(User::factory()->create());
        $existingProject = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);
        $deletedProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Deleted project']);

        $this->put(route('projects.update', $existingProject), [
            'name' => $deletedProject->name,
            'start_date' => self::START_DATE,
            'customer_id' => $existingProject->customer_id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $existingProject->id,
            'name' => 'Deleted project',
            'deleted_at' => null,
        ]);
        $this->assertSoftDeleted($deletedProject);
    }

    public function test_an_active_project_name_cannot_be_reused_by_another_active_project(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create(['name' => 'Existing project']);

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Existing project',
                'start_date' => self::START_DATE,
                'customer_id' => $project->customer_id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['name']);

        $this->assertDatabaseCount('PRO_projects', 1);
    }

    public function test_project_can_be_created_without_an_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->post(route('projects.store'), [
            'name' => self::OPEN_ENDED_PROJECT,
            'start_date' => self::START_DATE,
            'end_date' => '',
            'customer_id' => $customer->id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'name' => self::OPEN_ENDED_PROJECT,
            'end_date' => null,
            'customer_id' => $customer->id,
        ]);
        $this->assertSame(self::START_DATE, Project::query()->sole()->start_date->format('Y-m-d'));

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(self::OPEN_ENDED_PROJECT);
    }

    public function test_end_date_can_equal_start_date(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->post(route('projects.store'), [
            'name' => 'One-day project',
            'start_date' => self::START_DATE,
            'end_date' => self::START_DATE,
            'customer_id' => $customer->id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', [
            'name' => 'One-day project',
        ]);
        $project = Project::query()->sole();
        $this->assertSame(self::START_DATE, $project->start_date->format('Y-m-d'));
        $this->assertSame(self::START_DATE, $project->end_date->format('Y-m-d'));
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $this->followingRedirects()
            ->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Invalid date range',
                'start_date' => '2026-12-01',
                'end_date' => '2026-11-30',
                'customer_id' => $customer->id,
            ])
            ->assertSee(__('validation.after_or_equal', [
                'attribute' => __('End date'),
                'date' => __('Start date'),
            ]));
    }

    public function test_archived_customers_cannot_be_assigned_to_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->archived()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Project without an active customer',
                'start_date' => self::START_DATE,
                'end_date' => '2026-10-31',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['customer_id']);

        $this->assertDatabaseMissing('PRO_projects', ['name' => 'Project without an active customer']);
    }

    /**
     * The customer a project already belongs to stays valid while editing, even once it has been
     * deactivated, so a form that only renames the project can still be saved.
     */
    public function test_a_project_can_keep_its_archived_customer_while_being_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->archived()->create();
        $project = Project::factory()->for($customer)->create();

        $this->from(route('projects.index'))
            ->put(route('projects.update', $project), [
                'name' => 'Renamed project',
                'start_date' => self::START_DATE,
                'end_date' => '2026-10-31',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'name' => 'Renamed project',
            'customer_id' => $customer->id,
        ]);
    }

    public function test_a_project_cannot_be_moved_to_an_archived_customer(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        $archived = Customer::factory()->archived()->create();

        $this->from(route('projects.index'))
            ->put(route('projects.update', $project), [
                'name' => 'Moved project',
                'start_date' => self::START_DATE,
                'end_date' => '2026-10-31',
                'customer_id' => $archived->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['customer_id']);

        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'customer_id' => $project->customer_id,
        ]);
    }

    public function test_trashed_customers_cannot_be_assigned_to_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->trashed()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Project without an active customer',
                'start_date' => self::START_DATE,
                'end_date' => '2026-10-31',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['customer_id']);
    }
}
