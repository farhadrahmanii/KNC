<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plot form saves boundaries and numera_type.
     * The original table had numeraha_type and no boundary columns.
     */
    public function up(): void
    {
        Schema::table('numerahas', function (Blueprint $table) {
            if (! Schema::hasColumn('numerahas', 'north')) {
                $table->string('north')->nullable();
            }
            if (! Schema::hasColumn('numerahas', 'south')) {
                $table->string('south')->nullable();
            }
            if (! Schema::hasColumn('numerahas', 'east')) {
                $table->string('east')->nullable();
            }
            if (! Schema::hasColumn('numerahas', 'west')) {
                $table->string('west')->nullable();
            }
        });

        if (Schema::hasColumn('numerahas', 'numeraha_type') && ! Schema::hasColumn('numerahas', 'numera_type')) {
            Schema::table('numerahas', function (Blueprint $table) {
                $table->renameColumn('numeraha_type', 'numera_type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('numerahas', 'numera_type') && ! Schema::hasColumn('numerahas', 'numeraha_type')) {
            Schema::table('numerahas', function (Blueprint $table) {
                $table->renameColumn('numera_type', 'numeraha_type');
            });
        }

        Schema::table('numerahas', function (Blueprint $table) {
            $drop = [];
            foreach (['north', 'south', 'east', 'west'] as $column) {
                if (Schema::hasColumn('numerahas', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
