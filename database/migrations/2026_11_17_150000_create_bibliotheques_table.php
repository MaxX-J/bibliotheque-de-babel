<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIBLIOTHEQUE (A8, A9, V15, V16, V21, M13, B6).
 *
 * Notre bibliothèque est une ligne comme les autres, avec est_locale à vrai (règle 5).
 * Un identifiant stable, une URL qui change ; ni liste blanche ni liste noire (règle 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bibliotheques', function (Blueprint $table) {
            $table->id();
            $table->string('identifiant', 40)->unique();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->string('url_actuelle');
            $table->boolean('est_locale')->default(false);
            $table->enum('etat', ['en_ligne', 'hors_ligne', 'inconnu'])->default('inconnu');
            $table->unsignedInteger('echecs_consecutifs')->default(0);
            $table->timestamp('dernier_contact_le')->nullable();
            $table->timestamp('vue_annuaire_le')->nullable();
            $table->char('empreinte_cle', 64)->nullable();
            $table->timestamp('cle_changee_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bibliotheques');
    }
};
