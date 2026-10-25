<?php

namespace EntityProjects13\Tests;

use Entities13\Models\Entity;

abstract class DuskTestCase extends \Basics13\Support\Testing\DuskTestCase
{
    /** @var class-string */
    protected string $seeder = AccessSeeder::class;

    protected function entity(): Entity
    {
        return Entity::factory()->create();
    }
}
