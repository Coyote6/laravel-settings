<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the owner tables: users (integer keys), clients (UUIDs), teams (ULIDs), accounts (plain strings).
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

        Schema::create('teams', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamps();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->string('code', 20)->primary();
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
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('users');
    }
};
