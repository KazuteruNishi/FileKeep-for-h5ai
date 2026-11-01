# Optional isolated test image only. Production can keep its existing image;
# see compose.apache.yml and INSTALL-AUTH.md (no build needed).
FROM php:8.5-apache
RUN a2enmod rewrite
COPY apache-h5ai.conf /etc/apache2/sites-available/000-default.conf
COPY auth.ini /usr/local/etc/php/conf.d/h5ai-auth.ini
