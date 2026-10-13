<?php

namespace CustomerProjects13\Tests\Browser\Epics;

use App\Models\User;
use CustomerProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Epic;
use Projects13\Models\Project;

class EpicFormTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_no_active_projects_action_opens_the_project_form(): void
    {
        $user = User::factory()->create();
        Project::factory()->for($this->customer())->archived()->create();
        Project::factory()->for($this->customer())->trashed()->create();

        $this->browse(function (Browser $browser) use ($user): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->click('[data-test="epic-no-project-form-button"]')
                ->assertPathIs('/projects')
                ->waitFor('dialog[open] [data-test="project-name"]')
                ->assertSeeIn('dialog[open]', __('New project'))
                ->assertMissing('dialog[open] [data-test="epic-name"]');
        });
    }

    public function test_end_date_picker_starts_the_day_after_the_start_date(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($this->customer())->create();
        $epic = Epic::factory()->for($project)->create([
            'name' => 'Dusk dates '.Str::uuid()->toString(),
            'start_date' => '2026-12-31',
            'end_date' => '2027-01-15',
        ]);

        $this->browse(function (Browser $browser) use ($user, $epic): void {
            $browser->loginAs($user)
                ->visit('/epics?search='.urlencode($epic->name))
                ->click('[data-test="epic-edit-'.$epic->id.'"]')
                ->waitFor('dialog[open] [data-test="epic-end-date"]')
                ->assertAttribute('dialog[open] [data-test="epic-end-date"]', 'min', '2027-01-01');
        });
    }

    public function test_opening_the_project_select_loads_remote_project_options(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($this->customer())->create([
            'name' => 'Remote project '.Str::uuid()->toString(),
        ]);
        $epicName = 'Remote epic '.Str::uuid()->toString();

        $this->browse(function (Browser $browser) use ($user, $project, $epicName): void {
            $browser->loginAs($user)
                ->visit('/epics')
                ->click('[data-test="epic-create-button"]')
                ->waitFor('dialog[open] [data-test="epic-project"]')
                ->click('dialog[open] [data-test="epic-project"]')
                ->waitUntil(
                    'document.querySelector(\'[data-test="epic-project"] option[value="'.(string) $project->id.'"]\') !== null',
                    10,
                )
                ->select('[data-test="epic-project"]', (string) $project->id)
                ->type('dialog[open] [data-test="epic-name"]', $epicName)
                ->waitUntil('document.querySelector(\'[data-test="epic-submit"]\').disabled === false', 10)
                ->click('dialog[open] [data-test="epic-submit"]')
                ->waitFor('[data-test="epic-status"]');
        });

        $this->assertDatabaseHas('PRO_epics', [
            'name' => $epicName,
            'project_id' => $project->id,
        ]);
    }

    public function test_opening_the_project_select_loads_other_projects_for_an_existing_epic(): void
    {
        $user = User::factory()->create();
        $archivedProject = Project::factory()->for($this->customer())->archived()->create([
            'name' => 'Inactive selected project '.Str::uuid()->toString(),
        ]);
        $epic = Epic::factory()->for($archivedProject)->create();
        $otherProject = Project::factory()->for($this->customer())->create([
            'name' => 'Other project '.Str::uuid()->toString(),
        ]);
        $otherInactiveProject = Project::factory()->for($this->customer())->archived()->create([
            'name' => 'Other archived project '.Str::uuid()->toString(),
        ]);

        $this->browse(function (Browser $browser) use ($user, $epic, $archivedProject, $otherProject, $otherInactiveProject): void {
            $browser->loginAs($user)
                ->visit('/epics?search='.urlencode($epic->name))
                ->click('[data-test="epic-edit-'.$epic->id.'"]')
                ->waitFor('dialog[open] [data-test="epic-project"]')
                ->click('dialog[open] [data-test="epic-project"]')
                ->waitUntil(
                    'document.querySelector(\'dialog[open] [data-test="epic-project"] option[value="'.$otherProject->id.'"]\') !== null',
                    10,
                )
                ->assertSelected('dialog[open] [data-test="epic-project"]', (string) $epic->project_id)
                ->assertSeeIn(
                    'dialog[open] [data-test="epic-project"]',
                    $otherProject->name,
                )
                ->assertDontSeeIn('dialog[open] [data-test="epic-project"]', $otherInactiveProject->name)
                ->assertSelected('dialog[open] [data-test="epic-project"]', (string) $archivedProject->id);
        });
    }
}
