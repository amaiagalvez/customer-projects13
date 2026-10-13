<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use Basics13\Support\Validation\MaxLength;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Projects13\Models\Project;

class ResourceNotesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function resources(): array
    {
        return [
            'customers' => ['CUM_customers', 'customers', 'customer', 'Customer notes'],
            'projects' => ['PRO_projects', 'projects', 'project', 'Project notes'],
            'epics' => ['PRO_epics', 'epics', 'epic', 'Epic notes'],
        ];
    }

    #[DataProvider('resources')]
    public function test_notes_are_saved_and_replaced_on_update(string $table, string $resource, string $prefix, string $notes): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->storePayload($resource);

        $this->post(route($resource.'.store'), [...$payload, 'notes' => $notes])
            ->assertRedirect(route($resource.'.index'));

        $id = DB::table($table)->where('name', 'Notes record')->value('id');
        $this->assertSame($notes, DB::table($table)->where('id', $id)->value('notes'));

        $this->put(route($resource.'.update', $id), [...$payload, 'notes' => 'Rewritten notes'])
            ->assertRedirect(route($resource.'.index'));

        $this->assertSame('Rewritten notes', DB::table($table)->where('id', $id)->value('notes'));
    }

    /**
     * The column is nullable, so a record created without notes exists instead of failing on a
     * missing value, and submitting an empty field clears the notes it had.
     */
    #[DataProvider('resources')]
    public function test_notes_are_optional_and_can_be_cleared(string $table, string $resource, string $prefix, string $notes): void
    {
        $this->actingAs(User::factory()->create());
        $payload = $this->storePayload($resource);

        $this->post(route($resource.'.store'), $payload)
            ->assertRedirect(route($resource.'.index'))
            ->assertSessionHasNoErrors();

        $id = DB::table($table)->where('name', 'Notes record')->value('id');
        $this->assertNull(DB::table($table)->where('id', $id)->value('notes'));

        $this->put(route($resource.'.update', $id), [...$payload, 'notes' => $notes]);
        $this->put(route($resource.'.update', $id), [...$payload, 'notes' => ''])
            ->assertRedirect(route($resource.'.index'));

        $this->assertNull(DB::table($table)->where('id', $id)->value('notes'));
    }

    #[DataProvider('resources')]
    public function test_notes_longer_than_the_configured_maximum_are_rejected(string $table, string $resource, string $prefix, string $notes): void
    {
        $this->actingAs(User::factory()->create());

        $this->from(route($resource.'.index'))
            ->post(route($resource.'.store'), [
                ...$this->storePayload($resource),
                'notes' => str_repeat('a', $this->longtextLimit() + 1),
            ])
            ->assertRedirect(route($resource.'.index'))
            ->assertSessionHasErrors('notes');

        $this->assertDatabaseCount($table, 0);
    }

    /**
     * The drawer is fed by the row edit payload, so notes nothing carries would empty the field
     * every time the form is opened.
     */
    #[DataProvider('resources')]
    public function test_the_edit_form_is_bound_to_the_saved_notes(string $table, string $resource, string $prefix, string $notes): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route($resource.'.store'), [...$this->storePayload($resource), 'notes' => $notes])
            ->assertRedirect(route($resource.'.index'))
            ->assertSessionHasNoErrors();

        $this->get(route($resource.'.index'))
            ->assertOk()
            ->assertSee('x-model="form.notes"', false)
            ->assertSee('data-test="'.$prefix.'-notes"', false)
            ->assertViewHas('list', function (mixed $list) use ($notes): bool {
                /** @var array{rows: list<array{editPayload: array{notes: string|null}}>} $list */
                return $list['rows'][0]['editPayload']['notes'] === $notes;
            });
    }

    /**
     * Read from the config so the boundary keeps testing the limit the rules actually apply,
     * whatever it is set to.
     */
    private function longtextLimit(): int
    {
        return MaxLength::longText();
    }

    /**
     * The minimum input each resource needs to be stored, parent record included.
     *
     * @return array<string, mixed>
     */
    private function storePayload(string $resource): array
    {
        $payload = ['name' => 'Notes record'];

        if ($resource === 'customers') {
            return $payload;
        }

        $customer = Customer::factory()->create();

        return match ($resource) {
            'projects' => [
                ...$payload,
                'start_date' => '2026-10-01',
                'customer_id' => $customer->id,
            ],
            'epics' => [
                ...$payload,
                'start_date' => '2026-10-01',
                'project_id' => Project::factory()->for($customer)->create([
                    'start_date' => '2026-10-01',
                    'end_date' => null,
                ])->id,
            ],
            default => $payload,
        };
    }
}
