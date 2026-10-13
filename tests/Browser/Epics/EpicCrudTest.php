<?php

namespace CustomerProjects13\Tests\Browser\Epics;

use App\Models\User;
use CustomerProjects13\Tests\DuskTestCase;
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

    public function test_epic_row_name_opens_the_edit_form(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($this->customer())->create();
        $epic = Epic::factory()->for($project)->create(['name' => 'Editable from the row']);

        $this->browse(function (Browser $browser) use ($user, $epic): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->click('[data-test="epic-name-'.$epic->id.'"]')
                ->waitFor('dialog[open] [data-test="epic-comments"]')
                ->assertSeeIn('dialog[open]', __('Edit epic'))
                ->assertInputValue(self::EPIC_NAME_SELECTOR, $epic->name);
        });
    }

    public function test_epic_table_keeps_the_actions_column_stuck_to_the_end(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($this->customer())->create();
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
        $user = User::factory()->create();
        $suffix = Str::uuid()->toString();
        $project = Project::factory()->for($this->customer())->create(['name' => 'Dusk project '.$suffix]);
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
