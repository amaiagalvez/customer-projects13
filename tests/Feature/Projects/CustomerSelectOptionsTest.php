<?php

namespace CustomerProjects13\Tests\Feature\Projects;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CustomerSelectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_form_does_not_preload_every_customer(): void
    {
        $this->actingAs(User::factory()->create());

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Customer::factory()->create([
                'name' => sprintf('Option-only customer %02d', $index),
            ]);
        }

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('Option-only customer 21');
    }

    public function test_project_list_offers_form_action_when_no_active_customers_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(__('No active customers are available. Open the customer form to create one.'))
            ->assertSee(__('Open customer form'))
            ->assertSee('href="'.route('customers.index', ['create' => 1]).'"', false)
            ->assertSee('project-no-customer-form-button');
    }
}
