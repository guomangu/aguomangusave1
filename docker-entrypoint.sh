#!/bin/bash
set -e

# Attendre que la base de données soit prête (utile si PostgreSQL démarre en même temps)
echo "Vérification de la connexion à la base de données..."
max_attempts=30
attempt=0
while [ $attempt -lt $max_attempts ]; do
    if php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
        echo "Connexion à la base de données réussie"
        break
    fi
    attempt=$((attempt + 1))
    if [ $attempt -lt $max_attempts ]; then
        echo "Tentative $attempt/$max_attempts : En attente de la base de données..."
        sleep 2
    else
        echo "Avertissement: Impossible de se connecter à la base de données après $max_attempts tentatives"
        echo "Le conteneur continuera, mais les migrations ne seront pas exécutées"
    fi
done

# Exécuter les migrations seulement si la connexion est réussie
if php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
    echo "Exécution des migrations..."
    if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
        echo "Migrations exécutées avec succès"
    else
        echo "Avertissement: Erreur lors de l'exécution des migrations (peut être normal si les tables existent déjà)"
        # On continue quand même, le site gérera les tables manquantes
    fi
fi

# Lancer FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile

