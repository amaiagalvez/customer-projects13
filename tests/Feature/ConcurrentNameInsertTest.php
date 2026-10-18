<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use entities13\Models\Entity;
use EntityProjects13\Tests\Support\RacesNameInsert;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\Project;

/**
 * The unique index is the last line of defence: validation runs before the write, so a name that
 * becomes taken between the check and the insert arrives as a QueryException. Every store and
 * update path has to turn it back into a validation error instead of a 500.
 *
 * These cases were copy-pasted per resource; the only thing that changes is the resource.
 */
class ConcurrentNameInsertTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: 'entity'|'project'|'epic', 1: non-empty-string}>
     */
    public static function resources(): array
    {
        return [
            'entities' => ['entity', 'entities.index'],
            'projects' => ['project', 'projects.index'],
            'epics' => ['epic', 'epics.index'],
        ];
    }

    /**
     * @param  'entity'|'project'|'epic'  $singular
     * @param  non-empty-string  $indexRoute
     */
    #[DataProvider('resources')]
    public function test_store_converts_a_concurrent_duplicate_insert_to_a_validation_error(
        string $singular,
        string $indexRoute,
    ): void {
        $this->actingAs(User::factory()->create());
        $name = 'Concurrent Store '.$singular;
        $payload = $this->payloadFor($singular, $name);
        $parentId = $this->parentIdFromPayload($payload);
        $routePrefix = substr($indexRoute, 0, -strlen('.index'));
        $table = match ($singular) {
            'entity' => (new Entity)->getTable(),
            'project' => (new Project)->getTable(),
            'epic' => (new Epic)->getTable(),
        };

        RacesNameInsert::afterUniquenessSelect(
            $table,
            $name,
            fn () => $this->makeRecord($singular, ['name' => $name], parentId: $parentId),
        );

        $this->from(route($indexRoute))
            ->post(route($routePrefix.'.store'), $payload)
            ->assertRedirect(route($indexRoute))
            ->assertSessionHasErrors([
                'name' => __('validation.unique', ['attribute' => __('Name')]),
            ]);

        // Only the competitor survives: the request's own insert was rejected by the index.
        $this->assertDatabaseCount($table, 1);
        $this->assertDatabaseHas($table, ['name' => $name, 'deleted_at' => null]);
    }

    /**
     * @param  'entity'|'project'|'epic'  $singular
     * @param  non-empty-string  $indexRoute
     */
    #[DataProvider('resources')]
    public function test_update_converts_a_concurrent_duplicate_insert_to_a_validation_error(
        string $singular,
        string $indexRoute,
    ): void {
        $this->actingAs(User::factory()->create());
        $existing = $this->makeRecord($singular, ['name' => 'Original '.$singular]);
        $name = 'Concurrent Update '.$singular;
        $payload = $this->payloadFor($singular, $name, parentId: $this->parentIdOf($existing));
        $routePrefix = substr($indexRoute, 0, -strlen('.index'));
        $table = $existing->getTable();

        RacesNameInsert::afterUniquenessSelect(
            $table,
            $name,
            fn () => $this->makeRecord($singular, ['name' => $name], parentId: $this->parentIdFromPayload($payload)),
        );

        $this->from(route($indexRoute))
            ->put(route($routePrefix.'.update', $existing), $payload)
            ->assertRedirect(route($indexRoute))
            ->assertSessionHasErrors([
                'name' => __('validation.unique', ['attribute' => __('Name')]),
            ]);

        $this->assertDatabaseHas($table, ['id' => $existing->id, 'name' => 'Original '.$singular]);
        $this->assertDatabaseHas($table, ['name' => $name, 'deleted_at' => null]);
    }

    /**
     * A restore that loses the same race must answer the conflict message, not a validation error.
     * The race is on the name the trashed record already carries, because the restore looks that up
     * itself; no name is sent in the body.
     *
     * @param  'entity'|'project'|'epic'  $singular
     * @param  non-empty-string  $indexRoute
     */
    #[DataProvider('resources')]
    public function test_restore_converts_a_concurrent_duplicate_insert_to_a_conflict(
        string $singular,
        string $indexRoute,
    ): void {
        $this->actingAs(User::factory()->create());
        $name = 'Concurrent Restore '.$singular;
        $deleted = $this->makeRecord($singular, ['name' => $name], trashed: true);
        $routePrefix = substr($indexRoute, 0, -strlen('.index'));
        $table = $deleted->getTable();

        RacesNameInsert::afterUniquenessSelect(
            $table,
            $name,
            fn () => $this->makeRecord($singular, ['name' => $name], parentId: $this->parentIdOf($deleted)),
        );

        $this->patch(route($routePrefix.'.trash.restore', $deleted->id))
            ->assertRedirect(route($routePrefix.'.trash.index'))
            ->assertSessionHas('error', __('basics13::messages.cannot_restore_name_taken'));

        $this->assertSoftDeleted($deleted);
        $this->assertDatabaseHas($table, ['name' => $name, 'deleted_at' => null]);
    }

    /**
     * Epic names are unique per project, so a competitor must land in the same project as the
     * record it is racing, or its name is legitimately free.
     *
     * @param  'entity'|'project'|'epic'  $singular
     * @param  array<string, mixed>  $attributes
     */
    private function makeRecord(string $singular, array $attributes = [], bool $trashed = false, ?int $parentId = null): Entity|Project|Epic
    {
        return match ($singular) {
            'entity' => $trashed
                ? Entity::factory()->trashed()->create($attributes)
                : Entity::factory()->create($attributes),
            'project' => $trashed
                ? Project::factory()->for($this->entity())->trashed()->create($attributes)
                : Project::factory()->for($this->entity())->create($attributes),
            // The parent may itself be trashed, so it is resolved with withTrashed(). When no parent
            // is given, a fresh one is built, which is what an epic fixture needs on its own.
            default => $trashed
                ? Epic::factory()->for($this->projectFor($parentId))->trashed()->create($attributes)
                : Epic::factory()->for($this->projectFor($parentId))->create($attributes),
        };
    }

    /**
     * The parent id carried by a request payload, or null when the payload names no parent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function parentIdFromPayload(array $payload): ?int
    {
        $id = $payload['entity_id'] ?? $payload['project_id'] ?? null;

        return is_int($id) ? $id : null;
    }

    /**
     * The project an epic hangs from. The parent can itself be trashed, hence withTrashed(); when no
     * id is given a fresh project is created so an epic fixture stands on its own.
     */
    private function projectFor(?int $parentId): Project
    {
        return $parentId === null
            ? Project::factory()->for($this->entity())->create(['name' => 'Concurrent Epic Parent'])
            : Project::withTrashed()->findOrFail($parentId);
    }

    /**
     * The foreign key the record hangs from, or null when the record is itself the parent.
     */
    private function parentIdOf(Entity|Project|Epic $record): ?int
    {
        return match (true) {
            $record instanceof Epic => $record->project_id,
            $record instanceof Project => $record->getAttribute('entity_id'),
            default => null,
        };
    }

    /**
     * The parent must already exist before the request: project and epic lock it inside their
     * transaction, so creating it lazily here would deadlock the race instead of exercising it.
     *
     * @param  'entity'|'project'|'epic'  $singular
     * @return array<string, mixed>
     */
    private function payloadFor(string $singular, string $name, ?int $parentId = null): array
    {
        return match ($singular) {
            'entity' => ['name' => $name],
            'project' => [
                'name' => $name,
                'start_date' => '2045-07-09',
                'entity_id' => $parentId ?? Entity::factory()->create()->id,
            ],
            default => [
                'name' => $name,
                'project_id' => $parentId ?? Project::factory()->for($this->entity())->create(['name' => 'Concurrent Parent'])->id,
            ],
        };
    }
}
