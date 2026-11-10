<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sale form treats documents and installments as optional,
     * so a sale without files or with fewer than six installments
     * failed on insert.
     */
    public function up(): void
    {
        Schema::table('customer_numerahas', function (Blueprint $table) {
            $table->json('multipleDocs')->nullable()->change();
            $table->string('first_phase')->nullable()->change();
            $table->string('second_phase')->nullable()->change();
            $table->string('third_phase')->nullable()->change();
            $table->string('fourth_phase')->nullable()->change();
            $table->string('fifth_phase')->nullable()->change();
            $table->string('sixth_phase')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_numerahas', function (Blueprint $table) {
            $table->json('multipleDocs')->nullable(false)->change();
            $table->string('first_phase')->nullable(false)->change();
            $table->string('second_phase')->nullable(false)->change();
            $table->string('third_phase')->nullable(false)->change();
            $table->string('fourth_phase')->nullable(false)->change();
            $table->string('fifth_phase')->nullable(false)->change();
            $table->string('sixth_phase')->nullable(false)->change();
        });
    }
};
