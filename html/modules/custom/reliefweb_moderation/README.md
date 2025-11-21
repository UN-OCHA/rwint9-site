ReliefWeb - Moderation module
=============================

This module provides the editorial backend pages to moderate content as well as the logic to alter entity access based on the entity status.

## Services

This module provides moderation [services](src/Services) that handle access to moderated entities and provide the content of the moderation pages (ex: `/moderation/content/report`).

## Moderated entities

This module adds an `moderation_status` base field to `node` and `taxonomy_term` entities to allow a more fine grained management of the publication status of entities (ex: `draft`, `published`, `expired`).

To work with that, the module provides an [interface](src/EntityModeratedInterface.php) and a [trait](src/EntityModeratedTrait.php) to make an entity "moderated" (i.e. entity that can have different statuses).

This interface is used by the [reliefweb_entities](../reliefweb_entities) module for the content entities.

## Moderation pages

This module provides a route and [controller](src/Controller/ModerationPage.php) to moderation pages for all the main content entities (ex: `/moderation/content/job`)

Those pages contain a paginated and sortable list of entities and relevant filters to help with the ReliefWeb editorial workflow.

## Report access

This section documents the access control for report entities based on user roles and moderation statuses.

### Access Operations

The following operations are available for report entities:
- **view**: View the report content
- **create**: Create new reports
- **update**: Edit existing reports
- **delete**: Delete reports
- **view_moderation_information**: View moderation status and history

### Moderation Statuses

Reports can have the following moderation statuses:

**Unpublished statuses:**
- **draft**: Unpublished, work in progress
- **on-hold**: Unpublished, requires modifications/verifications/instructions
- **embargoed**: Unpublished, scheduled for future publication
- **reference**: Unpublished, kept as editorial reference
- **pending**: Unpublished, awaiting processing
- **refused**: Unpublished, rejected content
- **archive**: Unpublished, archived content

**Published statuses:**
- **to-review**: Published but editorial review requested
- **published**: Published and publicly available

### Ownership

A user is considered the owner of a report if they are the author of the document or if they have posting rights for at least one of the sources associated with the report.

### Anonymous User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| to-review | ✅ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| embargoed | ❌ | ❌ | ❌ | ❌ | ❌ |
| reference | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| archive | ❌ | ❌ | ❌ | ❌ | ❌ |

### Authenticated User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| to-review | ✅ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| embargoed | ❌ | ❌ | ❌ | ❌ | ❌ |
| reference | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| archive | ❌ | ❌ | ❌ | ❌ | ❌ |

### Submitter Access

Submitters can only access their own reports and require posting rights for creation.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅* | ✅ | ❌ | ❌ |
| on-hold | ✅ | ✅* | ✅ | ❌ | ❌ |
| to-review | ✅ | ✅* | ✅ | ❌ | ❌ |
| published | ✅ | ✅* | ✅ | ❌ | ❌ |
| embargoed | ✅ | ✅* | ✅ | ❌ | ❌ |
| reference | ✅ | ✅* | ✅ | ❌ | ❌ |
| pending | ✅ | ✅* | ✅ | ❌ | ❌ |
| refused | ✅ | ✅* | ✅ | ❌ | ❌ |
| archive | ✅ | ✅* | ❌ | ❌ | ❌ |

**Access Restrictions:**
- **View/Update**: Limited to reports owned by the submitter only for unpublished content
- **Create**: Allowed unless a flag is set in which case allowed/trusted posting rights for a source are required
- **Delete**: Not permitted for any reports
- **View Moderation Info**: Not permitted

### Contributor Access

Contributors have broader access to reports and can view moderation information.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ❌ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ❌ | ✅ |
| to-review | ✅ | ✅ | ✅ | ❌ | ✅ |
| published | ✅ | ✅ | ✅ | ❌ | ✅ |
| embargoed | ✅ | ✅ | ✅ | ❌ | ✅ |
| reference | ✅ | ✅ | ✅ | ❌ | ✅ |
| pending | ✅ | ✅ | ✅ | ❌ | ✅ |
| refused | ✅ | ✅ | ❌ | ❌ | ✅ |
| archive | ✅ | ✅ | ❌ | ❌ | ✅ |

### Editor Access

Editors have full access to all reports and can edit refused reports.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| to-review | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| embargoed | ✅ | ✅ | ✅ | ✅ | ✅ |
| reference | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| archive | ✅ | ✅ | ❌ | ✅ | ✅ |

### Administrator/Webmaster Access

Administrators and webmasters have full access including the ability to edit archived reports.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| to-review | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| embargoed | ✅ | ✅ | ✅ | ✅ | ✅ |
| reference | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| archive | ✅ | ✅ | ✅ | ✅ | ✅ |

## Job access

This section documents the access control for job entities based on user roles and moderation statuses.

### Access Operations

The following operations are available for job entities:
- **view**: View the job content
- **create**: Create new jobs
- **update**: Edit existing jobs
- **delete**: Delete jobs
- **view_moderation_information**: View moderation status and history

### Moderation Statuses

Jobs can have the following moderation statuses:

**Unpublished statuses:**
- **draft**: Unpublished, work in progress
- **pending**: Unpublished, awaiting processing
- **on-hold**: Unpublished, requires modifications/verifications/instructions
- **refused**: Unpublished, rejected content
- **duplicate**: Unpublished, duplicate content
- **expired**: Unpublished, previously published but now expired

**Published statuses:**
- **published**: Published and publicly available

### Ownership

A user is considered the owner of a job if they are the author of the document or if they have posting rights for at least one of the sources associated with the job.

