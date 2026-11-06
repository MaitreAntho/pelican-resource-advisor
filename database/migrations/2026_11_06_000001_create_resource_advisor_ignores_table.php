<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_advisor_ignores', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            // Empty means ignored until an administrator stops it.
            $table->dateTime('until')->nullable();
            $table->dateTime('created_at')->nullable();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_advisor_ignores');
    }
};
