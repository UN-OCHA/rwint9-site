local attachment_file = ngx.var.attachment_file

-- Try to get the attachment from a potential symlink.
-- This handles the redirection for some location maps so that they all have
-- the same pattern for example to compensate for some inconsistencies between
-- the URL, URI in DB and file on disk in D7.
-- This can also be used to handle aliases for some specific files.
local symlink = ngx.var.document_root .. '/sites/default/files/legacy-attachments/' .. attachment_file;
local handle = io.popen('readlink "' .. symlink .. '"')
local target = handle:read()
handle:close()

-- If there was no symlink then we generated the target based on the filename.
if target == nil or target == '' then
  -- The generation of the UUID from the legacy URL is similar to what is done
  -- in \Drupal\reliefeb_utility\Helpers\LegacyHelper::getAttachmentUuid().

  -- Strip the % characters.
  attachment_file = string.gsub(attachment_file, "%%", '')

  -- Consolidate the URL.
  local legacy_url = 'https://reliefweb.int/sites/reliefweb.int/files/resources/' .. attachment_file

  -- Generate the UUID corresponding to the URL.
  local jit_uuid = require 'resty.jit-uuid'
  local uuid = jit_uuid.generate_v3('6ba7b811-9dad-11d1-80b4-00c04fd430c8', legacy_url)

  target = uuid .. '/' .. attachment_file
else
  local uuid = string.gsub(target, '../attachments/[^/]+/[^/]+/([^.]+).+', '%1')
  target = uuid .. '/' .. attachment_file
end

-- Redirect to the new URL.
return ngx.redirect('/attachments/' .. target, 301)
