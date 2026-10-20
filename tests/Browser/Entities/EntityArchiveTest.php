<?php

namespace EntityProjects13\Tests\Browser\Entities;

use Entities13\Models\Entity;
use EntityProjects13\Tests\DuskTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Projects13\Models\Project;

/**
 * The archive button only replaces the delete button when the entity owns projects, which only
 * the bridge knows. That is why this coverage lives here and not in the entities13 browser suite.
 */
class EntityArchiveTest extends DuskTestCase
{
    private const ENTITY_CONFIRM_SUBMIT_SELECTOR = 'dialog[open] [data-test="entity-confirm-submit"]';

    private const ENTITY_STATUS_SELECTOR = '[data-test="entity-status"]';

    use DatabaseMigrations;

    public function test_a_entity_with_projects_is_archived_instead_of_deleted(): void
    {
        $user = $this->user();
        $entity = Entity::factory()->create(['name' => 'Entity with projects']);
        Project::factory()->for($entity)->create();

        $this->browse(function (Browser $browser) use ($user, $entity): void {
            $browser->loginAs($user)
                ->visit('/entities')
                ->assertDontSee("[data-test='entity-delete-{$entity->id}']")
                ->click("[data-test='entity-archive-{$entity->id}']")
                ->waitFor(self::ENTITY_CONFIRM_SUBMIT_SELECTOR)
                ->click(self::ENTITY_CONFIRM_SUBMIT_SELECTOR)
                ->waitFor(self::ENTITY_STATUS_SELECTOR)
                ->assertSee(__('basics13::messages.archived'))
                ->assertDontSee($entity->name);
        });

        $this->assertFalse($entity->refresh()->active);
        $this->assertNotSoftDeleted($entity);
    }

    public function test_an_archived_entity_returns_to_the_active_list(): void
    {
        $user = $this->user();
        $entity = Entity::factory()->archived()->create(['name' => 'Archived entity']);
        Project::factory()->for($entity)->create();

        $this->browse(function (Browser $browser) use ($user, $entity): void {
            $browser->loginAs($user)
                ->visit('/entities/archived')
                ->assertSee($entity->name)
                ->click("[data-test='entity-activate-{$entity->id}']")
                ->waitFor(self::ENTITY_CONFIRM_SUBMIT_SELECTOR)
                ->click(self::ENTITY_CONFIRM_SUBMIT_SELECTOR)
                ->waitFor(self::ENTITY_STATUS_SELECTOR)
                ->assertSee(__('basics13::messages.activated'));
        });

        $this->assertTrue($entity->refresh()->active);
    }
}
