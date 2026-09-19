<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->timestamps();

            $table->unique(['event_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seats');
    }
};
