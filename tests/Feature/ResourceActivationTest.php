<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class ResourceActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    /**
     * @return array<string, array{class-string<Entity|Project|Epic>, string}>
     */
    public static function resources(): array
    {
        return [
            'entities' => [Entity::class, 'entities'],
            'projects' => [Project::class, 'projects'],
            'epics' => [Epic::class, 'epics'],
        ];
    }

    /**
     * @return array<string, array{class-string<Model>, string, string}>
     */
    public static function resourcesWithTestPrefix(): array
    {
        return [
            'entities' => [Entity::class, 'entities', 'entity'],
            'projects' => [Project::class, 'projects', 'project'],
            'epics' => [Epic::class, 'epics', 'epic'],
        ];
    }

    /**
     * @return array<string, array{class-string<Entity|Project|Epic>, string, string, int, string, int, int}>
     */
    public static function listCountCases(): array
    {
        $cases = [];

        foreach (self::resources() as $resource => [$model, $routePrefix]) {
            foreach (['active' => '.index', 'archived' => '.archived.index', 'trash' => '.trash.index'] as $state => $suffix) {
                foreach (
                    [
                        'unfiltered' => [2, '', 2, 3],
                        'empty' => [0, '', 0, 3],
                        'matching search' => [2, 'Matching', 1, 4],
                        'unmatched search' => [2, 'Missing', 0, 4],
                    ] as $scenario => [$count, $search, $total, $countQueries]
                ) {
                    $cases[$resource . ' ' . $state . ' ' . $scenario] = [
                        $model,
                        $resource,
                        $routePrefix . $suffix,
                        $count,
                        $search,
                        $total,
                        $countQueries,
                    ];
                }
            }
        }

        return $cases;
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('listCountCases')]
    public function test_lists_avoid_redundant_counts_and_keep_global_tab_totals(
        string $model,
        string $resource,
        string $route,
        int $count,
        string $search,
        int $total,
        int $expectedCountQueries,
    ): void {
        $this->actingAs(User::factory()->create());
        $model::factory()->count($count)->sequence(['name' => 'Matching active'], ['name' => 'Other active'])->create();
        $model::factory()->count($count)->archived()->sequence(['name' => 'Matching archived'], ['name' => 'Other archived'])->create();
        $model::factory()->count($count)->trashed()->sequence(['name' => 'Matching trashed'], ['name' => 'Other trashed'])->create();
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->get(route($route, ['search' => $search]));
            $countQueries = collect(DB::getQueryLog())->filter(
                static fn(array $query): bool => preg_match(
                    '/^select count\((?:\*|distinct ' . preg_quote((new $model)->getTable(), '/') . '\.id)\) as aggregate from '
                        . preg_quote((new $model)->getTable(), '/') . ' /',
                    str_replace(['`', '"'], '', $query['query']),
                ) === 1,
            );
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertOk();
        $records = $response->viewData($resource);
        $this->assertInstanceOf(LengthAwarePaginator::class, $records);
        $this->assertSame($total, $records->total());
        $list = $response->viewData('list');
        $this->assertIsArray($list);
        $this->assertIsArray($list['tabs']);
        $this->assertSame([$count, $count, $count], array_column($list['tabs'], 'count'));
        $this->assertCount($expectedCountQueries, $countQueries);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_lists_partition_records_by_activation_and_trash(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $model::factory()->create(['name' => 'Active record']);
        $model::factory()->archived()->create(['name' => 'Inactive record']);
        $model::factory()->trashed()->create(['name' => 'Trashed active record']);
        $model::factory()->archived()->trashed()->create(['name' => 'Trashed archived record']);

        $this->get(route($resource . '.index'))
            ->assertOk()
            ->assertSee('Active record')
            ->assertDontSee('Inactive record')
            ->assertDontSee('Trashed active record');

        $this->get(route($resource . '.archived.index'))
            ->assertOk()
            ->assertSee('Inactive record')
            ->assertDontSee('Active record')
            ->assertDontSee('Trashed archived record');

        $this->get(route($resource . '.trash.index'))
            ->assertOk()
            ->assertSee('Trashed active record')
            ->assertSee('Trashed archived record')
            ->assertDontSee('>Active record<', false)
            ->assertDontSee('>Inactive record<', false);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resourcesWithTestPrefix')]
    public function test_archived_list_shows_records_and_only_offers_reactivation(
        string $model,
        string $resource,
        string $prefix,
    ): void {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-02 12:30:00');
        $record = $model::factory()->archived()->create(['name' => 'Dormant record']);

        $this->get(route($resource . '.archived.index'))
            ->assertOk()
            ->assertSeeInOrder(['<thead', __('Name')], false)
            ->assertSee('Dormant record')
            ->assertSee('data-test="' . $prefix . '-activate-' . $record->id . '"', false)
            ->assertDontSee('data-test="' . $prefix . '-edit-' . $record->id . '"', false)
            ->assertDontSee('data-test="' . $prefix . '-delete-' . $record->id . '"', false)
            ->assertDontSee('data-test="' . $prefix . '-archive-' . $record->id . '"', false);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_record_can_be_deactivated_and_reactivated(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-01 08:00:00');
        $record = $model::factory()->create();
        $this->travelTo('2026-10-02 10:00:00');

        $this->patch(route($resource . '.archive', $record))
            ->assertRedirect(route($resource . '.index'))
            ->assertSessionHas('status', __('basics13::messages.archived'));

        $record->refresh();
        $this->assertFalse($record->active);
        $this->assertSame('2026-10-02 10:00:00', $record->updated_at->toDateTimeString());

        $this->patch(route($resource . '.archived.activate', $record))
            ->assertRedirect(route($resource . '.archived.index'))
            ->assertSessionHas('status', __('basics13::messages.activated'));

        $this->assertTrue($record->refresh()->active);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_repeated_activation_actions_are_idempotent(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $archived = $model::factory()->archived()->create();
        $active = $model::factory()->create();

        $this->patch(route($resource . '.archive', $archived))
            ->assertSessionHas('status', __('basics13::messages.archived'));
        $this->patch(route($resource . '.archived.activate', $active))
            ->assertSessionHas('status', __('basics13::messages.activated'));

        $this->assertFalse($archived->refresh()->active);
        $this->assertTrue($active->refresh()->active);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_trashed_records_cannot_change_activation(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $active = $model::factory()->trashed()->create();
        $archived = $model::factory()->archived()->trashed()->create();

        $this->patch(route($resource . '.archive', $active->id))->assertNotFound();
        $this->patch(route($resource . '.archived.activate', $archived->id))->assertNotFound();

        $this->assertTrue($active->refresh()->active);
        $this->assertFalse($archived->refresh()->active);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_restoring_an_archived_record_keeps_it_archived(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $record = $model::factory()->archived()->trashed()->create(['name' => 'Restored dormant record']);

        $this->patch(route($resource . '.trash.restore', $record->id))->assertRedirect();

        $record->refresh();
        $this->assertNotSoftDeleted($record);
        $this->assertFalse($record->active);
        $this->get(route($resource . '.archived.index'))->assertSee('Restored dormant record');
        $this->get(route($resource . '.index'))->assertDontSee('Restored dormant record');
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_guests_cannot_access_activation_endpoints(string $model, string $resource): void
    {
        $record = $model::factory()->create();
        $archived = $model::factory()->archived()->create();

        $this->get(route($resource . '.archived.index'))->assertRedirect(route('login'));
        $this->patch(route($resource . '.archive', $record))->assertRedirect(route('login'));
        $this->patch(route($resource . '.archived.activate', $archived))->assertRedirect(route('login'));

        $this->assertTrue($record->refresh()->active);
        $this->assertFalse($archived->refresh()->active);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_unverified_users_cannot_change_activation(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->unverified()->create());
        $record = $model::factory()->create();

        $this->patch(route($resource . '.archive', $record))->assertRedirect(route('verification.notice'));

        $this->assertTrue($record->refresh()->active);
    }

    public function test_policy_allows_verified_users_to_change_activation(): void
    {
        $user = User::factory()->create();

        foreach ([Entity::factory()->create(), Project::factory()->for($this->entity())->create(), Epic::factory()->create()] as $record) {
            $this->assertTrue($user->can('archive', $record));
            $this->assertTrue($user->can('activate', $record));
        }
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function childStates(): array
    {
        return [
            'active child' => [[]],
            'archived child' => [['archived']],
            'trashed child' => [['trashed']],
            'trashed archived child' => [['archived', 'trashed']],
        ];
    }

    /**
     * @param  list<string>  $states
     */
    #[DataProvider('childStates')]
    public function test_entity_with_any_project_offers_deactivation_instead_of_deletion(array $states): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create();
        $projectFactory = Project::factory()->for($entity);

        foreach ($states as $state) {
            $projectFactory = $projectFactory->{$state}();
        }

        $projectFactory->create();

        $this->get(route('entities.index'))
            ->assertSee('data-test="entity-archive-' . $entity->id . '"', false)
            ->assertDontSee('data-test="entity-delete-' . $entity->id . '"', false);
    }

    /**
     * @param  list<string>  $states
     */
    #[DataProvider('childStates')]
    public function test_project_with_any_epic_offers_deactivation_instead_of_deletion(array $states): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->entity())->create();
        $epicFactory = Epic::factory()->for($project);

        foreach ($states as $state) {
            $epicFactory = $epicFactory->{$state}();
        }

        $epicFactory->create();

        $this->get(route('projects.index'))
            ->assertSee('data-test="project-archive-' . $project->id . '"', false)
            ->assertDontSee('data-test="project-delete-' . $project->id . '"', false);
    }

    public function test_epics_without_comments_offer_deletion(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();

        $this->get(route('epics.index'))
            ->assertSee('data-test="epic-delete-' . $epic->id . '"', false)
            ->assertDontSee('data-test="epic-archive-' . $epic->id . '"', false);
    }

    public function test_epics_with_comments_offer_deactivation_instead_of_deletion(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();
        $comment = EpicComment::factory()->for($epic)->create();

        $this->get(route('epics.index'))
            ->assertSee('data-test="epic-archive-' . $epic->id . '"', false)
            ->assertDontSee('data-test="epic-delete-' . $epic->id . '"', false)
            ->assertSee(__('basics13::messages.archive_instead_of_delete'));

        $this->delete(route('epics.destroy', $epic))
            ->assertRedirect(route('epics.index'))
            ->assertSessionHas('error', __('Cannot be deleted while it has related records.'));

        $this->patch(route('epics.archive', $epic))
            ->assertRedirect(route('epics.index'))
            ->assertSessionHas('status', __('basics13::messages.archived'));

        $this->assertFalse($epic->refresh()->active);
        $this->assertModelExists($epic);
        $this->assertModelExists($comment);
    }

    public function test_archived_entities_are_searchable_by_name_and_update_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-03-01 10:00:00');
        $updatedEntity = Entity::factory()->archived()->create(['name' => 'Alpha Dormant']);
        Entity::factory()->archived()->create(['name' => 'Beta Dormant']);
        Entity::factory()->create(['name' => 'Alpha Active']);
        $this->travelTo('2026-07-15 10:00:00');
        $updatedEntity->touch();

        $this->get(route('entities.archived.index', ['search' => 'Alpha']))
            ->assertSee('Alpha Dormant')
            ->assertDontSee('Beta Dormant')
            ->assertDontSee('Alpha Active');

        $this->get(route('entities.archived.index', ['search' => '2026-07-15']))
            ->assertSee('Alpha Dormant')
            ->assertDontSee('Beta Dormant');
    }

    public function test_archived_projects_are_searchable_by_entity_name_and_update_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-03-01 10:00:00');
        $entity = Entity::factory()->create(['name' => 'Searchable Entity']);
        $otherEntity = Entity::factory()->create(['name' => 'Other Entity']);
        $dates = ['start_date' => '2026-01-01', 'end_date' => '2026-02-01'];
        Project::factory()->for($entity)->archived()->create(['name' => 'Matching project', ...$dates]);
        $otherProject = Project::factory()->for($otherEntity)->archived()->create(['name' => 'Other project', ...$dates]);
        Project::factory()->for($entity)->create(['name' => 'Active project']);
        $this->travelTo('2026-07-15 10:00:00');
        $otherProject->touch();

        $this->get(route('projects.archived.index', ['search' => 'Searchable Entity']))
            ->assertSee('Matching project')
            ->assertDontSee('Other project')
            ->assertDontSee('Active project');

        $this->get(route('projects.archived.index', ['search' => '2026-07-15']))
            ->assertSee('Other project')
            ->assertDontSee('Matching project');
    }

    public function test_archived_epics_are_searchable_by_project_and_entity_names(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create(['name' => 'Epic Entity']);
        $project = Project::factory()->for($entity)->create(['name' => 'Epic Project']);
        $otherProject = Project::factory()->for(Entity::factory()->create(['name' => 'Other Entity']))
            ->create(['name' => 'Other Project']);
        Epic::factory()->for($project)->archived()->create(['name' => 'Matching epic']);
        Epic::factory()->for($otherProject)->archived()->create(['name' => 'Other epic']);
        Epic::factory()->for($project)->create(['name' => 'Active epic']);

        foreach (['Epic Entity', 'Epic Project'] as $search) {
            $this->get(route('epics.archived.index', ['search' => $search]))
                ->assertSee('Matching epic')
                ->assertDontSee('Other epic')
                ->assertDontSee('Active epic');
        }
    }

    /**
     * The archived list of every resource appends the last modification date and lets the user
     * search it; epics had only their parent names covered.
     */
    public function test_archived_epics_are_searchable_by_update_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-03-01 10:00:00');
        $updatedEpic = Epic::factory()->archived()->create([
            'name' => 'Alpha Dormant',
            'start_date' => null,
            'end_date' => null,
        ]);
        Epic::factory()->archived()->create([
            'name' => 'Beta Dormant',
            'start_date' => null,
            'end_date' => null,
        ]);
        $this->travelTo('2026-07-15 10:00:00');
        $updatedEpic->touch();

        $response = $this->get(route('epics.archived.index', ['search' => '2026-07-15']))
            ->assertOk()
            ->assertSee('Alpha Dormant');

        $list = $response->viewData('list');
        $this->assertIsArray($list);
        $rows = $list['rows'] ?? null;
        $this->assertIsArray($rows);

        /** @var array<int, array{name: string}> $rows */
        $this->assertSame(['Alpha Dormant'], array_column($rows, 'name'));
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resources')]
    public function test_archived_list_fragment_only_returns_results(string $model, string $resource): void
    {
        $this->actingAs(User::factory()->create());
        $model::factory()->archived()->create(['name' => 'Fragment record']);

        $this->get(route($resource . '.archived.index'), ['X-List-Fragment' => 'true'])
            ->assertOk()
            ->assertSee('data-list-results', false)
            ->assertSee('Fragment record')
            ->assertDontSee('<!DOCTYPE html>', false);
    }

    /**
     * @param  class-string<Entity|Project|Epic>  $model
     */
    #[DataProvider('resourcesWithTestPrefix')]
    public function test_archived_list_paginates_only_archived_records(
        string $model,
        string $resource,
        string $testPrefix,
    ): void {
        $this->actingAs(User::factory()->create());

        for ($index = 1; $index <= ListQueryBase::PER_PAGE + 1; $index++) {
            $model::factory()->archived()->create(['name' => "Inactive record {$index}"]);
        }

        for ($index = 1; $index <= 2; $index++) {
            $model::factory()->create(['name' => "Active record {$index}"]);
        }

        $model::factory()->archived()->trashed()->create(['name' => 'Trashed archived record']);

        $firstPage = $this->get(route($resource . '.archived.index'));
        $firstPage
            ->assertOk()
            ->assertDontSee('Active record 1')
            ->assertDontSee('Active record 2')
            ->assertDontSee('Trashed archived record');
        $firstPageContent = $firstPage->getContent();
        $this->assertIsString($firstPageContent);
        $this->assertSame(
            ListQueryBase::PER_PAGE,
            substr_count($firstPageContent, 'data-test="' . $testPrefix . '-activate-'),
        );

        $secondPage = $this->get(route($resource . '.archived.index', ['page' => 2]));
        $secondPage
            ->assertOk()
            ->assertDontSee('Active record 1')
            ->assertDontSee('Active record 2')
            ->assertDontSee('Trashed archived record');
        $secondPageContent = $secondPage->getContent();
        $this->assertIsString($secondPageContent);
        $this->assertSame(
            1,
            substr_count($secondPageContent, 'data-test="' . $testPrefix . '-activate-'),
        );
    }

    public function test_childless_parents_keep_the_delete_action(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create();
        // The project needs a entity of its own: ProjectFactory adopts whichever entity the
        // factory finds first, which would make the childless entity below a parent after all.
        $project = Project::factory()->for(Entity::factory()->create())->create();

        $this->get(route('entities.index'))
            ->assertSee('data-test="entity-delete-' . $entity->id . '"', false)
            ->assertDontSee('data-test="entity-archive-' . $entity->id . '"', false);

        $this->get(route('projects.index'))
            ->assertSee('data-test="project-delete-' . $project->id . '"', false)
            ->assertDontSee('data-test="project-archive-' . $project->id . '"', false);
    }

    public function test_deactivating_a_parent_does_not_cascade_to_children(): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create();
        $epic = Epic::factory()->for($project)->create();

        $this->patch(route('entities.archive', $entity));
        $this->patch(route('projects.archive', $project));

        $this->assertFalse($entity->refresh()->active);
        $this->assertFalse($project->refresh()->active);
        $this->assertTrue($epic->refresh()->active);
    }

    public function test_archived_entity_keeps_its_name_reserved(): void
    {
        $this->actingAs(User::factory()->create());
        Entity::factory()->archived()->create(['name' => 'Reserved name']);

        $this->post(route('entities.store'), ['name' => 'Reserved name'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount((new Entity)->getTable(), 1);
    }
}
