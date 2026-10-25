<?php

namespace EntityProjects13\Tests;

use Basics13\Database\Seeders\BasicsAccessSeeder;
use Entities13\Database\Seeders\EntitiesAccessSeeder;
use Illuminate\Database\Seeder;
use Projects13\Database\Seeders\ProjectsAccessSeeder;

/**
 * What `db:seed` gives the application for both domains: basics13 first, then entities13 and
 * projects13. The bridge seeds no access of its own.
 */
class AccessSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([BasicsAccessSeeder::class, EntitiesAccessSeeder::class, ProjectsAccessSeeder::class]);
    }
}
