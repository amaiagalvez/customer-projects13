<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('PRO_projects', function (Blueprint $table): void {
            // Nullable because the table already has rows and SQLite refuses to add a NOT NULL
            // column to one. The application still requires a entity on every project; see
            // config/entity-projects13.php.
            $table->foreignId('entity_id')
                ->nullable()
                ->constrained('ENT_entities')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('PRO_projects', function (Blueprint $table): void {
            // The foreign key goes first: SQLite refuses to drop a column that a live foreign key
            // definition still points at.
            $table->dropForeign(['entity_id']);
            $table->dropColumn('entity_id');
        });
    }
};
