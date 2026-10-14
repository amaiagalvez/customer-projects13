<?php

namespace CustomerProjects13\Tests\Browser\Customers;

use CustomerProjects13\Tests\DuskTestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Projects13\Models\Project;

/**
 * The archive button only replaces the delete button when the customer owns projects, which only
 * the bridge knows. That is why this coverage lives here and not in the customers13 browser suite.
 */
class CustomerArchiveTest extends DuskTestCase
{
    private const CUSTOMER_CONFIRM_SUBMIT_SELECTOR = 'dialog[open] [data-test="customer-confirm-submit"]';

    private const CUSTOMER_STATUS_SELECTOR = '[data-test="customer-status"]';

    use DatabaseMigrations;

    public function test_a_customer_with_projects_is_archived_instead_of_deleted(): void
    {
        $user = $this->user();
        $customer = Customer::factory()->create(['name' => 'Customer with projects']);
        Project::factory()->for($customer)->create();

        $this->browse(function (Browser $browser) use ($user, $customer): void {
            $browser->loginAs($user)
                ->visit('/customers')
                ->assertDontSee("[data-test='customer-delete-{$customer->id}']")
                ->click("[data-test='customer-archive-{$customer->id}']")
                ->waitFor(self::CUSTOMER_CONFIRM_SUBMIT_SELECTOR)
                ->click(self::CUSTOMER_CONFIRM_SUBMIT_SELECTOR)
                ->waitFor(self::CUSTOMER_STATUS_SELECTOR)
                ->assertSee(__('basics13::messages.archived'))
                ->assertDontSee($customer->name);
        });

        $this->assertFalse($customer->refresh()->active);
        $this->assertNotSoftDeleted($customer);
    }

    public function test_an_archived_customer_returns_to_the_active_list(): void
    {
        $user = $this->user();
        $customer = Customer::factory()->archived()->create(['name' => 'Archived customer']);
        Project::factory()->for($customer)->create();

        $this->browse(function (Browser $browser) use ($user, $customer): void {
            $browser->loginAs($user)
                ->visit('/customers/archived')
                ->assertSee($customer->name)
                ->click("[data-test='customer-activate-{$customer->id}']")
                ->waitFor(self::CUSTOMER_CONFIRM_SUBMIT_SELECTOR)
                ->click(self::CUSTOMER_CONFIRM_SUBMIT_SELECTOR)
                ->waitFor(self::CUSTOMER_STATUS_SELECTOR)
                ->assertSee(__('basics13::messages.activated'));
        });

        $this->assertTrue($customer->refresh()->active);
    }
}
