import {
  BedrockAgentRuntimeClient,
  RetrieveAndGenerateCommand,
} from "@aws-sdk/client-bedrock-agent-runtime";


// @todo add mapping between content type and KB Id.

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
    input: {
      text: text
    },
    retrieveAndGenerateConfiguration: {
      type: "KNOWLEDGE_BASE",
      knowledgeBaseConfiguration: {
        knowledgeBaseId: kb,
        modelArn: "arn:aws:bedrock:us-east-1::foundation-model/amazon.titan-text-premier-v1:0",
        retrievalConfiguration: {
          vectorSearchConfiguration: {
            numberOfResults: 10,
            overrideSearchType: "HYBRID",
            filter: {
              andAll: filters
            }
          }
        }
      }
    }
  };

  const command = new RetrieveAndGenerateCommand(input);

  const {citations, output} = await client.send(command);

  let data = {
    answer: output.text || '',
    citations: [],
  };

  for (let row of citations) {
    for (let ref of row.retrievedReferences) {
      data.citations.push({
        text: ref.content.text,
        metadata: ref.metadata
      });
    }
  }

  return data;
};
