<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_checks', function (Blueprint $table) {
            // Whether this site going down starts an analysis of its machine (when the machine has the web server analysis on).
            $table->boolean('analyze_on_down')->default(true);
        });

        // Sites used to be a list of URLs in the machine settings: they become rows managed on the Sites page.
        foreach (DB::table('machines')->whereNotNull('site_urls')->get(['id', 'site_urls']) as $machine) {
            foreach (array_filter(array_map('trim', preg_split('/\R/', $machine->site_urls))) as $url) {
                DB::table('site_checks')->insertOrIgnore(['machine_id' => $machine->id, 'url' => $url, 'ok' => true, 'failures' => 0, 'analyze_on_down' => true]);
            }
        }

        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('site_urls'));
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->text('site_urls')->nullable());
        Schema::table('site_checks', fn (Blueprint $table) => $table->dropColumn('analyze_on_down'));
    }
};
