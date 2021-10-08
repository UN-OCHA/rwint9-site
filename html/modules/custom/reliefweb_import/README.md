# Reliefweb Import

## Import jobs

## Testing

```bash
# with coverage
fin exec 'XDEBUG_MODE=coverage vendor/bin/phpunit html/modules/custom/reliefweb_import/tests/src/Unit'

# without coverage
fin exec 'vendor/bin/phpunit html/modules/custom/reliefweb_import/tests/src/Unit'
```

or run all test in custom

```bash
# with coverage
fin exec 'XDEBUG_MODE=coverage vendor/bin/phpunit'

# without coverage
fin exec 'vendor/bin/phpunit'
```

