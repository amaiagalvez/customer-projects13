<?php

namespace CustomerProjects13\Tests\Feature\Transformers;

use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Customers13\Transformers\CustomerListTransformer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\Project;
use Projects13\Transformers\EpicListTransformer;
use Projects13\Transformers\ProjectListTransformer;

class ListTransformerContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every resource answers the three list states with the same envelope, so the shared list
     * components can rely on it whichever resource and state they render.
     */
    private const ENVELOPE_KEYS = [
        'resource',
        'breadcrumbs',
        'extraDateHeading',
        'emptyMessage',
        'search',
        'tabs',
        'create',
        'rows',
    ];

    private const COUNTED_TABS = ['active' => 2, 'archived' => 1, 'trashed' => 3];

    /**
     * @return list<array{0: class-string, 1: 'active'|'archived'|'trash'}>
     */
    public static function listStates(): array
    {
        $cases = [];

        foreach (self::transformerClasses() as $transformer) {
            foreach (['active', 'archived', 'trash'] as $state) {
                $cases[] = [$transformer, $state];
            }
        }

        return $cases;
    }

    /**
     * @return list<array{0: class-string}>
     */
    public static function transformers(): array
    {
        return array_map(static fn (string $class): array => [$class], self::transformerClasses());
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     * @param  'active'|'archived'|'trash'  $state
     */
    #[DataProvider('listStates')]
    public function test_every_state_payload_has_the_expected_keys(string $transformerClass, string $state): void
    {
        app()->setLocale('en');
        $list = $this->payload($transformerClass, $state);
        $prefix = match ($transformerClass) {
            CustomerListTransformer::class => 'customers',
            ProjectListTransformer::class => 'projects',
            EpicListTransformer::class => 'epics',
            default => throw new \LogicException("Unsupported transformer class [{$transformerClass}]."),
        };

        $this->assertSame(self::ENVELOPE_KEYS, array_keys($list));
        $this->assertSame($state === 'active', $list['create']);
        $this->assertSame(
            route($prefix.'.'.match ($state) {
                'active' => 'index',
                'archived' => 'archived.index',
                'trash' => 'trash.index',
            }),
            $list['search']['action'],
        );
        $this->assertSame(
            [
                route($prefix.'.index'),
                route($prefix.'.archived.index'),
                route($prefix.'.trash.index'),
            ],
            array_column($list['tabs'], 'url'),
        );
        $this->assertSame(
            match ($state) {
                'active' => __('Created at'),
                'archived' => __('Updated at'),
                'trash' => __('Deleted at'),
            },
            $list['extraDateHeading'],
        );
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     */
    #[DataProvider('transformers')]
    public function test_state_tabs_count_records_only_when_counts_are_given(string $transformerClass): void
    {
        /** @var array<string, mixed> $withoutCounts */
        $withoutCounts = $this->payload($transformerClass, 'active');

        /** @var array<string, mixed> $withCounts */
        $withCounts = $this->payload($transformerClass, 'active', self::COUNTED_TABS);

        /** @var list<array{count: int|null}> $tabsWithoutCounts */
        $tabsWithoutCounts = $withoutCounts['tabs'];

        /** @var list<array{count: int|null}> $tabsWithCounts */
        $tabsWithCounts = $withCounts['tabs'];

        $this->assertSame([null, null, null], array_column($tabsWithoutCounts, 'count'));
        $this->assertSame([2, 1, 3], array_column($tabsWithCounts, 'count'));
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     */
    #[DataProvider('transformers')]
    public function test_every_resource_uses_the_same_active_empty_state_message(string $transformerClass): void
    {
        /** @var array<string, mixed> $list */
        $list = $this->payload($transformerClass, 'active');

        $this->assertSame('No records yet.', $list['emptyMessage']);
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     */
    #[DataProvider('transformers')]
    public function test_every_resource_uses_the_same_no_match_message(string $transformerClass): void
    {
        /** @var array<string, mixed> $list */
        $list = $this->payload($transformerClass, 'active', null, 'missing');

        $this->assertSame('No records match your search.', $list['emptyMessage']);
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     */
    #[DataProvider('transformers')]
    public function test_each_state_marks_only_its_own_tab_as_current(string $transformerClass): void
    {
        foreach (['active', 'archived', 'trash'] as $state) {
            /** @var array<string, mixed> $list */
            $list = $this->payload($transformerClass, $state);

            /** @var list<array{current: bool}> $tabs */
            $tabs = $list['tabs'];

            $this->assertSame(
                [$state === 'active', $state === 'archived', $state === 'trash'],
                array_column($tabs, 'current'),
                "{$transformerClass}: only the tab of [{$state}] may be current",
            );
        }
    }

    /**
     * A row exposes the same action envelope in every state.
     *
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     * @param  'active'|'archived'|'trash'  $state
     */
    #[DataProvider('listStates')]
    public function test_rows_carry_the_shared_action_envelope_in_every_state(string $transformerClass, string $state): void
    {
        /** @var array<string, mixed> $list */
        $list = $this->payloadWithOneRecord($transformerClass, $state);

        /** @var list<array<string, mixed>> $rows */
        $rows = $list['rows'];

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('actions', $rows[0]);
        $this->assertNotEmpty($rows[0]['actions']);

        /** @var list<array<string, mixed>> $actions */
        $actions = $rows[0]['actions'];

        foreach ($actions as $action) {
            $this->assertArrayHasKey('type', $action);
            $this->assertArrayHasKey('label', $action);
            $this->assertArrayHasKey('test', $action);
        }
    }

    /**
     * @return list<class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>>
     */
    private static function transformerClasses(): array
    {
        return [
            CustomerListTransformer::class,
            ProjectListTransformer::class,
            EpicListTransformer::class,
        ];
    }

    /** @return LengthAwarePaginator<int, Customer> */
    private function customers(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** @return LengthAwarePaginator<int, Project> */
    private function projects(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /** @return LengthAwarePaginator<int, Epic> */
    private function epics(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     * @param  'active'|'archived'|'trash'  $state
     * @param  array{active: int, archived: int, trashed: int}|null  $counts
     * @return array<string, mixed>
     */
    private function payload(string $transformerClass, string $state, ?array $counts = null, string $search = ''): array
    {
        return match (true) {
            $transformerClass === CustomerListTransformer::class => $this->customerState(
                app(CustomerListTransformer::class),
                $this->customers(),
                $state,
                $search,
                $counts,
            ),
            $transformerClass === ProjectListTransformer::class => $this->projectState(
                app(ProjectListTransformer::class),
                $this->projects(),
                $state,
                $search,
                $counts,
            ),
            default => $this->epicState(
                app(EpicListTransformer::class),
                $this->epics(),
                $state,
                $search,
                $counts,
            ),
        };
    }

    /**
     * @param  class-string<CustomerListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     * @param  'active'|'archived'|'trash'  $state
     * @return array<string, mixed>
     */
    private function payloadWithOneRecord(string $transformerClass, string $state): array
    {
        return match (true) {
            $transformerClass === CustomerListTransformer::class => $this->customerState(
                app(CustomerListTransformer::class),
                new LengthAwarePaginator([Customer::factory()->create()], 1, 15),
                $state,
                '',
            ),
            $transformerClass === ProjectListTransformer::class => $this->projectState(
                app(ProjectListTransformer::class),
                new LengthAwarePaginator([Project::factory()->for($this->customer())->create()], 1, 15),
                $state,
                '',
            ),
            default => $this->epicState(
                app(EpicListTransformer::class),
                new LengthAwarePaginator([Epic::factory()->create()], 1, 15),
                $state,
                '',
            ),
        };
    }

    /**
     * @param  LengthAwarePaginator<int, Customer>  $paginator
     * @param  'active'|'archived'|'trash'  $state
     * @param  array{active: int, archived: int, trashed: int}|null  $counts
     * @return array<string, mixed>
     */
    private function customerState(
        CustomerListTransformer $transformer,
        LengthAwarePaginator $paginator,
        string $state,
        string $search,
        ?array $counts = null,
    ): array {
        /** @var array<string, mixed> $list */
        $list = match ($state) {
            'active' => $transformer->active($paginator, $search, $counts),
            'archived' => $transformer->archived($paginator, $search, $counts),
            default => $transformer->trash($paginator, $search, $counts),
        };

        return $list;
    }

    /**
     * @param  LengthAwarePaginator<int, Project>  $paginator
     * @param  'active'|'archived'|'trash'  $state
     * @param  array{active: int, archived: int, trashed: int}|null  $counts
     * @return array<string, mixed>
     */
    private function projectState(
        ProjectListTransformer $transformer,
        LengthAwarePaginator $paginator,
        string $state,
        string $search,
        ?array $counts = null,
    ): array {
        /** @var array<string, mixed> $list */
        $list = match ($state) {
            'active' => $transformer->active($paginator, $search, $counts),
            'archived' => $transformer->archived($paginator, $search, $counts),
            default => $transformer->trash($paginator, $search, $counts),
        };

        return $list;
    }

    /**
     * @param  LengthAwarePaginator<int, Epic>  $paginator
     * @param  'active'|'archived'|'trash'  $state
     * @param  array{active: int, archived: int, trashed: int}|null  $counts
     * @return array<string, mixed>
     */
    private function epicState(
        EpicListTransformer $transformer,
        LengthAwarePaginator $paginator,
        string $state,
        string $search,
        ?array $counts = null,
    ): array {
        /** @var array<string, mixed> $list */
        $list = match ($state) {
            'active' => $transformer->active($paginator, $search, $counts),
            'archived' => $transformer->archived($paginator, $search, $counts),
            default => $transformer->trash($paginator, $search, $counts),
        };

        return $list;
    }
}
