<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que les membres enregistrent (règles 7 à 12) : MARQUE_PAGE, COLLECTION, COLLECTION_PAGE,
 * ANNOTATION, VOTE, LIEN_COURT, RECHERCHE.
 *
 * Supprimer un compte efface ses données (règle 19) : cascade depuis utilisateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marque_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses');
            $table->string('titre', 120)->nullable();
            $table->timestamp('cree_le')->nullable();

            $table->unique(['utilisateur_id', 'adresse_id']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->string('nom', 120);
            $table->text('description')->nullable();
            $table->boolean('est_publique')->default(false);
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->nullable();
            $table->timestamp('maj_le')->nullable();
        });

        Schema::create('collection_pages', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('adresse_id')->constrained('adresses');

            $table->primary(['collection_id', 'position']);
        });

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses');
            $table->string('contenu', 2000);
            $table->boolean('est_publique')->default(false);
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->nullable();
            $table->timestamp('maj_le')->nullable();
        });

        Schema::create('votes', function (Blueprint $table) {
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('adresse_id')->constrained('adresses');
            $table->tinyInteger('valeur');
            $table->timestamp('cree_le')->nullable();

            $table->primary(['utilisateur_id', 'adresse_id']);
        });

        Schema::create('liens_courts', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 8)->unique();
            $table->foreignId('utilisateur_id')->nullable()->constrained('utilisateurs')->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->cascadeOnDelete();
            $table->foreignId('adresse_id')->nullable()->constrained('adresses');
            $table->text('passage')->nullable();
            $table->unsignedInteger('visites')->default(0);
            $table->timestamp('expire_le')->nullable();
            $table->timestamp('masque_le')->nullable();
            $table->timestamp('cree_le')->nullable();
        });

        Schema::create('recherches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')->constrained('utilisateurs')->cascadeOnDelete();
            $table->text('texte');
            $table->enum('mode', ['exact', 'bruit']);
            $table->enum('portee', ['locale', 'reseau']);
            $table->timestamp('cree_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recherches');
        Schema::dropIfExists('liens_courts');
        Schema::dropIfExists('votes');
        Schema::dropIfExists('annotations');
        Schema::dropIfExists('collection_pages');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('marque_pages');
    }
};
