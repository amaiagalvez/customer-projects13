<?php

namespace EntityProjects13\Tests\Feature;

use Basics13\Queries\ListQueryBase;
use Entities13\Models\Entity;
use Entities13\Queries\Entities\EntityListQuery;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Queries\Epics\EpicListQuery;
use Projects13\Queries\Projects\ProjectListQuery;

/**
 * Behaviour that comes from ListQueryBase, so it must hold for every resource that extends it.
 *
 * These cases lived only in EntityListQueryTest, which left the shared LIKE escaping and page
 * size verified for one resource out of three. Epic names are only unique inside their project, so
 * every epic here belongs to its own project.
 */
class ListQuerySharedBehaviourTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function resources(): array
    {
        return [
            'entities' => [EntityListQuery::class],
            'projects' => [ProjectListQuery::class],
            'epics' => [EpicListQuery::class],
        ];
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_active_lists_are_paginated_by_the_shared_page_size(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, ListQueryBase::PER_PAGE + 1);

        $records = $this->listQuery($queryClass)->active('');

        $this->assertSame(ListQueryBase::PER_PAGE, $records->perPage());
        $this->assertSame(ListQueryBase::PER_PAGE + 1, $records->total());
        $this->assertSame(2, $records->lastPage());
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_trashed_lists_are_paginated_by_the_shared_page_size(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, ListQueryBase::PER_PAGE + 1, trashed: true);

        $records = $this->listQuery($queryClass)->trashed('');

        $this->assertSame(ListQueryBase::PER_PAGE, $records->perPage());
        $this->assertSame(ListQueryBase::PER_PAGE + 1, $records->total());
        $this->assertSame(2, $records->lastPage());
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_search_keeps_the_term_in_the_pagination_urls(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 2, ['Alpha shared', 'Beta shared']);

        $records = $this->listQuery($queryClass)->active('Alpha');

        $this->assertSame(['Alpha shared'], $records->pluck('name')->all());
        $this->assertStringContainsString('search=Alpha', $records->url(2));
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_search_treats_percent_as_a_literal_character(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 2, ['Record 100%', 'Record 1000']);

        $this->assertSame(
            ['Record 100%'],
            $this->listQuery($queryClass)->active('%')->pluck('name')->all(),
        );
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_search_treats_underscore_as_a_literal_character(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 2, ['Alpha_One', 'AlphaXOne']);

        $this->assertSame(
            ['Alpha_One'],
            $this->listQuery($queryClass)->active('_')->pluck('name')->all(),
        );
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_search_treats_backslash_as_a_literal_character(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 2, ['Alpha\\One', 'AlphaOne']);

        $this->assertSame(
            ['Alpha\\One'],
            $this->listQuery($queryClass)->active('\\')->pluck('name')->all(),
        );
    }

    /**
     * An empty term must not narrow the list, and a term no record matches must return none.
     *
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_an_empty_search_returns_everything(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 3);

        $this->assertCount(3, $this->listQuery($queryClass)->active(''));
        $this->assertCount(0, $this->listQuery($queryClass)->active('nothing matches this'));
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('resources')]
    public function test_active_lists_exclude_trashed_records(string $queryClass): void
    {
        $this->makeRecordsFor($queryClass, 1, ['Live record']);
        $this->makeRecordsFor($queryClass, 1, ['Deleted record'], trashed: true);

        $this->assertSame(
            ['Live record'],
            $this->listQuery($queryClass)->active('')->pluck('name')->all(),
        );
    }

    /**
     * The tab badges must not re-run the count the paginator already knows. The budget is per
     * resource: entities load their types, projects load no extra records, while epics load their
     * project and its entity (one query each, whatever the number of rows).
     *
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('queryBudgets')]
    public function test_state_counts_reuse_the_total_the_paginator_already_knew(
        string $queryClass,
        int $expectedQueries,
    ): void {
        $this->makeRecordsFor($queryClass, 2);
        $this->makeRecordsFor($queryClass, 1, null, trashed: true);

        $query = $this->listQuery($queryClass);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $records = $query->active('');
            $counts = $query->stateCounts(activeTotal: $records->total());
            $executed = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(['active' => 2, 'archived' => 0, 'trashed' => 1], $counts);
        $this->assertCount($expectedQueries, $executed);
    }

    /**
     * A total that is not passed costs exactly the query it replaces: two, since only one of the
     * three states is already known.
     *
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    #[DataProvider('queryBudgets')]
    public function test_state_counts_run_one_query_per_unpassed_total(
        string $queryClass,
        int $expectedQueries,
    ): void {
        $query = $this->listQuery($queryClass);
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $counts = $query->stateCounts(archivedTotal: 0);
            $executed = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $this->assertSame(['active' => 0, 'archived' => 0, 'trashed' => 0], $counts);
        $this->assertCount(2, $executed);
    }

    /**
     * @return array<string, array{0: class-string, 1: int}>
     */
    public static function queryBudgets(): array
    {
        return [
            // One more than before: the active list loads the persons with their types, which the edit
            // drawer lists without a query of its own.
            'entities' => [EntityListQuery::class, 6],
            // One more than before: the active list loads the members, so each row tells whether the
            // user may manage them without a query of its own.
            'projects' => [ProjectListQuery::class, 5],
            'epics' => [EpicListQuery::class, 6],
        ];
    }

    /**
     * Creates records of the resource under test. Epic names are unique per project, so a factory
     * builds a project of its own for each epic.
     *
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     * @param  list<string>|null  $names
     * @return array<int, Entity|Project|Epic>
     */
    private function makeRecordsFor(string $queryClass, int $count, ?array $names = null, bool $trashed = false): array
    {
        $records = [];

        for ($index = 1; $index <= $count; $index++) {
            $records[] = $this->makeRecord($queryClass, $names === null ? [] : ['name' => $names[$index - 1]], $trashed);
        }

        return $records;
    }

    /**
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     * @param  array<string, mixed>  $attributes
     */
    private function makeRecord(string $queryClass, array $attributes, bool $trashed): Entity|Project|Epic
    {
        return match ($queryClass) {
            EntityListQuery::class => $trashed
                ? Entity::factory()->trashed()->create($attributes)
                : Entity::factory()->create($attributes),
            ProjectListQuery::class => $trashed
                ? Project::factory()->for($this->entity())->trashed()->create($attributes)
                : Project::factory()->for($this->entity())->create($attributes),
            default => $trashed
                ? Epic::factory()->for(Project::factory()->for($this->entity())->create())->trashed()->create($attributes)
                : Epic::factory()->for(Project::factory()->for($this->entity())->create())->create($attributes),
        };
    }

    /**
     * The concrete query for the resource under test, so PHPStan sees active() and trashed().
     *
     * @param  class-string<EntityListQuery|ProjectListQuery|EpicListQuery>  $queryClass
     */
    private function listQuery(string $queryClass): EntityListQuery|ProjectListQuery|EpicListQuery
    {
        return match ($queryClass) {
            EntityListQuery::class => app(EntityListQuery::class),
            ProjectListQuery::class => app(ProjectListQuery::class),
            default => app(EpicListQuery::class),
        };
    }
}
