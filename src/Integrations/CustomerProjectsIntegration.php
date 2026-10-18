<?php

namespace CustomerProjects13\Integrations;

use Projects13\Models\Epic;
use Projects13\Models\Project;
use Illuminate\Validation\Rule;
use Customers13\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Database\Eloquent\Builder;
use Projects13\Http\Requests\ProjectRequest;
use Projects13\Contracts\ProjectQueryIntegration;
use Projects13\Contracts\ProjectDisplayIntegration;
use Projects13\Contracts\ProjectValidationIntegration;
use Projects13\Contracts\ProjectQueryIntegrationDefaults;
use Projects13\Contracts\ProjectDisplayIntegrationDefaults;
use Projects13\Contracts\ProjectValidationIntegrationDefaults;

/**
 * A project hangs from a customer, and everything that follows from it: the field in the form, the
 * rule that keeps the two consistent, the lock that keeps a project from being attached to a
 * customer that is being deleted at the same time, and the name and column the customer's presence
 * gives to a row.
 */
final class CustomerProjectsIntegration implements ProjectDisplayIntegration, ProjectQueryIntegration, ProjectValidationIntegration
{
    use ProjectDisplayIntegrationDefaults;
    use ProjectQueryIntegrationDefaults;
    use ProjectValidationIntegrationDefaults;

    /**
     * A project may only hang from an active customer, which is what the selector offers. While
     * editing, the customer the project already belongs to stays valid even after it has been
     * deactivated, so a form that does not change it can still be saved.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(ProjectRequest $request): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                $this->selectableCustomerRule($request),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['customer_id' => __('Customer')];
    }

    /**
     * The customer row is locked here, inside the controller transaction that is about to write the
     * project, so a concurrent delete of that customer makes this transaction wait and then fail
     * instead of leaving a project pointing at a customer in the trash.
     *
     * @return array<string, mixed>
     */
    public function saving(ProjectRequest $request, ?Project $project): array
    {
        $id = $request->integer('customer_id');

        DB::transaction(static fn (): Customer => Customer::query()->lockForUpdate()->findOrFail($id));

        return ['customer_id' => $id];
    }

    /**
     * Every project query reaches the customer through the alias it was given, so one join serves
     * the list, the selector, the planning board and the timeline without any of them knowing that
     * a customer exists.
     *
     * @param  string  $scope  One of `project`, `epic`, `planning`, `timeline`.
     * @param  string  $alias  The alias this scope is joined under.
     * @param  Builder<covariant Model>  $query
     */
    public function scope(Builder $query, string $scope, string $alias): void
    {
        $owner = $scope === 'epic' ? 'epic_projects' : 'PRO_projects';

        // A left join, not an inner one. An inner join would make every project without a
        // customer invisible — it would vanish from the list and from the board the moment this
        // package is installed, which is a silent data problem rather than a visible one. With a
        // left join the row stays where it is and simply carries no customer, and the validation
        // below is what stops that state from being reachable through the application.
        $query->leftJoin(
            (new Customer)->getTable().' as '.$alias,
            $alias.'.id',
            '=',
            $owner.'.customer_id',
        );

        // Without the column selected the relation cannot be resolved afterwards, and the label of
        // a project is half a customer.
        $query->addSelect($owner.'.customer_id');

        if ($scope === 'epic') {
            // The relation feeds the integration's customer fields and the base edit payload's
            // active state, so keep both columns along with the keys needed to match it.
            $query->with('project:id,name,customer_id,active');
        }
    }

    /**
     * @return list<string>
     */
    public function orderings(string $scope, string $alias): array
    {
        return match ($scope) {
            'project', 'epic' => [$alias.'.name'],
            default => [],
        };
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    public function searchColumns(string $scope, array $columns): array
    {
        return [...$columns, $this->aliasFor($scope).'.name'];
    }

    /**
     * How a project is named wherever it is picked: in the epic selector, in the option the epic
     * list rehydrates, and in the epic edit payload. Built once here so the three surfaces cannot
     * drift apart.
     */
    public function label(Project $project): string
    {
        return $project->name.' ('.$this->customerOf($project)?->name.')';
    }

    /**
     * @return list<string>
     */
    public function trail(Project $project): array
    {
        $customer = $this->customerOf($project);

        return $customer === null ? [] : [$customer->name];
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Model $record): array
    {
        if ($record instanceof Epic) {
            $customer = $this->customerOf($record->project);

            return array_filter([
                'customer' => $customer?->name,
                'project_label' => $this->label($record->project),
            ], static fn (mixed $value): bool => $value !== null);
        }

        if (! $record instanceof Project) {
            return [];
        }

        $customer = $this->customerOf($record);

        return [
            'customer' => $customer?->name,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name,
            'customer_active' => $customer?->active,
        ];
    }

    public function hasActiveRelatedEntities(): bool
    {
        return Customer::query()->where('active', true)->exists();
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
            'message' => __('No active customers are available. Open the customer form to create one.'),
            'action_label' => __('Open customer form'),
            'action_url' => route('customers.index', ['create' => 1]),
        ];
    }

    private function selectableCustomerRule(ProjectRequest $request): Exists
    {
        $current = $request->route('project');

        if ($current instanceof Project && $current->getAttribute('customer_id') === $request->integer('customer_id')) {
            return Rule::exists(Customer::class, 'id')->whereNull('deleted_at');
        }

        return Rule::exists(Customer::class, 'id')
            ->whereNull('deleted_at')
            ->where('active', true);
    }

    private function aliasFor(string $scope): string
    {
        return match ($scope) {
            'epic' => 'epic_customers',
            'planning' => 'planning_customers',
            'timeline' => 'timeline_customers',
            default => 'project_customers',
        };
    }

    private function customerOf(Project $project): ?Customer
    {
        return $project->getRelationValue('customer');
    }
}
