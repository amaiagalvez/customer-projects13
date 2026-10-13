<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;

class PackageViewsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_package_views_are_loaded(): void
    {
        $providers = array_keys($this->app->getLoadedProviders());
        $this->assertContains('Basics13\ServiceProvider', $providers, 'Basics13 ServiceProvider not loaded');

        $this->assertTrue(View::exists('basics13::components.list.create-action'));
        $this->assertTrue(View::exists('basics13::components.forms.field-label'));
    }

    public function test_package_view_can_be_rendered(): void
    {
        $html = view('basics13::components.list.create-action', [
            'prefix' => 'test',
            'label' => 'Test Label',
            'click' => 'testClick()',
        ])->render();

        $this->assertStringContainsString('Test Label', $html);
        $this->assertStringContainsString('testClick()', $html);
    }

    public function test_package_component_in_blade_template(): void
    {
        $blade = <<<'BLADE'
            <x-basics13::list.create-action prefix="customer" :label="__('New customer')" click="createCustomer()" />
        BLADE;

        $html = Blade::render($blade);

        $this->assertStringContainsString(__('New customer'), $html);
        $this->assertStringContainsString('createCustomer()', $html);
    }

    public function test_package_field_label_component_uses_the_form_request_rules(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-basics13::forms.field-label :request="\Customers13\Http\Requests\CustomerRequest::class" field="name"
                test="customer-name-info">
                {{ __('Name') }}
            </x-basics13::forms.field-label>
        BLADE);

        $this->assertStringContainsString(__('Name'), $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('data-test="customer-name-info"', $html);
    }

    public function test_package_components_can_render_nested_components(): void
    {
        $html = Blade::render(
            '<x-basics13::list.page-header :list="$list" prefix="customer" />',
            ['list' => [
                'breadcrumbs' => [['label' => 'Customers', 'url' => null]],
                'tabs' => [],
            ]],
        );

        $this->assertStringContainsString('customer-heading', $html);
        $this->assertStringContainsString('Customers', $html);
    }

    public function test_package_validation_translations_are_available_to_the_validator(): void
    {
        $this->app->setLocale('eu');

        $attributes = __('validation.attributes');

        $this->assertIsArray($attributes);
        $this->assertSame(__('basics13::validation.attributes.name'), $attributes['name']);
    }

    public function test_project_submit_guard_converts_empty_integration_state_to_a_boolean(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee(
                'x-bind:disabled="isSubmitting || !isDirty || Boolean(form.customerCreating)"',
                false,
            );
    }

    public function test_package_table_keeps_the_pagination_summary_for_an_empty_list(): void
    {
        $html = Blade::render(
            '<x-basics13::list.table prefix="customer" :paginator="$paginator" />',
            ['paginator' => new LengthAwarePaginator([], 0, 15)],
        );

        $this->assertStringContainsString(
            __('basics13::messages.pagination_summary', ['first' => 0, 'last' => 0, 'total' => 0]),
            $html,
        );
    }

    public function test_row_action_hints_do_not_replace_other_tooltips(): void
    {
        $html = Blade::render(
            '<x-basics13::list.row-actions prefix="record" edit-handler="editRecord" :actions="$actions" />',
            ['actions' => [
                [
                    'type' => 'confirm-modal',
                    'label' => 'Archive record',
                    'hint' => 'Archive instead of deleting',
                    'icon' => 'archive-box',
                    'test' => 'record-archive-1',
                ],
                [
                    'type' => 'confirm-modal',
                    'label' => 'Restore record',
                    'icon' => 'arrow-path',
                    'test' => 'record-restore-1',
                ],
                [
                    'type' => 'form-modal',
                    'label' => 'Edit record',
                    'icon' => 'pencil-square',
                    'test' => 'record-edit-1',
                    'payload' => ['id' => 1],
                ],
            ]],
        );

        $this->assertStringContainsString('Archive instead of deleting', $html);
        $this->assertStringContainsString('Restore record', $html);
        $this->assertStringContainsString('data-test="record-edit-1"', $html);
        $this->assertStringContainsString('data-payload="{&quot;id&quot;:1}"', $html);
    }

    #[DataProvider('packagePageProvider')]
    public function test_application_pages_render_package_components(string $route): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route($route))
            ->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function packagePageProvider(): array
    {
        return [
            'customers' => ['customers.index'],
            'customer trash' => ['customers.trash.index'],
            'customer archive' => ['customers.archived.index'],
            'projects' => ['projects.index'],
            'project trash' => ['projects.trash.index'],
            'project archive' => ['projects.archived.index'],
            'epics' => ['epics.index'],
            'epic trash' => ['epics.trash.index'],
            'epic archive' => ['epics.archived.index'],
            'planning' => ['planning'],
            'timeline' => ['timeline'],
        ];
    }
}
