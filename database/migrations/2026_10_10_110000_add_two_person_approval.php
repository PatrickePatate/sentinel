<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->boolean('two_person_approval')->default(false));
        // Approvals recorded so far: [{user_id, name, at}]
        Schema::table('pending_actions', fn (Blueprint $table) => $table->json('approvals')->nullable());
    }

    public function down(): void
    {
        Schema::table('pending_actions', fn (Blueprint $table) => $table->dropColumn('approvals'));
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('two_person_approval'));
    }
};
