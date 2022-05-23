# Build the code.
FROM public.ecr.aws/unocha/unified-builder:8.0-stable as builder

ARG  BRANCH_ENVIRONMENT

ENV  NODE_ENV=$BRANCH_ENVIRONMENT

# Copy only the files necessary to build the code (see .dockerignore in root).
COPY . /srv/www

WORKDIR /srv/www

# Clean up previous composer installation and run new one.
RUN rm -rf ./vendor && \
    # Update composer to avoid issues with missing drupal files.
    # @see https://github.com/drupal-composer/drupal-project/issues/282
    composer selfupdate && \
    composer install --no-interaction

# Copy settings to default site location.
RUN cp -a docker/settings.php docker/services.yml docker/redis.services.yml html/sites/default

################################################################################

# Generate the image.
FROM public.ecr.aws/unocha/php-k8s:8.0-NR-stable

ARG VCS_REF
ARG VCS_URL
ARG BUILD_DATE
ARG GITHUB_ACTOR
ARG GITHUB_REPOSITORY
ARG GITHUB_SHA
ARG GITHUB_REF

ENV  NGINX_SERVERNAME=reliefweb.int \
     PHP_ENVIRONMENT=production \
     PHP_MEMORY_LIMIT=256M \
     PHP_MAX_CHILDREN=16 \
     GIT_BLAME=$GITHUB_ACTOR \
     GIT_REPO=$GITHUB_REPOSITORY \
     GIT_SHA=$GITHUB_SHA \
     GIT_REF=$GITHUB_REF

LABEL info.humanitarianresponse.build.date=$BUILD_DATE \
      info.humanitarianresponse.build.vcs-url=$VCS_URL \
      info.humanitarianresponse.build.vcs-ref=$VCS_REF

RUN mkdir -p /etc/nginx/custom && \
    # Install lua resty library to generate UUIDs.
    cd /tmp && \
    git clone --branch=0.0.7 https://github.com/thibaultcha/lua-resty-jit-uuid.git && \
    cp lua-resty-jit-uuid/lib/resty/jit-uuid.lua /usr/share/lua/common/resty/ && \
    rm -rf /tmp/lua-resty-jit-uuid && \
    # Permit nginx access to the X_DOCSTORE_PROVIDER_UUID env variable.
    sed -i 's/env NGINX_OVERRIDE_PROTOCOL;/env NGINX_OVERRIDE_PROTOCOL;\n\n## Allow retrieval of a particular file version from the docstore.\nenv X_DOCSTORE_PROVIDER_UUID;/' /etc/nginx/nginx.conf

COPY --from=builder /srv/www/config /srv/www/config/
COPY --from=builder /srv/www/html /srv/www/html/
COPY --from=builder /srv/www/vendor /srv/www/vendor/
COPY --from=builder /srv/www/composer.json /srv/www/composer.json
COPY --from=builder /srv/www/composer.patches.json /srv/www/composer.patches.json
COPY --from=builder /srv/www/composer.lock /srv/www/composer.lock
COPY --from=builder /srv/www/symfony.lock /srv/www/symfony.lock
COPY --from=builder /srv/www/patches /srv/www/patches
COPY --from=builder /srv/www/scripts /srv/www/scripts
COPY --from=builder /srv/www/docker/etc/nginx/apps/drupal/fastcgi_drupal.conf /etc/nginx/apps/drupal/fastcgi_drupal.conf
COPY --from=builder /srv/www/docker/etc/nginx/apps/drupal/drupal.conf /etc/nginx/apps/drupal/drupal.conf
COPY --from=builder /srv/www/docker/etc/nginx/custom /etc/nginx/custom/
COPY --from=builder /srv/www/docker/etc/nginx/sites-enabled/01_uuid.conf /etc/nginx/sites-enabled/01_uuid.conf
