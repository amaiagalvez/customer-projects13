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
            // column to one. The application still requires a customer on every project; see
            // config/customer-projects13.php.
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('CUM_customers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('PRO_projects', function (Blueprint $table): void {
            // The foreign key goes first: SQLite refuses to drop a column that a live foreign key
            // definition still points at.
            $table->dropForeign(['customer_id']);
            $table->dropColumn('customer_id');
        });
    }
};
