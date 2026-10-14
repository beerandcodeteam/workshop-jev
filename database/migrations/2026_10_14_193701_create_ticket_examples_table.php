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
        Schema::ensureVectorExtensionExists();

        Schema::create('ticket_examples', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->unsignedInteger('dataset_id')->nullable()->unique();
            $table->foreignId('ticket_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->text('text');
            $table->string('team');
            $table->unsignedTinyInteger('urgency')->nullable();
            $table->text('note')->nullable();
            $table->vector('embedding', dimensions: 1536)->nullable()->vectorIndex();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_examples');
    }
};
