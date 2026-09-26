<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orders and attendees only ever recorded an email address, so a
        // buyer's own tickets could not be found from their account. Nullable
        // because guest and manually-created orders are legitimate.
        Schema::table('orders', static function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('user_id');
        });

        Schema::table('attendees', static function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('user_id');
        });

        // Email is the only link that exists today, so it is what the backfill
        // matches on — case-insensitively, and skipping soft-deleted rows. A
        // buyer who registered after placing a guest order, or who has since
        // changed their address, simply keeps a null user_id: their tickets
        // don't appear, and nothing else is affected.
        DB::statement(<<<'SQL'
            update orders o
               set user_id = u.id
              from users u
             where lower(o.email) = lower(u.email)
               and o.user_id is null
               and o.deleted_at is null
               and u.deleted_at is null
        SQL);

        DB::statement(<<<'SQL'
            update attendees a
               set user_id = u.id
              from users u
             where lower(a.email) = lower(u.email)
               and a.user_id is null
               and a.deleted_at is null
               and u.deleted_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::table('attendees', static function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('orders', static function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
