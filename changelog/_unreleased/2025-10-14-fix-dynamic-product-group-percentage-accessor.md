---
title: Fix percentage ratio dynamic product groups
issue: 12996
---
# Core
* Changed `Shopware\Core\Content\Product\DataAbstractionLayer\CheapestPrice\CheapestPriceAccessorBuilder::buildAccessor` to ignore zero-valued entries so dynamic product group conditions based on percentage ratios evaluate correctly again.
