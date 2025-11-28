#!/bin/bash
set -e

# Attendre que la base de données soit prête (optionnel, utile si PostgreSQL démarre en même temps)
# Vous pouvez décommenter si nécessaire
# until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
#   echo "En attente de la base de données..."
#   sleep 2
# done

# Exécuter les migrations
echo "Exécution des migrations..."
if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
    echo "Migrations exécutées avec succès"
else
    echo "Avertissement: Erreur lors de l'exécution des migrations (peut être normal si les tables existent déjà ou si la base n'est pas encore accessible)"
    # On continue quand même, le site gérera les tables manquantes
fi

# Lancer FrankenPHP
exec frankenphp run --config /etc/caddy/Caddyfile

