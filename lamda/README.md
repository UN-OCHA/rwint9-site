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

## API

### Working

```bash
curl -H "X-API-KEY: api1" \
  "https://y0crb0xdcj.execute-api.us-east-1.amazonaws.com/rw-api-test-1/search?text=Give%20me%20the%205%20most%20relevant%20doucments%20about%20famine%20in%20Kenya"

curl -H "X-API-KEY: api1" \
  "https://y0crb0xdcj.execute-api.us-east-1.amazonaws.com/rw-api-test-1/jobs/answer?text=Any%20job%20offers%20in%20Kenya"

curl -H "X-API-KEY: api2" \
  "https://y0crb0xdcj.execute-api.us-east-1.amazonaws.com/rw-api-test-1/jobs/answer?text=Any%20job%20offers%20in%20Kenya"

curl -H "X-API-KEY: api2" \
  "https://y0crb0xdcj.execute-api.us-east-1.amazonaws.com/rw-api-test-1/jobs/search?text=IT%20Assistant%20in%20North%20Africa" | jq [].metadata
```

### Should fail

```bash
curl -H "X-API-KEY: api2" \
  "https://y0crb0xdcj.execute-api.us-east-1.amazonaws.com/rw-api-test-1/jobs/answer?text=Any%20job%20offers%20in%20Kenya"
```
