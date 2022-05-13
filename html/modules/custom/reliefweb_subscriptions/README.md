# Reliefweb subscription

Allow users to subscribe to various lists and send out emails.

## Testing

Subscribe on `/user/1/subscriptions`

```bash
fin drush cr
fin drush sqlq "truncate table reliefweb_subscriptions_logs"

fin drush reliefweb_subscriptions:queue headlines --verbose
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue appeals --verbose
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue jobs --verbose
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue training --verbose
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue disaster --verbose --entity_id=42169
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue ocha_sitrep --verbose
fin drush reliefweb_subscriptions:send --verbose

fin drush reliefweb_subscriptions:queue country_updates_13 --verbose
fin drush reliefweb_subscriptions:send --verbose
```
