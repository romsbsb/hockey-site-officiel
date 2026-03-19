# 1. Utiliser une image officielle PHP avec Apache
FROM php:8.2-apache

# 2. Installer les dépendances système pour PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev

# 3. Installer les extensions PHP pour la base de données
RUN docker-php-ext-install pdo pdo_pgsql pgsql

# 4. Activer le module de réécriture d'Apache (pratique pour les URL)
RUN a2enmod rewrite

# 5. Copier tout ton code source dans le dossier du serveur
COPY . /var/www/html/

# 6. Configurer le port dynamique exigé par Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf

# 7. Donner les bonnes permissions aux fichiers
RUN chown -R www-data:www-data /var/www/html