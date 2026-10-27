<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identifies the open unguarded session (null: none), and the session a raw command was proposed in: a command
        // can only be approved in the session that proposed it.
        Schema::table('machines', fn (Blueprint $table) => $table->uuid('unguarded_session')->nullable());
        Schema::table('pending_actions', fn (Blueprint $table) => $table->uuid('unguarded_session')->nullable());
    }

    public function down(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('unguarded_session'));
        Schema::table('pending_actions', fn (Blueprint $table) => $table->dropColumn('unguarded_session'));
    }
};
