local directory_mapping = {
  ['announcements'] = 'images/announcements',
  ['attached-images'] = 'images/blog-posts',
  ['blog-post-images'] = 'images/blog-posts',
  ['headline-images'] = 'images/reports',
  ['report-images'] = 'images/reports',
  ['topic-icons'] = 'images/topics',
}

local image_dir = ngx.var.image_dir
local image_file = ngx.var.image_file
local image_ext = image_file:match('[^.]+$')

-- Throw a 404 if the directory is not recognized.
if directory_mapping[image_dir] == nil then
  ngx.status = 404
  ngx.exit(404)
end

-- The generation of the UUID from the legacy URL is similar to what is done
-- in \Drupal\reliefeb_utility\Helpers\LegacyHelper::getImageUuid().

-- Consolidate the URL.
local legacy_url = 'https://reliefweb.int/sites/reliefweb.int/files/' .. image_dir .. '/' .. image_file

-- Generate the UUID corresponding to the URL.
local jit_uuid = require 'resty.jit-uuid'
local uuid = jit_uuid.generate_v3('6ba7b811-9dad-11d1-80b4-00c04fd430c8', legacy_url)

-- Redirect to the new URL.
return ngx.redirect('/sites/default/files/' .. directory_mapping[image_dir] .. '/' .. uuid:sub(1, 2) .. '/' .. uuid:sub(3, 4) .. '/' .. uuid .. '.' .. image_ext, 301)
