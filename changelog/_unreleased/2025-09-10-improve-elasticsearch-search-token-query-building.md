---
title: Improve Elasticsearch token query building for relevance and performance
issue: 6652
---
# Core
* Added exact match using TermQuery in `\Shopware\Elasticsearch\TokenQueryBuilder` and prioritize it if matched over fuzzy search.
* Changed `\Shopware\Elasticsearch\TokenQueryBuilder` to combine exact term, full-text, prefix, and ngram queries via a boosted `dis_max`, improving relevance for short and multi-word queries.
* Changed fuzziness for numeric and alphanumeric (serial/SKU-like) tokens to zero and switched to `AUTO:3,8` for others via `\Shopware\Elasticsearch\Product\SearchFieldConfig::getFuzziness` to reduce noise.
* Changed `max_expansions` for `match_phrase_prefix` based on last token length and only enable phrase prefix on multi-word terms.
* Added prefix query fallback for very short single tokens (shorter than configured `min_gram`), and use ngram term matches otherwise.
* Changed `\Shopware\Elasticsearch\TokenQueryBuilder` to skip `<field>.ngram` query if the token length is shorter than `min_gram` 
* Changed `\Shopware\Elasticsearch\TokenQueryBuilder` to use TermQuery instead of MatchQuery for `<field>.ngram` query to reduce noise from fuzzy ngram matches. 
