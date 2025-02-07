# Migrate insarag

https://insarag.org/

Needs both `media.xml` and `posts.xml` and `migrations/insarag/create_reliefweb_file.php`
in the same directory.

Execute `drush scr migrations/insarag/migrate.php`

Will detect linked files, but will ignore links to images.

## Mapping

Mary’s initial metadata mapping/suggestions (comment)

- title
- date of publication
- attachment URL

AND

- Primary Country - WORLD (254)
- Organization - INSARAG (would need to be added as an ORG)
- Theme - Coordination (4590) and Disaster Management (4591)
- Content Format - OTHER (9)
- Language - EN (en)
