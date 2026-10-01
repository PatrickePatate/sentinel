<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // Opt-in: the web server analysis (scheduled checks, repairs, site-down triage) only runs where an admin switched it on.
            $table->boolean('webserver_enabled')->default(false);
            // How often the AI looks too when the plain check is healthy. null = the global default, 0 = only when the plain check finds a problem.
            $table->unsignedSmallInteger('webserver_full_check_hours')->nullable();
        });

        // Whoever already scheduled it keeps it.
        DB::table('machines')->whereNotNull('webserver_interval_minutes')->update(['webserver_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn(['webserver_enabled', 'webserver_full_check_hours']));
    }
};
