<?php

namespace EntityProjects13\Tests\Feature\Projects;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;

class EntitySelectOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_form_does_not_preload_every_entity(): void
    {
        $this->actingAs(User::factory()->create());

        $total = ListQueryBase::PER_PAGE + 1;

        for ($index = 1; $index <= $total; $index++) {
            Entity::factory()->create([
                'name' => sprintf('Option-only entity %02d', $index),
            ]);
        }

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertDontSee('Option-only entity 21');
    }

    public function test_project_list_offers_form_action_when_no_active_entities_exist(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee(__('No active entities are available. Open the entity form to create one.'))
            ->assertSee(__('Open entity form'))
            ->assertSee('href="' . route('entities.index', ['create' => 1]) . '"', false)
            ->assertSee('project-no-entity-form-button');
    }
}
