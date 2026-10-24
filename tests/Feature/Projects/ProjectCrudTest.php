<?php

namespace EntityProjects13\Tests\Feature\Projects;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use Projects13\Transformers\ProjectListTransformer;

class ProjectCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_has_entities_is_true_when_an_active_entity_exists(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Entity::factory()->create();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('hasRelated', true);
    }

    public function test_has_entities_is_false_when_only_archived_entities_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Entity::factory()->archived()->create();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('hasRelated', false);
    }

    public function test_has_entities_is_false_when_only_deleted_entities_exist(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Entity::factory()->trashed()->create();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('hasRelated', false);
    }

    public function test_creating_and_reassigning_a_project_persists_its_entity_and_preserves_notes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        $otherEntity = Entity::factory()->create();

        $this->post(route('projects.store'), [
            'name' => 'Website renewal',
            'start_date' => '2026-10-01',
            'end_date' => '2026-12-31',
            'entity_id' => $entity->id,
            'notes' => 'Project notes',
        ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', __('Record created successfully.'));

        $project = Project::query()->firstOrFail();
        $this->assertTrue($project->getRelationValue('entity')->is($entity));
        $this->assertSame('Project notes', $project->notes);

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee($entity->name)
            ->assertViewHas('list', function (array $list) use ($entity): bool {
                return $list['rows'][0]['editPayload']['entity_id'] === $entity->id
                    && $list['rows'][0]['editPayload']['notes'] === 'Project notes';
            });

        $this->put(route('projects.update', $project), [
            'name' => 'Updated website',
            'start_date' => '2026-10-15',
            'end_date' => '2027-01-15',
            'entity_id' => $otherEntity->id,
            'notes' => 'Rewritten notes',
        ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', __('Record updated successfully.'));

        $project->refresh();
        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'name' => 'Updated website',
            'entity_id' => $otherEntity->id,
            'notes' => 'Rewritten notes',
        ]);
        $this->assertTrue($project->getRelationValue('entity')->is($otherEntity));

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertViewHas('list', function (array $list) use ($otherEntity): bool {
                return $list['rows'][0]['editPayload']['entity_id'] === $otherEntity->id
                    && $list['rows'][0]['editPayload']['notes'] === 'Rewritten notes';
            });
    }

    public function test_a_entity_can_have_multiple_projects(): void
    {
        $entity = Entity::factory()->create();

        $projects = Project::factory()->for($this->entity())->count(2)->for($entity)->create();

        $this->assertCount(2, $entity->getRelationValue('projects'));
        $this->assertTrue($projects->every(function (Project $project) use ($entity): bool {
            return $project->getRelationValue('entity')->is($entity);
        }));
    }

    public function test_projects_can_be_searched_by_entity_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $matchingEntity = Entity::factory()->create(['name' => 'Northwind Studio']);
        $otherEntity = Entity::factory()->create(['name' => 'Contoso']);
        Project::factory()->for($matchingEntity)->create(['name' => 'Website redesign']);
        Project::factory()->for($otherEntity)->create(['name' => 'Mobile application']);

        $this->get(route('projects.index', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Website redesign')
            ->assertSee('Northwind Studio')
            ->assertDontSee('Mobile application');
    }

    public function test_projects_can_be_searched_by_their_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create(['name' => 'Searchable project']);
        Project::factory()->for($entity)->create(['name' => 'Unrelated project']);

        $this->get(route('projects.index', ['search' => 'Searchable project']))
            ->assertOk()
            ->assertSee('Searchable project')
            ->assertDontSee('Unrelated project');
    }

    public function test_project_name_with_non_latin_characters_can_be_stored_and_searched(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        $name = 'Ñandú プロジェクト';

        $this->post(route('projects.store'), [
            'name' => $name,
            'start_date' => '2026-10-01',
            'entity_id' => $entity->id,
        ])->assertRedirect(route('projects.index'));

        $this->assertDatabaseHas('PRO_projects', ['name' => $name, 'entity_id' => $entity->id]);

        $this->get(route('projects.index', ['search' => $name]))
            ->assertOk()
            ->assertSee($name);
    }

    public function test_project_search_is_kept_in_pagination_links(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();

        foreach (range(1, 6) as $index) {
            Project::factory()->for($entity)->create(['name' => "Repeat project {$index}"]);
        }

        $this->get(route('projects.index', ['search' => 'Repeat']))
            ->assertOk()
            ->assertSee('search=Repeat', false);
    }

    public function test_projects_cannot_be_searched_by_their_unshown_creation_date(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create([
            'name' => 'Website redesign',
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-01',
            'created_at' => '2001-02-03 12:00:00',
        ]);

        $this->get(route('projects.index', ['search' => '2001-02-03']))
            ->assertOk()
            ->assertSee(__('basics13::messages.no_matching_records'))
            ->assertDontSee('Website redesign');
    }

    public function test_project_list_shows_the_number_of_active_epics_and_their_comments(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();
        Epic::factory()->for($project)->create();
        $commentedEpic = Epic::factory()->for($project)->create();
        EpicComment::factory()->count(3)->for($commentedEpic)->create();
        EpicComment::factory()->count(4)->for(Epic::factory()->for($project)->archived())->create();
        EpicComment::factory()->count(5)->for(Epic::factory()->for($project)->trashed())->create();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSeeInOrder(['project-epics-count-'.$project->id, '2'])
            ->assertSeeInOrder(['project-comments-count-'.$project->id, '3']);
    }

    public function test_projects_are_ordered_by_start_date_end_date_and_entity_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $alphaEntity = Entity::factory()->create(['name' => 'Alpha entity']);
        $zuluEntity = Entity::factory()->create(['name' => 'Zulu entity']);

        Project::factory()->for($alphaEntity)->create([
            'name' => 'Z project, alpha entity',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
        ]);
        Project::factory()->for($zuluEntity)->create([
            'name' => 'A project, zulu entity',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
        ]);
        Project::factory()->for($alphaEntity)->create([
            'name' => 'B project, later end',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-04',
        ]);
        Project::factory()->for($alphaEntity)->create([
            'name' => 'C project, later start',
            'start_date' => '2026-10-02',
            'end_date' => '2026-10-02',
        ]);

        $this->get(route('projects.index'))
            ->assertSeeInOrder([
                'Z project, alpha entity',
                'A project, zulu entity',
                'B project, later end',
                'C project, later start',
            ]);
    }

    public function test_projects_with_matching_dates_and_entity_are_ordered_by_name(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create(['name' => 'Same entity']);

        Project::factory()->for($entity)->create([
            'name' => 'Zulu project',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
        ]);
        Project::factory()->for($entity)->create([
            'name' => 'Alpha project',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-05',
        ]);

        $this->get(route('projects.index'))
            ->assertSeeInOrder([
                'Alpha project',
                'Zulu project',
            ]);
    }

    public function test_project_list_shows_pagination_when_more_than_one_page_exists(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        for ($index = 1; $index <= ListQueryBase::PER_PAGE + 1; $index++) {
            Project::factory()->for($entity)->create();
        }

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('project-pagination', false);
    }

    public function test_project_list_header_offers_the_state_tabs_with_the_record_count_of_each_one(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        Project::factory()->for($this->entity())->count(2)->for($entity)->create();
        Project::factory()->for($entity)->archived()->create();
        Project::factory()->for($this->entity())->count(3)->for($entity)->trashed()->create();

        $this->get(route('projects.index'))
            ->assertOk()
            // A root list has nothing to walk back to: the H1 names it, without breadcrumbs.
            ->assertDontSee('data-test="project-breadcrumbs"', false)
            ->assertSeeInOrder(['data-test="project-heading"', __('Projects')], false)
            ->assertSeeInOrder([
                'data-test="project-active-link"',
                'aria-current="page"',
                'data-test="project-archived-link"',
                'data-test="project-trash-link"',
            ], false)
            ->assertSeeInOrder(['data-test="project-active-link"', '>2</span>'], false)
            ->assertSeeInOrder(['data-test="project-archived-link"', '>1</span>'], false)
            ->assertSeeInOrder(['data-test="project-trash-link"', '>3</span>'], false);

        $this->get(route('projects.archived.index'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="project-breadcrumbs"', 'href="'.route('projects.index').'"', __('Projects'), __('Archived')], false);

        $this->get(route('projects.trash.index'))
            ->assertOk()
            ->assertSeeInOrder(['data-test="project-breadcrumbs"', 'href="'.route('projects.index').'"', __('Projects'), __('Trash')], false);
    }

    public function test_project_row_edits_from_the_name_and_links_the_epic_count_to_its_epics(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create(['name' => 'Website redesign']);
        Epic::factory()->for($project)->create();
        $lonelyProject = Project::factory()->for($entity)->create(['name' => 'Lonely project']);

        $response = $this->get(route('projects.index'));

        $response
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="project-name-'.$project->id.'"',
                'editProject(JSON.parse(',
            ], false)
            ->assertSee('href="'.route('epics.index', ['search' => 'Website redesign']).'"', false)
            ->assertSee(__('View :count epics', ['count' => 1]))
            ->assertDontSee('href="'.route('epics.index', ['search' => 'Lonely project']).'"', false)
            ->assertSee('data-test="project-epics-count-'.$lonelyProject->id.'"></span>', false)
            ->assertSee('data-test="project-comments-count-'.$lonelyProject->id.'"></span>', false)
            ->assertDontSee('data-test="project-delete-'.$project->id.'"', false)
            ->assertSee('data-test="project-archive-'.$project->id.'"', false)
            ->assertSee('data-test="project-delete-'.$lonelyProject->id.'"', false);
    }

    #[Group('mariadb')]
    public function test_project_is_not_deleted_when_an_epic_appears_during_the_delete_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The concurrent delete guard requires MySQL row locks.');
        }

        $this->actingAs(User::factory()->admin()->create());
        $project = Project::factory()->for($this->entity())->create();
        $competitorCreated = false;

        DB::listen(static function (QueryExecuted $query) use ($project, &$competitorCreated): void {
            if (
                $competitorCreated
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains(strtolower($query->sql), 'pro_projects')
                || ! str_contains(strtolower($query->sql), 'for update')
                || ! in_array($project->id, $query->bindings, true)
            ) {
                return;
            }

            $competitorCreated = true;
            Epic::factory()->for($project)->create(['name' => 'Concurrent epic']);
        });

        $this->delete(route('projects.destroy', $project))
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('error', __('Cannot be deleted while it has related records.'));

        $this->assertTrue($competitorCreated);
        $this->assertNotSoftDeleted($project);
        $this->assertDatabaseHas('PRO_epics', [
            'project_id' => $project->id,
            'deleted_at' => null,
        ]);
    }

    public function test_project_list_transformer_renders_the_entity_it_is_bound_to(): void
    {
        $entity = Entity::factory()->create(['name' => 'Bound entity']);
        $project = Project::factory()->for($entity)->create();

        $list = app(ProjectListTransformer::class)->active(
            new LengthAwarePaginator([$project], 1, 15),
            '',
        );

        $this->assertSame('Bound entity', data_get($list, 'rows.0.entity'));
        $this->assertSame('Bound entity', data_get($list, 'rows.0.actions.0.payload.entity_name'));
    }

    /**
     * A project cannot exist without a entity: the column is NOT NULL, the foreign key restricts
     * deletion and the relation resolves soft-deleted entities, so the row always renders a name.
     */
    public function test_a_entity_with_projects_cannot_be_permanently_deleted(): void
    {
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();

        $entity->forceDelete();

        $this->assertModelExists($project);
        $this->assertDatabaseHas('PRO_projects', [
            'id' => $project->id,
            'entity_id' => $entity->id,
        ]);
    }

    public function test_empty_project_list_uses_the_shared_empty_state(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('data-test="list-empty-state"', false)
            ->assertSee('No records yet.');
    }

    public function test_guests_are_redirected_to_login_from_the_projects_list(): void
    {
        $this->get(route('projects.index'))->assertRedirect(route('login'));
    }
}
