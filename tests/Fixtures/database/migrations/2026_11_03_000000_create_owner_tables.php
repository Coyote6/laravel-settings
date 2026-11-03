<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the owner tables: users with integer keys, clients with UUIDs.
     *
     * @label Up
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Drop the owner tables.
     *
     * @label Down
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
        Schema::dropIfExists('users');
    }
};
