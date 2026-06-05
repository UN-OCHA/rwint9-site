ReliefWeb - Users module
========================

This module provides customizations for user accounts.

## People page

This module overrides the `/admin/people` page with a dedicated [controller](src/Controller/UserController.php) that displays a custom list of users with filters.

## Admin and system users

This module disallows access to the admin and system user pages.

## Email confirmation

This module manages the confirmation of the user's email address. This is used notably to ensure email notifications are sent to valid email addresses.

## Delete inactive user accounts

Drush command to delete user accounts with no ReliefWeb activity.

```sh
# Preview candidates (recommended first step)
drush reliefweb_users:delete-inactive --weeks=26 --limit=50 --dry-run

# Delete up to 50 accounts inactive for 26+ weeks
drush reliefweb_users:delete-inactive --weeks=26 --limit=50

# Process most recently active eligible accounts first
drush reliefweb_users:delete-inactive --weeks=26 --limit=50 --sort=newest --dry-run
```

**Eligibility criteria** (all must be true):

- uid > 2 (system users are never processed)
- Last activity older than the configured number of weeks — uses `access` when the user has visited the site (`access > 0`), otherwise uses account creation date (`created`) for never-accessed accounts
- Only the `authenticated` role (no custom roles)
- No nodes (authored/revised), subscriptions, bookmarks, posting-rights records, API keys, staff notes, files, media, or guidelines

Accounts are processed up to the `--limit` per run, ordered by effective activity (`--sort=oldest`, default, or `--sort=newest`). Use `--dry-run` to list candidates without deleting.
