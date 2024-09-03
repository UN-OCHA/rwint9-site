# Lambda function for KB

## Run

```bash
nodejs --env-file=.env index.js
```

## API mapping template

```vtl
#set($allParams = $input.params())
#set($params = $allParams.get('querystring'))
{
  #foreach($paramName in $params.keySet())
  #if($paramName != "text")
  "$paramName": "$util.escapeJavaScript($params.get($paramName))",
  #end
  #end
  "text": "$util.escapeJavaScript($params.get('text'))",
  "kb": "VIEPSPYNSS",
  "api-id": "$context.apiId",
  "api-key": "$context.identity.apiKey",
  "source-ip": "$context.identity.sourceIp",
  "user": "$context.identity.user",
  "user-arn": "$context.identity.userArn",
  "request-id": "$context.requestId",
  "resource-id": "$context.resourceId",
  "resource-path": "$context.resourcePath"
}
```

## Query parameters

- text: Question
- country: List of comma separated country ids
- disaster: List of comma separated disaster ids
- disaster_type: List of comma separated disaster_type ids
- feature: List of comma separated feature ids
- primary_country: List of comma separated primary_country ids
- source: List of comma separated source ids
- theme: List of comma separated theme ids

