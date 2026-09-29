<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->unsignedInteger('scan_interval_minutes')->nullable();
            $table->timestamp('last_scan_at')->nullable();
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->string('trigger')->default('manual');
            $table->string('severity')->nullable();
            $table->text('summary')->nullable();
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // mail | telegram
            $table->text('settings'); // encrypted array: email / bot token, chat id, approvers, webhook secret
            $table->string('min_severity')->default('medium');
            $table->boolean('notify_scans')->default(true);
            $table->boolean('notify_approvals')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_channels');

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['trigger', 'severity', 'summary']);
        });

        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['scan_interval_minutes', 'last_scan_at']);
        });
    }
};
