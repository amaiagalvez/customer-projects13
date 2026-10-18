<?php

namespace EntityProjects13\Tests\Feature;

use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Projects13\Models\Epic;
use Projects13\Models\Project;

/**
 * A entity with live projects cannot be moved to the trash, and a entity in the trash cannot be
 * deleted outright either. Both are enforced twice on purpose: the guard refuses the soft delete,
 * and the foreign key refuses the hard one, so neither depends on the other being called.
 */
class EntityDeletionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_entity_with_a_project_cannot_be_deleted(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create([]);

        $this->delete(route('entities.destroy', $entity))
            ->assertRedirect(route('entities.index'))
            ->assertSessionHas('error', __('Cannot be deleted while it has related records.'));

        $this->assertNotSoftDeleted($entity);
        $this->assertModelExists($project);
    }

    public function test_a_entity_with_a_trashed_project_cannot_be_deleted(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        $project = Project::factory()->for($entity)->create([]);
        $project->delete();

        $this->delete(route('entities.destroy', $entity))
            ->assertRedirect(route('entities.index'))
            ->assertSessionHas('error', __('Cannot be deleted while it has related records.'));

        $this->assertNotSoftDeleted($entity);
        $this->assertSoftDeleted($project);
    }

    public function test_a_entity_with_a_project_cannot_be_deleted_by_a_plain_model_delete(): void
    {
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create([]);

        $this->assertFalse($entity->delete());
        $this->assertNotSoftDeleted($entity);
    }

    /**
     * The guard locks the entity inside a transaction of its own, which is only safe because
     * `Entity::delete()` opens an enclosing one. What proves the lock outlives the check is that
     * the locking read happens one level deeper than the soft-delete write: before that enclosing
     * transaction existed, both ran at the same level and a project could slip in between them.
     */
    public function test_the_guard_holds_its_lock_until_the_soft_delete_is_written(): void
    {
        $entity = Entity::factory()->create();
        $levels = [];

        DB::listen(
            static function (QueryExecuted $query) use (&$levels): void {
                if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'CUM_entities')) {
                    $levels['guard'] = DB::transactionLevel();
                }

                if (str_starts_with($query->sql, 'update') && str_contains($query->sql, 'CUM_entities')) {
                    $levels['delete'] = DB::transactionLevel();
                }
            },
        );

        $ambient = DB::transactionLevel();

        $entity->delete();

        $this->assertArrayHasKey('guard', $levels, 'the guard has to lock the entity before deciding');
        $this->assertArrayHasKey('delete', $levels);
        $this->assertGreaterThan(
            $levels['delete'],
            $levels['guard'],
            'the locking read has to sit in a transaction that encloses the soft delete',
        );
        $this->assertGreaterThan($ambient, $levels['delete'], 'the soft delete itself has to be transactional');
        $this->assertSoftDeleted($entity);
    }

    /**
     * The guard is a soft-delete hook, so a force delete is refused by it too. What the foreign key
     * adds is the second line of defence: a delete that never goes through the model — a query
     * builder call, a maintenance script, another package — is still refused by the database.
     */
    public function test_a_force_delete_is_refused_and_the_database_refuses_it_too(): void
    {
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create();

        $this->assertFalse($entity->forceDelete(), 'the guard refuses it before the row is written');
        $this->assertModelExists($entity);

        $this->expectException(QueryException::class);

        DB::table('CUM_entities')->where('id', $entity->id)->delete();
    }

    public function test_a_entity_without_projects_can_be_deleted(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();

        $this->delete(route('entities.destroy', $entity))
            ->assertRedirect(route('entities.index'));

        $this->assertSoftDeleted($entity);
    }

    public function test_the_row_offers_archive_instead_of_delete_when_a_project_hangs_from_it(): void
    {
        $this->signIn();
        $entity = Entity::factory()->create();
        Project::factory()->for($entity)->create([]);
        Epic::factory()->create();

        $response = $this->get(route('entities.index'));

        $response->assertOk();
        $this->assertStringContainsString(
            'entity-archive-' . $entity->id,
            $response->getContent() ?: '',
        );
    }
}
