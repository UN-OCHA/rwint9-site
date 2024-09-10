import {
  BedrockAgentRuntimeClient,
  RetrieveCommand,
} from "@aws-sdk/client-bedrock-agent-runtime";


export const handler = async (event) => {
  let kb = 'VIEPSPYNSS';
  let siteid = event.siteid || '';
  let bundle = event.bundle || '';
  let text = event.text || '';
  const client = new BedrockAgentRuntimeClient({region: 'us-east-1'});

  // Make sure there are at least 2 filters.
  const filters = [
    {
      equals: {
        key: "status",
        value: "1"
      },
    },
    {
      notEquals: {
        key: "status",
        value: "0"
      },
    }
  ];

  // Limit to source site, ex. ReliefWeb.
  if (siteid != '') {
    filters.push({
      equals: {
        key: 'site',
        value: siteid
      }
    });
  }

  // Limit to bundle, ex. report.
  if (bundle != '') {
    filters.push({
      equals: {
        key: 'bundle',
        value: bundle
      }
    });
  }

  // Add user filters.
  if (event.city) {
    filters.push({
      equals: {
        key: 'city',
        value: event.city
      }
    });
  }

  if (event.country) {
    filters.push({
      in: {
        key: 'country',
        value: event.country.split(',')
      }
    });
  }

  if (event.disaster) {
    filters.push({
      in: {
        key: 'disaster',
        value: event.disaster.split(',')
      }
    });
  }

  if (event.disaster_type) {
    filters.push({
      in: {
        key: 'disaster_type',
        value: event.disaster_type.split(',')
      }
    });
  }

  if (event.feature) {
    filters.push({
      in: {
        key: 'feature',
        value: event.feature.split(',')
      }
    });
  }

  if (event.primary_country) {
    filters.push({
      in: {
        key: 'primary_country',
        value: event.primary_country.split(',')
      }
    });
  }

  if (event.source) {
    filters.push({
      in: {
        key: 'source',
        value: event.source.split(',')
      }
    });
  }

  if (event.theme) {
    filters.push({
      in: {
        key: 'theme',
        value: event.theme.split(',')
      }
    });
  }

  const input = {
    knowledgeBaseId: kb,
    retrievalQuery: {
      text: text,
    },
    retrievalConfiguration: {
      vectorSearchConfiguration: {
        numberOfResults: 10,
        overrideSearchType: "HYBRID",
        filter: {
          andAll: filters
        }
      }
    },
  };

  const command = new RetrieveCommand(input);
  const results = await client.send(command);

  let output = [];
  for (let row of results.retrievalResults) {
    output.push({
      text: row.content.text || '',
      metadata: row.metadata,
      score: row.score,
    });
  }

  return output;
};
