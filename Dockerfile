FROM php:8.1-apache

# Install system dependencies and required PHP extensions for QloApps
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libxml2-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip xml soap

# Enable Apache mod_rewrite for QloApps pretty URLs
RUN a2enmod rewrite

# Copy QloApps source code to web root
COPY . /var/www/html/

# Adjust file permissions for web installer write access
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
