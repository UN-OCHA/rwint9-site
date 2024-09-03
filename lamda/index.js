import {
  BedrockAgentRuntimeClient,
  RetrieveCommand,
} from "@aws-sdk/client-bedrock-agent-runtime";


const client = new BedrockAgentRuntimeClient({region: process.env.AWS_BEDROCK_REGION,
	credentials:{
		secretAccessKey:process.env.AWS_BEDROCK_SECRET_ACCESS_KEY,
		accessKeyId:process.env.AWS_BEDROCK_ACCESS_KEY_ID
	}
});

const input = {
  knowledgeBaseId: "VIEPSPYNSS",
  retrievalQuery: {
    text: "Are people starving in Kenya",
  },
  retrievalConfiguration: {
    vectorSearchConfiguration: {
      numberOfResults: 10,
      overrideSearchType: "HYBRID",
      filter: {
        andAll: [
          {
            equals: {
              key: "status",
              value: "1"
            },
          },
          {
            listContains: {
              key: "country",
              value: "220"
            }
          }
        ]
      }
    }
  },
};

const command = new RetrieveCommand(input);
const results = await client.send(command);
for (let row of results.retrievalResults) {
  console.log(row);
}
