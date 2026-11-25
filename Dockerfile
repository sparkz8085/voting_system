# CampusVote — Online Voting System
# Production image for Render (PHP 8.2 + Apache + required extensions).

FROM php:8.2-apache

# Install required PHP extensions and Apache modules.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && apt-get clean

# Apache modules required by the application .htaccess.
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy the application source (keeps the existing directory structure).
COPY . /var/www/html/

# Render injects the port through the PORT environment variable (default 10000).
ENV PORT=10000
RUN sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# Allow .htaccess overrides (Options -Indexes, FilesMatch, mod_headers).
RUN sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/sites-available/000-default.conf

EXPOSE 10000

CMD ["apache2-foreground"]
