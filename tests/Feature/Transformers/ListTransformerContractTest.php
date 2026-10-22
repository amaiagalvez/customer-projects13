<?php

namespace EntityProjects13\Tests\Feature\Transformers;

use Entities13\Models\Entity;
use Entities13\Transformers\EntityListTransformer;
use EntityProjects13\Tests\TestCase;
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
     * A row exposes the same action envelope in every state.
     *
     * @param  class-string<EntityListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
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
     * @return list<class-string<EntityListTransformer|ProjectListTransformer|EpicListTransformer>>
     */
    private static function transformerClasses(): array
    {
        return [
            EntityListTransformer::class,
            ProjectListTransformer::class,
            EpicListTransformer::class,
        ];
    }

    /**
     * @param  class-string<EntityListTransformer|ProjectListTransformer|EpicListTransformer>  $transformerClass
     * @param  'active'|'archived'|'trash'  $state
     * @return array<string, mixed>
     */
    private function payloadWithOneRecord(string $transformerClass, string $state): array
    {
        return match (true) {
            $transformerClass === EntityListTransformer::class => $this->entityState(
                app(EntityListTransformer::class),
                new LengthAwarePaginator([Entity::factory()->create()], 1, 15),
                $state,
                '',
            ),
            $transformerClass === ProjectListTransformer::class => $this->projectState(
                app(ProjectListTransformer::class),
                new LengthAwarePaginator([Project::factory()->for($this->entity())->create()], 1, 15),
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
     * @param  LengthAwarePaginator<int, Entity>  $paginator
     * @param  'active'|'archived'|'trash'  $state
     * @param  array{active: int, archived: int, trashed: int}|null  $counts
     * @return array<string, mixed>
     */
    private function entityState(
        EntityListTransformer $transformer,
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
