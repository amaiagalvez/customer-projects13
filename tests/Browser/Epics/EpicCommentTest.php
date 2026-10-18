<?php

namespace EntityProjects13\Tests\Browser\Epics;

use EntityProjects13\Tests\DuskTestCase;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class EpicCommentTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_comment_added_from_the_edit_drawer_is_shown_after_reopening_it(): void
    {
        $user = $this->user(['name' => 'Dusk commenter']);
        $epicName = 'Dusk epic '.Str::uuid()->toString();
        $project = Project::factory()->for($this->entity())->create();
        $epic = Epic::factory()->for($project)->create(['name' => $epicName]);
        $existingComment = 'Existing comment '.Str::uuid()->toString();
        EpicComment::factory()->for($epic)->for($user)->create(['body' => $existingComment]);
        $commentBody = 'Dusk comment '.Str::uuid()->toString();

        $this->browse(function (Browser $browser) use ($user, $epic, $epicName, $existingComment, $commentBody): void {
            $browser->loginAs($user)
                ->visit('/epics?search='.urlencode($epicName))
                ->assertSeeIn('[data-test="epic-comments-count-'.$epic->id.'"]', '1')
                ->assertDontSee($existingComment)
                ->click('[data-test="epic-edit-'.$epic->id.'"]')
                ->waitFor('dialog[open] [data-test="epic-comments"]')
                ->waitFor('dialog[open] [data-test="epic-comment"]')
                ->assertSeeIn('dialog[open]', $existingComment)
                ->assertScript(
                    'document.querySelector(\'dialog[open] [data-test="epic-comment-body"]\').form.noValidate',
                    false,
                )
                ->type('dialog[open] [data-test="epic-comment-body"]', $commentBody)
                ->waitForReload(fn (Browser $browser) => $browser->click(
                    'dialog[open] [data-test="epic-comment-submit"]'
                ))
                ->waitFor('dialog[open] [data-test="epic-comment"]')
                ->assertInputValue('dialog[open] [data-test="epic-name"]', $epicName)
                ->assertSeeIn('dialog[open]', $existingComment)
                ->assertSeeIn('dialog[open] [data-test="epic-comment"]', $commentBody)
                ->assertSeeIn('dialog[open] [data-test="epic-comment"]', 'Dusk commenter')
                ->assertSeeIn('[data-test="epic-comments-count-'.$epic->id.'"]', '2');
        });

        $this->assertDatabaseHas('PRO_epic_comments', [
            'epic_id' => $epic->id,
            'user_id' => $user->id,
            'body' => $commentBody,
        ]);
    }

    public function test_comment_time_is_displayed_in_the_browser_timezone(): void
    {
        $user = $this->user();
        $project = Project::factory()->for($this->entity())->create();
        $epic = Epic::factory()->for($project)->create([
            'name' => 'Timezone epic '.Str::uuid()->toString(),
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-02',
        ]);
        EpicComment::factory()->for($epic)->for($user)->create([
            'body' => 'Timezone comment',
            'created_at' => '2026-10-01 09:30:00',
        ]);

        $this->browse(function (Browser $browser) use ($user, $epic): void {
            $browser->loginAs($user);
            (new ChromeDevToolsDriver($browser->driver))->execute(
                'Emulation.setTimezoneOverride',
                ['timezoneId' => 'Europe/Madrid'],
            );

            $browser->visit('/epics?search='.urlencode($epic->name))
                ->assertScript(
                    'document.querySelector(\'tbody time[data-local-datetime="date"]\').dateTime',
                    '2026-10-01',
                )
                ->assertScript(
                    '!document.querySelector(\'tbody time[data-local-datetime="date"]\').textContent.trim().includes(":")',
                    true,
                )
                ->assertScript(
                    'document.querySelector(\'tbody time[data-local-datetime="date"]\').textContent.trim() === window.formatLocalDateTime(document.querySelector(\'tbody time[data-local-datetime="date"]\').dateTime, "date")',
                    true,
                )
                ->click('[data-test="epic-edit-'.$epic->id.'"]')
                ->waitFor('dialog[open] [data-test="epic-comment-written-at"]')
                ->assertScript('new Intl.DateTimeFormat().resolvedOptions().timeZone', 'Europe/Madrid')
                ->assertScript(
                    'new Date(document.querySelector(\'[data-test="epic-comment-written-at"]\').dateTime).getHours()',
                    11,
                )
                ->assertScript(
                    'document.querySelector(\'[data-test="epic-comment-written-at"]\').textContent.trim() === window.formatLocalDateTime(document.querySelector(\'[data-test="epic-comment-written-at"]\').dateTime)',
                    true,
                )
                ->assertScript(
                    '(() => { const time = document.querySelector(\'[data-test="epic-comment-written-at"]\'); const locale = document.documentElement.lang; document.documentElement.lang = "eu"; const basque = window.formatLocalDateTime(time.dateTime); document.documentElement.lang = "es"; const spanish = window.formatLocalDateTime(time.dateTime); document.documentElement.lang = locale; return basque + "|" + spanish; })()',
                    '2026-10-01 11:30|01-10-2026 11:30',
                )
                ->assertScript(
                    '(() => { const locale = document.documentElement.lang; document.documentElement.lang = "eu"; const basque = window.formatLocalDateTime("2026-10-01", "date"); document.documentElement.lang = "es"; const spanish = window.formatLocalDateTime("2026-10-01", "date"); document.documentElement.lang = locale; return basque + "|" + spanish; })()',
                    '2026-10-01|01-10-2026',
                );
        });
    }
}
