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
        Schema::create('placement_test_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('PlacementTestId')->constrained('placement_tests')->onDelete('cascade');
            $table->foreignId('QuestionId')->nullable();
            $table->foreignId('SelectedAnswerId')->nullable()->constrained('placement_test_answers');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
