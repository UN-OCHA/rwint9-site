# ReliefWeb - Semantic module

This module provides integration with the ReliefWeb Semantic API.

## To do

- [x] Add service to query API

## AWS

Dashboards:

- https://us-east-1.console.aws.amazon.com/s3/lens/dashboard/RW-KB?region=us-east-1&bucketType=general

Config:

- Role: `BedrockRoleKbRw`
- Collection: `arn:aws:aoss:us-east-1:694216630861:collection/b2h8ajgjb3x87ur892hc`
- Vector field: `embedding`
- Text field name: `AMAZON_BEDROCK_TEXT_CHUNK`
- Metadata field name: `AMAZON_BEDROCK_METADATA`

All content is using a single KB, current Id is `VIEPSPYNSS`

## Drush

```bash
drush reliefweb-semantic:index            Index content in the ReliefWeb API.
drush reliefweb-semantic:list-kbs         List kbs.
drush reliefweb-semantic:list-datasources List datasources.
drush reliefweb-semantic:list-jobs        List ingestion jobs.
drush reliefweb-semantic:trigger-sync     Trigger sync..
drush reliefweb-semantic:query-kb --id=WYBGQOFQLN --q="Any jobs in Europe"
drush reliefweb-semantic:list-apikeys
```

## Openseach

```json
GET /bedrock-knowledge-base-default-index/_search
{
  "query": {
    "match_all": {}
  }
}
```

```json
GET /bedrock-knowledge-base-default-index/_search
{
  "query": {
    "match": {
      "country": "*"
    }

  }
}
```

```json
GET /bedrock-knowledge-base-default-index/_search
{
  "query": {
    "match": {
      "title": {
        "query": "china",
        "fuzziness": "AUTO"
      }
    }
  }
}
```

## Questions

- Describe the weather in china in june 2024
