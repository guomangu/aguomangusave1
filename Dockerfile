# Étape 1 : Image de base FrankenPHP
FROM dunglas/frankenphp

# Configuration de l'environnement
ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV SERVER_NAME=":80"

# Installation des extensions PHP
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

# --- CORRECTION ICI ---
# On utilise des wildcards (*) pour que ça ne plante pas si symfony.lock est absent
COPY composer.* symfony.* ./

# Installation des dépendances
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --optimize-autoloader

# Copie du reste du code source
COPY . .

# Création des dossiers de cache
RUN mkdir -p var/cache var/log && \
    chmod -R 777 var/

# --- ÉTAPE TAILWIND ---
RUN php bin/console tailwind:install --no-interaction || true
RUN php bin/console tailwind:build --minify || echo "Tailwind build skipped"
RUN php bin/console assets:install public

# Nettoyage final
RUN php bin/console cache:clear

# Lancement
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]