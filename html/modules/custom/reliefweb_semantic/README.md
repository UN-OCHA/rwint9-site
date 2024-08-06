# ReliefWeb - Semantic module

This module provides integration with the ReliefWeb Semantic API.

## AWS

Dashboards:

- https://us-east-1.console.aws.amazon.com/s3/lens/dashboard/RW-KB?region=us-east-1&bucketType=general

Config:

- Role: `BedrockRoleKbRw`
- Collection: `arn:aws:aoss:us-east-1:694216630861:collection/b2h8ajgjb3x87ur892hc`
- Vector field: `embedding`
- Text field name: `AMAZON_BEDROCK_TEXT_CHUNK`
- Metadata field name: `AMAZON_BEDROCK_METADATA`

| KB Id      | Data Id    | Bundle    | S3 source                    | Bucket           | Index           | KB                           |
| ---------- | ---------- | --------- | ---------------------------- | ---------------- | --------------- | ---------------------------- |
| VIEPSPYNSS | 6KGHOEXLGY | report    | kb-data-source-rw-reports    | rw-kb-reports    | rw-reports      | rw-knowledge-base-reports    |
| WYBGQOFQLN | ZQW6GY0WYE | job       | kb-data-source-rw-jobs       | rw-kb-jobs       | rw-jobs         | rw-knowledge-base-jobs       |
| VDQ6RY0K5K | XJMXTP72QA | training  | kb-data-source-rw-trainings  | rw-kb-trainings  | rw-trainings-2  | rw-knowledge-base-trainings  |
| D2E5HCYCTQ | URINLN9HIR | blog_post | kb-data-source-rw-blog-posts | rw-kb-blog-posts | rw-blog-posts-2 | rw-knowledge-base-blog-posts |
| NZTC9LPLJN | XETKAPIJKB | book      | kb-data-source-rw-books      | rw-kb-books      | rw-books-2      | rw-knowledge-base-books      |
| Y5EU13DU6Q | AXCARFTXKS | topic     | kb-data-source-rw-topics     | rw-kb-topics     | rw-topics       | rw-knowledge-base-topics     |

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
