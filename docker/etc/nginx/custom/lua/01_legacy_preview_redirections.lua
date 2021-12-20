local image_file = ngx.var.image_file
local image_ext = 'png'

-- The generation of the UUID from the legacy URL is similar to what is done
-- in \Drupal\reliefeb_utility\Helpers\LegacyHelper::getAttachmentUuid().

-- Strip the % characters.
image_file = string.gsub(image_file, "%%", "")

-- Handle cases where the orginal file's extension is "PDF" instead of "pdf".
-- When that's the case, the extension is preserved in the filename
if string.sub(image_file, -4) ~= ".PDF" then
  image_file = image_file .. '.pdf'
end

-- Generate the legacy URL of the PDF file associated with the preview.
local legacy_url = 'https://reliefweb.int/sites/reliefweb.int/files/resources/' .. image_file

-- Generate the UUID corresponding to the URL of the original image.
local jit_uuid = require 'resty.jit-uuid'
local uuid = jit_uuid.generate_v3('6ba7b811-9dad-11d1-80b4-00c04fd430c8', legacy_url)

-- Redirect to the new URL.
return ngx.redirect('/sites/default/files/previews/' .. uuid:sub(1, 2) .. '/' .. uuid:sub(3, 4) .. '/' .. uuid .. '.' .. image_ext, 301)
