<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Follow-up turns ("fix what you found") are runs of their own, threaded under the scan they continue.
     */
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('parent_run_id')->nullable()->after('machine_id')->constrained('agent_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_run_id');
        });
    }
};
