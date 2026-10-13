<?php

namespace CustomerProjects13\Tests\Feature\Epics;

use App\Models\User;
use Basics13\Queries\ListQueryBase;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use Projects13\Transformers\EpicListTransformer;

class EpicCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_authenticated_users_can_create_update_and_delete_epics(): void
    {
        $this->actingAs(User::factory()->create());
        // The epic dates below are fixed, so the projects they are validated against need a window
        // that always covers them: the factory would otherwise randomise them away.
        $project = Project::factory()->for($this->customer())->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        $otherProject = Project::factory()->for($this->customer())->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->post(route('epics.store'), [
            'name' => '  Checkout flow  ',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHas('status', __('Record created successfully.'));

        $epic = Epic::query()->firstOrFail();
        $this->assertSame('Checkout flow', $epic->name);

        $this->assertTrue($epic->project->is($project));

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('epic-create-button')
            ->assertSee('aria-labelledby="epic-form-heading"', false)
            ->assertSee('id="epic-form-heading"', false)
            ->assertSee('aria-labelledby="epic-confirm-heading"', false)
            ->assertSee('id="epic-confirm-heading"', false)
            ->assertSee('epic-edit-'.$epic->id)
            ->assertSee('Checkout flow')
            ->assertSee('title="Checkout flow"', false)
            ->assertSee($project->name)
            ->assertSee($project->customer->name);

        $this->put(route('epics.update', $epic), [
            'name' => 'Payment flow',
            'start_date' => '',
            'end_date' => '',
            'project_id' => $otherProject->id,
        ])->assertRedirect(route('epics.index'))
            ->assertSessionHas('status', __('Record updated successfully.'));

        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'name' => 'Payment flow',
            'start_date' => null,
            'end_date' => null,
            'project_id' => $otherProject->id,
        ]);

        $this->delete(route('epics.destroy', $epic))
            ->assertRedirect(route('epics.index'))
            ->assertSessionHas('status', __('Record moved to trash.'));

        $this->assertSoftDeleted($epic);
    }

    public function test_epic_store_persists_only_validated_attributes(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        $this->post(route('epics.store'), [
            'name' => 'Validated epic',
            'project_id' => $project->id,
            'id' => 999999,
            'created_at' => '2000-01-01 00:00:00',
        ])->assertRedirect(route('epics.index'));

        $this->assertDatabaseHas('PRO_epics', [
            'name' => 'Validated epic',
            'project_id' => $project->id,
        ]);
        $this->assertDatabaseMissing('PRO_epics', [
            'name' => 'Validated epic',
            'id' => 999999,
        ]);
        $this->assertDatabaseMissing('PRO_epics', [
            'name' => 'Validated epic',
            'created_at' => '2000-01-01 00:00:00',
        ]);
    }

    public function test_a_project_can_have_multiple_epics(): void
    {
        $project = Project::factory()->for($this->customer())->create();

        Epic::factory()->count(2)->for($project)->create();

        $this->assertCount(2, $project->epics);
    }

    public function test_epic_list_transformer_renders_the_parents_it_is_bound_to(): void
    {
        $customer = Customer::factory()->create(['name' => 'Bound customer']);
        $project = Project::factory()->for($customer)->create(['name' => 'Bound project']);
        $epic = Epic::factory()->for($project)->create();

        $list = app(EpicListTransformer::class)->active(
            new LengthAwarePaginator([$epic], 1, 15),
            '',
        );

        $this->assertSame('Bound project', data_get($list, 'rows.0.project'));
        $this->assertSame('Bound customer', data_get($list, 'rows.0.customer'));
        $this->assertSame(
            'Bound project (Bound customer)',
            data_get($list, 'rows.0.actions.0.payload.project_label'),
        );
    }

    /**
     * An epic cannot exist without a project: the column is NOT NULL, the foreign key restricts
     * deletion and the relation resolves soft-deleted projects, so the row always renders a name.
     */
    public function test_a_project_with_epics_cannot_be_permanently_deleted(): void
    {
        $project = Project::factory()->for($this->customer())->create();
        $epic = Epic::factory()->for($project)->create();

        $project->forceDelete();

        $this->assertModelExists($epic);
        $this->assertDatabaseHas('PRO_epics', [
            'id' => $epic->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_empty_epic_list_uses_the_shared_empty_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('epics.index'))
            ->assertOk()
            ->assertSee('data-test="list-empty-state"', false)
            ->assertSee(__('No epics yet.'));
    }

    public function test_database_rejects_duplicate_epic_names_in_the_same_project(): void
    {
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->create(['name' => 'Shared epic']);

        $this->expectException(QueryException::class);

        Epic::factory()->for($project)->create(['name' => 'Shared epic']);
    }

    public function test_database_allows_the_same_epic_name_in_different_projects_and_after_soft_delete(): void
    {
        $project = Project::factory()->for($this->customer())->create();
        $deletedEpic = Epic::factory()->for($project)->trashed()->create(['name' => 'Shared epic']);
        $activeEpic = Epic::factory()->for($project)->create(['name' => 'Shared epic']);
        // The third epic needs a project of its own: the factory adopts whichever project it
        // finds first, which would make the name collide inside the project above.
        $otherProject = Project::factory()->for($this->customer())->create(['name' => 'Other project']);
        $otherProjectEpic = Epic::factory()->for($otherProject)->create(['name' => 'Shared epic']);

        $this->assertSoftDeleted($deletedEpic);
        $this->assertModelExists($activeEpic);
        $this->assertModelExists($otherProjectEpic);
    }

    public function test_epics_are_ordered_by_start_date_end_date_project_and_customer(): void
    {
        $this->actingAs(User::factory()->create());
        $alphaCustomer = Customer::factory()->create(['name' => 'Alpha customer']);
        $zuluCustomer = Customer::factory()->create(['name' => 'Zulu customer']);
        $alphaProject = Project::factory()->for($zuluCustomer)->create(['name' => 'Alpha project']);
        $zuluProjectAlphaCustomer = Project::factory()->for($alphaCustomer)->create(['name' => 'Zulu project A']);
        $zuluProjectZuluCustomer = Project::factory()->for($zuluCustomer)->create(['name' => 'Zulu project Z']);

        $dates = ['start_date' => '2026-10-01', 'end_date' => '2026-10-03'];
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic later start', 'start_date' => '2026-10-02', 'end_date' => '2026-10-03']);
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic later end', 'start_date' => '2026-10-01', 'end_date' => '2026-10-04']);
        Epic::factory()->for($zuluProjectZuluCustomer)->create(['name' => 'Epic zulu customer', ...$dates]);
        Epic::factory()->for($zuluProjectAlphaCustomer)->create(['name' => 'Epic alpha customer', ...$dates]);
        Epic::factory()->for($alphaProject)->create(['name' => 'Epic alpha project', ...$dates]);

        // Fill the first page so the undated epic, which always sorts last, lands on the second one.
        for ($index = 6; $index <= ListQueryBase::PER_PAGE; $index++) {
            Epic::factory()->for($alphaProject)->create([
                'name' => "Epic filler {$index}",
                ...$dates,
            ]);
        }

        Epic::factory()->withoutDates()->for($alphaProject)->create(['name' => 'Epic without dates']);

        $this->get(route('epics.index'))
            ->assertSeeInOrder([
                'Epic alpha project',
                'Epic alpha customer',
                'Epic zulu customer',
                'Epic later end',
                'Epic later start',
            ]);

        $this->get(route('epics.index', ['page' => 2]))
            ->assertSee('Epic without dates');
    }

    public function test_epics_can_be_searched_by_project_and_customer_name(): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'Northwind Studio']);
        $otherCustomer = Customer::factory()->create(['name' => 'Southwind Studio']);
        Epic::factory()->for(Project::factory()->for($customer)->state(['name' => 'Website']))->create(['name' => 'Matching epic']);
        // Both parents are pinned: the factories adopt whichever record they find first, which
        // would hang the other epic off Northwind as well.
        Epic::factory()->for(Project::factory()->for($otherCustomer)->state(['name' => 'Other project']))->create(['name' => 'Other epic']);

        $this->get(route('epics.index', ['search' => 'Northwind']))
            ->assertOk()
            ->assertSee('Matching epic')
            ->assertDontSee('Other epic');

        $this->get(route('epics.index', ['search' => 'Website']))
            ->assertOk()
            ->assertSee('Matching epic')
            ->assertDontSee('Other epic');
    }

    public function test_epics_can_be_searched_by_their_name(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->create(['name' => 'Searchable epic']);
        Epic::factory()->for($project)->create(['name' => 'Unrelated epic']);

        $this->get(route('epics.index', ['search' => 'Searchable epic']))
            ->assertOk()
            ->assertSee('Searchable epic')
            ->assertDontSee('Unrelated epic');
    }

    public function test_epic_name_with_non_latin_characters_can_be_stored_and_searched(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        $name = 'Ñandú プロジェクト';

        $this->post(route('epics.store'), [
            'name' => $name,
            'project_id' => $project->id,
        ])->assertRedirect(route('epics.index'));

        $this->assertDatabaseHas('PRO_epics', ['name' => $name, 'project_id' => $project->id]);

        $this->get(route('epics.index', ['search' => $name]))
            ->assertOk()
            ->assertSee($name);
    }

    public function test_epic_search_is_kept_in_pagination_links(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();

        foreach (range(1, 6) as $index) {
            Epic::factory()->for($project)->create(['name' => "Repeat epic {$index}"]);
        }

        $this->get(route('epics.index', ['search' => 'Repeat']))
            ->assertOk()
            ->assertSee('search=Repeat', false);
    }

    public function test_epics_can_be_searched_by_start_and_end_date(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->for($project)->create([
            'name' => 'Epic starts on date',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);
        Epic::factory()->for($project)->create([
            'name' => 'Epic ends on date',
            'start_date' => '2026-09-01',
            'end_date' => '2026-10-01',
        ]);
        Epic::factory()->for($project)->create([
            'name' => 'Epic on other dates',
            'start_date' => '2026-10-02',
            'end_date' => '2026-10-03',
        ]);

        $this->get(route('epics.index', ['search' => '2026-10-01']))
            ->assertOk()
            ->assertSee('Epic starts on date')
            ->assertSee('Epic ends on date')
            ->assertDontSee('Epic on other dates');
    }

    public function test_epic_list_shows_pagination_when_more_than_one_page_exists(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        for ($index = 1; $index <= ListQueryBase::PER_PAGE + 1; $index++) {
            Epic::factory()->for($project)->create();
        }

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('epic-pagination', false);
    }

    public function test_epic_list_shows_the_number_of_comments(): void
    {
        $this->actingAs(User::factory()->create());
        $epic = Epic::factory()->create();
        EpicComment::factory()->count(3)->for($epic)->create();

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSeeInOrder(['epic-comments-count-'.$epic->id, '3']);
    }

    public function test_epic_list_header_offers_the_state_tabs_with_the_record_count_of_each_one(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        Epic::factory()->count(2)->for($project)->create();
        Epic::factory()->for($project)->archived()->create();
        Epic::factory()->count(3)->for($project)->trashed()->create();

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSee('data-test="epic-breadcrumbs"', false)
            ->assertSeeInOrder([
                'href="'.route('dashboard').'"',
                __('Dashboard'),
                __('Epics'),
            ], false)
            ->assertSee('data-test="epic-heading"', false)
            ->assertSeeInOrder([
                'data-test="epic-active-link"',
                'aria-current="page"',
                'data-test="epic-archived-link"',
                'data-test="epic-trash-link"',
            ], false)
            ->assertSeeInOrder(['data-test="epic-active-link"', '>2</span>'], false)
            ->assertSeeInOrder(['data-test="epic-archived-link"', '>1</span>'], false)
            ->assertSeeInOrder(['data-test="epic-trash-link"', '>3</span>'], false);

        $this->get(route('epics.archived.index'))
            ->assertOk()
            ->assertSeeInOrder([__('Dashboard'), __('Epics'), __('Archived')], false);

        $this->get(route('epics.trash.index'))
            ->assertOk()
            ->assertSeeInOrder([__('Dashboard'), __('Epics'), __('Trash')], false);
    }

    public function test_epic_row_edits_from_the_name(): void
    {
        $this->actingAs(User::factory()->create());
        $project = Project::factory()->for($this->customer())->create();
        $epic = Epic::factory()->for($project)->create(['name' => 'Editable from the row']);

        $this->get(route('epics.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'data-test="epic-name-'.$epic->id.'"',
                'editEpic(JSON.parse(',
            ], false)
            ->assertSee('data-test="epic-comments-count-'.$epic->id.'"></span>', false)
            ->assertSee('data-test="epic-delete-'.$epic->id.'"', false);
    }

    public function test_guests_are_redirected_to_login_from_the_epics_list(): void
    {
        $this->get(route('epics.index'))->assertRedirect(route('login'));
    }

    public function test_guests_cannot_create_update_or_delete_epics(): void
    {
        $project = Project::factory()->for($this->customer())->create();
        $epic = Epic::factory()->for($project)->create(['name' => 'Existing epic']);

        $this->post(route('epics.store'), [
            'name' => 'Guest epic',
            'project_id' => $project->id,
        ])->assertRedirect(route('login'));

        $this->put(route('epics.update', $epic), [
            'name' => 'Updated epic',
            'project_id' => $project->id,
        ])->assertRedirect(route('login'));

        $this->delete(route('epics.destroy', $epic))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('PRO_epics', 1);
        $this->assertDatabaseHas('PRO_epics', ['id' => $epic->id, 'name' => 'Existing epic']);
        $this->assertNotSoftDeleted($epic);
    }
}
