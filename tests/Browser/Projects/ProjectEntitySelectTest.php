<?php

namespace EntityProjects13\Tests\Browser\Projects;

use Entities13\Models\Entity;
use EntityProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Project;

class ProjectEntitySelectTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const OPEN_DIALOG = 'dialog[open]';

    public function test_no_active_entities_action_opens_the_entity_form(): void
    {
        $user = $this->user();
        Entity::factory()->archived()->create();
        Entity::factory()->trashed()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->click('[data-test="project-no-related-form-button"]')
                ->assertPathIs('/entities')
                ->waitFor(self::OPEN_DIALOG)
                ->assertSeeIn(self::OPEN_DIALOG, __('New entity'))
                ->assertVisible('dialog[open] [data-test="entity-name"]')
                ->assertMissing('dialog[open] [data-test="project-name"]');
        });
    }

    public function test_project_entity_select_can_search_and_create_entities(): void
    {
        $user = $this->user();
        $executionId = Str::uuid()->toString();
        $existingEntityName = 'Northwind '.$executionId;
        $newEntityName = 'Dusk Select2 '.$executionId;
        $projectName = 'Select2 project '.$executionId;
        Entity::factory()->create(['name' => $existingEntityName]);

        $this->browse(function (Browser $browser) use (
            $user,
            $existingEntityName,
            $newEntityName,
            $projectName,
        ): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->waitUntil('typeof window.jQuery?.fn?.select2 === "function"', 10)
                ->click('[data-test="project-create-button"]')
                ->waitFor(self::OPEN_DIALOG)
                ->assertScript(
                    'document.querySelector("#project-entity-id").classList.contains("select2-hidden-accessible")',
                    true,
                )
                ->click('#select2-project-entity-id-container')
                ->type('dialog[open] .select2-container--open .select2-search__field', 'Northwind')
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode($existingEntityName).')',
                    10,
                )
                ->assertSeeIn('dialog[open] .select2-results', $existingEntityName)
                ->click('dialog[open] .select2-results__option--selectable')
                ->waitUntil('document.querySelector(\'[data-test="project-submit"]\').disabled === false', 10)
                ->assertEnabled('[data-test="project-submit"]')
                ->click('#select2-project-entity-id-container')
                ->type(
                    'dialog[open] .select2-container--open .select2-search__field',
                    $newEntityName,
                )
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode(__('Create entity').': '.$newEntityName).')',
                    10,
                )
                ->assertSeeIn(
                    'dialog[open] .select2-results',
                    __('Create entity').': '.$newEntityName,
                )
                ->click('dialog[open] .select2-results__option--selectable')
                ->waitUntil(
                    'document.querySelector("#project-entity-id option:checked")?.text === '.json_encode($newEntityName),
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

        $entity = Entity::query()->where('name', $newEntityName)->firstOrFail();

        $this->assertDatabaseHas('PRO_projects', [
            'name' => $projectName,
            'entity_id' => $entity->id,
        ]);
    }

    public function test_edit_keeps_its_archived_entity_selected_and_only_offers_active_options(): void
    {
        $user = $this->user();
        $archivedEntity = Entity::factory()->archived()->create(['name' => 'Inactive selected entity']);
        $activeEntity = Entity::factory()->create(['name' => 'Active option entity']);
        $otherInactiveEntity = Entity::factory()->archived()->create(['name' => 'Other archived entity']);
        $project = Project::factory()->for($archivedEntity)->create(['name' => 'Project with archived entity']);

        $this->browse(function (Browser $browser) use (
            $user,
            $archivedEntity,
            $activeEntity,
            $otherInactiveEntity,
            $project,
        ): void {
            $browser->loginAs($user)
                ->visit('/projects?search='.urlencode($project->name))
                ->click('[data-test="project-edit-'.$project->id.'"]')
                ->waitFor(self::OPEN_DIALOG.' [data-test="project-name"]')
                ->assertSelected(self::OPEN_DIALOG.' #project-entity-id', (string) $archivedEntity->id)
                ->assertSeeIn(
                    self::OPEN_DIALOG.' #select2-project-entity-id-container',
                    $archivedEntity->name,
                )
                ->assertSeeIn(
                    self::OPEN_DIALOG.' #select2-project-entity-id-container',
                    __('basics13::messages.archived_label'),
                )
                ->click('#select2-project-entity-id-container')
                ->type(self::OPEN_DIALOG.' .select2-container--open .select2-search__field', 'option entity')
                ->waitUntil(
                    'document.querySelector("dialog[open] .select2-results")?.textContent.includes('.json_encode($activeEntity->name).')',
                    10,
                )
                ->assertSeeIn(self::OPEN_DIALOG.' .select2-results', $activeEntity->name)
                ->assertSeeIn(self::OPEN_DIALOG.' .select2-results', $archivedEntity->name)
                ->assertSeeIn(
                    self::OPEN_DIALOG.' .select2-results',
                    __('basics13::messages.archived_label'),
                )
                ->assertDontSeeIn(self::OPEN_DIALOG.' .select2-results', $otherInactiveEntity->name)
                ->assertSelected(self::OPEN_DIALOG.' #project-entity-id', (string) $archivedEntity->id)
                ->click('#select2-project-entity-id-result-'.$activeEntity->id)
                ->assertSelected(self::OPEN_DIALOG.' #project-entity-id', (string) $activeEntity->id)
                ->assertDontSeeIn(
                    self::OPEN_DIALOG.' #select2-project-entity-id-container',
                    __('basics13::messages.archived_label'),
                );
        });
    }
}
