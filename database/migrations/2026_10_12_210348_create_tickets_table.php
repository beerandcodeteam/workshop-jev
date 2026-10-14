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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('model')->index();
            $table->string('customer');
            $table->text('text');
            $table->string('expected_team')->nullable();
            $table->unsignedTinyInteger('expected_urgency')->nullable();
            $table->string('kind')->nullable();
            $table->string('team')->nullable();
            $table->json('team_probabilities')->nullable();
            $table->float('confidence')->nullable();
            $table->float('urgency')->nullable();
            $table->json('urgency_probabilities')->nullable();
            $table->float('frustration')->nullable();
            $table->json('frustration_probabilities')->nullable();
            $table->float('refund')->nullable();
            $table->unsignedInteger('ms')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('triaged_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
