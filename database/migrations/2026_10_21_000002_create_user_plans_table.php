<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Per-user resource plans: the total RAM / CPU / disk (and server count) a user
| may spread across servers they create themselves. No row = no self-service.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_plans', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->unsignedInteger('user_id')->unique();
            $table->unsignedInteger('memory'); // MiB
            $table->unsignedInteger('cpu'); // percent, 100 = 1 core
            $table->unsignedInteger('disk'); // MiB
            $table->unsignedSmallInteger('servers')->default(0); // 0 = no limit

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_plans');
    }
};
