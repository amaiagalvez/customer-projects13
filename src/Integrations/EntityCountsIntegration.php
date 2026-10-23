<?php

namespace EntityProjects13\Integrations;

use Basics13\Concerns\ChecksPermissions;
use Entities13\Contracts\EntityIntegration;
use Entities13\Models\Entity;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Projects13\Models\EpicComment;
use Projects13\Models\Project;
use Projects13\Queries\ProjectVisibility;

/**
 * What a entity's projects put back on the entity lists: how many there are, how many epics
 * and comments live under them, where to drill into them, and whether anything hangs from the row
 * at all — which is what turns its delete action into an archive one.
 */
final class EntityCountsIntegration extends EntityIntegration
{
    use ChecksPermissions;

    /**
     * The context the project form's entity selector sends while a project is being edited.
     */
    public const PROJECT_EDIT_CONTEXT = 'project-edit';

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
            // Any project, trashed ones included, keeps the entity from being deleted.
            'blocks_delete' => (bool) $record->getAttribute('projects_exists'),
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

    /**
     * The project form needs an entity, so whoever creates or edits projects may pick one even
     * without being allowed to list entities.
     */
    public function allowsSelectOptions(Authenticatable $user): bool
    {
        return $this->permits($user, 'projects.create') || $this->permits($user, 'projects.edit');
    }

    /**
     * While a project is edited, a user who may not list entities is offered only the entities of
     * the projects they reach, which include the one the project already has. Creating a project
     * offers every active entity, so that user can start their first one.
     */
    public function scopeSelectOptions(Builder $query, Authenticatable $user, string $context): void
    {
        if ($context !== self::PROJECT_EDIT_CONTEXT || $this->permits($user, 'entities.view')) {
            return;
        }

        $query->whereIn('ENT_entities.id', self::entitiesOfVisibleProjects());
    }

    /**
     * The ids of the entities the projects the signed-in user reaches belong to.
     *
     * @return Builder<Project>
     */
    public static function entitiesOfVisibleProjects(): Builder
    {
        return (new ProjectVisibility)->restrict(Project::withTrashed(), 'id')
            ->whereNotNull('entity_id')
            ->select('entity_id');
    }
}
