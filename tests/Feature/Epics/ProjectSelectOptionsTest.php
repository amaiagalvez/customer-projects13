<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use Basics13\Support\Validation\MaxLength;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

class ProjectSelectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_epic_list_offers_project_form_when_no_projects_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertViewHas('hasProjects', false)
            ->assertSee(__('No active projects are available. Open the project form to create one.'))
            ->assertSee(__('Open project form'))
            ->assertSee('href="'.route('projects.index', ['create' => 1]).'"', false)
            ->assertSee('epic-no-project-form-button');
    }

    public function test_epic_list_offers_project_form_when_only_archived_projects_exist(): void
    {
        $this->actingAs(User::factory()->create());
        Project::factory()->for($this->customer())->archived()->create();

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertViewHas('hasProjects', false)
            ->assertSee('epic-no-project-form-button');
    }

    public function test_epic_list_offers_project_form_when_only_deleted_projects_exist(): void
    {
        $this->actingAs(User::factory()->create());
        Project::factory()->for($this->customer())->trashed()->create();

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertViewHas('hasProjects', false)
            ->assertSee('epic-no-project-form-button');
    }

    public function test_epic_list_hides_project_form_notice_when_an_active_project_exists(): void
    {
        $this->actingAs(User::factory()->create());
        Project::factory()->for($this->customer())->create();

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertViewHas('hasProjects', true)
            ->assertDontSee('epic-no-project-form-button');
    }

    public function test_epic_form_does_not_preload_every_project(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create();

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Project::factory()->for($customer)->create([
                'name' => sprintf('Option-only project %02d', $index),
            ]);
        }

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertDontSee('Option-only project 21');
    }

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

    public function test_project_options_reject_search_terms_over_the_configured_maximum(): void
    {
        $this->actingAs(User::factory()->create());

        $term = str_repeat('a', MaxLength::string() + 1);

        $this->getJson(route('projects.options', ['q' => $term]))
            ->assertJsonValidationErrors('q');
    }

    public function test_guests_are_redirected_when_requesting_project_options(): void
    {
        $this->get(route('projects.options'))
            ->assertRedirect(route('login'));
    }
}
