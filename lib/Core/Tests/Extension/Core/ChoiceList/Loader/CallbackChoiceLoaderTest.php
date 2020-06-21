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

namespace Rollerworks\Component\Search\Tests\Extension\Core\ChoiceList\Loader;

use PHPUnit\Framework\TestCase;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\LazyChoiceList;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\Loader\CallbackChoiceLoader;

/**
 * @author Jules Pietri <jules@heahprod.com>
 *
 * @internal
 */
final class CallbackChoiceLoaderTest extends TestCase
{
    /**
     * @var CallbackChoiceLoader|null
     */
    private static $loader;

    /**
     * @var callable|null
     */
    private static $value;

    /**
     * @var array|null
     */
    private static $choices;

    /**
     * @var string[]|null
     */
    private static $choiceValues;

    /**
     * @var LazyChoiceList|null
     */
    private static $lazyChoiceList;

    public static function setUpBeforeClass(): void
    {
        self::$loader = new CallbackChoiceLoader(function () {
            return self::$choices;
        });

        self::$value = function ($choice) {
            return isset($choice->value) ? $choice->value : null;
        };

        self::$choices = [
            (object) ['value' => 'choice_one'],
            (object) ['value' => 'choice_two'],
        ];

        self::$choiceValues = ['choice_one', 'choice_two'];
        self::$lazyChoiceList = new LazyChoiceList(self::$loader, self::$value);
    }

    public function testLoadChoiceListOnlyOnce()
    {
        $loadedChoiceList = self::$loader->loadChoiceList(self::$value);

        self::assertSame($loadedChoiceList, self::$loader->loadChoiceList(self::$value));
    }

    public function testLoadChoicesForValuesLoadsChoiceListOnFirstCall()
    {
        self::assertSame(
            self::$loader->loadChoicesForValues(self::$choiceValues, self::$value),
            self::$lazyChoiceList->getChoicesForValues(self::$choiceValues),
            'Choice list should not be reloaded.'
        );
    }

    public function testLoadValuesForChoicesLoadsChoiceListOnFirstCall()
    {
        self::assertSame(
            self::$loader->loadValuesForChoices(self::$choices, self::$value),
            self::$lazyChoiceList->getValuesForChoices(self::$choices),
            'Choice list should not be reloaded.'
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$loader = null;
        self::$value = null;
        self::$choices = [];
        self::$choiceValues = [];
        self::$lazyChoiceList = null;
    }
}
