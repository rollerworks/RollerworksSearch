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

namespace Rollerworks\Component\Search\Tests\Doctrine\Dbal;

use Doctrine\DBAL\Connection;
use Rollerworks\Component\Search\Doctrine\Dbal\ColumnConversion;
use Rollerworks\Component\Search\Doctrine\Dbal\ConversionHints;
use Rollerworks\Component\Search\Doctrine\Dbal\ValueConversion;
use Rollerworks\Component\Search\Extension\Core\Type\DateType;
use Rollerworks\Component\Search\Extension\Core\Type\IntegerType;
use Rollerworks\Component\Search\Extension\Core\Type\TextType;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\SearchConditionBuilder;
use Rollerworks\Component\Search\SearchPrimaryCondition;
use Rollerworks\Component\Search\Value\Compare;
use Rollerworks\Component\Search\Value\ExcludedRange;
use Rollerworks\Component\Search\Value\PatternMatch;
use Rollerworks\Component\Search\Value\Range;
use Rollerworks\Component\Search\Value\ValuesGroup;

final class SqlConditionGeneratorTest extends DbalTestCase
{
    private function getConditionGenerator(SearchCondition $condition, Connection $connection = null)
    {
        $conditionGenerator = $this->getDbalFactory()->createConditionGenerator(
            $connection ?: $this->getConnectionMock(),
            $condition
        );

        $conditionGenerator->setField('customer', 'customer', 'I', 'integer');
        $conditionGenerator->setField('customer_name', 'name', 'C', 'string');
        $conditionGenerator->setField('customer_birthday', 'birthday', 'C', 'date');
        $conditionGenerator->setField('status', 'status', 'I', 'integer');
        $conditionGenerator->setField('label', 'label', 'I', 'string');

        return $conditionGenerator;
    }

