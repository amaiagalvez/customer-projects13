<?php

namespace EntityProjects13\Tests\Browser\Projects;

use EntityProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Projects13\Enums\ProjectRole;
use Projects13\Models\Project;

/**
 * The members part of the project drawer works in Alpine against the members endpoints: searching,
 * adding, changing a role and removing only happen in the browser.
 */
class ProjectMembersTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const MEMBERS_IDLE = 'document.querySelector(\'dialog[open] [data-test="project-members"] ul\').getAttribute("aria-busy") !== "true"';

    public function test_a_member_is_added_given_another_role_and_removed_from_the_drawer(): void
    {
        $user = $this->admin();
        $colleague = $this->user(['name' => 'Ane Colleague']);
        $project = Project::factory()->for($this->entity())->create(['name' => 'Shared project']);

        $this->browse(function (Browser $browser) use ($user, $colleague, $project): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->click("[data-test='project-edit-{$project->id}']")
                ->waitFor('dialog[open] [data-test="project-members"]')
                ->type('dialog[open] [data-test="project-member-search"]', 'Ane Coll')
                ->waitFor("dialog[open] [data-test='project-member-candidate-{$colleague->getKey()}']")
                ->click("dialog[open] [data-test='project-member-candidate-{$colleague->getKey()}']")
                ->select('dialog[open] [data-test="project-member-new-role"]', 'editor')
                ->click('dialog[open] [data-test="project-member-add"]')
                ->waitFor("dialog[open] [data-test='project-member-{$colleague->getKey()}']")
                // The controls stay disabled until the reload after each change is over.
                ->waitUntil(self::MEMBERS_IDLE);

            $this->assertSame(ProjectRole::Editor, $project->members()->where('user_id', $colleague->getKey())->sole()->role);

            $browser->select("dialog[open] [data-test='project-member-role-{$colleague->getKey()}']", 'viewer')
                ->waitUsing(5, 100, fn (): bool => $project->members()->where('user_id', $colleague->getKey())->value('role') === ProjectRole::Viewer)
                ->waitUntil(self::MEMBERS_IDLE)
                ->click("dialog[open] [data-test='project-member-remove-{$colleague->getKey()}']")
                ->click("dialog[open] [data-test='project-member-remove-confirm-{$colleague->getKey()}']")
                ->waitUntilMissing("dialog[open] [data-test='project-member-{$colleague->getKey()}']");

            $this->assertSame([], array_values(array_filter(
                $browser->driver->manage()->getLog('browser'),
                fn (array $entry): bool => $entry['level'] === 'SEVERE',
            )));
        });

        $this->assertSame(0, $project->members()->where('user_id', $colleague->getKey())->count());
    }

    public function test_the_last_owner_cannot_be_demoted(): void
    {
        $user = $this->admin();
        $project = Project::factory()->for($this->entity())->create();
        $project->members()->forceCreate(['user_id' => $user->getKey(), 'role' => ProjectRole::Owner]);

        $this->browse(function (Browser $browser) use ($user, $project): void {
            $browser->loginAs($user)
                ->visit('/projects')
                ->click("[data-test='project-edit-{$project->id}']")
                ->waitFor("dialog[open] [data-test='project-member-{$user->getKey()}']")
                ->select("dialog[open] [data-test='project-member-role-{$user->getKey()}']", 'viewer')
                ->waitForTextIn('dialog[open] [data-test="project-members-error"]', __('A project must have at least one owner.'))
                // The list is reloaded after the refusal; read it once that is over.
                ->waitUntil(self::MEMBERS_IDLE)
                ->assertSelected("dialog[open] [data-test='project-member-role-{$user->getKey()}']", 'owner');
        });

        $this->assertSame(ProjectRole::Owner, $project->members()->sole()->role);
    }
}
