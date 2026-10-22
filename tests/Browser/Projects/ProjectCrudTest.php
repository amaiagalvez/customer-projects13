<?php

namespace EntityProjects13\Tests\Browser\Projects;

use Entities13\Models\Entity;
use EntityProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Project;

class ProjectCrudTest extends DuskTestCase
{
    private const PROJECT_NAME_SELECTOR = 'dialog[open] [data-test="project-name"]';

    private const PROJECT_STATUS_SELECTOR = '[data-test="project-status"]';

    use DatabaseMigrations;

    public function test_advanced_search_filters_projects_by_entity_and_removes_the_filters(): void
    {
        $user = $this->admin();
        $north = Entity::factory()->create(['name' => 'North '.Str::uuid()]);
        $south = Entity::factory()->create(['name' => 'South '.Str::uuid()]);
        $northProject = Project::factory()->for($north)->create(['name' => 'North project '.Str::uuid()]);
        $southProject = Project::factory()->for($south)->create(['name' => 'South project '.Str::uuid()]);

        $this->browse(function (Browser $browser) use ($user, $north, $northProject, $southProject): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->assertDontSee('1 emaitza')
                ->click('[data-test="project-advanced-search"]')
                ->waitFor('dialog[open] #advanced-search-heading')
                ->assertMissing('dialog[open] select[multiple]')
                ->assertVisible('[data-test="entity_id-advanced-search-info"]')
                ->assertSeeIn('dialog[open]', __('Start date'))
                ->assertSeeIn('dialog[open]', __('basics13::messages.created_at'))
                ->assertDontSeeIn('dialog[open]', 'projects13::messages.')
                ->assertMissing('[data-test="active-advanced-search-operator"]')
                ->check('[data-test="entity_id-advanced-search-options"] input[value="'.$north->id.'"]')
                ->press(__('basics13::messages.apply_filters'))
                ->waitFor('[data-test="project-filter-remove-entity_id"]')
                ->assertMissing('[data-test="project-filter-remove-active"]')
                ->assertSee($northProject->name)
                ->assertDontSee($southProject->name)
                ->click('[data-test="project-filter-remove-entity_id"]')
                ->waitUntilMissing('[data-test="project-filter-badges"]')
                ->assertSee($southProject->name);

            $this->assertSame([], array_values(array_filter(
                $browser->driver->manage()->getLog('browser'),
                fn (array $entry): bool => $entry['level'] === 'SEVERE',
            )));
        });
    }

    public function test_project_row_name_is_not_interactive(): void
    {
        $user = $this->admin();
        $project = Project::factory()->for($this->entity())->create(['name' => 'Editable from the row']);

        $this->browse(function (Browser $browser) use ($user, $project): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->waitUntil('typeof window.jQuery?.fn?.select2 === "function"', 10)
                ->assertSeeIn('[data-test="project-name-'.$project->id.'"]', $project->name)
                ->assertScript(
                    'document.querySelector(\'[data-test="project-name-'.$project->id.'"]\').closest(\'a, button\') === null',
                    true,
                );
        });
    }

    public function test_project_table_keeps_the_actions_column_stuck_to_the_end(): void
    {
        $user = $this->admin();
        $entity = Entity::factory()->create();
        $longName = 'Very long project name '.str_repeat('x', 120);
        $project = Project::factory()->for($entity)->create(['name' => $longName]);

        $this->browse(function (Browser $browser) use ($user, $project, $longName): void {
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/projects?search='.urlencode($longName));

            $browser->assertAttribute('[data-test="project-name-'.$project->id.'"]', 'title', $longName)
                ->assertScript(
                    'getComputedStyle(document.querySelector(\'.resource-list-table tbody tr td:last-child\')).position',
                    'sticky',
                )
                ->assertScript(
                    'getComputedStyle(document.querySelector(\'.resource-list-table tbody tr td:last-child\')).textAlign',
                    'end',
                )
                ->resize(320, 420)
                ->assertScript(
                    'getComputedStyle(document.querySelector(\'[data-test="project-name-'.$project->id.'"]\')).textOverflow',
                    'ellipsis',
                );
        });
    }

    public function test_project_can_be_created_updated_and_deleted_from_the_drawer(): void
    {
        $user = $this->admin();
        $suffix = Str::uuid()->toString();
        $entityName = 'Dusk entity '.$suffix;
        $projectName = 'Dusk project '.$suffix;
        $updatedProjectName = 'Updated project '.$suffix;
        $entity = Entity::factory()->create(['name' => $entityName]);
        $projectId = null;

        $this->browse(function (Browser $browser) use (
            $user,
            $entity,
            $entityName,
            $projectName,
            $updatedProjectName,
            &$projectId,
        ): void {
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/projects')
                ->waitUntil('typeof window.jQuery?.fn?.select2 === "function"', 10)
                ->click('[data-test="project-create-button"]')
                ->waitFor(self::PROJECT_NAME_SELECTOR)
                ->type(self::PROJECT_NAME_SELECTOR, $projectName)
                ->click('#select2-project-entity-id-container')
                ->type('dialog[open] .select2-container--open .select2-search__field', $entityName)
                ->waitFor('#select2-project-entity-id-result-'.$entity->id, 10)
                ->click('#select2-project-entity-id-result-'.$entity->id);

            $browser->script(<<<'JS'
                const input = document.querySelector('dialog[open] [data-test="project-start-date"]');
                input.value = '2026-10-01';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                JS);

            $browser->waitUntilEnabled('dialog[open] [data-test="project-submit"]')
                ->click('dialog[open] [data-test="project-submit"]')
                ->waitFor(self::PROJECT_STATUS_SELECTOR)
                ->assertSee($projectName);

            $project = Project::query()->where('name', $projectName)->firstOrFail();
            $projectId = $project->id;

            $browser->click("[data-test='project-edit-{$project->id}']")
                ->waitFor(self::PROJECT_NAME_SELECTOR)
                ->clear(self::PROJECT_NAME_SELECTOR)
                ->type(self::PROJECT_NAME_SELECTOR, $updatedProjectName)
                ->waitUntilEnabled('dialog[open] [data-test="project-submit"]')
                ->click('dialog[open] [data-test="project-submit"]')
                ->waitFor(self::PROJECT_STATUS_SELECTOR)
                ->assertSee($updatedProjectName)
                ->click("[data-test='project-delete-{$project->id}']")
                ->waitFor('dialog[open] [data-test="project-confirm-submit"]')
                ->click('dialog[open] [data-test="project-confirm-submit"]')
                ->waitFor(self::PROJECT_STATUS_SELECTOR)
                ->assertDontSee($updatedProjectName);
        });

        $this->assertNotNull($projectId);
        $this->assertSoftDeleted('PRO_projects', ['id' => $projectId]);
    }
}
