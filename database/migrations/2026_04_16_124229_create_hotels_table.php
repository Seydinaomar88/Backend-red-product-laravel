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
    Schema::create('hotels', function (Blueprint $table) {
        $table->id();

        // USER RELATION
        $table->foreignId('user_id')
            ->constrained()
            ->onDelete('cascade');

        $table->string('name');
        $table->string('address');

        $table->string('email')->nullable();

        $table->decimal('price', 10, 2);
        $table->string('currency')->default('XOF');

        $table->string('image')->nullable();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hotels');
    }
};
