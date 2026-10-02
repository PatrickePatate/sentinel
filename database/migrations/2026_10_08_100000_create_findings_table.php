<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            // Scan profile that tracks it: a web server check never resolves what an audit found, and the other way round.
            $table->string('profile')->default('audit');
            // Stable slug chosen by the agent and reused across scans (e.g. ssh-password-auth-enabled).
            $table->string('key', 80);
            $table->string('title');
            $table->string('severity');
            $table->text('evidence')->nullable();
            // open, acknowledged, muted, resolved
            $table->string('status')->default('open');
            $table->timestamp('muted_until')->nullable();
            $table->foreignId('first_seen_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->foreignId('resolved_run_id')->nullable()->constrained('agent_runs')->nullOnDelete();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamps();

            $table->unique(['machine_id', 'profile', 'key']);
            $table->index(['status', 'severity']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            // What this scan changed in the machine's findings: ids of new, escalated, resolved and ongoing ones.
            // Findings the agent reported during the run, applied to the machine's findings once the scan completes.
            $table->json('reported_findings')->nullable();
            $table->json('findings_diff')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', fn (Blueprint $table) => $table->dropColumn(['reported_findings', 'findings_diff']));
        Schema::dropIfExists('findings');
    }
};
