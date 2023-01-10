Reliefweb - Bookmarks module
============================

This modules provides a simple content bookmarking of users.

Based on https://www.drupal.org/project/entity_wishlist.

## Administration

What content can be bookmarked is set up on the `/admin/structure/reliefweb-bookmarks` page.

## Bookmark link

A bookmark link is available to content templates (ex: `node.html.twig`) via the `content.reliefweb_bookmarks_link` variable if the content type was marked as bookmarkable via the admin page.

## Bookmarks page

This modules also provides a user page to see one's bookmarks: `/user/USER_ID/bookmarks`.
