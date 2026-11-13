<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_payram_accounts', static function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('organizer_id')->constrained('organizers')->onDelete('cascade')->unique();

            // Nullable: a provisioning attempt that failed before the project
            // was created still has to be recorded.
            $table->unsignedInteger('external_platform_id')->nullable()->comment('PayRam project id for this merchant');
            $table->string('project_name')->nullable();
            $table->string('member_email')->nullable()->comment('Their PayRam dashboard login');
            // Nullable: a failed provisioning attempt never got a key.
            $table->text('api_key')->nullable()->comment('Project API key, encrypted at rest');
            $table->text('provisioned_password')->nullable()->comment('Initial dashboard password, shown to them once');

            $table->string('status', 30)->default('PROVISIONING')->comment('PROVISIONING | READY | FAILED');
            $table->string('wallet_status', 30)->nullable()->comment('NOT_CONFIGURED | READY | UNKNOWN');
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('member_email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_payram_accounts');
    }
};
