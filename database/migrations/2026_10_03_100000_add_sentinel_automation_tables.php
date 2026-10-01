<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->text('site_urls')->nullable();
            $table->float('gate_max_destructive')->nullable();
            $table->float('gate_min_reversible')->nullable();
            $table->unsignedSmallInteger('gate_max_actions')->nullable();
            $table->json('trusted_actions')->nullable();
        });

        Schema::create('memory_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 500);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('site_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->boolean('ok')->default(true);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamp('cert_expires_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->string('alerted', 16)->nullable(); // down | cert: what the admin was last told about
            $table->timestamp('checked_at')->nullable();
            $table->unique(['machine_id', 'url']);
        });

        Schema::create('machine_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->float('value');
            $table->timestamp('recorded_at')->index();
            $table->index(['machine_id', 'name', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_metrics');
        Schema::dropIfExists('site_checks');
        Schema::dropIfExists('memory_suggestions');
        Schema::table('machines', fn (Blueprint $table) => $table->dropColumn(['site_urls', 'gate_max_destructive', 'gate_min_reversible', 'gate_max_actions', 'trusted_actions']));
    }
};
