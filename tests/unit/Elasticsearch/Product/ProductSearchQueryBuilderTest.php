<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Elasticsearch\Product;

use OpenSearchDSL\Query\Compound\BoolQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Category\Aggregate\CategoryTranslation\CategoryTranslationDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductCategory\ProductCategoryDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturerTranslation\ProductManufacturerTranslationDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductTag\ProductTagDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\SearchConfigLoader;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Filter\AbstractTokenFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Filter\TokenFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Term\Tokenizer;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriteGatewayInterface;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\CustomField\CustomFieldService;
use Shopware\Core\System\Tag\TagDefinition;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticDefinitionInstanceRegistry;
use Shopware\Core\Test\Stub\Framework\Adapter\Storage\ArrayKeyValueStorage;
use Shopware\Elasticsearch\ElasticsearchException;
use Shopware\Elasticsearch\Product\AbstractProductSearchQueryBuilder;
use Shopware\Elasticsearch\Product\ElasticsearchOptimizeSwitch;
use Shopware\Elasticsearch\Product\ProductSearchQueryBuilder;
use Shopware\Elasticsearch\TokenQueryBuilder;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @internal
 */
#[CoversClass(AbstractProductSearchQueryBuilder::class)]
#[CoversClass(ProductSearchQueryBuilder::class)]
class ProductSearchQueryBuilderTest extends TestCase
{
    private const SECOND_LANGUAGE_ID = '2fbb5fe2e29a4d70aa5854ce7ce3e20c';

    private TokenQueryBuilder $tokenQueryBuilder;

    protected function setUp(): void
    {
        $this->tokenQueryBuilder = new TokenQueryBuilder(
            $this->getRegistry(),
            new CustomFieldServiceStub([
                'evolvesInt' => new IntField('evolvesInt', 'evolvesInt'),
                'evolvesFloat' => new FloatField('evolvesFloat', 'evolvesFloat'),
                'evolvesText' => new StringField('evolvesText', 'evolvesText'),
            ]),
            new ArrayKeyValueStorage([
                ElasticsearchOptimizeSwitch::FLAG => true,
            ]),
        );
    }

    public function testBuildEmptyQuery(): void
    {
        static::expectException(ElasticsearchException::class);
        static::expectExceptionMessage('Empty query provided');

        $builder = $this->getBuilder([
            self::config(field: 'restockTime', ranking: 500, tokenize: true, and: false),
        ]);

        $criteria = new Criteria();
        $criteria->setTerm('foo');
        $parsed = $builder->build($criteria, Context::createDefaultContext());

        static::assertSame([], $parsed->toArray());
    }

    public function testBuildWithoutFields(): void
    {
        static::expectException(ElasticsearchException::class);
        static::expectExceptionMessage('Empty query provided');

        $builder = $this->getBuilder(null);

        $criteria = new Criteria();

        $parsed = $builder->build($criteria, Context::createDefaultContext());

        static::assertSame([], $parsed->toArray());
    }

    /**
     * @param array{array{and_logic: string, field: string, tokenize: int, ranking: float}} $config
     * @param array{string: mixed} $expected
     */
    #[DataProvider('buildSingleLanguageProvider')]
    public function testBuildSingleLanguage(array $config, string $term, array $expected): void
    {
        $builder = $this->getBuilder($config);

        $criteria = new Criteria();
        $criteria->setTerm($term);

        $parsed = $builder->build($criteria, Context::createDefaultContext());

        static::assertEquals($expected, $parsed->toArray());
    }

    /**
     * @param array{array{and_logic: string, field: string, tokenize: int, ranking: int}} $config
     * @param array{string: mixed} $expected
     */
    #[DataProvider('buildMultipleLanguageProvider')]
    public function testBuildMultipleLanguages(array $config, string $term, array $expected): void
    {
        $builder = $this->getBuilder($config);

        $criteria = new Criteria();
        $criteria->setTerm($term);

        $context = new Context(
            new SystemSource(),
            [],
            Defaults::CURRENCY,
            [Defaults::LANGUAGE_SYSTEM, self::SECOND_LANGUAGE_ID],
        );

        $parsed = $builder->build($criteria, $context);

        static::assertEquals($expected, $parsed->toArray());
    }

