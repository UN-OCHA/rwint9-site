ReliefWeb - User History module
===============================

This module provides a simple mechanism to store some old data about an account when it is updated that can be displayed as history like the revision history for node an term entities.

This is a light solution to the lack of user revisions that helps the Editorial team keep track of changes are made on an account.

## Table

This module stores the data in a `reliefweb_user_history` table that is created when the module is installed.

## History

As opposed to entity revisions, only actual changes on a specific set of fields are stored.

## Dependency

This module depends on the `reliefweb_revisions` module to display the history on an account's edit form for people with the `view entity history` permission.

## User entity

To be able to use some of the functionalities of the `reliefweb_revisions` module, this module replaces the `User` class provided by the `user` core module with a custom class that implements the `EntityRevisionedInterface` provided by the `reliefweb_revisions` module.

**Note:** If ever another module needs to override the class, then, the [src/Entity/User.php](src/Entity/User.php) should be updated to extend the class override instead of the core `User` class.
