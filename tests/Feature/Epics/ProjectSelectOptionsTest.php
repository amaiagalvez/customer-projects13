<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Projects13\Models\Project;

class ProjectSelectOptionsTest extends TestCase
{
    public function test_project_options_search_by_project_or_customer_and_are_limited_to_the_shared_page_size(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'Shared search customer']);

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Project::factory()->for($customer)->create([
                'name' => sprintf('Project result %02d', $index),
            ]);
        }

        $response = $this->getJson(route('projects.options', ['q' => 'Shared search customer']));

        $response->assertOk()
            ->assertJsonCount(ListQueryBase::PER_PAGE, 'results')
            ->assertJsonPath('results.0.text', 'Project result 01 (Shared search customer)')
            ->assertJsonMissing(['text' => sprintf('Project result %02d (Shared search customer)', $total)]);

        $this->getJson(route('projects.options', ['q' => sprintf('Project result %02d', $total)]))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.text', sprintf('Project result %02d (Shared search customer)', $total));
    }

    public function test_project_options_only_include_active_projects(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();
        Project::factory()->for($customer)->create(['name' => 'Active select project']);
        Project::factory()->for($customer)->archived()->create(['name' => 'Inactive select project']);
        Project::factory()->for($customer)->trashed()->create(['name' => 'Deleted select project']);

        $this->getJson(route('projects.options', ['q' => 'select project']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.text', 'Active select project ('.$customer->name.')');
    }
}
