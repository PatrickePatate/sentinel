<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Recurring window for approved actions: ISO weekdays (1 = Monday), local start time "HH:MM", length.
            $table->json('maintenance_days')->nullable();
            $table->string('maintenance_start', 5)->nullable();
            $table->unsignedSmallInteger('maintenance_minutes')->nullable();
            // One-off planned work: alerts are muted and scheduled scans skipped until then.
            $table->timestamp('maintenance_until')->nullable();
        });

        Schema::table('pending_actions', function (Blueprint $table) {
            // Approved for the maintenance window: runs at the start of the next one.
            $table->timestamp('run_after')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pending_actions', fn (Blueprint $table) => $table->dropColumn('run_after'));
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn(['maintenance_days', 'maintenance_start', 'maintenance_minutes', 'maintenance_until']));
    }
};
