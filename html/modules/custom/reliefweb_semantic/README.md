# ReliefWeb - Semantic module

This module provides integration with the ReliefWeb Semantic API.

## AWS

- Role: `BedrockRoleKbRw`
- Collection: `arn:aws:aoss:us-east-1:694216630861:collection/b2h8ajgjb3x87ur892hc`
- Vector field: `embedding`
- Text field name: `AMAZON_BEDROCK_TEXT_CHUNK`
- Metadata field name: `AMAZON_BEDROCK_METADATA`

| Bundle | S3 source | Bucket | Index | KB |
| - | - | - | - | - |
| report    | kb-data-source-rw-reports    | rw-kb-reports | rw-reports | rw-knowledge-base-reports |
| job       | kb-data-source-rw-jobs       | rw-kb-jobs | rw-jobs | rw-knowledge-base-jobs |
| training  | kb-data-source-rw-trainings  | rw-kb-trainings | rw-trainings-2 | rw-knowledge-base-trainings |
| blog_post | kb-data-source-rw-blog-posts    | rw-kb-blog-posts | rw-blog-posts-2 | rw-knowledge-base-blog-posts |
| book      | kb-data-source-rw-books    | rw-kb-books | rw-books-2 | rw-knowledge-base-books |
| topic     | kb-data-source-rw-topics    | rw-kb-topics | rw-topics | rw-knowledge-base-topics |


```
GET /bedrock-knowledge-base-default-index/_search
{
  "query": {
    "match_all": {}
  }
}
```

```
GET /bedrock-knowledge-base-default-index/_search
{
  "query": {
    "match": {
      "country": "*"
    }

  }
}
```

```
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

Describe the weather in china in june 2024
