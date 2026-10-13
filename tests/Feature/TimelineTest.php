<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class TimelineTest extends TestCase
{
    public function test_an_active_project_of_an_archived_customer_still_shows_up(): void
    {
        $customer = Customer::factory()->archived()->create(['name' => 'Sleeping customer']);
        Project::factory()->for($customer)->create(['name' => 'Still running']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline'))
            ->assertOk()
            ->assertSee('Still running')
            ->assertSee('Sleeping customer');
    }

    public function test_searching_by_customer_name_shows_its_projects_and_their_epics(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind Traders']);
        $project = Project::factory()->for($customer)->create(['name' => 'Warehouse move']);
        Epic::factory()->for($project)->create(['name' => 'Shelf audit']);

        $this->actingAs(User::factory()->create())
            ->get(route('timeline', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Warehouse move')
            ->assertSee('Shelf audit');
    }
}
