<?php

use Coyote6\LaravelSettings\Tests\Fixtures\Models\Client;
use Coyote6\LaravelSettings\Tests\Fixtures\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create one settings table per owner type, in the shape settings:add-type will write.
     *
     * @label Up
     *
     * @ai foreignIdFor() reads the owner model: bigint for User, uuid for
     *     Client (HasUuids), so both owner key types are tested.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('client_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->foreignIdFor(Client::class)->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'name']);
        });
    }

    /**
     * Drop the settings tables.
     *
     * @label Down
     */
    public function down(): void
    {
        Schema::dropIfExists('client_settings');
        Schema::dropIfExists('user_settings');
    }
};
