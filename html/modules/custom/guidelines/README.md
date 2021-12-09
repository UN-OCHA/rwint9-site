# Guidelines

This module allows you to add extended help text to fields on edit forms.

- The guidelines are stored as entites, so they will not be reset when importing config.
- Guidelines can have a parent and can be ordered.
- Guidelines can either replace the default `'#description` of a field or can be loaded using json, see `/admin/structure/guideline_type/settings`
- Guidelines are fieldable, by default they have
  1. Guideline list can be used to group field guidelines
    - Title
    - Description
  2. Field guideline
    - Title
    - Description
    - Images
    - Links
- Output is controlled by manage display
