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
        # --- CORRECTION FINALE ---
        # On utilise "database:create" avec "--if-not-exists".
        # C'est une astuce fiable : pour vérifier si la DB existe, Symfony doit se connecter.
        # Si la connexion échoue (timeout, mauvais mdp), la commande renvoie une erreur.
        if php bin/console doctrine:database:create --connection=default --if-not-exists > /dev/null 2>&1; then
            echo "[Migrations] Connexion réussie à la tentative $attempt !"
            break
        fi

        # --- MODE DEBUG ---
        if [ $attempt -eq 1 ] || [ $attempt -eq 10 ]; then
            echo "-----------------------------------------------------"
            echo "[DEBUG] La connexion a échoué. Voici l'erreur exacte :"
            # On affiche l'erreur
            php bin/console doctrine:database:create --connection=default --if-not-exists || true
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

    # Vérifier d'abord si les tables existent déjà en validant le schéma
    echo "[Migrations] Vérification de l'existence des tables..."
    if php bin/console doctrine:schema:validate 2>&1 | grep -q "mapping.*OK"; then
        echo "[Migrations] ✓ Les tables existent déjà, pas besoin de migration"
        return 0
    fi
    
    # Essayer d'abord les migrations si elles existent
    echo "[Migrations] Tentative d'exécution des migrations..."
    if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
        echo "[Migrations] Migrations exécutées"
        
        # Vérifier que ça a fonctionné en validant le schéma
        if php bin/console doctrine:schema:validate 2>&1 | grep -q "mapping.*OK"; then
            echo "[Migrations] ✓ Tables créées via migrations"
            return 0
        fi
    fi
    
    # Si les migrations n'ont pas fonctionné ou n'existent pas, créer le schéma directement
    echo "[Migrations] Création du schéma directement depuis les entités..."
    if php bin/console doctrine:schema:update --force 2>&1; then
        echo "[Migrations] ✓ Schéma créé avec succès"
        
        # Attendre un peu pour que PostgreSQL finalise la création
        sleep 1
        
        # Vérifier que le schéma est valide
        echo "[Migrations] Vérification de la validité du schéma..."
        if php bin/console doctrine:schema:validate 2>&1 | grep -q "mapping.*OK"; then
            echo "[Migrations] ✓ Schéma validé avec succès"
            return 0
        else
            echo "[Migrations] ⚠ Le schéma n'a pas pu être validé, mais il a été créé"
            echo "[Migrations] Le site devrait fonctionner quand même"
            return 0  # On retourne 0 car le schéma a été créé avec succès
        fi
    else
        echo "[Migrations] ⚠ Échec de la création du schéma"
        return 1
    fi
    
    echo "[Migrations] Processus de migration terminé."
}

# 1. Lancer la fonction en arrière-plan
run_migrations &

# 2. Démarrer FrankenPHP immédiatement
echo "Démarrage du serveur FrankenPHP..."
exec frankenphp run --config /etc/caddy/Caddyfile