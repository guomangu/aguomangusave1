# Étape 1 : Image de base FrankenPHP
FROM dunglas/frankenphp

# Configuration de l'environnement
ENV APP_ENV=prod
# --- CORRECTION ICI : On retire "worker" pour passer en mode standard (stable) ---
ENV FRANKENPHP_CONFIG="./public/index.php"
ENV SERVER_NAME=":80"

# Installation des extensions PHP requises
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu

# Définition du dossier de travail dans le conteneur
WORKDIR /app

# Copie de Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# On copie les fichiers de dépendances DEPUIS le dossier 'app/' vers la racine du conteneur
COPY app/composer.* ./
# On essaie de copier symfony.lock s'il existe
COPY app/symfony.* ./

# Installation des dépendances PHP
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --optimize-autoloader

# On copie tout le reste du code DEPUIS le dossier 'app/'
COPY app/ .

# Création des dossiers de cache
RUN mkdir -p var/cache var/log && \
    chmod -R 777 var/

# --- ÉTAPE ASSETS (TAILWIND & JS) ---
# 1. Installation des dépendances JavaScript
RUN php bin/console importmap:install

# 2. Installation et compilation de Tailwind
RUN php bin/console tailwind:install --no-interaction || true
RUN php bin/console tailwind:build --minify || echo "Tailwind build skipped"

# 3. Installation finale des assets dans le dossier public
RUN php bin/console assets:install public

# Nettoyage final du cache
RUN php bin/console cache:clear

# Lancement du serveur
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]