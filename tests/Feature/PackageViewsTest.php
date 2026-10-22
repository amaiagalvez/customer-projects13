<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;

class PackageViewsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_package_component_in_blade_template(): void
    {
        $blade = <<<'BLADE'
            <x-basics13::list.create-action prefix="entity" :label="__('New entity')" click="createEntity()" />
        BLADE;

        $html = Blade::render($blade);

        $this->assertStringContainsString(__('New entity'), $html);
        $this->assertStringContainsString('createEntity()', $html);
    }

    public function test_package_field_label_component_uses_the_form_request_rules(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-basics13::forms.field-label :request="\Entities13\Http\Requests\EntityRequest::class" field="name"
                test="entity-name-info">
                {{ __('Name') }}
            </x-basics13::forms.field-label>
        BLADE);

        $this->assertStringContainsString(__('Name'), $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('data-test="entity-name-info"', $html);
    }

    public function test_package_components_can_render_nested_components(): void
    {
        $html = Blade::render(
            '<x-basics13::list.page-header :list="$list" prefix="entity" />',
            ['list' => [
                'resource' => 'Entities',
                'breadcrumbs' => [['label' => 'Entities', 'url' => null]],
                'tabs' => [],
            ]],
        );

        $this->assertStringContainsString('entity-heading', $html);
        $this->assertStringContainsString('Entities', $html);
    }

    public function test_project_submit_guard_converts_empty_integration_state_to_a_boolean(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee(
                'x-bind:disabled="isSubmitting || !isDirty || Boolean(form.entityCreating)"',
                false,
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
            'entities' => ['entities.index'],
            'entity trash' => ['entities.trash.index'],
            'entity archive' => ['entities.archived.index'],
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
