<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Projects13\Models\Project;

class PlanningTest extends TestCase
{
    public function test_an_active_project_of_an_archived_customer_still_shows_up(): void
    {
        $customer = Customer::factory()->archived()->create(['name' => 'Sleeping customer']);
        Project::factory()->for($customer)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping customer');
    }

    public function test_searching_finds_a_record_by_the_name_of_the_record_that_owns_it(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($customer)->create(['name' => 'Warehouse move']);

        $this->actingAs(User::factory()->create())
            ->get(route('planning', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move');
    }
}
