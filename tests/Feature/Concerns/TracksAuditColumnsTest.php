<?php

namespace EntityProjects13\Tests\Feature\Concerns;

use App\Models\User;
use entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class TracksAuditColumnsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every model that declares the trait, EpicComment included: it has no soft deletes, so it is
     * the one audited model whose deleted_by is never written.
     *
     * @return array<string, array{class-string}>
     */
    public static function auditedModels(): array
    {
        return [
            'entities' => [Entity::class],
            'projects' => [Project::class],
            'epics' => [Epic::class],
            'epic comments' => [EpicComment::class],
        ];
    }

    /**
     * The audited models that keep a deleted_at, the only ones with a deleted_by to write.
     *
     * @return array<string, array{class-string}>
     */
    public static function softDeletingModels(): array
    {
        return [
            'entities' => [Entity::class],
            'projects' => [Project::class],
            'epics' => [Epic::class],
        ];
    }

    /**
     * @param  class-string<Entity|Project|Epic|EpicComment|User>  $model
     */
    #[DataProvider('auditedModels')]
    public function test_creating_a_record_stamps_the_actor_on_created_by_and_updated_by(string $model): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor);
        $record = $model::factory()->create();

        $this->assertSame($actor->id, $record->created_by);
        $this->assertSame($actor->id, $record->updated_by);
        $this->assertNull($record->deleted_by);
    }

    /**
     * @param  class-string<Entity|Project|Epic|EpicComment|User>  $model
     */
    #[DataProvider('auditedModels')]
    public function test_changing_a_record_stamps_the_new_actor_and_keeps_the_author(string $model): void
    {
        $author = User::factory()->create();
        $editor = User::factory()->create();

        $this->actingAs($author);
        $record = $model::factory()->create();

        $this->actingAs($editor);
        $record->update(['notes' => 'Rewritten notes']);

        $this->assertSame($author->id, $record->refresh()->created_by);
        $this->assertSame($editor->id, $record->updated_by);
    }

    /**
     * A write with nobody authenticated has no actor to record, so the trail of whoever really
     * created or changed the row has to survive it, as a console command or a queued job leaves it.
     *
     * @param  class-string<Entity|Project|Epic|EpicComment|User>  $model
     */
    #[DataProvider('auditedModels')]
    public function test_a_write_without_an_authenticated_user_keeps_the_existing_trail(string $model): void
    {
        $author = User::factory()->create();

        $this->actingAs($author);
        $record = $model::factory()->create();

        $this->loggedOut();
        $record->update(['notes' => 'Rewritten notes']);

        $this->assertSame($author->id, $record->refresh()->created_by);
        $this->assertSame($author->id, $record->updated_by);
    }

    /**
     * @param  class-string<Entity|Project|Epic|User>  $model
     */
    #[DataProvider('softDeletingModels')]
    public function test_trashing_a_record_stamps_the_actor_on_deleted_by(string $model): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor);
        $record = $model::factory()->create();
        $record->delete();

        $this->assertSoftDeleted($record);
        $this->assertSame($actor->id, $record->refresh()->deleted_by);
    }

    /**
     * SoftDeletes::runSoftDelete() updates deleted_at and updated_at and nothing else, so a hook
     * that only set the attribute in memory would leave deleted_by empty on a trashed row.
     *
     * @param  class-string<Entity|Project|Epic|User>  $model
     */
    #[DataProvider('softDeletingModels')]
    public function test_trashing_without_an_authenticated_user_keeps_the_author_of_the_row(string $model): void
    {
        $author = User::factory()->create();

        $this->actingAs($author);
        $record = $model::factory()->create();

        $this->loggedOut();
        $record->delete();

        $this->assertSame($author->id, $record->refresh()->created_by);
        $this->assertNull($record->deleted_by);
    }

    /**
     * @param  class-string<Entity|Project|Epic|User>  $model
     */
    #[DataProvider('softDeletingModels')]
    public function test_restoring_a_record_clears_deleted_by(string $model): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor);
        $record = $model::factory()->create();
        $record->delete();

        $this->actingAs($actor);
        $record->restore();

        $this->assertNotSoftDeleted($record->refresh());
        $this->assertNull($record->deleted_by);
    }

    /**
     * A force delete leaves no row behind to attribute the deletion to, so the trait must not
     * write a deleted_by that nothing would ever read.
     */
    public function test_force_deleting_a_record_removes_it_without_leaving_a_deletion_trail(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor);
        $record = Entity::factory()->create();
        $id = $record->id;

        $record->forceDelete();

        $this->assertDatabaseMissing('ENT_entities', ['id' => $id]);
    }

    /**
     * User deletion is restricted while an audit record still references that user.
     */
    public function test_deleting_the_actor_is_restricted_while_audit_records_reference_them(): void
    {
        $actor = User::factory()->create();

        $this->actingAs($actor);
        $record = Entity::factory()->create();

        try {
            $actor->forceDelete();
            self::fail('A user with audit references must not be force deleted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $actor->id]);
        }

        $record->refresh();
        $this->assertSame($actor->id, $record->created_by);
        $this->assertSame($actor->id, $record->updated_by);
    }

    /**
     * The columns are written by the trait alone, so a payload cannot claim authorship.
     *
     * @param  class-string<Entity|Project|Epic|EpicComment|User>  $model
     */
    #[DataProvider('auditedModels')]
    public function test_the_audit_columns_are_not_mass_assignable(string $model): void
    {
        foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
            $this->assertFalse((new $model)->isFillable($column), "{$model} must not accept {$column}");
        }
    }

    /**
     * A write with nobody authenticated is the console and queue path: no user on the guard.
     */
    private function loggedOut(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
