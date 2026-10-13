<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EpicTrashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_trash_preserves_index_columns_without_deletion_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-02 12:00:00');
        $customer = Customer::factory()->create();
        $project = Project::factory()->for($customer)->create();
        $epic = Epic::factory()->for($project)->create([
            'name' => 'Epic with dates',
            'start_date' => '2026-01-03',
            'end_date' => '2026-08-04',
        ]);

        $activeResponse = $this->get(route('epics.index'));
        $epic->delete();

        $response = $this->get(route('epics.trash.index'));

        $response->assertSeeInOrder([
            '<thead',
            __('Name'),
            __('Project'),
            __('Customer'),
            __('Start date'),
            __('End date'),
            __('Comments'),
            __('Actions'),
        ], false)->assertSeeInOrder([
            'Epic with dates',
            $project->name,
            $customer->name,
            '2026-01-03',
            '2026-08-04',
        ]);
        $response->assertSee('data-test="epic-comments-count-'.$epic->id.'"></span>', false);
    }

    public function test_trash_can_be_searched_by_project_and_customer_names(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'Archived epic customer']);
        $project = Project::factory()->for($customer)->create(['name' => 'Archived epic project']);
        $matchingEpic = Epic::factory()->for($project)->trashed()->create(['name' => 'First archived epic']);
        $otherCustomer = Customer::factory()->create(['name' => 'Other customer']);
        $otherProject = Project::factory()->for($otherCustomer)->create(['name' => 'Other project']);
        $otherEpic = Epic::factory()->for($otherProject)->trashed()->create(['name' => 'Second archived epic']);

        foreach (['Archived epic customer', 'Archived epic project'] as $search) {
            $this->get(route('epics.trash.index', ['search' => $search]))
                ->assertOk()
                ->assertSee($matchingEpic->name)
                ->assertDontSee($otherEpic->name);
        }
    }
}