    /**
     * @return iterable<array-key, array{config: array{array{and_logic: string, field: string, tokenize: int, ranking: int|float}}, term: string, expected: array<string, mixed>}>
     */
    public static function buildSingleLanguageProvider(): iterable
    {
        $prefix = 'customFields.' . Defaults::LANGUAGE_SYSTEM . '.';

        yield 'Test tokenized fields' => [
            'config' => [
                self::config(field: 'name', ranking: 1000, tokenize: true, and: false),
                self::config(field: 'tags.name', ranking: 500, tokenize: true, and: false),
            ],
            'term' => 'foo',
            'expected' => self::bool([
                self::textMatch('name', 'foo', 1000, Defaults::LANGUAGE_SYSTEM, andSearch: false),
                self::nested('tags', self::textMatch('tags.name', 'foo', 500, andSearch: false)),
            ]),
        ];

        yield 'Test single term with numeric field' => [
            'config' => [
                self::config(field: 'restockTime', ranking: 1000),
            ],
            'term' => '2023',
            'expected' => self::term('restockTime', 2023, 1000),
        ];

        yield 'Test multiple fields with terms' => [
            'config' => [
                self::config(field: 'name', ranking: 1000),
                self::config(field: 'ean', ranking: 2000),
                self::config(field: 'restockTime', ranking: 1500),
                self::config(field: 'tags.name', ranking: 500),
            ],
            'term' => 'foo 2023',
            'expected' => self::disMax([
                self::bool([
                    self::bool([
                        self::textMatch('name', 'foo', 1000, Defaults::LANGUAGE_SYSTEM, false),
                        self::textMatch('ean', 'foo', 2000, null, false),
                        self::nested('tags', self::textMatch('tags.name', 'foo', 500, null, false)),
                    ]),
                    self::bool([
                        self::textMatch('name', '2023', 1000, Defaults::LANGUAGE_SYSTEM, false),
                        self::textMatch('ean', '2023', 2000, null, false),
                        self::term('restockTime', 2023, 1500),
                        self::nested('tags', self::textMatch('tags.name', '2023', 500, null, false)),
                    ]),
                ], BoolQuery::MUST),
                self::bool([
                    self::textMatch('name', 'foo 2023', 1000, Defaults::LANGUAGE_SYSTEM, false),
                    self::textMatch('ean', 'foo 2023', 2000, null, false),
                    self::nested('tags', self::textMatch('tags.name', 'foo 2023', 500, null, false)),
                ]),
            ]),
        ];

        yield 'Test multiple fields with all numeric terms' => [
            'config' => [
                self::config(field: 'height', ranking: 2000),
                self::config(field: 'restockTime', ranking: 1500),
            ],
            'term' => 'foo 2023 2024',
            'expected' => self::bool([
                self::bool([
                    self::term('height', 2023.0, 2000),
                    self::term('restockTime', 2023, 1500),
                ]),
                self::bool([
                    self::term('height', 2024.0, 2000),
                    self::term('restockTime', 2024, 1500),
                ]),
            ], BoolQuery::MUST),
        ];

        yield 'Test multiple custom fields with terms' => [
            'config' => [
                self::config(field: 'customFields.evolvesText', ranking: 500),
                self::config(field: 'customFields.evolvesInt', ranking: 400),
                self::config(field: 'customFields.evolvesFloat', ranking: 500),
                self::config(field: 'categories.childCount', ranking: 500),
            ],
            'term' => 'foo 2023',
            'expected' => self::disMax([
                self::bool([
                    self::textMatch($prefix . 'evolvesText', 'foo', 500, null, false),
                    self::bool([
                        self::textMatch($prefix . 'evolvesText', '2023', 500, null, false),
                        self::term($prefix . 'evolvesInt', 2023, 400),
                        self::term($prefix . 'evolvesFloat', 2023.0, 500),
                        self::nested('categories', self::term('categories.childCount', 2023, 500)),
                    ]),
                ], BoolQuery::MUST),
                self::textMatch($prefix . 'evolvesText', 'foo 2023', 500, null, false),
            ]),
        ];
    }

