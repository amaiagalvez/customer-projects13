<?php

namespace CustomerProjects13\Tests\Feature\Projects;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class ProjectTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_trash_preserves_index_columns_without_deletion_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-02 12:00:00');
        $project = Project::factory()->for($this->customer())->create([
            'name' => 'Project with dates',
            'start_date' => '2026-01-03',
            'end_date' => '2026-08-04',
        ]);
        $customer = $project->customer;

        $activeResponse = $this->get(route('projects.index'));
        $project->delete();

        $response = $this->get(route('projects.trash.index'));

        $response->assertSeeInOrder([
            '<thead',
            __('Name'),
            __('Customer'),
            __('Start date'),
            __('End date'),
            __('Epics'),
            __('Comments'),
            __('Actions'),
        ], false)->assertSeeInOrder(['Project with dates', $customer->name, '2026-01-03', '2026-08-04']);
    }

    public function test_deleted_projects_can_be_restored_or_permanently_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $activeProject = Project::factory()->for($this->customer())->create(['name' => 'Active project']);
        $deletedProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Deleted project']);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Active project')
            ->assertDontSee('Deleted project');

        $this->get(route('projects.trash.index'))
            ->assertOk()
            ->assertSee('Deleted project')
            ->assertDontSee('Active project');

        $this->patch(route('projects.trash.restore', $deletedProject->id))
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas(
                'status',
                __('basics13::messages.restored'),
            );
        $this->assertNotSoftDeleted($deletedProject);

        $this->delete(route('projects.destroy', $activeProject))
            ->assertRedirect(route('projects.index'));
        $this->delete(route('projects.trash.destroy', $activeProject->id))
            ->assertRedirect(route('projects.trash.index'));
        $this->assertDatabaseMissing('PRO_projects', ['id' => $activeProject->id]);

        $this->delete(route('projects.destroy', $deletedProject))
            ->assertRedirect(route('projects.index'));
        $this->delete(route('projects.trash.destroy', $deletedProject->id))
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas('status', __('basics13::messages.permanently_deleted'));
        $this->assertDatabaseMissing('PRO_projects', ['id' => $deletedProject->id]);
    }

    public function test_active_project_is_not_found_through_trash_actions(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->patch(route('projects.trash.restore', $project->id))
            ->assertNotFound();

        $this->delete(route('projects.trash.destroy', $project->id))
            ->assertNotFound();

        $this->assertModelExists($project);
    }

    public function test_deleted_project_with_epics_cannot_be_permanently_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->trashed()->create();
        Epic::factory()->for($project)->create();

        $this->delete(route('projects.trash.destroy', $project->id))
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas('error', __('basics13::messages.cannot_force_delete_related'));

        $this->assertSoftDeleted($project);
    }

    public function test_project_model_cannot_be_force_deleted_while_it_has_trashed_epics(): void
    {
        $project = Project::factory()->for($this->customer())->trashed()->create();
        $epic = Epic::factory()->for($project)->trashed()->create();

        $this->assertFalse($project->forceDelete());

        $this->assertSoftDeleted($project);
        $this->assertSoftDeleted($epic);
    }

    public function test_project_restore_conflict_resolution_reports_that_no_duplicate_was_created(): void
    {
        $this->actingAs(User::factory()->create());
        $deletedProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Restorable project']);

        $this->patch(route('projects.trash.restore', $deletedProject->id), [
            'resolve_name_conflict' => '1',
        ])
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas(
                'status',
                __('basics13::messages.restored_no_new_record'),
            );

        $this->assertDatabaseCount('PRO_projects', 1);
        $this->assertNotSoftDeleted($deletedProject);
    }

    public function test_trash_lists_most_recently_deleted_projects_first(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-02 12:00:00');
        $olderProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Older deleted project']);
        $newerProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Newer deleted project']);
        $tiedProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Tied deleted project']);
        $olderProject->forceFill(['deleted_at' => now()->subHour()])->saveQuietly();

        $response = $this->get(route('projects.trash.index'));

        $response->assertSeeInOrder([
            $newerProject->name,
            $tiedProject->name,
            $olderProject->name,
        ]);
    }

    public function test_trash_can_be_searched_by_project_name(): void
    {
        $this->actingAs(User::factory()->create());
        $matchingProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Archived project match']);
        $otherProject = Project::factory()->for($this->customer())->trashed()->create(['name' => 'Unrelated archived project']);

        $this->get(route('projects.trash.index', ['search' => 'project match']))
            ->assertOk()
            ->assertSee($matchingProject->name)
            ->assertDontSee($otherProject->name);
    }

    /**
     * The trash query searches the customer name too, so a trashed project is reachable from its
     * customer even when its own name does not match.
     */
    public function test_trash_can_be_searched_by_customer_name(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'Archived customer match']);
        $matchingProject = Project::factory()->for($customer)->trashed()->create(['name' => 'First archived project']);
        $otherCustomer = Customer::factory()->create(['name' => 'Other customer']);
        $otherProject = Project::factory()->for($otherCustomer)->trashed()->create(['name' => 'Second archived project']);

        $this->get(route('projects.trash.index', ['search' => 'Archived customer match']))
            ->assertOk()
            ->assertSee($matchingProject->name)
            ->assertDontSee($otherProject->name);
    }

    public function test_project_cannot_be_restored_when_an_active_project_uses_its_name(): void
    {
        $this->actingAs(User::factory()->create());
        $deletedProject = Project::factory()->for($this->customer())->trashed()->create([
            'name' => 'Repeated project name',
        ]);
        $activeProject = Project::factory()->for($this->customer())->create(['name' => 'Repeated project name']);

        $this->patch(route('projects.trash.restore', $deletedProject->id))
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas(
                'error',
                __('basics13::messages.cannot_restore_name_taken'),
            );

        $this->get(route('projects.trash.index'))
            ->assertSee(__('basics13::messages.cannot_restore_name_taken'));

        $this->assertModelExists($activeProject);
        $this->assertSoftDeleted($deletedProject);
    }

    public function test_project_cannot_be_restored_when_an_archived_project_uses_its_name(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        $deletedProject = Project::factory()->for($customer)->trashed()->create([
            'name' => 'Repeated project name',
        ]);
        $archivedProject = Project::factory()->for($customer)->archived()->create([
            'name' => 'Repeated project name',
        ]);

        $this->patch(route('projects.trash.restore', $deletedProject->id))
            ->assertRedirect(route('projects.trash.index'))
            ->assertSessionHas(
                'error',
                __('basics13::messages.cannot_restore_name_taken'),
            );

        $this->assertSoftDeleted($deletedProject);
        $this->assertModelExists($archivedProject);
        $this->assertFalse($archivedProject->active);
    }
}
