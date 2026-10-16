<?php

namespace CustomerProjects13\Tests\Feature;

use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

/**
 * The acceptance cases the integration owes on its own: what the field may be, and when a customer
 * may no longer be chosen.
 */
class ProjectCustomerValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_project_cannot_be_created_without_a_customer(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
        ])->assertSessionHasErrors('customer_id');

        $this->assertDatabaseCount('PRO_projects', 0);
    }

    public function test_a_customer_id_that_is_not_an_integer_is_rejected(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'customer_id' => 'not-an-integer',
        ])->assertSessionHasErrors('customer_id');
    }

    public function test_a_customer_that_does_not_exist_is_rejected(): void
    {
        $this->signIn();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'customer_id' => 999999,
        ])->assertSessionHasErrors('customer_id');
    }

    public function test_a_soft_deleted_customer_is_rejected(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create();
        $customer->delete();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'customer_id' => $customer->id,
        ])->assertSessionHasErrors('customer_id');
    }

    public function test_an_archived_customer_is_rejected_when_creating(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['active' => false]);

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'customer_id' => $customer->id,
        ])->assertSessionHasErrors('customer_id');
    }

    public function test_an_archived_customer_is_accepted_when_the_project_already_belongs_to_it(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['active' => false]);
        $project = Project::factory()->for($customer)->create(['name' => 'Website renewal']);

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'customer_id' => $customer->id,
        ])->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', __('basics13::messages.updated'));

        $this->assertDatabaseHas('PRO_projects', ['id' => $project->id, 'customer_id' => $customer->id]);
    }

    public function test_changing_to_another_archived_customer_is_rejected(): void
    {
        $this->signIn();
        $current = Customer::factory()->create();
        $archived = Customer::factory()->create(['active' => false]);
        $project = Project::factory()->for($current)->create(['name' => 'Website renewal']);

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'customer_id' => $archived->id,
        ])->assertSessionHasErrors('customer_id');

        $this->assertDatabaseHas('PRO_projects', ['id' => $project->id, 'customer_id' => $current->id]);
    }

    public function test_the_customer_is_written_on_create_and_on_change(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create();
        $other = Customer::factory()->create();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
            'customer_id' => $customer->id,
        ])->assertRedirect(route('projects.index'));

        $project = Project::query()->where('name', 'Website renewal')->firstOrFail();
        $this->assertSame($customer->id, $project->getAttribute('customer_id'));

        $this->put(route('projects.update', $project), [
            'name' => $project->name,
            'start_date' => '2026-02-01',
            'customer_id' => $other->id,
        ])->assertRedirect(route('projects.index'));

        $this->assertSame($other->id, $project->refresh()->getAttribute('customer_id'));
    }

    public function test_the_error_names_the_field_the_user_sees(): void
    {
        $this->signIn();

        $response = $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-01-01',
        ]);

        $response->assertSessionHasErrors('customer_id');
        $response->assertSessionHasErrors([
            'customer_id' => __('validation.required', ['attribute' => __('Customer')]),
        ]);
    }
}