    /**
     * @return iterable<array-key, array{config: array{array{and_logic: string, field: string, tokenize: int, ranking: int|float}}, term: string, expected: array<string, mixed>}>
     */
    public static function buildMultipleLanguageProvider(): iterable
    {
        $prefixCfLang1 = 'customFields.' . Defaults::LANGUAGE_SYSTEM . '.';
        $prefixCfLang2 = 'customFields.' . self::SECOND_LANGUAGE_ID . '.';

        yield 'Test tokenized fields' => [
            'config' => [
                self::config(field: 'name', ranking: 1000, tokenize: true, and: false),
                self::config(field: 'tags.name', ranking: 500, tokenize: true, and: false),
                self::config(field: 'categories.name', ranking: 200, tokenize: true, and: false),
            ],
            'term' => 'foo',
            'expected' => self::bool([
                self::textMatch('name', 'foo', 1000, Defaults::LANGUAGE_SYSTEM, andSearch: false),
                self::nested('tags', self::textMatch('tags.name', 'foo', 500, andSearch: false)),
                self::nested('categories', self::disMax([
                    self::textMatch('categories.name', 'foo', 200, Defaults::LANGUAGE_SYSTEM, andSearch: false),
                    self::textMatch('categories.name', 'foo', 160, self::SECOND_LANGUAGE_ID, andSearch: false),
                ])),
            ]),
        ];

        yield 'Test multiple fields with terms' => [
            'config' => [
                self::config(field: 'name', ranking: 1000),
                self::config(field: 'ean', ranking: 2000),
                self::config(field: 'restockTime', ranking: 1500),
                self::config(field: 'tags.name', ranking: 500),
            ],
            'term' => 'foo 2023',
            'expected' => self::disMax([
                self::bool([
                    self::bool([
                        self::textMatch('name', 'foo', 1000, Defaults::LANGUAGE_SYSTEM, false),
                        self::textMatch('ean', 'foo', 2000, null, false),
                        self::nested('tags', self::textMatch('tags.name', 'foo', 500, null, false)),
                    ]),
                    self::bool([
                        self::textMatch('name', '2023', 1000, Defaults::LANGUAGE_SYSTEM, false),
                        self::textMatch('ean', '2023', 2000, null, false),
                        self::term('restockTime', 2023, 1500),
                        self::nested('tags', self::textMatch('tags.name', '2023', 500, null, false)),
                    ]),
                ], BoolQuery::MUST),
                self::bool([
                    self::textMatch('name', 'foo 2023', 1000, Defaults::LANGUAGE_SYSTEM, false),
                    self::textMatch('ean', 'foo 2023', 2000, null, false),
                    self::nested('tags', self::textMatch('tags.name', 'foo 2023', 500, null, false)),
                ]),
            ]),
        ];

        yield 'Test multiple custom fields with terms' => [
            'config' => [
                self::config(field: 'customFields.evolvesText', ranking: 500),
                self::config(field: 'customFields.evolvesInt', ranking: 400),
                self::config(field: 'customFields.evolvesFloat', ranking: 500),
                self::config(field: 'categories.childCount', ranking: 500),
            ],
            'term' => 'foo 2023',
            'expected' => self::disMax([
                self::bool([
                    self::disMax([
                        self::textMatch($prefixCfLang1 . 'evolvesText', 'foo', 500, null, false),
                        self::textMatch($prefixCfLang2 . 'evolvesText', 'foo', 400, null, false),
                    ]),
                    self::bool([
                        self::disMax([
                            self::textMatch($prefixCfLang1 . 'evolvesText', '2023', 500, null, false),
                            self::textMatch($prefixCfLang2 . 'evolvesText', '2023', 400, null, false),
                        ]),
                        self::disMax([
                            self::term($prefixCfLang1 . 'evolvesInt', 2023, 400),
                            self::term($prefixCfLang2 . 'evolvesInt', 2023, 320),
                        ]),
                        self::disMax([
                            self::term($prefixCfLang1 . 'evolvesFloat', 2023, 500),
                            self::term($prefixCfLang2 . 'evolvesFloat', 2023, 400),
                        ]),
                        self::nested('categories', self::term('categories.childCount', 2023, 500)),
                    ]),
                ], BoolQuery::MUST),
                self::disMax([
                    self::textMatch($prefixCfLang1 . 'evolvesText', 'foo 2023', 500, null, false),
                    self::textMatch($prefixCfLang2 . 'evolvesText', 'foo 2023', 400, null, false),
                ]),
            ]),
        ];
    }

