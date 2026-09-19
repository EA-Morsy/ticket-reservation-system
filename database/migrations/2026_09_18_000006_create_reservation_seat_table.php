<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_seat', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reservation_id', 'seat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_seat');
    }
};
