FROM php:8.2-apache

# Install PDO MySQL extension
RUN docker-php-ext-install pdo pdo_mysql

# Disable conflicting MPMs and enable mpm_prefork & rewrite
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true \
    && a2enmod mpm_prefork rewrite

# Enable AllowOverride in Apache
RUN sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Configure Apache to listen on $PORT provided dynamically by Railway
RUN sed -i 's/80/${PORT}/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
ENV PORT=80

# Copy project files
COPY . /var/www/html/

# Set working directory & permissions
WORKDIR /var/www/html
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["sh", "-c", "a2dismod mpm_event mpm_worker 2>/dev/null || true; a2enmod mpm_prefork 2>/dev/null || true; exec apache2-foreground"]
