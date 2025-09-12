# Copilot Instructions for rwint9-site

## Project Overview
- This is the Drupal 10 codebase for [ReliefWeb](https://reliefweb.int), a major humanitarian information portal.
- The codebase is highly customized, with many custom modules and a custom subtheme based on OCHA Common Design.

## Key Architecture & Components
- **Custom modules** live in `html/modules/custom/`. Notable modules:
  - `reliefweb_api`: Integrates with the ReliefWeb API for content lists.
  - `reliefweb_entities`, `reliefweb_fields`, `reliefweb_files`, `reliefweb_form`, `reliefweb_moderation`, `reliefweb_revisions`, `reliefweb_rivers`, `reliefweb_utility`: Handle entity customization, fields, files, forms, moderation, revision history, content lists, and utility helpers.
- **Theme**: `html/themes/custom/common_design_subtheme` extends OCHA Common Design. Custom components and templates are in `components/` and `templates/` subfolders.
- **Docker**: The `docker/` folder contains Dockerfile and build customizations.

## Developer Workflows
- **Local development**: See `local/README.md` for stack setup. Docksal is supported.
- **Testing**: Use PHPUnit. Example commands:
  - `XDEBUG_MODE=coverage ./vendor/bin/phpunit --testsuite Unit`
  - `./vendor/bin/phpunit --testsuite Unit`
  - Run all custom tests: `vendor/bin/phpunit`
- **Drush**: Common Drush commands for content and settings management are listed in the main `README.md`.

## Project Conventions & Patterns
- **Custom module structure**: Each module in `html/modules/custom/` is self-contained, often with its own services, plugins, and tests.
- **Service classes**: Service logic (e.g., `InoreaderService`) is in `src/Service/` within each module. Unit tests are in `tests/src/Unit/Service/`.
- **Testing**: Use PHPUnit with data providers for methodical coverage. Reflection is used to test protected methods.
- **Settings**: Many modules/settings are managed via Drush `cget`/`cset` commands.
- **Theme customizations**: Use the subtheme for both frontend and admin UI. SVG icons and components are managed in the subtheme's `components/` directory.

## Integration Points
- **ReliefWeb API**: Consumed via custom modules and services.
- **OCHA Common Design**: The base for the site's look and feel.
- **External scripts**: See `scripts/` for retagging and other batch operations.

## Examples
- To add a new content type or field, see the relevant custom module in `html/modules/custom/`.
- To add a new theme component, place it in `html/themes/custom/common_design_subtheme/components/` and register it in the subtheme's `.libraries.yml`.
- To test a service method, use PHPUnit with a data provider and reflection if needed (see `InoreaderServiceTest`).

## References
- Main documentation: `README.md`
- Local stack: `local/README.md`
- Theme: `html/themes/custom/common_design_subtheme/README.md`

---

If you are unsure about a workflow or convention, check the main `README.md` or look for examples in the custom modules and theme directories.
