#!/bin/bash
set -e

# Fonction pour exécuter les migrations en arrière-plan
run_migrations() {
    echo "[Migrations] Démarrage du processus de migration en arrière-plan..."
    
    # Attente active de la base de données (Max 60 secondes)
    max_attempts=30
    attempt=0
    
    until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
        attempt=$((attempt + 1))
        if [ $attempt -ge $max_attempts ]; then
            echo "[Migrations] ERREUR: Timeout - La base de données n'est pas accessible."
            return 1
        fi
        echo "[Migrations] En attente de la base de données... ($attempt/$max_attempts)"
        sleep 2
    done

    echo "[Migrations] Base de données connectée. Lancement des migrations..."
    
    # On ignore les erreurs ici pour ne pas crasher le script complet si une migration échoue
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || echo "[Migrations] Échec des migrations"
    
    echo "[Migrations] Terminé."
}

# 1. On lance la fonction en arrière-plan
run_migrations &

# 2. Démarrage immédiat du serveur Web (C'est ce qui valide le Health Check)
echo "Démarrage de FrankenPHP..."

# Le 'exec' est crucial : il remplace le processus shell par FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile