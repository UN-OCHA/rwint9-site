ReliefWeb - Migrate module
==========================

This module handles the migration from Drupal 7 to Drupal 9.

Migrated content
----------------

In migration order:

1. Menu links
2. Users
3. Images
4. Media
5. Terms
6. Nodes
7. URL aliasses

Todo
----

Use `migration_lookup` plugin to ensure entity reference fields only reference
existing content?

Parse `blog post` and `book` body field to replace harcoded URL to files as the
image styles will probably change. Alternatively we could do some URL rewriting
in nginx which may be easier.
