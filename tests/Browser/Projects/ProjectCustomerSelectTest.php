<?php

namespace CustomerProjects13\Tests\Browser\Projects;

use CustomerProjects13\Tests\DuskTestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Project;

class ProjectCustomerSelectTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const OPEN_DIALOG = 'dialog[open]';

    public function test_no_active_customers_action_opens_the_customer_form(): void
    {
        $user = $this->user();
        Customer::factory()->archived()->create();
        Customer::factory()->trashed()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->click('[data-test="project-no-customer-form-button"]')
                ->assertPathIs('/customers')
                ->waitFor(self::OPEN_DIALOG)
                ->assertSeeIn(self::OPEN_DIALOG, __('New customer'))
                ->assertVisible('dialog[open] [data-test="customer-name"]')
                ->assertMissing('dialog[open] [data-test="project-name"]');
        });
    }

    public function test_project_customer_select_can_search_and_create_customers(): void
    {
        $user = $this->user();
        $executionId = Str::uuid()->toString();
        $existingCustomerName = 'Northwind '.$executionId;
        $newCustomerName = 'Dusk Select2 '.$executionId;
        $projectName = 'Select2 project '.$executionId;
        Customer::factory()->create(['name' => $existingCustomerName]);

        $this->browse(function (Browser $browser) use (
            $user,
            $existingCustomerName,
            $newCustomerName,
            $projectName,
        ): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->waitUntil('typeof window.jQuery?.fn?.select2 === "function"', 10)
                ->click('[data-test="project-create-button"]')
                ->waitFor(self::OPEN_DIALOG)
                ->assertScript(
                    'document.querySelector("#project-customer-id").classList.contains("select2-hidden-accessible")',
                    true,
                )
                ->click('#select2-project-customer-id-container')
                ->type('dialog[open] .select2-container--open .select2-search__field', 'Northwind')
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode($existingCustomerName).')',
                    10,
                )
                ->assertSeeIn('dialog[open] .select2-results', $existingCustomerName)
                ->click('dialog[open] .select2-results__option--selectable')
                ->waitUntil('document.querySelector(\'[data-test="project-submit"]\').disabled === false', 10)
                ->assertEnabled('[data-test="project-submit"]')
                ->click('#select2-project-customer-id-container')
                ->type(
                    'dialog[open] .select2-container--open .select2-search__field',
                    $newCustomerName,
                )
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode(__('Create customer').': '.$newCustomerName).')',
                    10,
                )
                ->assertSeeIn(
                    'dialog[open] .select2-results',
                    __('Create customer').': '.$newCustomerName,
                )
                ->click('dialog[open] .select2-results__option--selectable')
                ->waitUntil(
                    'document.querySelector("#project-customer-id option:checked")?.text === '.json_encode($newCustomerName),
                    10,
                )
                ->type('dialog[open] [data-test="project-name"]', $projectName);

            $browser->script('const input = document.querySelector(\'dialog[open] [data-test="project-start-date"]\'); input.value = "2026-10-01"; input.dispatchEvent(new Event("input", { bubbles: true })); input.dispatchEvent(new Event("change", { bubbles: true }));');

            $browser
                ->click('dialog[open] [data-test="project-submit"]')
                ->waitFor('[data-test="project-status"]')
                ->visit('/projects?search='.urlencode($projectName))
                ->waitUntil(
                    '(() => { const element = document.querySelector(\'tbody time[data-local-datetime="date"]\'); return element && element.textContent.trim() === window.formatLocalDateTime(element.dateTime, "date"); })()',
                    10,
                )
                ->assertScript(
                    'document.querySelector(\'tbody time[data-local-datetime="date"]\').dateTime',
                    '2026-10-01',
                )
                ->assertScript(
                    '!document.querySelector(\'tbody time[data-local-datetime="date"]\').textContent.trim().includes(":")',
                    true,
                );
        });

        $customer = Customer::query()->where('name', $newCustomerName)->firstOrFail();

        $this->assertDatabaseHas('PRO_projects', [
            'name' => $projectName,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_edit_keeps_its_archived_customer_selected_and_only_offers_active_options(): void
    {
        $user = $this->user();
        $archivedCustomer = Customer::factory()->archived()->create(['name' => 'Inactive selected customer']);
        $activeCustomer = Customer::factory()->create(['name' => 'Active option customer']);
        $otherInactiveCustomer = Customer::factory()->archived()->create(['name' => 'Other archived customer']);
        $project = Project::factory()->for($archivedCustomer)->create(['name' => 'Project with archived customer']);

        $this->browse(function (Browser $browser) use (
            $user,
            $archivedCustomer,
            $activeCustomer,
            $otherInactiveCustomer,
            $project,
        ): void {
            $browser->loginAs($user)
                ->visit('/projects?search='.urlencode($project->name))
                ->click('[data-test="project-edit-'.$project->id.'"]')
                ->waitFor(self::OPEN_DIALOG.' [data-test="project-name"]')
                ->assertSelected(self::OPEN_DIALOG.' #project-customer-id', (string) $archivedCustomer->id)
                ->assertSeeIn(
                    self::OPEN_DIALOG.' #select2-project-customer-id-container',
                    $archivedCustomer->name,
                )
                ->assertSeeIn(
                    self::OPEN_DIALOG.' #select2-project-customer-id-container',
                    __('basics13::messages.archived_label'),
                )
                ->click('#select2-project-customer-id-container')
                ->type(self::OPEN_DIALOG.' .select2-container--open .select2-search__field', 'option customer')
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode($activeCustomer->name).')',
                    10,
                )
                ->assertSeeIn(self::OPEN_DIALOG.' .select2-results', $activeCustomer->name)
                ->assertSeeIn(self::OPEN_DIALOG.' .select2-results', $archivedCustomer->name)
                ->assertSeeIn(
                    self::OPEN_DIALOG.' .select2-results',
                    __('basics13::messages.archived_label'),
                )
                ->assertDontSeeIn(self::OPEN_DIALOG.' .select2-results', $otherInactiveCustomer->name)
                ->assertSelected(self::OPEN_DIALOG.' #project-customer-id', (string) $archivedCustomer->id)
                ->click('#select2-project-customer-id-result-'.$activeCustomer->id)
                ->assertSelected(self::OPEN_DIALOG.' #project-customer-id', (string) $activeCustomer->id)
                ->assertDontSeeIn(
                    self::OPEN_DIALOG.' #select2-project-customer-id-container',
                    __('basics13::messages.archived_label'),
                );
        });
    }
}
