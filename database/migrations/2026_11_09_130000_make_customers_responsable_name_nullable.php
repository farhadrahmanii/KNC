<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No customer form collects a responsible person, so a required
     * responsable_name made every customer insert fail.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('responsable_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('responsable_name')->nullable(false)->change();
        });
    }
};
