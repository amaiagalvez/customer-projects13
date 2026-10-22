<?php

namespace EntityProjects13\Tests\Browser\Epics;

use EntityProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EpicCrudTest extends DuskTestCase
{
    private const EPIC_PROJECT_SELECTOR = 'dialog[open] [data-test="epic-project"]';

    private const EPIC_NAME_SELECTOR = 'dialog[open] [data-test="epic-name"]';

    private const EPIC_STATUS_SELECTOR = '[data-test="epic-status"]';

    use DatabaseMigrations;

    public function test_advanced_search_filters_epics_and_clears_all_filters(): void
    {
        $user = $this->user();
        $project = Project::factory()->for($this->entity())->create();
        $alpha = Epic::factory()->for($project)->create(['name' => 'Alpha epic '.Str::uuid()]);
        $beta = Epic::factory()->for($project)->create(['name' => 'Beta epic '.Str::uuid()]);

        $this->browse(function (Browser $browser) use ($user, $alpha, $beta): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->click('[data-test="epic-advanced-search"]')
                ->waitFor('dialog[open] #advanced-search-heading')
                ->assertMissing('[data-test="active-advanced-search-operator"]')
                ->assertSeeIn('dialog[open]', __('Start date'))
                ->assertSeeIn('dialog[open]', __('basics13::messages.created_at'))
                ->assertDontSeeIn('dialog[open]', 'projects13::messages.')
                ->type('dialog[open] input[wire\\:model="filters.name.value"]', 'Alpha')
                ->press(__('basics13::messages.apply_filters'))
                ->waitFor('[data-test="epic-filter-remove-name"]')
                ->assertSee($alpha->name)
                ->assertDontSee($beta->name)
                ->click('[data-test="epic-filter-clear-all"]')
                ->waitUntilMissing('[data-test="epic-filter-badges"]')
                ->assertSee($beta->name)
                ->assertScript('location.search', '');

            $this->assertSame([], array_values(array_filter(
                $browser->driver->manage()->getLog('browser'),
                fn (array $entry): bool => $entry['level'] === 'SEVERE',
            )));
        });
    }

    public function test_epic_row_name_is_not_interactive(): void
    {
        $user = $this->user();
        $project = Project::factory()->for($this->entity())->create();
        $epic = Epic::factory()->for($project)->create(['name' => 'Editable from the row']);

        $this->browse(function (Browser $browser) use ($user, $epic): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->assertSeeIn('[data-test="epic-name-'.$epic->id.'"]', $epic->name)
                ->assertScript(
                    'document.querySelector(\'[data-test="epic-name-'.$epic->id.'"]\').closest(\'a, button\') === null',
                    true,
                );
        });
    }

    public function test_epic_table_keeps_the_actions_column_stuck_to_the_end(): void
    {
        $user = $this->user();
        $project = Project::factory()->for($this->entity())->create();
        $longName = 'Very long epic name '.str_repeat('x', 120);
        $epic = Epic::factory()->for($project)->create(['name' => $longName]);

        $this->browse(function (Browser $browser) use ($user, $epic, $longName): void {
            $browser->loginAs($user)
                ->resize(1920, 1080)
                ->visit('/epics?search='.urlencode($longName));

            $browser->assertAttribute('[data-test="epic-name-'.$epic->id.'"]', 'title', $longName)
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
                    'getComputedStyle(document.querySelector(\'[data-test="epic-name-'.$epic->id.'"]\')).textOverflow',
                    'ellipsis',
                );
        });
    }

    public function test_epic_can_be_created_updated_and_deleted_from_the_drawer(): void
    {
        $user = $this->user();
        $suffix = Str::uuid()->toString();
        $project = Project::factory()->for($this->entity())->create(['name' => 'Dusk project '.$suffix]);
        $epicName = 'Dusk epic '.$suffix;
        $updatedEpicName = 'Updated epic '.$suffix;
        $epicId = null;

        $this->browse(function (Browser $browser) use (
            $user,
            $project,
            $epicName,
            $updatedEpicName,
            &$epicId,
        ): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->click('[data-test="epic-create-button"]')
                ->waitFor(self::EPIC_PROJECT_SELECTOR)
                ->click(self::EPIC_PROJECT_SELECTOR)
                ->waitUntil(
                    'document.querySelector(\'[data-test="epic-project"] option[value="'.$project->id.'"]\') !== null',
                    10,
                )
                ->select(self::EPIC_PROJECT_SELECTOR, (string) $project->id)
                ->type(self::EPIC_NAME_SELECTOR, $epicName)
                ->click('dialog[open] [data-test="epic-submit"]')
                ->waitFor(self::EPIC_STATUS_SELECTOR)
                ->assertSee($epicName);

            $epic = Epic::query()->where('name', $epicName)->firstOrFail();
            $epicId = $epic->id;

            $browser->click("[data-test='epic-edit-{$epic->id}']")
                ->waitFor(self::EPIC_NAME_SELECTOR)
                ->clear(self::EPIC_NAME_SELECTOR)
                ->type(self::EPIC_NAME_SELECTOR, $updatedEpicName)
                ->click('dialog[open] [data-test="epic-submit"]')
                ->waitFor(self::EPIC_STATUS_SELECTOR)
                ->assertSee($updatedEpicName)
                ->click("[data-test='epic-delete-{$epic->id}']")
                ->waitFor('dialog[open] [data-test="epic-confirm-submit"]')
                ->click('dialog[open] [data-test="epic-confirm-submit"]')
                ->waitFor(self::EPIC_STATUS_SELECTOR)
                ->assertDontSee($updatedEpicName);
        });

        $this->assertNotNull($epicId);
        $this->assertSoftDeleted('PRO_epics', ['id' => $epicId]);
    }
}
