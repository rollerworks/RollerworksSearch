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

namespace Rollerworks\Component\Search\Extension\Core\Type;

use Rollerworks\Component\Search\Extension\Core\DataTransformer\DateTimeToLocalizedStringTransformer;
use Rollerworks\Component\Search\FieldConfig;
use Rollerworks\Component\Search\SearchFieldView;
use Rollerworks\Component\Search\Value\Compare;
use Rollerworks\Component\Search\Value\Range;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class DateType extends BaseDateTimeType
{
    const DEFAULT_FORMAT = \IntlDateFormatter::MEDIUM;

    const HTML5_FORMAT = 'yyyy-MM-dd';

    /**
     * {@inheritdoc}
     */
    public function buildType(FieldConfig $config, array $options)
    {
        $config->setValueComparison($this->valueComparison);
        $config->setValueTypeSupport(Range::class, true);
        $config->setValueTypeSupport(Compare::class, true);

        if (null === $options['pattern']) {
            $this->validateFormat('format', $options['format']);
        } else {
            $this->validateDateFormat('pattern', $options['pattern']);
        }

        $config->setViewTransformer(
            new DateTimeToLocalizedStringTransformer(
                $options['model_timezone'],
                $options['view_timezone'],
                $options['format'],
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::GREGORIAN,
                $options['pattern']
            )
        );

        $config->setNormTransformer(
            new DateTimeToLocalizedStringTransformer(
                $options['model_timezone'],
                $options['view_timezone'],
                $options['format'],
                \IntlDateFormatter::NONE,
                \IntlDateFormatter::GREGORIAN,
                self::HTML5_FORMAT
            )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function buildView(SearchFieldView $view, FieldConfig $config, array $options)
    {
        $pattern = $options['pattern'];

        if (null === $pattern) {
            $pattern = \IntlDateFormatter::create(
                \Locale::getDefault(),
                $options['format'],
                \IntlDateFormatter::NONE,
                $options['view_timezone'],
                \IntlDateFormatter::GREGORIAN
            )->getPattern();
        }

        $view->vars['html5'] = $options['html5'];
        $view->vars['timezone'] = $options['view_timezone'] ?? date_default_timezone_get();
        $view->vars['pattern'] = $pattern;
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(
            [
                'model_timezone' => null,
                'view_timezone' => null,
                'pattern' => null,
                'format' => self::DEFAULT_FORMAT,
                'html5' => true,
            ]
        );

        $resolver->setAllowedTypes('format', ['int']);
        $resolver->setAllowedTypes('pattern', ['string', 'null']);
    }
}
