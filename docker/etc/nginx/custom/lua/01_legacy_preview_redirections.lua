local image_file = ngx.var.image_file
local image_ext = 'png'

-- Try to get the preview from a potential symlink.
-- Some legacy preview file names cannot be derived from the PDF file name, in
-- that case there should be a symlink pointing to the new preview.
local symlink = ngx.var.document_root .. '/sites/default/files/legacy-previews/' .. ngx.var.file_id .. '.' .. image_ext;
local handle = io.popen('readlink "' .. symlink .. '"')
local target = handle:read()
handle:close()

-- If there was no symlink then we generated the target based on the filename.
if target == nil or target == '' then
  -- The generation of the UUID from the legacy URL is similar to what is done
  -- in \Drupal\reliefeb_utility\Helpers\LegacyHelper::getAttachmentUuid().

  -- Strip the % characters.
  image_file = string.gsub(image_file, "%%", '')

  -- Handle cases where the orginal file's extension is "PDF" instead of "pdf".
  -- When that's the case, the extension is preserved in the filename
  if string.sub(image_file, -4) ~= '.PDF' then
    image_file = image_file .. '.pdf'
  end

  -- Generate the legacy URL of the PDF file associated with the preview.
  local legacy_url = 'https://reliefweb.int/sites/reliefweb.int/files/resources/' .. image_file

  -- Generate the UUID corresponding to the URL of the original image.
  local jit_uuid = require 'resty.jit-uuid'
  local uuid = jit_uuid.generate_v3('6ba7b811-9dad-11d1-80b4-00c04fd430c8', legacy_url)

  target = uuid:sub(1, 2) .. '/' .. uuid:sub(3, 4) .. '/' .. uuid .. '.' .. image_ext
else
  target = string.gsub(target, '../previews/', '')
end

-- Redirect to the new URL.
return ngx.redirect('/sites/default/files/previews/' .. target, 301)
