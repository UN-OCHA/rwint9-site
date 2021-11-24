# Guidelines

## Todo

- [ ] Add moderation
- [x] Add tab for child pages
- [x] Add drag and drop, https://www.drupal.org/project/drupal/issues/2989889
- [x] Extract images from description
- [x] Add attachment links
- [ ] Parse and rewrite trello links like `https://trello.com/c/XlpG8tHh/17-country-coverage`
      Can only be done after importing all lists and cards.

## Fields left to map

- [ ] attachments
- [ ] original_published_date
- [ ] disasters
- [ ] new_comment
- [ ] information
- [x] job_location
- [x] organization
- [ ] new_comment
- [ ] information
- [ ] dates
- [ ] event_url
- [x] organization
- [ ] advertisement_language
- [ ] course_event_language
- [ ] training_description
- [ ] attachments
- [ ] new_comment
- [ ] training_categories
- [ ] category
- [ ] professional_function

## Testing snippets

### Rest and import

```bash
drush eval "_reliefweb_guidelines_delete_all()" && drush cim -y && drush eval "reliefweb_guidelines_migrate()"
```

### One list only

```php
  $lists = [
    [
      'name' => 'test',
      'id' => '5c740dfe91f2b20a384d3c2c',
    ]
  ];
```
