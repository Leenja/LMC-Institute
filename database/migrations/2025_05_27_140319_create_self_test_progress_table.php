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
        Schema::create('self_test_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('StudentId')->constrained('users');
            $table->foreignId('SelfTestId')->constrained('self_tests');
            $table->unsignedBigInteger('LastAnsweredQuestionId')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('self_test_progress');
    }
};
