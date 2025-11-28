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
        # Tentative de connexion silencieuse
        if php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; then
            echo "[Migrations] Connexion réussie à la tentative $attempt !"
            break
        fi

        # --- MODE DEBUG ---
        # Si c'est la 1ère tentative ou la 10ème, on affiche l'erreur réelle pour le débogage
        if [ $attempt -eq 1 ] || [ $attempt -eq 10 ]; then
            echo "-----------------------------------------------------"
            echo "[DEBUG] La connexion a échoué. Voici l'erreur exacte :"
            # On relance la commande sans masquer la sortie pour voir l'erreur
            php bin/console doctrine:query:sql "SELECT 1" || true
            echo "-----------------------------------------------------"
        fi
        # ------------------

        echo "[Migrations] ($attempt/$max_attempts) En attente de la base de données..."
        sleep 2
        attempt=$((attempt + 1))
    done

    # Si on a dépassé le nombre d'essais
    if [ $attempt -gt $max_attempts ]; then
        echo "[Migrations] ERREUR CRITIQUE : Impossible de se connecter à la DB après 60 secondes."
        echo "[Migrations] Les migrations sont annulées."
        return
    fi

    # Si la connexion est bonne, on lance les migrations
    echo "[Migrations] Lancement de 'doctrine:migrations:migrate'..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --all-or-nothing
    
    echo "[Migrations] Terminé avec succès."
}

# 1. Lancer la fonction en arrière-plan (background)
# Le '&' est vital pour que FrankenPHP démarre tout de suite
run_migrations &

# 2. Démarrer FrankenPHP immédiatement
# Cela permet à Koyeb de valider le Health Check (port 80 ouvert)
echo "Démarrage du serveur FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile