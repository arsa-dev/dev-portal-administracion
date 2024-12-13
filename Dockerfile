FROM php:8.2.7-apache

RUN apt-get update && apt install --fix-missing -y \
		libpq-dev nano \
		libxml2-dev libbz2-dev zlib1g-dev \
		libsqlite3-dev libsqlite3-0 mariadb-client curl exif ftp \
		zip unzip git npm \
		libldap2-dev \
		netcat-traditional \
	&& apt install --no-install-recommends -y libpq-dev \
	&& docker-php-ext-install intl \
	&& docker-php-ext-install mysqli \
	&& docker-php-ext-install pgsql \
	&& docker-php-ext-enable mysqli \
	&& docker-php-ext-enable pgsql \
	&& docker-php-ext-install pdo_mysql \
	&& docker-php-ext-install pdo_pgsql \
    && docker-php-ext-enable pdo_mysql \
    && docker-php-ext-enable pdo_pgsql \
    && docker-php-ext-install fileinfo \
    && docker-php-ext-enable fileinfo

# Instalación de librdkafka
RUN apt-get update \
    && apt-get install -y build-essential libssl-dev \
    && git clone https://github.com/edenhill/librdkafka.git \
    && cd librdkafka \
    && ./configure --prefix=/usr \
    && make \
    && make install

# Instalar las dependencias necesarias para GD y Xdebug
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libxpm-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install gd \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug

# Instalación de la extensión rdkafka para PHP
RUN pecl install rdkafka

RUN rm -rf /var/lib/apt/lists/*

# Añadir el php.ini de producción ofrecido por la imagen.
#RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

RUN echo "upload_max_filesize = 140M"  > /usr/local/etc/php/conf.d/custom.ini
RUN echo "post_max_size = 140M"  >> /usr/local/etc/php/conf.d/custom.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer self-update --2

# Copiamos y damos los permisos a la carpeta
WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Copiar el archivo de configuración de PHP
RUN mv "/var/www/html/custom-php.ini" "$PHP_INI_DIR/php.ini"
# RUN chown -R www-data:www-data /var/www/html

# Instalación de dependencias (composer)
RUN composer install
RUN npm install
RUN npm run build

# Generar un certificado autofirmado
RUN openssl req -x509 -nodes -days 365 -newkey rsa:2048 -keyout /etc/ssl/private/apache-selfsigned.key -out /etc/ssl/certs/apache-selfsigned.crt -subj "/CN=localhost"
# Copiar la configuración de Apache SSL
COPY docker_files/000-default-ssl.conf /etc/apache2/sites-available/000-default-ssl.conf
# Habilitar el sitio SSL
RUN a2enmod ssl
RUN a2ensite 000-default-ssl

# Habilitar htaccess
RUN a2enmod rewrite headers

RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 775 /var/www/html

# Ajustamos entrypoint y scripts de inicio
COPY ./docker_files/10-wait-pgsql.sh /etc/cont-init.d/10-wait-pgsql.sh
COPY ./docker_files/15-run-migrations.sh /etc/cont-init.d/15-run-migrations.sh
COPY ./docker_files/20-directory-permissions.sh /etc/cont-init.d/20-directory-permissions.sh
COPY <<EOF /startup.sh
#!/bin/sh
for script in /etc/cont-init.d/*.sh; do
  /bin/sh "\$script"
  if [ \$? -ne 0 ]; then
    echo "Error executing \$script. Abort startup"
    exit 1
  fi
done

# Define the Laravel log file path
LOGFILE="/var/www/html/storage/logs/laravel.log"
touch \$LOGFILE
chown -R www-data:www-data \$LOGFILE

# Ensure both Apache logs and Laravel logs are streamed to stdout and the log file
tail -f \$LOGFILE &

# Run Apache in the foreground and pipe both stdout and stderr to tee for dual logging
/usr/local/bin/docker-php-entrypoint apache2-foreground 2>&1 | tee -a \$LOGFILE &
APACHE_PID=\$!

# Monitor Apache process and exit the container if Apache stops
wait \$APACHE_PID
EXIT_CODE=\$?

echo "Apache exited with status \$EXIT_CODE, stopping container."
exit \$EXIT_CODE

EOF
RUN chmod +x /startup.sh /etc/cont-init.d/*.sh
ENTRYPOINT ["/startup.sh"]
