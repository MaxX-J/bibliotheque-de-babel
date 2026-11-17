<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIGNALEMENT (règles 13 à 15), ACTIVITE_JOUR (règle 21), REGLAGE (règle 22).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Exactement une cible, par une vraie clé étrangère. Si le contenu signalé est
        // supprimé, ses signalements partent avec lui ; si l'auteur supprime son compte,
        // ses signalements restent, sans auteur.
        Schema::create('signalements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('annotation_id')->nullable()->constrained('annotations')->cascadeOnDelete();
            $table->foreignId('collection_id')->nullable()->constrained('collections')->cascadeOnDelete();
            $table->foreignId('lien_court_id')->nullable()->constrained('liens_courts')->cascadeOnDelete();
            $table->foreignId('auteur_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->text('motif');
            $table->enum('statut', ['en_attente', 'traite', 'rejete'])->default('en_attente');
            $table->foreignId('traite_par_id')->nullable()->constrained('utilisateurs')->nullOnDelete();
            $table->timestamp('traite_le')->nullable();
            $table->timestamp('cree_le')->nullable();
        });

        // Des compteurs par jour : ni une ligne par page lue, ni donnée personnelle.
        Schema::create('activites_jour', function (Blueprint $table) {
            $table->date('jour')->primary();
            $table->unsignedInteger('pages_lues')->default(0);
            $table->unsignedInteger('recherches')->default(0);
        });

        // Inscriptions ouvertes ou fermées, quota de l'API publique. Jamais la clé du
        // moteur ni le jeton réseau, qui restent dans la configuration du serveur.
        Schema::create('reglages', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
            $table->text('valeur');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reglages');
        Schema::dropIfExists('activites_jour');
        Schema::dropIfExists('signalements');
    }
};
