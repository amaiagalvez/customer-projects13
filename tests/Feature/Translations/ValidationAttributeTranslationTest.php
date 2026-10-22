<?php

namespace EntityProjects13\Tests\Feature\Translations;

use Entities13\Models\Entity;
use EntityProjects13\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ValidationAttributeTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unique_validation_errors_use_the_translated_name_in_every_locale(): void
    {
        Entity::factory()->create(['name' => 'Existing entity']);
        $originalLocale = app()->getLocale();

        try {
            foreach ($this->locales() as $locale) {
                app()->setLocale($locale);

                $attributes = __('validation.attributes');
                $this->assertIsArray($attributes);

                $validator = Validator::make(
                    ['name' => 'Existing entity'],
                    ['name' => [Rule::unique(Entity::class)->whereNull('deleted_at')]],
                );
                $validator->fails();

                $this->assertSame(
                    __('validation.unique', [
                        'attribute' => $attributes['name'],
                    ]),
                    $validator->errors()->first('name'),
                    "The unique name error should use its translated label in {$locale}.",
                );
            }
        } finally {
            app()->setLocale($originalLocale);
        }
    }

    /**
     * @return list<string>
     */
    private function locales(): array
    {
        return ['en', 'es', 'eu', 'fr'];
    }
}
