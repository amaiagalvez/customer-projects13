<?php

namespace CustomerProjects13\Tests\Feature;

use App\Models\User;
use CustomerProjects13\Tests\TestCase;
use Customers13\Models\Customer;
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
    public function test_case_and_accent_variants_are_rejected_when_creating_a_customer(string $variant): void
    {
        $this->actingAs(User::factory()->create());
        $customer = Customer::factory()->create(['name' => 'Mikel']);

        $this->from(route('customers.index'))
            ->post(route('customers.store'), ['name' => $variant])
            ->assertRedirect(route('customers.index'))
            ->assertSessionHasErrors('name');

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame('Mikel', $customer->refresh()->name);
    }

    #[DataProvider('nameVariants')]
    public function test_case_and_accent_variants_cannot_be_restored_over_an_existing_name(string $variant): void
    {
        $this->actingAs(User::factory()->create());
        $trashed = Customer::factory()->trashed()->create(['name' => $variant]);
        Customer::factory()->create(['name' => 'Mikel']);

        $this->patch(route('customers.trash.restore', $trashed))
            ->assertRedirect(route('customers.trash.index'))
            ->assertSessionHas('error', __('basics13::messages.cannot_restore_name_taken'))
            ->assertSessionMissing('status');

        $this->assertSoftDeleted($trashed->refresh());
        $this->assertSame(1, Customer::query()->count());
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
