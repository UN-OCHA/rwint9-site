import {
  BedrockAgentRuntimeClient,
  RetrieveAndGenerateCommand,
} from "@aws-sdk/client-bedrock-agent-runtime";


const client = new BedrockAgentRuntimeClient({region: process.env.AWS_BEDROCK_REGION,
	credentials:{
		secretAccessKey:process.env.AWS_BEDROCK_SECRET_ACCESS_KEY,
		accessKeyId:process.env.AWS_BEDROCK_ACCESS_KEY_ID
	}
});

const input = {
  input: { text: "Which African countries do need to most support from the World Food Program?" },
  retrieveAndGenerateConfiguration: {
    type: "KNOWLEDGE_BASE",
    knowledgeBaseConfiguration: {
      knowledgeBaseId: "VIEPSPYNSS",
      modelArn: "arn:aws:bedrock:us-east-1::foundation-model/amazon.titan-text-premier-v1:0",
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
      }
    }
  }
};

const command = new RetrieveAndGenerateCommand(input);

const {citations, output} = await client.send(command);
console.log(output.text)
for (let row of citations) {
  console.log(row.generatedResponsePart.textResponsePart.text);
  for (let ref of row.retrievedReferences) {
    console.log(ref.metadata);
  }
}


