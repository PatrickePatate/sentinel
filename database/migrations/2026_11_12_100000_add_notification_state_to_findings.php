<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('findings', function (Blueprint $table) {
            // Worst severity someone was already told about, and when: a finding is only announced again when it gets worse
            // than that, or as a reminder. Null: nobody was told yet.
            $table->string('notified_severity')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            // Scans in a row that did not report it: one silent scan is not enough to call it fixed.
            $table->unsignedTinyInteger('misses')->default(0);
        });

        // What exists today was already announced: deploying this must not send everything again.
        DB::table('findings')->update(['notified_severity' => DB::raw('severity'), 'last_notified_at' => DB::raw('last_seen_at')]);
    }

    public function down(): void
    {
        Schema::table('findings', fn (Blueprint $table) => $table->dropColumn(['notified_severity', 'last_notified_at', 'misses']));
    }
};
