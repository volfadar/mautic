FROM php:8.1-apache

# Arguments defined in docker-compose.yml
ARG user
ARG uid

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

#Modify php.ini setings

RUN touch /usr/local/etc/php/conf.d/uploads.ini \
    && echo "memory_limit = 512M;" >> /usr/local/etc/php/conf.d/uploads.ini
