ReliefWeb - User posts module
=============================

This module provides the **My posts** pages where submitters, advertisers, and
contributors can review content they posted and, when permitted, affiliated
content posted by colleagues.

Routes
------

- `/user/{user}/posts` — overview with per-type stats (mine, colleagues when
  applicable, linked organizations)
- `/user/{user}/posts/{bundle}` — filtered list for `report`, `job`, or
  `training`
- `/user/posts` and `/user/posts/{bundle}` — shortcuts that redirect to the
  current user's pages

Tabs (Overview plus the available types) appear whenever the profile user has
at least one content type. Availability is based on create permission,
affiliated view/edit permission, or having authored content of that type. The
create sentence sits above the tabs; overview cards navigate to each type list.

Posted by
---------

The list can be filtered by **Me** and **Colleagues**. Colleague posts are
limited to sources the user is allowed or trusted for, and only for content
types where they have affiliated view or edit permission. Submitters therefore
see colleagues' reports, not jobs or training.

Source filter
-------------

“My organization(s)” uses a searchable autocomplete dropdown (same pattern as
the report form), limited to organizations the user can post for or has already
posted under. When there are none, the filter still appears with “No associated
organizations”.

Other filters
-------------

Title, Id, Post date, and Deadline (jobs/training) use dedicated fields — not
the omnibox. Title and Id share one row. Dates use from/to inputs with the
shared datepicker widget.
