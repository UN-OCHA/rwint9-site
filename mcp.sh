##curl -X POST \
##  -d '{"jsonrpc":"2.0","id":4,"method":"resources/read","params":{"uri":"reliefweb-mcp-report://report/4057897"}}' \
##  https://rwint9-site.docksal.site/mcp/post


## curl -X POST \
##   -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' \
##   https://rwint9-site.docksal.site/mcp/post




curl -X POST \
  -d ' {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"reliefweb-mcp-report_b36facaa06426d894f29fdbdb85c4c22","arguments":{"iso3":"AFG"},"_meta":{"progressToken":"39e3ceb4-6522-4014-ba1b-ba7ed737f3d8"}}}' \
  https://rwint9-site.docksal.site/mcp/post

curl -X POST \
  -d ' {"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"general_caf9b6b99962bf5c2264824231d7a40c","arguments":{},"_meta":{"progressToken":"39e3ceb4-6522-4014-ba1b-ba7ed737f3d8"}}}' \
  https://rwint9-site.docksal.site/mcp/post


