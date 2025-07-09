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
        Schema::create('test_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('StudentId')->constrained('users')->onDelete('cascade');
            $table->foreignId('TestId')->constrained('tests')->onDelete('cascade');
            $table->foreignId('LastAnsweredQuestionId')->nullable()->constrained('test_questions')->nullOnDelete();
            $table->integer('Score')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('test_progresses');
    }
};
