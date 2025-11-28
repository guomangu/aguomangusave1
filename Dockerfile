# Étape 1 : Image de base FrankenPHP (contient PHP 8.2+ et Caddy)
FROM dunglas/frankenphp

# Configuration de l'environnement
ENV APP_ENV=prod
ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV SERVER_NAME=":80"

# Installation des extensions PHP requises par Symfony et PostgreSQL
# Ajoutez d'autres extensions ici si nécessaire (ex: redis, amqp)
RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu

# Définition du dossier de travail
WORKDIR /app

# Copie de Composer depuis l'image officielle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copie des fichiers de configuration de dépendances
COPY composer.json composer.lock symfony.lock ./

# Installation des dépendances (sans les paquets de dev, optimisé pour la prod)
# On lance l'installation AVANT de copier tout le code pour profiter du cache Docker
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --optimize-autoloader

# Copie du reste du code source
COPY . .

# Création des dossiers de cache et logs si inexistants
RUN mkdir -p var/cache var/log && \
    chmod -R 777 var/

# --- ÉTAPE TAILWIND ---
# Si vous utilisez symfony/ux-tailwind (binaire standalone)
# On télécharge le binaire nécessaire pour le build
RUN php bin/console tailwind:install --no-interaction || true
# On compile les fichiers CSS pour la production (minifiés)
RUN php bin/console tailwind:build --minify || echo "Tailwind build skipped"
# On installe les assets publics
RUN php bin/console assets:install public

# Nettoyage final du cache Symfony pour la prod
RUN php bin/console cache:clear

# Lancement du serveur FrankenPHP
CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile" ]