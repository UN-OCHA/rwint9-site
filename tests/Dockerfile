FROM unocha/rwint9-site:local

RUN apk add php8-pecl-xdebug && \
    echo 'zend_extension=xdebug;' >> /etc/php8/conf.d/90_xdebug.ini

RUN mkdir -p /srv/www/html/build/logs
