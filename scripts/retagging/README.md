# Retagging content

To retag content, for example to change an organization or add a disaster, simple retagging scripts can executed via the `php-script` job.

The steps are:

1. Evaluate what content should be retagged based on the request, using the moderation backend, API or database queries.
2. Write a SQL query (or several if needed) to get the node or term IDs matching the above criteria.
3. Write a script (see examples in this directory)
4. Make a dump of your local database.
5. Execute the script locally via `drush php-eval` and confirm that the relevant content was properly retagged.
6. Adjust, repeat etc. until satisfied.
7. Run the script on one of the dev sites (after making a dump of its DB), confirm.
8. Run the script on one of the prod site (after making a dump of its DB), confirm.

**Notes:**

Make sure the retagging loop in the script creates a new revision with a log message containing the ID of the ticket so we can easily revert.

The part of the script to run via the `php-script` job, should follow those rules:

- No opening `<?php` tag
- No single quotes
- No comments

**Tips:**

- If your local DB, doesn't contain any of the nodes to retag, generate a new "light dump", specifying some of the node IDs to include.
- Exit the retagging loop after one node/term is processed and check the retagging went well before running the script for all the entities.
