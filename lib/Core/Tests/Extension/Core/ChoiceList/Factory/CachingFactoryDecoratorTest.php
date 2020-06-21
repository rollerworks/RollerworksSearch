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

namespace Rollerworks\Component\Search\Tests\Extension\Core\ChoiceList\Factory;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\ChoiceList;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\Factory\CachingFactoryDecorator;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\Factory\ChoiceListFactory;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\Loader\ChoiceLoader;
use Rollerworks\Component\Search\Extension\Core\ChoiceList\View\ChoiceListView;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 *
 * @internal
 */
final class CachingFactoryDecoratorTest extends TestCase
{
    /**
     * @var MockObject|null
     */
    private $decoratedFactory;

    /**
     * @var CachingFactoryDecorator|null
     */
    private $factory;

    protected function setUp(): void
    {
        $this->decoratedFactory = $this->createMock(ChoiceListFactory::class);
        $this->factory = new CachingFactoryDecorator($this->decoratedFactory);
    }

    public function testCreateFromChoicesEmpty()
    {
        $list = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromChoices')
            ->with([])
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromChoices([]));
        self::assertSame($list, $this->factory->createListFromChoices([]));
    }

    public function testCreateFromChoicesComparesTraversableChoicesAsArray()
    {
        // The top-most traversable is converted to an array
        $choices1 = new \ArrayIterator(['A' => 'a']);
        $choices2 = ['A' => 'a'];
        $list = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromChoices')
            ->with($choices2)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromChoices($choices1));
        self::assertSame($list, $this->factory->createListFromChoices($choices2));
    }

    public function testCreateFromChoicesFlattensChoices()
    {
        $choices1 = ['key' => ['A' => 'a']];
        $choices2 = ['A' => 'a'];
        $list = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromChoices')
            ->with($choices1)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromChoices($choices1));
        self::assertSame($list, $this->factory->createListFromChoices($choices2));
    }

    /**
     * @dataProvider provideSameChoices
     */
    public function testCreateFromChoicesSameChoices($choice1, $choice2)
    {
        $choices1 = [$choice1];
        $choices2 = [$choice2];
        $list = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromChoices')
            ->with($choices1)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromChoices($choices1));
        self::assertSame($list, $this->factory->createListFromChoices($choices2));
    }

    /**
     * @dataProvider provideDistinguishedChoices
     */
    public function testCreateFromChoicesDifferentChoices($choice1, $choice2)
    {
        $choices1 = [$choice1];
        $choices2 = [$choice2];
        $list1 = $this->createMock(ChoiceList::class);
        $list2 = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->at(0))
            ->method('createListFromChoices')
            ->with($choices1)
            ->willReturn($list1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createListFromChoices')
            ->with($choices2)
            ->willReturn($list2);

        self::assertSame($list1, $this->factory->createListFromChoices($choices1));
        self::assertSame($list2, $this->factory->createListFromChoices($choices2));
    }

    public function testCreateFromChoicesSameValueClosure()
    {
        $choices = [1];
        $list = $this->createMock(ChoiceList::class);
        $closure = function () {
        };

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromChoices')
            ->with($choices, $closure)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromChoices($choices, $closure));
        self::assertSame($list, $this->factory->createListFromChoices($choices, $closure));
    }

    public function testCreateFromChoicesDifferentValueClosure()
    {
        $choices = [1];
        $list1 = $this->createMock(ChoiceList::class);
        $list2 = $this->createMock(ChoiceList::class);
        $closure1 = function () {
        };
        $closure2 = function () {
        };

        $this->decoratedFactory->expects($this->at(0))
            ->method('createListFromChoices')
            ->with($choices, $closure1)
            ->willReturn($list1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createListFromChoices')
            ->with($choices, $closure2)
            ->willReturn($list2);

        self::assertSame($list1, $this->factory->createListFromChoices($choices, $closure1));
        self::assertSame($list2, $this->factory->createListFromChoices($choices, $closure2));
    }

    public function testCreateFromLoaderSameLoader()
    {
        $loader = $this->createMock(ChoiceLoader::class);
        $list = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromLoader')
            ->with($loader)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromLoader($loader));
        self::assertSame($list, $this->factory->createListFromLoader($loader));
    }

    public function testCreateFromLoaderDifferentLoader()
    {
        $loader1 = $this->createMock(ChoiceLoader::class);
        $loader2 = $this->createMock(ChoiceLoader::class);
        $list1 = $this->createMock(ChoiceList::class);
        $list2 = $this->createMock(ChoiceList::class);

        $this->decoratedFactory->expects($this->at(0))
            ->method('createListFromLoader')
            ->with($loader1)
            ->willReturn($list1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createListFromLoader')
            ->with($loader2)
            ->willReturn($list2);

        self::assertSame($list1, $this->factory->createListFromLoader($loader1));
        self::assertSame($list2, $this->factory->createListFromLoader($loader2));
    }

    public function testCreateFromLoaderSameValueClosure()
    {
        $loader = $this->createMock(ChoiceLoader::class);
        $list = $this->createMock(ChoiceList::class);
        $closure = function () {
        };

        $this->decoratedFactory->expects($this->once())
            ->method('createListFromLoader')
            ->with($loader, $closure)
            ->willReturn($list);

        self::assertSame($list, $this->factory->createListFromLoader($loader, $closure));
        self::assertSame($list, $this->factory->createListFromLoader($loader, $closure));
    }

    public function testCreateFromLoaderDifferentValueClosure()
    {
        $loader = $this->createMock(ChoiceLoader::class);
        $list1 = $this->createMock(ChoiceList::class);
        $list2 = $this->createMock(ChoiceList::class);
        $closure1 = function () {
        };
        $closure2 = function () {
        };

        $this->decoratedFactory->expects($this->at(0))
            ->method('createListFromLoader')
            ->with($loader, $closure1)
            ->willReturn($list1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createListFromLoader')
            ->with($loader, $closure2)
            ->willReturn($list2);

        self::assertSame($list1, $this->factory->createListFromLoader($loader, $closure1));
        self::assertSame($list2, $this->factory->createListFromLoader($loader, $closure2));
    }

    public function testCreateViewSamePreferredChoices()
    {
        $preferred = ['a'];
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, $preferred)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, $preferred));
        self::assertSame($view, $this->factory->createView($list, $preferred));
    }

    public function testCreateViewDifferentPreferredChoices()
    {
        $preferred1 = ['a'];
        $preferred2 = ['b'];
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, $preferred1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, $preferred2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, $preferred1));
        self::assertSame($view2, $this->factory->createView($list, $preferred2));
    }

    public function testCreateViewSamePreferredChoicesClosure()
    {
        $preferred = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, $preferred)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, $preferred));
        self::assertSame($view, $this->factory->createView($list, $preferred));
    }

    public function testCreateViewDifferentPreferredChoicesClosure()
    {
        $preferred1 = function () {
        };
        $preferred2 = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, $preferred1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, $preferred2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, $preferred1));
        self::assertSame($view2, $this->factory->createView($list, $preferred2));
    }

    public function testCreateViewSameLabelClosure()
    {
        $labels = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, null, $labels)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, null, $labels));
        self::assertSame($view, $this->factory->createView($list, null, $labels));
    }

    public function testCreateViewDifferentLabelClosure()
    {
        $labels1 = function () {
        };
        $labels2 = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, null, $labels1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, null, $labels2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, null, $labels1));
        self::assertSame($view2, $this->factory->createView($list, null, $labels2));
    }

    public function testCreateViewSameIndexClosure()
    {
        $index = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, null, null, $index)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, null, null, $index));
        self::assertSame($view, $this->factory->createView($list, null, null, $index));
    }

    public function testCreateViewDifferentIndexClosure()
    {
        $index1 = function () {
        };
        $index2 = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, null, null, $index1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, null, null, $index2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, null, null, $index1));
        self::assertSame($view2, $this->factory->createView($list, null, null, $index2));
    }

    public function testCreateViewSameGroupByClosure()
    {
        $groupBy = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, null, null, null, $groupBy)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, null, null, null, $groupBy));
        self::assertSame($view, $this->factory->createView($list, null, null, null, $groupBy));
    }

    public function testCreateViewDifferentGroupByClosure()
    {
        $groupBy1 = function () {
        };
        $groupBy2 = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, null, null, null, $groupBy1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, null, null, null, $groupBy2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, null, null, null, $groupBy1));
        self::assertSame($view2, $this->factory->createView($list, null, null, null, $groupBy2));
    }

    public function testCreateViewSameAttributes()
    {
        $attr = ['class' => 'foobar'];
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, null, null, null, null, $attr)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, null, null, null, null, $attr));
        self::assertSame($view, $this->factory->createView($list, null, null, null, null, $attr));
    }

    public function testCreateViewDifferentAttributes()
    {
        $attr1 = ['class' => 'foobar1'];
        $attr2 = ['class' => 'foobar2'];
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, null, null, null, null, $attr1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, null, null, null, null, $attr2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, null, null, null, null, $attr1));
        self::assertSame($view2, $this->factory->createView($list, null, null, null, null, $attr2));
    }

    public function testCreateViewSameAttributesClosure()
    {
        $attr = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view = new ChoiceListView();

        $this->decoratedFactory->expects($this->once())
            ->method('createView')
            ->with($list, null, null, null, null, $attr)
            ->willReturn($view);

        self::assertSame($view, $this->factory->createView($list, null, null, null, null, $attr));
        self::assertSame($view, $this->factory->createView($list, null, null, null, null, $attr));
    }

    public function testCreateViewDifferentAttributesClosure()
    {
        $attr1 = function () {
        };
        $attr2 = function () {
        };
        $list = $this->createMock(ChoiceList::class);
        $view1 = new ChoiceListView();
        $view2 = new ChoiceListView();

        $this->decoratedFactory->expects($this->at(0))
            ->method('createView')
            ->with($list, null, null, null, null, $attr1)
            ->willReturn($view1);
        $this->decoratedFactory->expects($this->at(1))
            ->method('createView')
            ->with($list, null, null, null, null, $attr2)
            ->willReturn($view2);

        self::assertSame($view1, $this->factory->createView($list, null, null, null, null, $attr1));
        self::assertSame($view2, $this->factory->createView($list, null, null, null, null, $attr2));
    }

    public function provideSameChoices()
    {
        $object = (object) ['foo' => 'bar'];

        return [
            [0, 0],
            ['a', 'a'],
            // https://github.com/symfony/symfony/issues/10409
            [\chr(181).'meter', \chr(181).'meter'], // UTF-8
            [$object, $object],
        ];
    }

    public function provideDistinguishedChoices()
    {
        return [
            [0, false],
            [0, null],
            [0, '0'],
            [0, ''],
            [1, true],
            [1, '1'],
            [1, 'a'],
            ['', false],
            ['', null],
            [false, null],
            // Same properties, but not identical
            [(object) ['foo' => 'bar'], (object) ['foo' => 'bar']],
        ];
    }

    public function provideSameKeyChoices()
    {
        // Only test types here that can be used as array keys
        return [
            [0, 0],
            [0, '0'],
            ['a', 'a'],
            [\chr(181).'meter', \chr(181).'meter'],
        ];
    }

    public function provideDistinguishedKeyChoices()
    {
        // Only test types here that can be used as array keys
        return [
            [0, ''],
            [1, 'a'],
            ['', 'a'],
        ];
    }
}
