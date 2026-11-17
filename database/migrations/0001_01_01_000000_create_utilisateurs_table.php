<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UTILISATEUR (V19, M1, M9, M10, A1, A2, A3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('pseudo', 50)->unique();
            $table->string('email')->unique();
            $table->string('mot_de_passe_hache');
            $table->enum('role', ['membre', 'admin'])->default('membre');
            $table->timestamp('suspendu_le')->nullable();
            $table->timestamp('derniere_connexion_le')->nullable();
            // Colonne technique de Laravel pour « rester connecté » au back-office.
            $table->rememberToken();
            $table->timestamp('cree_le')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('utilisateurs');
    }
};
