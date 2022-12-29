Reliefweb - Subscriptions module
================================

This module allows users to subscribe to various lists and send out emails.

## Subscriptions page

This module provides a route and [controller](src/Controller/SubscriptionsController.php) for the `user/USER_ID/notifications` page that allow users to manage their subscriptions.

### Unsubscribe

It also provides controllers to a page to unsubscribe. A link to this page is added at the bottom of the notification emails.

## Templates

This module provides [templates](templates) for the different type of subscriptions (ex: jobs, disasters).

## Scheduled or Triggered

There are 2 types of subscriptions:

- `Scheduled` notifications are queued to be sent at a predefined interval set up via a cron-like syntax in [reliefweb_subscriptions.module](reliefweb_subscriptions.module)
- `Triggered` notifications are queued to be sent when an entity meeting the triggering criteria is saved.

### Queue

Notifications are not sent direcly, they are added to a queue and a job checks the queue every now and then.

## Drush commands

Sending (and even queueing) notifications is done via [drush commands](src/Command/ReliefwebSubscriptionsSendCommand.php).

- `reliefweb_subscriptions:queue` is used to queue notifications (mostly for testing)
- `reliefweb_subscriptions:send` is used to send the queued notifications and is called regularly via a job

### Sending

Sending notification can be pretty slow depending on the content of the emails (ex: hundred of jobs) and the number of subscribers.

## Testing

Log in, and go to the subscriptions page `/user/USER_ID/subscriptions`, then (replace `XXX` by a disaster ID):

```bash
drush cr
drush sqlq "truncate table reliefweb_subscriptions_logs"

drush reliefweb_subscriptions:queue headlines --verbose
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue appeals --verbose
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue jobs --verbose
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue training --verbose
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue disaster --verbose --entity_id=XXX
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue ocha_sitrep --verbose
drush reliefweb_subscriptions:send --verbose

drush reliefweb_subscriptions:queue country_updates_13 --verbose
drush reliefweb_subscriptions:send --verbose
```
