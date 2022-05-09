local attachment_file = ngx.var.attachment_file

-- The generation of the UUID from the legacy URL is similar to what is done
-- in \Drupal\reliefeb_utility\Helpers\LegacyHelper::getAttachmentUuid().

-- Strip the % characters.
attachment_file = string.gsub(attachment_file, "%%", '')

-- Consolidate the URL.
local legacy_url = 'https://reliefweb.int/sites/reliefweb.int/files/resources/' .. attachment_file

-- Generate the UUID corresponding to the URL.
local jit_uuid = require 'resty.jit-uuid'
local uuid = jit_uuid.generate_v3('6ba7b811-9dad-11d1-80b4-00c04fd430c8', legacy_url)

-- Redirect to the new URL.
return ngx.redirect('/attachments/' .. uuid .. '/' .. attachment_file, 301)
