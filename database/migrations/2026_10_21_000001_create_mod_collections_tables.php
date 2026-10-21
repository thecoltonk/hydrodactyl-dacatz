<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Per-user mod/plugin collections ("albums") for one-click installs on any of
| the user's servers. Private to each account. Replaces the short-lived public
| favorites table.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('marketplace_favorites');

        Schema::create('mod_collections', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedInteger('user_id');
            $table->string('name', 64);
            $table->unsignedSmallInteger('sort')->default(0);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'sort']);
        });

        Schema::create('mod_collection_items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedBigInteger('collection_id');
            $table->string('type', 16); // 'mod' | 'plugin'
            $table->string('source', 32);
            $table->string('project_id', 128);
            $table->string('title', 255);
            $table->string('slug', 191)->default('');
            $table->string('author', 191)->default('');
            $table->string('description', 500)->default('');
            $table->string('icon', 1024)->nullable();
            $table->string('url', 1024)->nullable();
            $table->unsignedBigInteger('downloads')->default(0);

            $table->foreign('collection_id')->references('id')->on('mod_collections')->onDelete('cascade');
            $table->unique(['collection_id', 'type', 'source', 'project_id'], 'mod_collection_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_collection_items');
        Schema::dropIfExists('mod_collections');
    }
};
