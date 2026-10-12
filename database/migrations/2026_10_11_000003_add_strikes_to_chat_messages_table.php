<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->boolean('strike')->default(false)->after('guideline');
            $table->boolean('ban')->default(false)->after('strike');
            $table->boolean('skipped')->default(false)->after('ban');
            $table->index(['model', 'user']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['model', 'user']);
            $table->dropColumn(['strike', 'ban', 'skipped']);
        });
    }
};
