# Reliefweb Import

## Import jobs

## Testing

```bash
# with coverage
fin exec 'XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite Unit'
fin exec 'XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite Existing'

# without coverage
fin exec './vendor/bin/phpunit --testsuite Unit'
fin exec './vendor/bin/phpunit --testsuite Existing'
```

or run all test in custom

```bash
# with coverage
fin exec 'XDEBUG_MODE=coverage vendor/bin/phpunit'

# without coverage
fin exec 'vendor/bin/phpunit'
```

