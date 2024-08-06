ReliefWeb - Semantic module
===========================

This module provides integration with the ReliefWeb Semantic API.


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
