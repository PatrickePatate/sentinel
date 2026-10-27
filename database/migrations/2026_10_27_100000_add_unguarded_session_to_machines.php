<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // End of the root lease opened on the machine for an unguarded chat session (null: no session).
        Schema::table('machines', fn (Blueprint $table) => $table->timestamp('unguarded_until')->nullable());
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('unguarded_until'));
    }
};
