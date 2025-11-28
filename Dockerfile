# Étape 1 : Image de base FrankenPHP
FROM dunglas/frankenphp

# Force l'environnement de production
ENV APP_ENV=prod

# Installation des dépendances système (curl, git, unzip, etc.)
RUN apt-get update && apt-get install -y curl git unzip ca-certificates

# Installation des extensions PHP
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu

WORKDIR /app

# Copie de Composer et des fichiers de définition
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY app/composer.* ./
COPY app/symfony.* ./

# Installation des dépendances PHP
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --optimize-autoloader

# Copie du code source
COPY app/ .

# Permissions pour le cache et les logs
RUN mkdir -p var/cache var/log var/sessions && \
    chmod -R 777 var/

# Configuration Caddy
COPY Caddyfile /etc/caddy/Caddyfile

# --- ASSETS ---
RUN php bin/console importmap:install
RUN php bin/console tailwind:build --minify
RUN php bin/console assets:install public
RUN php bin/console asset-map:compile

# Nettoyage et préchauffage du cache
RUN php bin/console cache:clear
RUN php bin/console cache:warmup

# --- CORRECTION DE L'ENTRYPOINT ---
# On copie le fichier vers le bon chemin
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

# On le rend exécutable
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Lancement
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]