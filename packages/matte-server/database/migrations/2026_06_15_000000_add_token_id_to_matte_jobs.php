<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matte_jobs', function (Blueprint $table): void {
            $table->string('token_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('matte_jobs', function (Blueprint $table): void {
            $table->dropIndex(['token_id']);
            $table->dropColumn('token_id');
        });
    }
};
