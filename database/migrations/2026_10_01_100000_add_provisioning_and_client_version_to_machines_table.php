<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            // One-line provisioning: a short-lived secret in the URL the machine downloads its script from.
            $table->text('provision_token')->nullable();
            $table->timestamp('provision_token_expires_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            // Host key fingerprints the provisioning script reported over TLS (what the pin is checked against).
            $table->json('host_keys_reported')->nullable();
            // What the machine runs ("bundle=<hash> updater=<hash>") as last seen, to offer remote updates.
            $table->string('client_version')->nullable();
            $table->timestamp('client_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['provision_token', 'provision_token_expires_at', 'provisioned_at', 'host_keys_reported', 'client_version', 'client_checked_at']);
        });
    }
};
