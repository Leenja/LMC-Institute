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
        Schema::create('self_test_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('SelfTestId')->constrained('self_tests')->onDelete('cascade');
            $table->string('Media')->nullable();
            $table->text('QuestionText');
            $table->enum('Type', ['MCQ', 'true_false','translate']);
            $table->json('Choices')->nullable(); // Only for MCQ
            $table->string('CorrectAnswer')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('self_test_questions');
    }
};
