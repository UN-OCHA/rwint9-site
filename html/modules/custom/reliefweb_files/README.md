ReliefWeb Files
===============

This module handles report file attachments on ReliefWeb.

It provides a file type that can store files locally or remotely in the OCHA Document Store (docstore).

## Concepts

The docstore integration only concerns the attachments.

All the report files are stored either locally or in the docstore but the preview of eligible files (i.e. PDF files) are always stored locally on the RW site.

This is because the page and rotation to use for the preview are specific to RW (another site may want to show something different) and there is currently no endpoint to generate previews in the docstore.

When using the remote mode, in addition to the `file` resources, a `reliefweb_document` document resource is created for reports that have attachments to keep track of the file usages.

### File field.

The `reliefweb_files` module adds a new field type: `reliefweb_file` that handles the the local or remote storage.

This field type works with the revisions and allow the editorial worflow of replacing files without changing the file permanent URL.

Each reliefweb_file entry contains:

- `uuid`: the UUID of the file resource in the docstore (used for the permanent URL)
- `revision_id`: the entity file ID in local mode or the revision id of the file resource in the docstore in remote mode
- `file_uuid`: UUID of a file entity in the RW site used to keep track of the URL of the revision, its status (private/public) and to be able to regenerate the file preview. Note: there is no file on disk matching the file URI.
- `file_name`: file name
- `file_mime`: file mime type
- `file_size`: file size in bytes
- `description`: file description
- `language`: language of the content
- `page_count`: the number of pages in the document (only calculated for PDF files)
- `preview_uuid`: UUID of a file entity on the RW site used for the preview. As opposed to the file entity mentioned above, there is a file on disk for the latest published revision of the report (the preview file is deleted for the old revisions as is recreated when reverting)
- `preview_page`: the page used for the preview
- `preview_rotation`:  the rotation to use for the preview

#### File replacement:

The `file_uuid` changes when replacing a file. Ex:

- revision1:
  - uuid1: uuid of the file resource
  - revision_id1: id of the file resource
  - file_uuid1: uuid of the file entity in RW
- revision2 (file is replaced):
  - uuid1: same as revision1 because it's the same file resource
  - revision_id2: id of the new revision in the docstore
  - file_uuid2: uuid of the new file entity in RW corresponding to the new file resource revision.

#### Preview update

The `preview_uuid` changes when changing the preview page and rotation. This prevents overriding the preview accessible to the users when working on the form. Ex:

- revision1:
  - uuid1: uuid of the file resource in docstore
  - revision_id1: id of file resource revision in docstore
  - file_uuid1: uuid of the file entity in RW
  - preview_uuid1: uuid of the preview file entity in RW
  - preview_page: 1
  - preview_rotation: 0
- revision2 (preview page change):
  - uuid1: same as revision1 because it's the same file resource
  - revision_id1: same as revision1
  - file_uuid1: same as revision1
  - preview_uuid2: uuid of the new preview file entity in RW
  - preview_page: 2
  - preview_rotation: 0

For files that cannot have previews, the `preview_*` fields are empty.

### URIs

The URI in the file entities determine the access to the files.

For the latest revision of a **published** file, the URI of the file entity on RW is in the form `public://attachments/ab/cd/{uuid}.{ext}` where `{uuid}` is the UUID of the file resource in the docstore (permanent UUID).

This URI is used to generate the permanent URL of the file on RW: `https//reliefweb.int/attachments/{uuid}/{file_name}` which is rewritten to get the file from the docstore `http://docstore.internal/files/{uuid}/{file_name}` in remote mode.

**Note**: if the report, the file is attached to, is not published, then the file URI is `private://attachments/ab/cd/{uuid}.{ext}` which translates to `https//reliefweb.int/private/attachments/{uuid}/{file_name}`. This URL is only accessible to users with the `access reliefweb private files` permission (i.e. Editors).

For the other revisions, the URI of the file entities is `private://attachments/ab/cd/{file_uuid}.{ext}` where `{file_uuid}` is the UUID of the file entity.

This is similar for the previews, except there is currently no particular URL pattern for those, notably because the previews are basically only shown as derivative images in the form `https//reliefweb.int/sites/default/styles/small/{scheme}/previews/ab/cd/{uuid}.png` where `{uuid}`  is the UUID of the file resource (permanent UUID) and `{scheme}` is either "public" or "private" depending on the status of the report.

### Public/private

The field type manages the access to the files based on the status of the report they are attached to.

If the report is published, then the files attached to the published revision are publicly accessible ortherwise they are only accessible to users with the `access reliefweb private files` permission. This allows the editors to view the nodes with their attachments as they would be shown to anonymous users once published.

### Document resource

In remote mode a `reliefweb_document` resource is created in the docstore for reports with attachments. This is a revision less document resource that only contains references to all the files referenced by the report entity and its revisions on RW.

Their purpose is to keep track of the file usage in the docstore so that another provider cannot delete files used on RW.

## Storage mode

The `reliefweb_file` module has a `reliefweb_files.settings.local` setting to store the files locally when `true` or remotely in the docstore when `false`.

When set to TRUE, no calls to the docstore are done and the files on disk are not removed. The rest (URIs, private/public etc.) is handled the same way.
