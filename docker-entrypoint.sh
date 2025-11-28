#!/bin/bash
set -e

# Fonction pour exécuter les migrations en arrière-plan
run_migrations() {
    echo "[Migrations] Démarrage du processus de migration..."

    # Configuration de la boucle d'attente
    max_attempts=30
    attempt=1
    
    echo "[Migrations] Test de connexion à la base de données..."

    while [ $attempt -le $max_attempts ]; do
        # --- CORRECTION ICI : On utilise dbal:run-sql au lieu de query:sql ---
        if php bin/console doctrine:dbal:run-sql "SELECT 1" > /dev/null 2>&1; then
            echo "[Migrations] Connexion réussie à la tentative $attempt !"
            break
        fi

        # --- MODE DEBUG ---
        if [ $attempt -eq 1 ] || [ $attempt -eq 10 ]; then
            echo "-----------------------------------------------------"
            echo "[DEBUG] La connexion a échoué. Voici l'erreur exacte :"
            # On affiche l'erreur avec la nouvelle commande
            php bin/console doctrine:dbal:run-sql "SELECT 1" || true
            echo "-----------------------------------------------------"
        fi
        # ------------------

        echo "[Migrations] ($attempt/$max_attempts) En attente de la base de données..."
        sleep 2
        attempt=$((attempt + 1))
    done

    if [ $attempt -gt $max_attempts ]; then
        echo "[Migrations] ERREUR CRITIQUE : Impossible de se connecter à la DB après 60 secondes."
        return
    fi

    echo "[Migrations] Lancement de 'doctrine:migrations:migrate'..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
    
    echo "[Migrations] Terminé avec succès."
}

# 1. Lancer la fonction en arrière-plan
run_migrations &

# 2. Démarrer FrankenPHP immédiatement
echo "Démarrage du serveur FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile