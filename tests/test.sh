#!/usr/bin/env bash

# Build local image.
echo "Build local image."
make

# Create the site, memcache and mysql containers.
echo "Create the site, memcache and mysql containers."
docker-compose -p rwint9-test -f tests/docker-compose.yml up -d

# Dump some information about the created containers.
echo "Dump some information about the created containers."
docker ps -a

# Wait a bit for memcache and mysql to be ready.
echo "Wait a bit for memcache and mysql to be ready."
sleep 10

# Install the dev dependencies.
echo "docker exec -it -w /srv/www rwint9-test-site composer install"
docker exec -it -w /srv/www rwint9-test-site composer install

# Check coding standards.
echo "Check coding standards."
docker exec -it -u appuser -w /srv/www rwint9-test-site ./vendor/bin/phpcs -p --report=full ./html/modules/custom ./html/themes/custom

# Run unit tests.
echo "Run unit tests."
docker exec -it -u root -w /srv/www rwint9-test-site mkdir -p /srv/www/html/sites/default/files/browser_output
docker exec -it -u root -w /srv/www -e BROWSERTEST_OUTPUT_DIRECTORY=/srv/www/html/sites/default/files/browser_output rwint9-test-site php -d zend_extension=xdebug ./vendor/bin/phpunit --testsuite Unit --debug

# Install the site with the existing config.
echo "Install the site with the existing config."
docker exec -it rwint9-test-site drush -y si --existing-config
docker exec -it rwint9-test-site drush -y en dblog

# Ensure the file directories are writable.
echo "Ensure the file directories are writable."
docker exec -it rwint9-test-site chmod -R 777 /srv/www/html/sites/default/files /srv/www/html/sites/default/private

# Create the build logs directory and make sure it's writable.
echo "Create the build logs directory and make sure it's writable."
docker exec -it -u root rwint9-test-site mkdir -p /srv/www/html/build/logs
docker exec -it -u root rwint9-test-site chmod -R 777 /srv/www/html/build/logs

# Run all tests and generate coverage report.
echo "Run all tests and generate coverage report."
docker exec -it -u root -w /srv/www -e XDEBUG_MODE=coverage -e BROWSERTEST_OUTPUT_DIRECTORY=/srv/www/html/sites/default/files/browser_output -e DTT_BASE_URL=http://127.0.0.1 rwint9-test-site php -d zend_extension=xdebug ./vendor/bin/phpunit --coverage-clover /srv/www/html/build/logs/clover.xml --debug
