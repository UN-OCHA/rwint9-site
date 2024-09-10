const allowedPaths = {
  'api1': {
    allow: [
      '/',
      '/answer',
      '/search',
      '/jobs/answer',
      '/jobs/search',
      '/reports/answer',
      '/reports/search',
    ],
    deny: [
    ],
  },
  'api2': {
    allow: [
      '/jobs/answer',
      '/jobs/search',
    ],
    deny: [
      '/',
      '/answer',
      '/search',
      '/reports/answer',
      '/reports/search',
    ],
  },
  'api3': {
    allow: [
      '/jobs/answer',
      '/jobs/search',
    ],
    deny: [
      '/',
      '/answer',
      '/search',
      '/reports/answer',
      '/reports/search',
    ],
  }
};

export const handler = function(event, context, callback) {
    console.log('Received event:', JSON.stringify(event, null, 2));
    console.log('Context:', JSON.stringify(context, null, 2));

    // Retrieve request parameters from the Lambda function input:
    var headers = event.headers || {};
    var apikey = headers['x-api-key'];

    // Deny access for unknown keys.
    if (!allowedPaths[apikey]) {
        callback("Unauthorized");
    }

    // Rebuild arn prefix.
    let parts = event.methodArn.split(':');
    let apiGatewayArnTmp = parts[5].split('/');

    parts = parts.slice(0, 5);
    apiGatewayArnTmp = apiGatewayArnTmp.slice(0, 3);
    apiGatewayArnTmp = apiGatewayArnTmp.join('/');
    apiGatewayArnTmp = apiGatewayArnTmp.split('?')[0];

    let prefix = parts.join(':') + ':' + apiGatewayArnTmp;

    // Account.
    let accountId = event.requestContext.accountId;
    callback(null, generatePolicies(apikey, prefix, accountId));
}

// Help function to generate an IAM policy
var generatePolicies = function(apikey, prefix, accountId) {
    // Required output:
    var authResponse = {};
    authResponse.principalId = accountId;
    authResponse.usageIdentifierKey = apikey;

    var policyDocument = {};
    policyDocument.Version = '2012-10-17'; // default version
    policyDocument.Statement = [];

    // Allows.
    var statementAllow = {};
    statementAllow.Action = 'execute-api:Invoke'; // default action
    statementAllow.Effect = 'Allow';
    statementAllow.Resource = [];

    for (let pol in allowedPaths[apikey].allow) {
      statementAllow.Resource.push(prefix + allowedPaths[apikey].allow[pol]);
    }
    policyDocument.Statement.push(statementAllow);

    // Denies.
    var statementDeny = {};
    statementDeny.Action = 'execute-api:Invoke'; // default action
    statementDeny.Effect = 'Deny';
    statementDeny.Resource = [];

    for (let pol in allowedPaths[apikey].deny) {
      statementDeny.Resource.push(prefix + allowedPaths[apikey].deny[pol]);
    }
    policyDocument.Statement.push(statementDeny);

    authResponse.policyDocument = policyDocument;

    console.log(JSON.stringify(authResponse));
    return authResponse;
}
