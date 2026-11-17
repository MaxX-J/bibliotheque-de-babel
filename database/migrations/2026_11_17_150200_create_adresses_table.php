<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADRESSE : tout ce qui désigne une page ou un livre (règles 1 à 4, 18).
 *
 * Aucun texte de page. Un hexagone peut faire 3 004 caractères, trop pour un index unique :
 * l'unicité porte sur (bibliotheque_id, empreinte), l'empreinte étant le SHA-256 de
 * l'adresse écrite sous sa forme valide. page nulle = livre entier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bibliotheque_id')->constrained('bibliotheques');
            $table->text('hexagone');
            $table->unsignedTinyInteger('mur');
            $table->unsignedTinyInteger('etagere');
            $table->unsignedTinyInteger('volume');
            $table->unsignedSmallInteger('page')->nullable();
            $table->char('empreinte', 64);
            $table->char('empreinte_livre', 64)->index();
            $table->foreignId('texte_banni_id')->nullable()->constrained('textes_bannis')->nullOnDelete();
            $table->timestamp('cree_le')->nullable();

            $table->unique(['bibliotheque_id', 'empreinte']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adresses');
    }
};
