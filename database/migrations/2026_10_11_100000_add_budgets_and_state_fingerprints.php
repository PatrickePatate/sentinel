<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', fn (Blueprint $table) => $table->decimal('monthly_budget_usd', 10, 2)->nullable());

        Schema::table('agent_runs', function (Blueprint $table) {
            // Hash of the machine's security-relevant state when an audit ran: an unchanged state can skip the next AI call.
            $table->string('state_fingerprint', 64)->nullable();
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn('state_fingerprint');
        });
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn('monthly_budget_usd'));
    }
};
