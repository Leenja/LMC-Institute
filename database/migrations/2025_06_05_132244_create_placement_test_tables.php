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
        Schema::create('placement_test_questions', function (Blueprint $table) {
            $table->id();
            $table->enum('Section', ['Listening', 'Reading', 'LanguageUse']);
            $table->text('Context')->nullable();
            $table->text('QuestionText');
            $table->text('Media')->nullable();
            $table->timestamps();
        });

        Schema::create('placement_test_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('QuestionId')->constrained('placement_test_questions')->onDelete('cascade');
            $table->text('AnswerText');
            $table->boolean('isCorrect')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('placement_test_answers');
        Schema::dropIfExists('placement_test_questions');
    }
};
