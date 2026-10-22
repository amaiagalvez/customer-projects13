<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Basics13\Models\Role;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Projects13\Models\Project;

/**
 * The entity selector of the project form asks for the project permission, not for the right to list
 * entities: a user who creates projects needs to pick one.
 */
class ProjectEntityOptionsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_projects_offers_the_active_entities_without_entities_view(): void
    {
        $active = Entity::factory()->create(['name' => 'Active entity']);
        Entity::factory()->archived()->create(['name' => 'Archived entity']);
        $this->actingAs($this->holding(['projects.view', 'projects.create']));

        $this->get(route('entities.index'))->assertForbidden();
        $this->getJson(route('entities.options'))
            ->assertOk()
            ->assertExactJson(['results' => [['id' => $active->id, 'text' => 'Active entity']]]);

        $this->post(route('projects.store'), [
            'name' => 'New project',
            'start_date' => '2026-10-01',
            'entity_id' => $active->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($active->id, Project::query()->where('name', 'New project')->value('entity_id'));
    }

    public function test_editing_projects_offers_the_entities_too(): void
    {
        $this->actingAs($this->holding(['projects.edit']));

        $this->getJson(route('entities.options'))->assertOk();
    }

    public function test_the_entities_are_still_offered_to_whoever_lists_them(): void
    {
        $this->actingAs($this->holding(['entities.view']));

        $this->getJson(route('entities.options'))->assertOk();
    }

    public function test_without_project_permissions_the_options_are_refused(): void
    {
        $this->actingAs($this->holding(['projects.view', 'epics.manage']));

        $this->getJson(route('entities.options'))->assertForbidden();
    }

    /**
     * @param  list<string>  $permissions
     */
    private function holding(array $permissions): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::factory()->withPermissions($permissions)->create());

        return $user;
    }
}
