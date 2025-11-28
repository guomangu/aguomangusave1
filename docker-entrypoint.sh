#!/bin/bash
set -e

# Fonction pour exécuter les migrations en arrière-plan
run_migrations() {
    # Attendre que la base de données soit prête (utile si PostgreSQL démarre en même temps)
    echo "[Migrations] Vérification de la connexion à la base de données..."
    max_attempts=30
    attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        if php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
            echo "[Migrations] Connexion à la base de données réussie"
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -lt $max_attempts ]; then
            echo "[Migrations] Tentative $attempt/$max_attempts : En attente de la base de données..."
            sleep 2
        else
            echo "[Migrations] Avertissement: Impossible de se connecter à la base de données après $max_attempts tentatives"
            echo "[Migrations] Les migrations seront réessayées plus tard"
            return 1
        fi
    done

    # Exécuter les migrations seulement si la connexion est réussie
    if php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
        echo "[Migrations] Exécution des migrations..."
        if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
            echo "[Migrations] Migrations exécutées avec succès"
            return 0
        else
            echo "[Migrations] Avertissement: Erreur lors de l'exécution des migrations (peut être normal si les tables existent déjà)"
            return 1
        fi
    fi
    
    return 1
}

# Lancer les migrations en arrière-plan pour ne pas bloquer le démarrage du serveur
run_migrations &

# Lancer FrankenPHP immédiatement (ne pas attendre les migrations)
echo "Démarrage du serveur web..."
exec frankenphp run --config /etc/caddy/Caddyfile

