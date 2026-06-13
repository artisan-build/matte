<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matte_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('input_ref');
            $table->string('output_ref')->nullable();
            $table->string('mode');
            $table->string('preset');
            $table->string('model')->nullable();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matte_jobs');
    }
};
