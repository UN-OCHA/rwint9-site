ReliefWeb - Users module
========================

This module provides customizations for user accounts.

## People page

This module overrides the `/admin/people` page with a dedicated [controller](src/Controller/UserController.php) that displays a custom list of users with filters.

## Admin and system users

This module disallows access to the admin and system user pages.

## Email confirmation

This module manages the confirmation of the user's email address. This is used notably to ensure email notifications are sent to valid email addresses.
