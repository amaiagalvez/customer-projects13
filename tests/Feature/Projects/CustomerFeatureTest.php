<?php

namespace CustomerProjects13\Tests\Feature\Projects;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class CustomerFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_trashed_customers_cannot_be_assigned_to_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->trashed()->create();

        $this->from(route('projects.index'))
            ->post(route('projects.store'), [
                'name' => 'Project without an active customer',
                'start_date' => '2026-10-31',
                'end_date' => '2026-10-31',
                'customer_id' => $customer->id,
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHasErrors(['customer_id']);
    }

    public function test_a_project_can_keep_its_archived_customer_while_being_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->archived()->create();
        $project = Project::factory()->for($customer)->create();

        $this->from(route('projects.index'))
            ->put(route('projects.update', $project), [
                'name' => 'Renamed project',
                'start_date' => '2026-10-31',
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
                'start_date' => '2026-10-31',
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
}
