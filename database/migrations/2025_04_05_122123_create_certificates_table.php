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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('CourseId')->constrained('courses')->onDelete('cascade');
            $table->foreignId('StudentId')->constrained('users')->onDelete('cascade');
            $table->string('VerificationCode')->unique();
            $table->string('CourseLanguage');
            $table->string('CourseLevel');
            $table->string('TeacherName');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
