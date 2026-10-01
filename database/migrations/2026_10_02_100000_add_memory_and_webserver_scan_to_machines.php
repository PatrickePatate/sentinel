<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->text('memory')->nullable();
            $table->unsignedInteger('webserver_interval_minutes')->nullable();
            $table->timestamp('last_webserver_scan_at')->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('profile', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn(['memory', 'webserver_interval_minutes', 'last_webserver_scan_at']));
        Schema::table('agent_runs', fn (Blueprint $table) => $table->dropColumn('profile'));
    }
};