    public function testSimpleQuery()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('((I.customer IN(2, 5)))', $conditionGenerator->getWhereClause());
    }

    public function testQueryWithPrepend()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('WHERE ((I.customer IN(2, 5)))', $conditionGenerator->getWhereClause('WHERE '));
    }

    public function testEmptyQueryWithPrepend()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('id')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('', $conditionGenerator->getWhereClause('WHERE '));
    }

    public function testQueryWithPrependAndPrimaryCond()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $condition->setPrimaryCondition(
            new SearchPrimaryCondition(
                SearchConditionBuilder::create($this->getFieldSet())
                    ->field('status')
                        ->addSimpleValue(1)
                        ->addSimpleValue(2)
                    ->end()
                ->getSearchCondition()
                ->getValuesGroup()
            )
        );

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('WHERE ((I.status IN(1, 2))) AND ((I.customer IN(2, 5)))', $conditionGenerator->getWhereClause('WHERE '));
    }

    public function testEmptyQueryWithPrependAndPrimaryCond()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('id')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $condition->setPrimaryCondition(
            new SearchPrimaryCondition(
                SearchConditionBuilder::create($this->getFieldSet())
                    ->field('status')
                        ->addSimpleValue(1)
                        ->addSimpleValue(2)
                    ->end()
                ->getSearchCondition()
                ->getValuesGroup()
            )
        );

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('WHERE ((I.status IN(1, 2)))', $conditionGenerator->getWhereClause('WHERE '));
    }

    public function testQueryWithMultipleFields()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
            ->field('status')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('((I.customer IN(2, 5)) AND (I.status IN(2, 5)))', $conditionGenerator->getWhereClause());
    }

    public function testQueryWithCombinedField()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        $conditionGenerator->setField('customer#1', 'id');
        $conditionGenerator->setField('customer#2', 'number2');

        $this->assertEquals('(((id IN(2, 5) OR number2 IN(2, 5))))', $conditionGenerator->getWhereClause());
    }

    public function testQueryWithCombinedFieldAndCustomAlias()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        $conditionGenerator->setField('customer#1', 'id');
        $conditionGenerator->setField('customer#2', 'number2', 'C', 'string');

        $this->assertEquals('(((id IN(2, 5) OR C.number2 IN(2, 5))))', $conditionGenerator->getWhereClause());
    }

    public function testEmptyResult()
    {
        $connection = $this->getConnectionMock();
        $condition = new SearchCondition($this->getFieldSet(), new ValuesGroup());
        $conditionGenerator = $this->getConditionGenerator($condition, $connection);

        $this->assertEquals('', $conditionGenerator->getWhereClause());
    }

    public function testExcludes()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addExcludedSimpleValue(2)
                ->addExcludedSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('((I.customer NOT IN(2, 5)))', $conditionGenerator->getWhereClause());
    }

    public function testIncludesAndExcludes()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addExcludedSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('((I.customer IN(2) AND I.customer NOT IN(5)))', $conditionGenerator->getWhereClause());
    }

    public function testRanges()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Range(2, 5))
                ->add(new Range(10, 20))
                ->add(new Range(60, 70, false))
                ->add(new Range(100, 150, true, false))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '((((I.customer >= 2 AND I.customer <= 5) OR (I.customer >= 10 AND I.customer <= 20) OR '.
            '(I.customer > 60 AND I.customer <= 70) OR (I.customer >= 100 AND I.customer < 150))))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testExcludedRanges()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new ExcludedRange(2, 5))
                ->add(new ExcludedRange(10, 20))
                ->add(new ExcludedRange(60, 70, false))
                ->add(new ExcludedRange(100, 150, true, false))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '((((I.customer <= 2 OR I.customer >= 5) AND (I.customer <= 10 OR I.customer >= 20) AND '.
            '(I.customer < 60 OR I.customer >= 70) AND (I.customer <= 100 OR I.customer > 150))))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testSingleComparison()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '>'))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals('((I.customer > 2))', $conditionGenerator->getWhereClause());
    }

    public function testMultipleComparisons()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '>'))
                ->add(new Compare(10, '<'))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '(((I.customer > 2 AND I.customer < 10)))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testMultipleComparisonsWithGroups()
    {
        // Use two subgroups here as the comparisons are AND to each other
        // but applying them in the head group would ignore subgroups
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->field('customer')
                    ->add(new Compare(2, '>'))
                    ->add(new Compare(10, '<'))
                    ->addSimpleValue(20)
                ->end()
            ->end()
            ->group()
                ->field('customer')
                    ->add(new Compare(30, '>'))
                ->end()
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '((((I.customer IN(20) OR (I.customer > 2 AND I.customer < 10)))) OR ((I.customer > 30)))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testExcludingComparisons()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(2, '<>'))
                ->add(new Compare(5, '<>'))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '((I.customer <> 2 AND I.customer <> 5))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testExcludingComparisonsWithNormal()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->add(new Compare(35, '<>'))
                ->add(new Compare(45, '<>'))
                ->add(new Compare(30, '>'))
                ->add(new Compare(50, '<'))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '(((I.customer > 30 AND I.customer < 50) AND I.customer <> 35 AND I.customer <> 45))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testPatternMatchers()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer_name')
                ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                ->add(new PatternMatch('fo\\\'o', PatternMatch::PATTERN_STARTS_WITH))
                ->add(new PatternMatch('bar', PatternMatch::PATTERN_NOT_ENDS_WITH, true))
                ->add(new PatternMatch('My name', PatternMatch::PATTERN_EQUALS))
                ->add(new PatternMatch('Last', PatternMatch::PATTERN_NOT_EQUALS))
                ->add(new PatternMatch('Spider', PatternMatch::PATTERN_EQUALS, true))
                ->add(new PatternMatch('Piggy', PatternMatch::PATTERN_NOT_EQUALS, true))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            "(((C.name LIKE '%foo' ESCAPE '\\' OR C.name LIKE '%fo\\'o' ESCAPE '\\' OR C.name = 'My name' OR ".
            "LOWER(C.name) = LOWER('Spider')) AND (LOWER(C.name) NOT LIKE LOWER('bar%') ESCAPE '\\' AND C.name <> 'Last' ".
            "AND LOWER(C.name) <> LOWER('Piggy'))))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testSubGroups()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->field('customer')->addSimpleValue(2)->end()
            ->end()
            ->group()
                ->field('customer')->addSimpleValue(3)->end()
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            '(((I.customer IN(2))) OR ((I.customer IN(3))))',
            $conditionGenerator->getWhereClause()
        );
    }

    public function testSubGroupWithRootCondition()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
            ->group()
                ->field('customer_name')
                    ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                ->end()
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            "(((I.customer IN(2))) AND (((C.name LIKE '%foo' ESCAPE '\\'))))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testOrGroupRoot()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet(), ValuesGroup::GROUP_LOGICAL_OR)
            ->field('customer')
                ->addSimpleValue(2)
            ->end()
            ->field('customer_name')
                ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            "((I.customer IN(2)) OR (C.name LIKE '%foo' ESCAPE '\\'))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testSubOrGroup()
    {
        $condition = SearchConditionBuilder::create($this->getFieldSet())
            ->group()
                ->group(ValuesGroup::GROUP_LOGICAL_OR)
                    ->field('customer')
                        ->addSimpleValue(2)
                    ->end()
                    ->field('customer_name')
                        ->add(new PatternMatch('foo', PatternMatch::PATTERN_STARTS_WITH))
                    ->end()
                ->end()
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);

        $this->assertEquals(
            "((((I.customer IN(2)) OR (C.name LIKE '%foo' ESCAPE '\\'))))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testColumnConversion()
    {
        $converter = $this->createMock(ColumnConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->willReturnCallback(function ($column, array $options, ConversionHints $hints) {
                self::assertArrayHasKey('grouping', $options);
                self::assertTrue($options['grouping']);

                self::assertEquals('I', $hints->field->alias);
                self::assertEquals('I.customer', $hints->column);

                return "CAST($column AS customer_type)";
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, ['grouping' => true, 'doctrine_dbal_conversion' => $converter]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        self::assertEquals('((CAST(I.customer AS customer_type) IN(2, 5)))', $conditionGenerator->getWhereClause());
    }

    public function testValueConversion()
    {
        $converter = $this->createMock(ValueConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->willReturnCallback(function ($value, array $options) {
                self::assertArrayHasKey('grouping', $options);
                self::assertTrue($options['grouping']);

                return "get_customer_type($value)";
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, ['grouping' => true, 'doctrine_dbal_conversion' => $converter]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        self::assertEquals('(((I.customer = get_customer_type(2) OR I.customer = get_customer_type(5))))', $conditionGenerator->getWhereClause());
    }

    public function testConversionStrategyValue()
    {
        $converter = $this->createMock(ValueConversionStrategy::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('getConversionStrategy')
            ->willReturnCallback(function ($value) {
                if (!$value instanceof \DateTime && !\is_int($value)) {
                    throw new \InvalidArgumentException('Only integer/string and DateTime are accepted.');
                }

                if ($value instanceof \DateTime) {
                    return 2;
                }

                return 1;
            })
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertValue')
            ->willReturnCallback(function ($value, array $passedOptions, ConversionHints $hints) {
                self::assertArrayHasKey('pattern', $passedOptions);
                self::assertEquals('dd-MM-yy', $passedOptions['pattern']);

                if ($value instanceof \DateTime) {
                    self::assertEquals(2, $hints->conversionStrategy);

                    return 'CAST('.$hints->connection->quote($value->format('Y-m-d')).' AS AGE)';
                }

                self::assertEquals(1, $hints->conversionStrategy);

                return $value;
            })
        ;

        $fieldSet = $this->getFieldSet(false);
        $fieldSet->add('customer_birthday', DateType::class, ['doctrine_dbal_conversion' => $converter, 'pattern' => 'dd-MM-yy']);

        $condition = SearchConditionBuilder::create($fieldSet->getFieldSet())
            ->field('customer_birthday')
                ->addSimpleValue(18)
                ->addSimpleValue(new \DateTime('2001-01-15', new \DateTimeZone('UTC')))
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        self::assertEquals(
            "(((C.birthday = 18 OR C.birthday = CAST('2001-01-15' AS AGE))))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testConversionStrategyColumn()
    {
        $converter = $this->createMock(ColumnConversionStrategy::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('getConversionStrategy')
            ->willReturnCallback(function ($value) {
                if (!\is_string($value) && !\is_int($value)) {
                    throw new \InvalidArgumentException('Only integer/string is accepted.');
                }

                if (\is_string($value)) {
                    return 2;
                }

                return 1;
            })
        ;

        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->willReturnCallback(function ($column, array $options, ConversionHints $hints) {
                if (2 === $hints->conversionStrategy) {
                    return "search_conversion_age($column)";
                }

                self::assertEquals(1, $hints->conversionStrategy);

                return $column;
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer_birthday', TextType::class, ['doctrine_dbal_conversion' => $converter]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer_birthday')
                ->addSimpleValue(18)
                ->addSimpleValue('2001-01-15')
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        $conditionGenerator->setField('customer_birthday', 'birthday', 'C', 'string');

        self::assertEquals(
            "(((C.birthday = 18 OR search_conversion_age(C.birthday) = '2001-01-15')))",
            $conditionGenerator->getWhereClause()
        );
    }

    public function testLazyConversionLoading()
    {
        $converter = $this->createMock(ColumnConversion::class);
        $converter
            ->expects($this->atLeastOnce())
            ->method('convertColumn')
            ->willReturnCallback(function ($column, array $options, ConversionHints $hints) {
                self::assertArrayHasKey('grouping', $options);
                self::assertTrue($options['grouping']);
                self::assertEquals('I', $hints->field->alias);
                self::assertEquals('I.customer', $hints->column);

                return "CAST($column AS customer_type)";
            })
        ;

        $fieldSetBuilder = $this->getFieldSet(false);
        $fieldSetBuilder->add('customer', IntegerType::class, [
            'grouping' => true,
            'doctrine_dbal_conversion' => function () use ($converter) {
                return $converter;
            },
        ]);

        $condition = SearchConditionBuilder::create($fieldSetBuilder->getFieldSet())
            ->field('customer')
                ->addSimpleValue(2)
                ->addSimpleValue(5)
            ->end()
        ->getSearchCondition();

        $conditionGenerator = $this->getConditionGenerator($condition);
        self::assertEquals('((CAST(I.customer AS customer_type) IN(2, 5)))', $conditionGenerator->getWhereClause());
    }
}
