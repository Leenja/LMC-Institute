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
        Schema::create('staff_infos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('UserId')->constrained('users')->onDelete('cascade');
            $table->string('Photo')->nullable();
            $table->text('Description')->nullable();
            $table->timestamps();
        
            $table->softDeletes();  // <-- هذا السطر لإضافة soft deletes
        
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_infos');
    }
};
