<?php

namespace CustomerProjects13\Tests\Feature;

use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Customers13\Queries\Customers\CustomerListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Queries\Epics\EpicListQuery;
use Projects13\Transformers\EpicListTransformer;

class CustomerProjectEpicIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_with_project_and_epic_shows_correct_counts(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($customer)->create(['name' => 'Website renewal', 'active' => true]);
        Epic::factory()->for($project)->create(['name' => 'First slice', 'active' => true]);

        $query = app(CustomerListQuery::class);
        $record = $query->active('')->items()[0];

        $this->assertSame(1, (int) $record->getAttribute('projects_count'));
        $this->assertSame(1, (int) $record->getAttribute('epics_count'));
    }

    public function test_customer_search_by_project_name(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($customer)->create(['name' => 'Website renewal']);
        Epic::factory()->for($project)->create(['name' => 'First slice']);

        $this->get(route('customers.index', ['search' => 'Website renewal']))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_epic_shows_customer(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['name' => 'North client']);
        $project = Project::factory()->for($customer)->create(['name' => 'Website renewal']);
        $epic = Epic::factory()->for($project)->create(['name' => 'First slice']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('North client');
    }

    public function test_customer_can_have_multiple_projects(): void
    {
        $this->signIn();
        $customer = Customer::factory()->create(['name' => 'North client']);

        Project::factory()->for($customer)->create(['name' => 'Project A']);
        Project::factory()->for($customer)->create(['name' => 'Project B']);

        $query = app(CustomerListQuery::class);
        $all = $query->active('');

        $this->assertSame(1, $all->total());
        $this->assertSame([$customer->id], $all->pluck('id')->all());
        $this->assertSame(2, (int) $all->items()[0]->getAttribute('projects_count'));
    }

    public function test_epic_with_project(): void
    {
        $this->signIn();
        $customer1 = Customer::factory()->create(['name' => 'Customer A']);
        $customer2 = Customer::factory()->create(['name' => 'Customer B']);

        $project1 = Project::factory()->for($customer1)->create(['name' => 'Project A']);
        $project2 = Project::factory()->for($customer2)->create(['name' => 'Project B']);

        $epic1 = Epic::factory()->for($project1)->create(['name' => 'Epic under customer A']);
        $epic2 = Epic::factory()->for($project2)->create(['name' => 'Epic under customer B']);

        $list = app(EpicListTransformer::class)->active(
            app(EpicListQuery::class)->active(''),
            '',
        );
        $this->assertIsArray($list['rows']);
        $rows = collect($list['rows'])->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertSame('Project A', $rows[$epic1->id]['project']);
        $this->assertSame('Customer A', $rows[$epic1->id]['customer']);
        $this->assertSame('Project B', $rows[$epic2->id]['project']);
        $this->assertSame('Customer B', $rows[$epic2->id]['customer']);
    }
}
