<?php

declare(strict_types=1);

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Search\Elasticsearch;

use Rollerworks\Component\Search\Exception\BadMethodCallException;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\Value\Compare;
use Rollerworks\Component\Search\Value\ExcludedRange;
use Rollerworks\Component\Search\Value\PatternMatch;
use Rollerworks\Component\Search\Value\Range;
use Rollerworks\Component\Search\Value\ValuesGroup;

/* final */ class QueryConditionGenerator implements ConditionGenerator
{
    private const PROPERTY_ID = '_id';

    // Elasticsearch general query elements
    public const QUERY = 'query';
    public const QUERY_BOOL = 'bool';
    public const QUERY_IDS = 'ids';
    public const QUERY_NESTED = 'nested';
    public const QUERY_MATCH = 'match';
    public const QUERY_PREFIX = 'prefix';
    public const QUERY_RANGE = 'range';
    public const QUERY_WILDCARD = 'wildcard';
    public const QUERY_TERM = 'term';
    public const QUERY_TERMS = 'terms';
    public const QUERY_VALUE = 'value';
    public const QUERY_VALUES = 'values';

    // Elasticsearch boolean operators
    public const CONDITION_NOT = 'must_not';
    public const CONDITION_AND = 'must';
    public const CONDITION_OR = 'should';

    // Elasticsearch comparison operators
    public const COMPARISON_LESS = 'lt';
    public const COMPARISON_LESS_OR_EQUAL = 'lte';
    public const COMPARISON_GREATER = 'gt';
    public const COMPARISON_GREATER_OR_EQUAL = 'gte';

    // note: this one is NOT available for Elasticsearch, we use it as a named constant only
    private const COMPARISON_UNEQUAL = '<>';

    private const COMPARISON_OPERATOR_MAP = [
        '<>' => self::COMPARISON_UNEQUAL,
        '<' => self::COMPARISON_LESS,
        '<=' => self::COMPARISON_LESS_OR_EQUAL,
        '>' => self::COMPARISON_GREATER,
        '>=' => self::COMPARISON_GREATER_OR_EQUAL,
    ];

    private $searchCondition;
    private $fieldSet;

    /** @var FieldMapping[] $mapping */
    private $mappings;

    public function __construct(SearchCondition $searchCondition)
    {
        $this->searchCondition = $searchCondition;
        $this->fieldSet = $searchCondition->getFieldSet();
        // $this->mappings = ['id' => $mapping, 'name' => $mapping2]; // TODO MultiMatch
    }

    /**
     * @inheritdoc
     */
    public function registerField(string $fieldName, string $mapping)
    {
        $this->mappings[$fieldName] = new FieldMapping($fieldName, $mapping, $this->fieldSet->get($fieldName));
    }

    /**
     * This uses the `multi_match` instead of mapping the field multiple times,
     * and allows for more flexibility tailored to Elasticsearch.
     *
     * @param string $fieldName
     *
     * @return MultiFieldMapping
     */
    public function registerMultiField(string $fieldName)
    {
    }

    /**
     * @inheritdoc
     */
    public function getQuery(): ?array
    {
        $rootGroupCondition = $this->processGroup($this->searchCondition->getValuesGroup());

        if ([] === $rootGroupCondition) {
            return null;
        }

        return [self::QUERY => $rootGroupCondition];
    }

    /**
     * @inheritdoc
     */
    public function getMappings(): array
    {
        $mappings = [];

        $group = $this->searchCondition->getValuesGroup();
        foreach ($group->getFields() as $fieldName => $valuesBag) {
            if ($valuesBag->hasSimpleValues()) {
                $mappings[$fieldName] = $this->mappings[$fieldName];
            }

            if ($valuesBag->has(Range::class)) {
                $mappings[$fieldName] = $this->mappings[$fieldName];
            }

            if ($valuesBag->has(Compare::class)) {
                $mappings[$fieldName] = $this->mappings[$fieldName];
            }

            if ($valuesBag->has(PatternMatch::class)) {
                $mappings[$fieldName] = $this->mappings[$fieldName];
            }
        }

        return array_values($mappings);
    }

    /**
     * @inheritdoc
     */
    public function getSearchCondition(): SearchCondition
    {
        return $this->searchCondition;
    }

    /**
     * @param Range $range
     *
     * @return array
     */
    public static function generateRangeParams(Range $range): array
    {
        $lowerCondition = $range->isLowerInclusive() ? self::COMPARISON_GREATER_OR_EQUAL : self::COMPARISON_GREATER;
        $upperCondition = $range->isUpperInclusive() ? self::COMPARISON_LESS_OR_EQUAL : self::COMPARISON_LESS;

        return [
            $lowerCondition => $range->getLower(),
            $upperCondition => $range->getUpper(),
        ];
    }

    /**
     * @param string $operator SearchCondition / Compare operator
     *
     * @return string Equivalent Elasticsearch operator
     */
    public static function translateComparison(string $operator): string
    {
        return self::COMPARISON_OPERATOR_MAP[$operator];
    }

    private function processGroup(ValuesGroup $group): array
    {
        // Note: Excludes are `must_not`, for includes `must` (AND) or `should` (OR) is used. Subgroups use `must`.
        $includingType = ValuesGroup::GROUP_LOGICAL_AND === $group->getGroupLogical()
            ? self::CONDITION_AND
            : self::CONDITION_OR;

        $bool = [];
        $hints = new QueryPreparationHints();
        foreach ($group->getFields() as $fieldName => $valuesBag) {
            // TODO: this looks fishy, what about nested fields?
            $mapping = $this->mappings[$fieldName];

            $propertyName = $mapping->propertyName;
            $valueConverter = $mapping->valueConversion;
            $queryConverter = $mapping->queryConversion;
            $nested = $mapping->nested;

            $hints->identifier = (self::PROPERTY_ID === $propertyName);
            $callback = function ($value) use ($valueConverter) {
                return $this->convertValue($value, $valueConverter);
            };

            // simple values
            if ($valuesBag->hasSimpleValues()) {
                $values = array_map($callback, array_values($valuesBag->getSimpleValues()), [$valueConverter]);
                $hints->context = QueryPreparationHints::CONTEXT_SIMPLE_VALUES;
                $bool[$includingType][] = $this->prepareQuery($propertyName, $values, $hints, $queryConverter, $nested);
            }
            if ($valuesBag->hasExcludedSimpleValues()) {
                $values = array_map($callback, array_values($valuesBag->getExcludedSimpleValues()), [$valueConverter]);
                $hints->context = QueryPreparationHints::CONTEXT_EXCLUDED_SIMPLE_VALUES;
                $bool[self::CONDITION_NOT][] = $this->prepareQuery($propertyName, $values, $hints, $queryConverter, $nested);
            }

            // ranges
            if ($valuesBag->has(Range::class)) {
                /** @var Range $range */
                foreach ($valuesBag->get(Range::class) as $range) {
                    $range = $this->convertRangeValues($range, $valueConverter);
                    $hints->context = QueryPreparationHints::CONTEXT_RANGE_VALUES;
                    $bool[$includingType][] = $this->prepareQuery($propertyName, $range, $hints, $queryConverter, $nested);
                }
            }
            if ($valuesBag->has(ExcludedRange::class)) {
                /** @var Range $range */
                foreach ($valuesBag->get(ExcludedRange::class) as $range) {
                    $range = $this->convertRangeValues($range, $valueConverter);
                    $hints->context = QueryPreparationHints::CONTEXT_EXCLUDED_RANGE_VALUES;
                    $bool[self::CONDITION_NOT][] = $this->prepareQuery($propertyName, $range, $hints, $queryConverter, $nested);
                }
            }

            // comparison
            if ($valuesBag->has(Compare::class)) {
                /** @var Compare $compare */
                foreach ($valuesBag->get(Compare::class) as $compare) {
                    $compare = $this->convertCompareValue($compare, $valueConverter);
                    $hints->context = QueryPreparationHints::CONTEXT_COMPARISON;
                    $localIncludingType = self::COMPARISON_UNEQUAL === $compare->getOperator() ? self::CONDITION_NOT : $includingType;
                    $bool[$localIncludingType][] = $this->prepareQuery($propertyName, $compare, $hints, $queryConverter, $nested);
                }
            }

            // matchers
            if ($valuesBag->has(PatternMatch::class)) {
                /** @var PatternMatch $patternMatch */
                foreach ($valuesBag->get(PatternMatch::class) as $patternMatch) {
                    $patternMatch = $this->convertMatcherValue($patternMatch, $valueConverter);
                    $hints->context = QueryPreparationHints::CONTEXT_PATTERN_MATCH;
                    $localIncludingType = $patternMatch->isExclusive() ? self::CONDITION_NOT : $includingType;
                    $bool[$localIncludingType][] = $this->prepareQuery($propertyName, $patternMatch, $hints, $queryConverter, $nested);
                }
            }
        }

        foreach ($group->getGroups() as $subGroup) {
            $subGroupCondition = $this->processGroup($subGroup);

            if ([] !== $subGroupCondition) {
                $bool[self::CONDITION_AND][] = $subGroupCondition;
            }
        }

        if ([] === $bool) {
            return [];
        }

        return [self::QUERY_BOOL => $bool];
    }

    /**
     * @param mixed                $value
     * @param null|ValueConversion $converter
     *
     * @return mixed
     */
    private function convertValue($value, ?ValueConversion $converter)
    {
        if (null === $converter) {
            return $value;
        }

        return $converter->convertValue($value);
    }

    /**
     * @param Range           $range
     * @param ValueConversion $converter
     *
     * @return Range
     */
    private function convertRangeValues(Range $range, ?ValueConversion $converter): Range
    {
        return new Range(
            $this->convertValue($range->getLower(), $converter),
            $this->convertValue($range->getUpper(), $converter),
            $range->isLowerInclusive(),
            $range->isUpperInclusive()
        );
    }

    /**
     * @param Compare         $compare
     * @param ValueConversion $converter
     *
     * @return Compare
     */
    private function convertCompareValue(Compare $compare, ?ValueConversion $converter): Compare
    {
        return new Compare(
            $this->convertValue($compare->getValue(), $converter),
            $compare->getOperator()
        );
    }

    /**
     * @param PatternMatch    $patternMatch
     * @param ValueConversion $converter
     *
     * @throws \InvalidArgumentException
     *
     * @return PatternMatch
     */
    private function convertMatcherValue(PatternMatch $patternMatch, ?ValueConversion $converter): PatternMatch
    {
        return new PatternMatch(
            $this->convertValue($patternMatch->getValue(), $converter),
            $patternMatch->getType(),
            $patternMatch->isCaseInsensitive()
        );
    }

    /**
     * @param string                $propertyName
     * @param mixed                 $value
     * @param QueryPreparationHints $hints
     * @param null|QueryConversion  $converter
     * @param array|bool            $nested
     *
     * @throws \Rollerworks\Component\Search\Exception\BadMethodCallException
     *
     * @return array
     */
    private function prepareQuery(string $propertyName, $value, QueryPreparationHints $hints, ?QueryConversion $converter, $nested): array
    {
        if (null === $converter || null === ($query = $converter->convertQuery($propertyName, $value, $hints))) {
            switch ($hints->context) {
                case QueryPreparationHints::CONTEXT_RANGE_VALUES:
                case QueryPreparationHints::CONTEXT_EXCLUDED_RANGE_VALUES:
                    $query = [self::QUERY_RANGE => [$propertyName => static::generateRangeParams($value)]];
                    if ($hints->identifier) {
                        // IDs cannot be queries by range in Elasticsearch, use ids query
                        // https://www.elastic.co/guide/en/elasticsearch/reference/current/query-dsl-ids-query.html
                        /** @var Range $value */
                        $query = [
                            self::QUERY_IDS => [
                                self::QUERY_VALUES => range($value->getLower(), $value->getUpper()),
                            ],
                        ];
                    }
                    break;
                case QueryPreparationHints::CONTEXT_COMPARISON:
                    /** @var Compare $value */
                    $operator = self::translateComparison($value->getOperator());
                    $query = [
                        $propertyName => [$operator => $value->getValue()],
                    ];

                    if (self::COMPARISON_UNEQUAL === $value->getOperator()) {
                        $query = [
                            self::QUERY_TERM => [
                                $propertyName => [self::QUERY_VALUE => $value->getValue()],
                            ],
                        ];
                    }
                    break;
                case QueryPreparationHints::CONTEXT_PATTERN_MATCH:
                    /** @var PatternMatch $value */
                    $query = $this->preparePatternMatch($propertyName, $value);
                    break;
                default:
                case QueryPreparationHints::CONTEXT_SIMPLE_VALUES:
                case QueryPreparationHints::CONTEXT_EXCLUDED_SIMPLE_VALUES:
                    // simple values
                    $query = [self::QUERY_TERMS => [$propertyName => $value]];
                    if ($hints->identifier) {
                        $query = [self::QUERY_IDS => [self::QUERY_VALUES => $value]];
                    }
                    break;
            }
        }

        if ($nested) {
            while (false !== $nested) {
                $path = $nested['path'];
                $query = [
                    self::QUERY_NESTED => compact('path', 'query'),
                ];
                $nested = $nested['nested'];
            }
        }

        return $query;
    }

    /**
     * @param string       $propertyName
     * @param PatternMatch $patternMatch
     *
     * @throws \Rollerworks\Component\Search\Exception\BadMethodCallException
     *
     * @return array
     */
    private function preparePatternMatch(string $propertyName, PatternMatch $patternMatch): array
    {
        $query = [];
        switch ($patternMatch->getType()) {
            // Faster then Wildcard but less accurate.
            // XXX Allow to configure `fuzzy`, `operator`, `zero_terms_query` and `cutoff_frequency` (TextType).
            case PatternMatch::PATTERN_CONTAINS:
            case PatternMatch::PATTERN_NOT_CONTAINS:
                $query[self::QUERY_MATCH] = [$propertyName => [self::QUERY => $patternMatch->getValue()]];
                break;

            case PatternMatch::PATTERN_STARTS_WITH:
            case PatternMatch::PATTERN_NOT_STARTS_WITH:
                $query[self::QUERY_PREFIX] = [$propertyName => [self::QUERY_VALUE => $patternMatch->getValue()]];
                break;

            case PatternMatch::PATTERN_ENDS_WITH:
            case PatternMatch::PATTERN_NOT_ENDS_WITH:
                $query[self::QUERY_WILDCARD] = [
                    $propertyName => [self::QUERY_VALUE => '?'.addcslashes($patternMatch->getValue(), '?*')],
                ];
                break;

            case PatternMatch::PATTERN_EQUALS:
            case PatternMatch::PATTERN_NOT_EQUALS:
                $query[self::QUERY_TERM] = [$propertyName => [self::QUERY_VALUE => $patternMatch->getValue()]];
                break;

            default:
                $message = sprintf('Not supported PatternMatch type "%s"', $patternMatch->getType());
                throw new BadMethodCallException($message);
        }

        return $query;
    }
}
