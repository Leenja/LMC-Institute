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
        Schema::create('flash_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('LessonId')->constrained('lessons');
            $table->foreignId('CourseId')->constrained('courses');
            $table->string('Content');
            $table->string('Translation');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flash_cards');
    }
};