### Anonymous User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| duplicate | ❌ | ❌ | ❌ | ❌ | ❌ |
| expired | ❌ | ❌ | ❌ | ❌ | ❌ |

### Authenticated User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| duplicate | ❌ | ❌ | ❌ | ❌ | ❌ |
| expired | ❌ | ❌ | ❌ | ❌ | ❌ |

### Advertiser Access

Advertisers can create and manage their own jobs with posting rights.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅* | ✅ | ✅* | ✅* | ✅* |
| pending | ✅* | ✅ | ✅* | ✅* | ✅* |
| published | ✅ | ✅ | ✅* | ❌ | ✅* |
| on-hold | ✅* | ✅ | ✅* | ✅* | ✅* |
| refused | ✅* | ✅ | ✅* | ❌ | ✅* |
| duplicate | ✅* | ✅ | ✅* | ❌ | ✅* |
| expired | ✅* | ✅ | ✅* | ❌ | ✅* |

**Access Restrictions:**
- **View**: Published jobs are publicly viewable; unpublished jobs only if owned by advertiser with posting rights
- **Create**: Allowed for advertisers
- **Update**: Limited to jobs owned by advertiser with posting rights
- **Delete**: Allowed for draft, pending, and on-hold jobs only if owned by advertiser with posting rights
- **View Moderation Info**: Limited to jobs owned by advertiser with posting rights

### Editor Access

Editors have full access to all jobs and can edit refused jobs.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| duplicate | ✅ | ✅ | ✅ | ✅ | ✅ |
| expired | ✅ | ✅ | ✅ | ✅ | ✅ |

### Administrator/Webmaster Access

Administrators and webmasters have full access including the ability to edit all jobs.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| duplicate | ✅ | ✅ | ✅ | ✅ | ✅ |
| expired | ✅ | ✅ | ✅ | ✅ | ✅ |

## Training access

This section documents the access control for training entities based on user roles and moderation statuses.

### Access Operations

The following operations are available for training entities:
- **view**: View the training content
- **create**: Create new training
- **update**: Edit existing training
- **delete**: Delete training
- **view_moderation_information**: View moderation status and history

### Moderation Statuses

Training can have the following moderation statuses:

**Unpublished statuses:**
- **draft**: Unpublished, work in progress
- **pending**: Unpublished, awaiting processing
- **on-hold**: Unpublished, requires modifications/verifications/instructions
- **refused**: Unpublished, rejected content
- **duplicate**: Unpublished, duplicate content
- **expired**: Unpublished, previously published but now expired

**Published statuses:**
- **published**: Published and publicly available

### Ownership

A user is considered the owner of a training if they are the author of the document or if they have posting rights for at least one of the sources associated with the training.

### Anonymous User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| duplicate | ❌ | ❌ | ❌ | ❌ | ❌ |
| expired | ❌ | ❌ | ❌ | ❌ | ❌ |

### Authenticated User Access

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ❌ | ❌ | ❌ | ❌ | ❌ |
| pending | ❌ | ❌ | ❌ | ❌ | ❌ |
| published | ✅ | ❌ | ❌ | ❌ | ❌ |
| on-hold | ❌ | ❌ | ❌ | ❌ | ❌ |
| refused | ❌ | ❌ | ❌ | ❌ | ❌ |
| duplicate | ❌ | ❌ | ❌ | ❌ | ❌ |
| expired | ❌ | ❌ | ❌ | ❌ | ❌ |

### Advertiser Access

Advertisers can create and manage their own training with posting rights.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅* | ✅ | ✅* | ✅* | ✅* |
| pending | ✅* | ✅ | ✅* | ✅* | ✅* |
| published | ✅ | ✅ | ✅* | ❌ | ✅* |
| on-hold | ✅* | ✅ | ✅* | ✅* | ✅* |
| refused | ✅* | ✅ | ✅* | ❌ | ✅* |
| duplicate | ✅* | ✅ | ✅* | ❌ | ✅* |
| expired | ✅* | ✅ | ✅* | ❌ | ✅* |

**Access Restrictions:**
- **View**: Published training is publicly viewable; unpublished training only if owned by advertiser with posting rights
- **Create**: Allowed for advertisers
- **Update**: Limited to training owned by advertiser with posting rights
- **Delete**: Allowed for draft, pending, and on-hold training only if owned by advertiser with posting rights
- **View Moderation Info**: Limited to training owned by advertiser with posting rights

### Editor Access

Editors have full access to all training and can edit refused training.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| duplicate | ✅ | ✅ | ✅ | ✅ | ✅ |
| expired | ✅ | ✅ | ✅ | ✅ | ✅ |

### Administrator/Webmaster Access

Administrators and webmasters have full access including the ability to edit all training.

| Status | View | Create | Update | Delete | View Moderation Info |
|--------|------|--------|--------|--------|---------------------|
| draft | ✅ | ✅ | ✅ | ✅ | ✅ |
| pending | ✅ | ✅ | ✅ | ✅ | ✅ |
| published | ✅ | ✅ | ✅ | ✅ | ✅ |
| on-hold | ✅ | ✅ | ✅ | ✅ | ✅ |
| refused | ✅ | ✅ | ✅ | ✅ | ✅ |
| duplicate | ✅ | ✅ | ✅ | ✅ | ✅ |
| expired | ✅ | ✅ | ✅ | ✅ | ✅ |

## Inactive sources

This module provides a [drush command](src/Commands/ReliefWebModerationCommands.php) that can be run to update the status of sources for which that has been no recently posted content.
