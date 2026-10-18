<?php

namespace EntityProjects13\Integrations;

use entities13\Models\Entity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Projects13\Contracts\ProjectDisplayIntegration;
use Projects13\Contracts\ProjectDisplayIntegrationDefaults;
use Projects13\Contracts\ProjectQueryIntegration;
use Projects13\Contracts\ProjectQueryIntegrationDefaults;
use Projects13\Contracts\ProjectValidationIntegration;
use Projects13\Contracts\ProjectValidationIntegrationDefaults;
use Projects13\Http\Requests\ProjectRequest;
use Projects13\Models\Epic;
use Projects13\Models\Project;

/**
 * A project hangs from a entity, and everything that follows from it: the field in the form, the
 * rule that keeps the two consistent, the lock that keeps a project from being attached to a
 * entity that is being deleted at the same time, and the name and column the entity's presence
 * gives to a row.
 */
final class EntityProjectsIntegration implements ProjectDisplayIntegration, ProjectQueryIntegration, ProjectValidationIntegration
{
    use ProjectDisplayIntegrationDefaults;
    use ProjectQueryIntegrationDefaults;
    use ProjectValidationIntegrationDefaults;

    /**
     * A project may only hang from an active entity, which is what the selector offers. While
     * editing, the entity the project already belongs to stays valid even after it has been
     * deactivated, so a form that does not change it can still be saved.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(ProjectRequest $request): array
    {
        return [
            'entity_id' => [
                'required',
                'integer',
                $this->selectableEntityRule($request),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['entity_id' => __('Entity')];
    }

    /**
     * The entity row is locked here, inside the controller transaction that is about to write the
     * project, so a concurrent delete of that entity makes this transaction wait and then fail
     * instead of leaving a project pointing at a entity in the trash.
     *
     * @return array<string, mixed>
     */
    public function saving(ProjectRequest $request, ?Project $project): array
    {
        $id = $request->integer('entity_id');

        DB::transaction(static fn(): Entity => Entity::query()->lockForUpdate()->findOrFail($id));

        return ['entity_id' => $id];
    }

    /**
     * Every project query reaches the entity through the alias it was given, so one join serves
     * the list, the selector, the planning board and the timeline without any of them knowing that
     * a entity exists.
     *
     * @param  string  $scope  One of `project`, `epic`, `planning`, `timeline`.
     * @param  string  $alias  The alias this scope is joined under.
     * @param  Builder<covariant Model>  $query
     */
    public function scope(Builder $query, string $scope, string $alias): void
    {
        $owner = $scope === 'epic' ? 'epic_projects' : 'PRO_projects';

        // A left join, not an inner one. An inner join would make every project without a
        // entity invisible — it would vanish from the list and from the board the moment this
        // package is installed, which is a silent data problem rather than a visible one. With a
        // left join the row stays where it is and simply carries no entity, and the validation
        // below is what stops that state from being reachable through the application.
        $query->leftJoin(
            (new Entity)->getTable() . ' as ' . $alias,
            $alias . '.id',
            '=',
            $owner . '.entity_id',
        );

        // Select entity columns to avoid N+1 when the transformer accesses the entity relation.
        // Use distinct aliases to avoid conflict with PRO_projects.entity_id (the FK).
        $query->addSelect([
            $alias . '.name as entity_name',
            $alias . '.id as entity_id_joined',
            $alias . '.active as entity_active',
        ]);

        if ($scope === 'epic') {
            // The relation feeds the integration's entity fields and the base edit payload's
            // active state, so keep both columns along with the keys needed to match it.
            $query->with('project:id,name,entity_id,active');
        }
    }

    /**
     * @return list<string>
     */
    public function orderings(string $scope, string $alias): array
    {
        return match ($scope) {
            'project', 'epic' => [$alias . '.name'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function searchColumns(string $scope, array $columns): array
    {
        return [...$columns, $this->aliasFor($scope) . '.name'];
    }

    /**
     * How a project is named wherever it is picked: in the epic selector, in the option the epic
     * list rehydrates, and in the epic edit payload. Built once here so the three surfaces cannot
     * drift apart.
     */
    public function label(Project $project): string
    {
        return $project->name . ' (' . $this->entityOf($project)?->name . ')';
    }

    /**
     * @return list<string>
     */
    public function trail(Project $project): array
    {
        $entity = $this->entityOf($project);

        return $entity === null ? [] : [$entity->name];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Model $record): array
    {
        if ($record instanceof Epic) {
            $entity = $this->entityOf($record->project);

            return array_filter([
                'entity' => $entity?->name,
                'entity_active' => $entity?->active,
                'project_label' => $this->label($record->project),
            ], static fn(mixed $value): bool => $value !== null);
        }

        if (! $record instanceof Project) {
            return [];
        }

        $entity = $this->entityOf($record);

        return [
            'entity' => $entity?->name,
            'entity_id' => $entity?->id,
            'entity_name' => $entity?->name,
            'entity_active' => $entity?->active,
        ];
    }

    public function hasActiveRelatedEntities(): bool
    {
        return Entity::query()->where('active', true)->exists();
    }

    /**
     * @return array{message: string, action_label: string, action_url: string}|null
     */
    public function noRelatedEntitiesBanner(): ?array
    {
        if ($this->hasActiveRelatedEntities()) {
            return null;
        }

        return [
            'message' => __('No active entities are available. Open the entity form to create one.'),
            'action_label' => __('Open entity form'),
            'action_url' => route('entities.index', ['create' => 1]),
        ];
    }

    private function selectableEntityRule(ProjectRequest $request): Exists
    {
        $current = $request->route('project');

        if ($current instanceof Project && $current->getAttribute('entity_id') === $request->integer('entity_id')) {
            return Rule::exists(Entity::class, 'id')->whereNull('deleted_at');
        }

        return Rule::exists(Entity::class, 'id')
            ->whereNull('deleted_at')
            ->where('active', true);
    }

    private function aliasFor(string $scope): string
    {
        return match ($scope) {
            'epic' => 'epic_entities',
            'planning' => 'planning_entities',
            'timeline' => 'timeline_entities',
            default => 'project_entities',
        };
    }

    /**
     * @return object{name: string, id: int, active: bool}|null
     */
    private function entityOf(Project $project): ?object
    {
        // Prefer joined columns (set by scope()) to avoid N+1. Fall back to the relation
        // for unit tests that set the relation directly without the joined columns.
        $name = $project->getAttribute('entity_name');

        if ($name !== null) {
            return (object) [
                'name' => $name,
                'id' => $project->getAttribute('entity_id_joined'),
                'active' => (bool) $project->getAttribute('entity_active'),
            ];
        }

        $entity = $project->getRelationValue('entity');

        if ($entity === null) {
            return null;
        }

        return (object) [
            'name' => $entity->name,
            'id' => $entity->id,
            'active' => $entity->active,
        ];
    }
}
