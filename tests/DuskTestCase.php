<?php

namespace EntityProjects13\Tests;

use entities13\Models\Entity;

abstract class DuskTestCase extends \Basics13\Support\Testing\DuskTestCase
{
    protected function entity(): Entity
    {
        return Entity::factory()->create();
    }
}
