<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEXTE_BANNI (A5, A6, A7, B5).
 *
 * Stocké en clair et normalisé, pour pouvoir le chercher ; l'empreinte (SHA-256 du texte)
 * n'évite que les doublons (règle 16). Trois statuts (règle 17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textes_bannis', function (Blueprint $table) {
            $table->id();
            $table->text('texte');
            $table->char('empreinte', 64)->unique();
            $table->text('motif')->nullable();
            $table->foreignId('origine_id')->constrained('bibliotheques');
            $table->enum('statut', ['propose', 'applique', 'rejete']);
            $table->timestamp('decide_le')->nullable();
            $table->timestamp('cree_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textes_bannis');
    }
};
