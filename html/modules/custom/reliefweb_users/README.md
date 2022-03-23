ReliefWeb - Users module
========================

This module provides customizations for user accounts.

TODO
----

If it is decided to remove the `field_email` in favor of the `mail` property,
the following code needs to be updated:

- **reliefweb_users**: entity hooks, email confirmation controller etc.
- **reliefweb_contact**: `hook_mail_alter()`
- **reliefweb_subscriptions**: `ReliefwebSubscriptionsMailer::getSubscribers()`
- **reliefweb_entities**: `EntityFormAlterServiceBase::addUserInformation()`
- **reliefweb_fields**: ReliefWebUserPostingRights
- **reliefweb_moderation**: `ModerationServiceBase::getUserAutocompleteSuggestions()`
