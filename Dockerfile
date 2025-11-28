# Étape 1 : Image de base FrankenPHP
FROM dunglas/frankenphp

# Force l'environnement de production immédiatement
ENV APP_ENV=prod

# --- CORRECTION ICI ---
# On installe curl et ca-certificates pour pouvoir télécharger le binaire Tailwind
RUN apt-get update && apt-get install -y curl git unzip ca-certificates
# ----------------------

# Installation des extensions PHP requises
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu

# Définition du dossier de travail
WORKDIR /app

# Copie de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copie des fichiers de dépendances (depuis le dossier app/)
COPY app/composer.* ./
COPY app/symfony.* ./

# Installation des dépendances
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --optimize-autoloader

# Copie du reste du code
COPY app/ .

# Création des dossiers de cache
RUN mkdir -p var/cache var/log && \
    chmod -R 777 var/

# --- CONFIGURATION DU SERVEUR (La partie ajoutée) ---
# On copie le Caddyfile qu'on vient de créer vers le dossier de config du conteneur
COPY Caddyfile /etc/caddy/Caddyfile


# --- ÉTAPE ASSETS ---
RUN php bin/console importmap:install
# ON SUPPRIME la ligne "tailwind:install" qui n'existe pas.

# IMPORTANT : On retire le "|| echo" pour voir si le build plante
RUN php bin/console tailwind:build --minify

# Installation finale
RUN php bin/console assets:install public

# 4. Compile et écrit les fichiers versionnés (avec les hashs) dans public/assets/
RUN php bin/console asset-map:compile

RUN php bin/console cache:clear
RUN php bin/console cache:warmup

# Lancement du serveur
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]