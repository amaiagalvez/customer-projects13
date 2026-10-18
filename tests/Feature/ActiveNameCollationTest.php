<?php

namespace EntityProjects13\Tests\Feature;

use App\Models\User;
use EntityProjects13\Tests\TestCase;
use entities13\Models\Entity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class ActiveNameCollationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Case- and accent-insensitive uniqueness depends on the MySQL collation.');
        }
    }

    #[DataProvider('nameVariants')]
    public function test_case_and_accent_variants_are_rejected_when_creating_a_entity(string $variant): void
    {
        $this->actingAs(User::factory()->create());
        $entity = Entity::factory()->create(['name' => 'Mikel']);

        $this->from(route('entities.index'))
            ->post(route('entities.store'), ['name' => $variant])
            ->assertRedirect(route('entities.index'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Entity::query()->count());
        $this->assertSame('Mikel', $entity->refresh()->name);
    }

    #[DataProvider('nameVariants')]
    public function test_case_and_accent_variants_cannot_be_restored_over_an_existing_name(string $variant): void
    {
        $this->actingAs(User::factory()->create());
        $trashed = Entity::factory()->trashed()->create(['name' => $variant]);
        Entity::factory()->create(['name' => 'Mikel']);

        $this->patch(route('entities.trash.restore', $trashed))
            ->assertRedirect(route('entities.trash.index'))
            ->assertSessionHas('error', __('basics13::messages.cannot_restore_name_taken'))
            ->assertSessionMissing('status');

        $this->assertSoftDeleted($trashed->refresh());
        $this->assertSame(1, Entity::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function nameVariants(): array
    {
        return [
            'case' => ['mikel'],
            'accent' => ['Míkel'],
        ];
    }
}