    public function testDecoration(): void
    {
        $builder = new ProductSearchQueryBuilder(
            $this->getDefinition(),
            $this->createMock(TokenFilter::class),
            new Tokenizer(2),
            $this->createMock(SearchConfigLoader::class),
            $this->tokenQueryBuilder
        );

        static::expectException(DecorationPatternException::class);
        $builder->getDecorated();
    }

    private function getDefinition(): EntityDefinition
    {
        $instanceRegistry = $this->getRegistry();

        return $instanceRegistry->getByEntityName('product');
    }

    private function getRegistry(): DefinitionInstanceRegistry
    {
        return new StaticDefinitionInstanceRegistry(
            [
                ProductDefinition::class,
                ProductTagDefinition::class,
                TagDefinition::class,
                ProductTranslationDefinition::class,
                ProductManufacturerDefinition::class,
                ProductManufacturerTranslationDefinition::class,
                ProductCategoryDefinition::class,
                CategoryDefinition::class,
                CategoryTranslationDefinition::class,
            ],
            $this->createMock(ValidatorInterface::class),
            $this->createMock(EntityWriteGatewayInterface::class)
        );
    }

    /**
     * @param array{array{and_logic: string, field: string, tokenize: int, ranking: float}}|null $config
     */
    private function getBuilder(?array $config): ProductSearchQueryBuilder
    {
        $configLoader = $this->createMock(SearchConfigLoader::class);
        $configLoader->method('load')->willReturn($config ?? []);

        $tokenFilter = $this->createMock(AbstractTokenFilter::class);
        $tokenFilter->method('filter')->willReturnArgument(0);

        return new ProductSearchQueryBuilder(
            $this->getDefinition(),
            $tokenFilter,
            new Tokenizer(2),
            $configLoader,
            $this->tokenQueryBuilder
        );
    }

    /**
     * @return array{and_logic: string, field: string, tokenize: int, ranking: float}
     */
    private static function config(string $field, float $ranking, bool $tokenize = false, bool $and = true): array
    {
        return [
            'and_logic' => $and ? '1' : '0',
            'field' => $field,
            'tokenize' => $tokenize ? 1 : 0,
            'ranking' => $ranking,
        ];
    }

    /**
     * @return array{term: array<string, array{value: string|int|float, boost: float}>}
     */
    private static function term(string $field, string|int|float $query, float $boost): array
    {
        return [
            'term' => [
                $field => [
                    'boost' => $boost,
                    'value' => $query,
                ],
            ],
        ];
    }

    /**
     * @param array<mixed> $query
     *
     * @return array{nested: array{path: string, query: array<mixed>}}
     */
    private static function nested(string $root, array $query): array
    {
        return [
            'nested' => [
                'path' => $root,
                'query' => $query,
            ],
        ];
    }

    /**
     * @return array<mixed>
     */
    private static function textMatch(string $field, string|int|float $query, int|float $boost, ?string $languageId = null, ?bool $tokenized = true, ?bool $andSearch = true): array
    {
        if ($languageId !== null) {
            $field .= '.' . $languageId;
        }

        $tokens = \explode(' ', (string) $query);
        $tokenCount = \count($tokens);

        $queries = [];

        if ($tokenCount === 1) {
            $queries[] = self::term($field, $query, 1);
        } else {
            $queries[] = self::terms($field, $tokens, 1);
        }

        $queries[] = self::match($field . '.search', $query, 0.8, self::computeFuzziness($query), $andSearch);

        if ($tokenCount > 1) {
            $queries[] = self::matchPhrasePrefix($field . '.search', $query, 0.6, 3, self::maxExpansionsForLastWord((string) $query));
        }

        if ($tokenCount === 1) {
            if (mb_strlen((string) $query) >= 4) {
                if ($tokenized) {
                    $queries[] = self::term($field . '.ngram', $query, 0.4);
                }
            } else {
                $queries[] = self::prefix($field, $query, 0.4);
            }
        }

        return self::disMax($queries, $boost);
    }

