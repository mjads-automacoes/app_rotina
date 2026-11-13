FROM php:8.2-apache

# Apache: reescrita para o index.php, cabecalhos, IP real do visitante atras do proxy (Traefik/Easypanel)
RUN a2enmod rewrite headers expires remoteip \
 && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16\nServerTokens Prod\nServerSignature Off\nTraceEnable Off\n' > /etc/apache2/conf-available/rotina.conf \
 && a2enconf rotina

# PHP: sem versao nos cabecalhos, erros so no log, sessao estrita
RUN { echo 'expose_php=Off'; echo 'display_errors=Off'; echo 'log_errors=On'; echo 'session.use_strict_mode=1'; echo 'session.cookie_httponly=1'; echo 'session.cookie_samesite=Lax'; } > /usr/local/etc/php/conf.d/zz-rotina.ini

COPY src /var/www/html/src
COPY views /var/www/html/views
COPY public /var/www/html/public
COPY scripts/tick.php /var/www/html/scripts/tick.php

# O banco (SQLite) vive no volume /data: monte um volume persistente aqui.
ENV ROTINA_DATA_DIR=/data
RUN mkdir -p /data && chown -R www-data:www-data /data
VOLUME ["/data"]

EXPOSE 80
