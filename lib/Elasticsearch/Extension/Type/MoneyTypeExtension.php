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

namespace Rollerworks\Component\Search\Elasticsearch\Extension\Type;

use Rollerworks\Component\Search\Elasticsearch\Extension\Conversion\CurrencyConversion;
use Rollerworks\Component\Search\Extension\Core\Type\MoneyType;
use Rollerworks\Component\Search\Field\AbstractFieldTypeExtension;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Class MoneyTypeExtension.
 */
class MoneyTypeExtension extends AbstractFieldTypeExtension
{
    /**
     * @var CurrencyConversion
     */
    private $conversion;

    /**
     * @param CurrencyConversion $conversion
     */
    public function __construct(CurrencyConversion $conversion)
    {
        $this->conversion = $conversion;
    }

    /**
     * @throws \Symfony\Component\OptionsResolver\Exception\AccessException
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(
            [
                'elasticsearch_conversion' => $this->conversion,
            ]
        );
    }

    /**
     * Returns the name of the type being extended.
     *
     * @return string
     */
    public function getExtendedType(): string
    {
        return MoneyType::class;
    }
}
