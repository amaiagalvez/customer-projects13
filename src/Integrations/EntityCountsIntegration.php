<?php

namespace EntityProjects13\Integrations;

use entities13\Contracts\EntityIntegration;
use entities13\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Projects13\Models\EpicComment;

/**
 * What a entity's projects put back on the entity lists: how many there are, how many epics
 * and comments live under them, where to drill into them, and whether anything hangs from the row
 * at all — which is what turns its delete action into an archive one.
 */
final class EntityCountsIntegration extends EntityIntegration
{
    /**
     * The alias the join and the search column share. A feature test searches the entity list by
     * a project name, which only works while both still name the same table.
     */
    private const ALIAS = 'entity_projects';

    private const ALIAS_TABLE = 'PRO_projects as entity_projects';

    /**
     * Only the live slice is counted: active projects, the active epics they own and the comments
     * written on those epics. Deleted records are excluded by the relations themselves.
     */
    public function scope(Builder $query, string $scope, string $alias): void
    {
        $query
            ->select('ENT_entities.*')
            // A left join, not an inner one: most entities have no projects at all, and an inner
            // join would drop them from the page and from its total before a count is even read.
            ->leftJoin(self::ALIAS_TABLE, self::ALIAS.'.entity_id', '=', 'ENT_entities.id')
            ->distinct('ENT_entities.id')
            ->withCount([
                'projects' => fn (Builder $projects): Builder => $projects->where('PRO_projects.active', true),
                'epics' => fn (Builder $epics): Builder => $epics
                    ->where('PRO_epics.active', true)
                    ->where('PRO_projects.active', true),
            ])
            ->withExists(['projects' => fn (Builder $projects): Builder => $projects
                ->withoutGlobalScope(SoftDeletingScope::class)])
            ->selectSub($this->activeCommentsCount(), 'comments_count');
    }

    /**
     * The entity name is searchable once the join above exists, which is why it is appended here
     * rather than baked into the base columns.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function searchColumns(string $scope, array $columns): array
    {
        return [...$columns, self::ALIAS.'.name'];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Model $record): array
    {
        if (! $record instanceof Entity) {
            return [];
        }

        $projectsCount = (int) $record->getAttribute('projects_count');

        return [
            'projectsCount' => $projectsCount,
            // The projects list searches entity names, so the row count can link to its own slice.
            'projectsUrl' => $projectsCount > 0
                ? route('projects.index', ['search' => $record->name])
                : null,
            'epicsCount' => (int) $record->getAttribute('epics_count'),
            'commentsCount' => (int) $record->getAttribute('comments_count'),
            'projects_exists' => (bool) $record->getAttribute('projects_exists'),
        ];
    }

    /**
     * Comments live two hops away from the entity, which `withCount` cannot express, so the
     * correlated subquery walks projects and epics explicitly.
     *
     * @return Builder<EpicComment>
     */
    private function activeCommentsCount(): Builder
    {
        return EpicComment::query()
            ->selectRaw('count(*)')
            ->join('PRO_epics', 'PRO_epics.id', '=', 'PRO_epic_comments.epic_id')
            ->join('PRO_projects', 'PRO_projects.id', '=', 'PRO_epics.project_id')
            ->whereColumn('PRO_projects.entity_id', 'ENT_entities.id')
            ->where('PRO_projects.active', true)
            ->whereNull('PRO_projects.deleted_at')
            ->where('PRO_epics.active', true)
            ->whereNull('PRO_epics.deleted_at');
    }
}
