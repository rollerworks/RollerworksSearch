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

namespace Rollerworks\Component\Search\Tests\Extension\Core\Type;

use Rollerworks\Component\Search\Extension\Core\Type\DateType;
use Rollerworks\Component\Search\FieldSetView;
use Rollerworks\Component\Search\Test\FieldTransformationAssertion;
use Rollerworks\Component\Search\Test\SearchIntegrationTestCase;
use Symfony\Component\Intl\Util\IntlTestHelper;

/**
 * @internal
 */
final class DateTypeTest extends SearchIntegrationTestCase
{
    public function testPatternCanBeConfigured()
    {
        $field = $this->getFactory()->createField('datetime', DateType::class, [
            'pattern' => 'MM*yyyy*dd',
        ]);

        $outputTime = new \DateTime('2010-06-02T00:00:00.000000+0000');

        FieldTransformationAssertion::assertThat($field)
            ->withInput($outputTime->format('m*Y*d'), $outputTime->format('Y-m-d'))
            ->successfullyTransformsTo($outputTime)
            ->andReverseTransformsTo('06*2010*02', '2010-06-02');
    }

    public function testInvalidInputShouldFailTransformation()
    {
        $field = $this->getFactory()->createField('datetime', DateType::class, [
            'pattern' => 'MM-yyyy-dd',
        ]);

        FieldTransformationAssertion::assertThat($field)
            ->withInput('06*2010*02', '2010-06-02')
            ->failsToTransforms();

        FieldTransformationAssertion::assertThat($field)
            ->withInput('06-2010-02', '2010-06*02')
            ->failsToTransforms();
    }

    public function testViewIsConfiguredProperlyWithoutExplicitPattern()
    {
        $field = $this->getFactory()->createField('datetime', DateType::class, [
            'format' => \IntlDateFormatter::SHORT,
        ]);

        $field->finalizeConfig();
        $fieldView = $field->createView(new FieldSetView());

        self::assertArrayHasKey('timezone', $fieldView->vars);
        self::assertArrayHasKey('pattern', $fieldView->vars);

        self::assertEquals(date_default_timezone_get(), $fieldView->vars['timezone']);
        self::assertEquals('M/d/yy', $fieldView->vars['pattern']);
    }

    public function testViewIsConfiguredProperly()
    {
        $field = $this->getFactory()->createField('datetime', DateType::class, [
            'pattern' => 'MM-yyyy-dd',
        ]);

        $field->finalizeConfig();
        $fieldView = $field->createView(new FieldSetView());

        self::assertArrayHasKey('timezone', $fieldView->vars);
        self::assertArrayHasKey('pattern', $fieldView->vars);

        self::assertEquals(date_default_timezone_get(), $fieldView->vars['timezone']);
        self::assertEquals('MM-yyyy-dd', $fieldView->vars['pattern']);
    }

    protected function setUp(): void
    {
        IntlTestHelper::requireIntl($this, '58.1');

        parent::setUp();
    }
}
