<?php

namespace EntityProjects13\Tests\Unit\Concerns;

use App\Models\User;
use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Epic;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;

class TracksAuditColumnsTest extends TestCase
{
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
}
