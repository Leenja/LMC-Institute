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
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('GuestId')->constrained('users');
            $table->foreignId('LanguageId')->constrained('languages');
            $table->string('Level')->default('Not Set');
            $table->float('AudioScore')->nullable();
            $table->float('ReadingScore')->nullable();
            $table->float('SpeakingScore')->nullable();
            $table->float('TotalScore')->nullable();
            $table->enum('Status', ['Pending','Completed'])->default('Pending');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('placement_tests');
    }
};
