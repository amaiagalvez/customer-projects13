<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->restoreProjectActiveNameIndex();
    }

    public function down(): void
    {
        $this->restoreProjectActiveNameIndex();
    }

    private function restoreProjectActiveNameIndex(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS "PRO_projects_active_name_unique"');
        addUniqueActiveNameIndex('PRO_projects');
    }
};
