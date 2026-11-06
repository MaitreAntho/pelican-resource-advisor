<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The limits of the server when the hour was measured, so the advice only looks at the data
     * gathered since the last change. Empty for the hours measured before 1.1.0.
     */
    public function up(): void
    {
        Schema::table('resource_advisor_samples', function (Blueprint $table) {
            $table->unsignedInteger('memory_limit')->nullable();
            $table->unsignedInteger('disk_limit')->nullable();
            $table->unsignedInteger('cpu_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resource_advisor_samples', function (Blueprint $table) {
            $table->dropColumn(['memory_limit', 'disk_limit', 'cpu_limit']);
        });
    }
};
