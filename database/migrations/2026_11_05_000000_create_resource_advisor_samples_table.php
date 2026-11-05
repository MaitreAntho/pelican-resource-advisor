<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_advisor_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->dateTime('hour');
            $table->unsignedSmallInteger('samples')->default(0);
            $table->unsignedSmallInteger('running_samples')->default(0);
            $table->unsignedBigInteger('memory_max')->default(0);
            $table->unsignedBigInteger('memory_sum')->default(0);
            $table->float('cpu_max')->default(0);
            $table->float('cpu_sum')->default(0);
            $table->unsignedBigInteger('disk_max')->default(0);
            $table->unsignedBigInteger('disk_last')->default(0);

            $table->unique(['server_id', 'hour']);
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_advisor_samples');
    }
};