    /**
     * @return array{match: array<string, array{query: string|int|float, boost: float, fuzziness?: int|string|null}>}
     */
    private static function match(string $field, string|int|float $query, int|float $boost, int|string|null $fuzziness = 0, ?bool $andSearch = true): array
    {
        $payload = [
            'query' => $query,
            'boost' => (float) $boost,
        ];

        if (is_numeric($query) || preg_match('/\\d{3,}/', $query)) {
            $fuzziness = 0;
        }

        if ($fuzziness !== null) {
            $payload['fuzziness'] = $fuzziness;
        }

        if (!\str_contains($field, '.ngram')) {
            $payload['operator'] = $andSearch ? 'and' : 'or';
        }

        $payload['fuzzy_transpositions'] = true;
        $payload['max_expansions'] = self::maxExpansionsForLastWord((string) $query);
        $payload['prefix_length'] = 1;

        return [
            'match' => [
                $field => $payload,
            ],
        ];
    }

    /**
     * @param array<mixed> $queries
     *
     * @return array{dis_max: array{queries: array<mixed>}}
     */
    private static function disMax(array $queries, float|int|null $boost = null): array
    {
        $payload = [
            'queries' => $queries,
        ];

        if ($boost !== null) {
            $payload['boost'] = (float) $boost;
        }

        return [
            'dis_max' => $payload,
        ];
    }

    /**
     * @param array<mixed> $queries
     *
     * @return array{ bool: array<string, array<mixed>> }
     */
    private static function bool(array $queries, string $operator = BoolQuery::SHOULD): array
    {
        return [
            'bool' => [
                $operator => $queries,
            ],
        ];
    }

    /**
     * @return array{match_phrase_prefix: array<string, array{query: string|int|float, boost: float, slop: int}>}
     */
    private static function matchPhrasePrefix(string $field, string|int|float $query, float $boost, int $slop = 3, int $maxExpansion = 10): array
    {
        return [
            'match_phrase_prefix' => [
                $field => [
                    'query' => $query,
                    'boost' => $boost,
                    'slop' => $slop,
                    'max_expansions' => $maxExpansion,
                ],
            ],
        ];
    }

    private static function computeFuzziness(string|int|float $query): int|string
    {
        if (is_numeric($query) || preg_match('/\\d{3,}/', $query)) {
            return 0;
        }

        if (preg_match('/[A-Za-z].*\\d|\\d.*[A-Za-z]/', $query)) {
            return 0;
        }

        return 'AUTO:3,8';
    }

    private static function maxExpansionsForLastWord(string $query): int
    {
        $parts = explode(' ', $query);
        $last = ($parts[\count($parts) - 1] ?? $query);
        $len = mb_strlen($last);

        if ($len <= 3) {
            return 5;
        }

        if ($len <= 6) {
            return 10;
        }

        return 20;
    }

    /**
     * @return array{prefix: array<string, array{value: string|int|float, boost: float}>}
     */
    private static function prefix(string $field, string|int|float $query, float $boost): array
    {
        return [
            'prefix' => [
                $field => [
                    'value' => $query,
                    'boost' => $boost,
                ],
            ],
        ];
    }

    /**
     * @param array<string> $tokens
     *
     * @return array{terms: non-empty-array<string, array<string>|float|int>}
     */
    private static function terms(string $field, array $tokens, int|float $boost): array
    {
        return [
            'terms' => [
                $field => $tokens,
                'boost' => $boost,
            ],
        ];
    }
}

/**
 * @internal
 */
class CustomFieldServiceStub extends CustomFieldService
{
    /**
     * @internal
     *
     * @param array<string, Field> $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function getCustomField(string $attributeName): Field
    {
        return $this->config[$attributeName];
    }
}
