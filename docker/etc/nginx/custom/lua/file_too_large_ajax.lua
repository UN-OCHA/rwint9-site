local cjson = require "cjson"

local message = '<div class="" data-drupal-messages=""><div class="messages__wrapper"> <div class="messages messages--error cd-alert cd-alert--error" role="alert" data-drupal-message-type="error"> <div role="alert" aria-label="error"> <svg class="cd-icon cd-icon--error" aria-hidden="true" focusable="false" width="24" height="24"> <use xlink:href="#cd-icon--error"></use> </svg> <div class="cd-alert__container cd-max-width"> <div class="cd-alert__message [ cd-flow ]"> File Too Large. The file exceeds the maximum size of 25 MB. </div> <div class="cd-alert__title"> <h2 class="visually-hidden">Error message</h2> </div>  </div> </div> </div>'

local response = {
  command = "insert",
  method = "append",
  data = message
}

ngx.header["Content-type"] = 'application/json'
ngx.say(cjson.encode(response))
