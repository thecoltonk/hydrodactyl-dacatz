<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Panel-wide marketplace favorites. Every user's favorites are visible to every
| other user in the installer's Favorites tab.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_favorites', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedInteger('user_id');
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

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['user_id', 'type', 'source', 'project_id'], 'marketplace_favorites_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_favorites');
    }
};
