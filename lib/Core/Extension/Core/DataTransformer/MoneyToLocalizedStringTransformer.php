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

namespace Rollerworks\Component\Search\Extension\Core\DataTransformer;

use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Formatter\IntlMoneyFormatter;
use Money\Parser\IntlMoneyParser;
use Rollerworks\Component\Search\Exception\TransformationFailedException;
use Rollerworks\Component\Search\Extension\Core\Model\MoneyValue;

/**
 * Transforms between a normalized format and a localized money string.
 *
 * @author Sebastiaan Stok <s.stok@rollerscapes.net>
 */
final class MoneyToLocalizedStringTransformer extends NumberToLocalizedStringTransformer
{
    private $defaultCurrency;

    private static $patterns = [];

    public function __construct(string $defaultCurrency, bool $grouping = false)
    {
        $this->defaultCurrency = $defaultCurrency;
        $this->grouping = $grouping;
    }

    /**
     * Transforms a normalized format into a localized money string.
     *
     * @param MoneyValue|null $value Normalized number
     *
     * @throws TransformationFailedException If the given value is not numeric or
     *                                       if the value can not be transformed
     */
    public function transform($value): ?string
    {
        if ($value === null) {
            return '';
        }

        if (! $value instanceof MoneyValue) {
            throw new TransformationFailedException('Expected a MoneyValue object.');
        }

        $result = (new IntlMoneyFormatter($this->getNumberFormatter(), new ISOCurrencies()))->format($value->value);

        // Convert fixed spaces to normal ones
        $result = \str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $result);

        if (! $value->withCurrency) {
            $result = $this->removeCurrencySymbol($result, (string) $value->value->getCurrency());
        }

        return $result;
    }

    /**
     * Transforms a localized number into an integer or float.
     *
     * @param string|null $value The localized value
     *
     * @throws TransformationFailedException if the given value is not a string
     *                                       or if the value can not be transformed
     */
    public function reverseTransform($value): ?MoneyValue
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! \is_string($value)) {
            throw new TransformationFailedException('Expected a string or null.');
        }

        if ($value === 'NaN') {
            throw new TransformationFailedException('"NaN" is not a valid number');
        }

        if (\mb_strpos($value, '∞') !== false) {
            throw new TransformationFailedException('I don\'t have a clear idea what infinity looks like.');
        }

        // Convert normal spaces to fixed spaces.
        $value = \str_replace(' ', "\xc2\xa0", $value);

        $formatter = $this->getNumberFormatter();

        $groupSep = $formatter->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
        $decSep = $formatter->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
        $withCurrency = (bool) \preg_match('#\p{Sc}#u', $value);

        // Some locales use the space as group separation.
        // The ICU data confirms this, but since v58.1 this can no longer be parsed
        // in the currency format. Unless you use DECIMAL which doesn't work
        // for currency. So... simple remove the spaces between numbers.
        $value = \preg_replace("/(\\p{N})\xc2\xa0(\\p{N})/u", '$1$2', $value);

        if ($decSep !== '.' && (! $this->grouping || $groupSep !== '.')) {
            $value = \str_replace('.', $decSep, $value);
        }

        if ($decSep !== ',' && (! $this->grouping || $groupSep !== ',')) {
            $value = \str_replace(',', $decSep, $value);
        }

        if (! $withCurrency) {
            $value = $this->addCurrencySymbol($value);
        }

        try {
            $money = (new IntlMoneyParser($formatter, new ISOCurrencies()))->parse($value);

            return new MoneyValue($money, $withCurrency);
        } catch (ParserException $e) {
            throw new TransformationFailedException($e->getMessage(), 0, $e);
        } catch (\Exception $e) {
            throw new TransformationFailedException($e->getMessage(), 0, $e);
        }
    }

    protected function getNumberFormatter(): \NumberFormatter
    {
        $formatter = new \NumberFormatter(\Locale::getDefault(), \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::GROUPING_USED, $this->grouping ? 1 : 0);

        return $formatter;
    }

    /**
     * Adds the currency symbol when missing.
     *
     * ICU cannot parse() without a currency,
     * and decimal doesn't include scale when 0.
     */
    private function addCurrencySymbol(string $value, ?string $currency = null): string
    {
        $currency = $currency ?? $this->defaultCurrency;
        $locale = \Locale::getDefault();

        if (! isset(self::$patterns[$locale])) {
            self::$patterns[$locale] = [];
        }

        if (! isset(self::$patterns[$locale][$currency])) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $pattern = $formatter->formatCurrency(123.00, $currency);

            // 1=left-position currency, 2=left-space, 3=right-space, 4=left-position currency.
            // With non latin number scripts.
            \preg_match(
                '/^([^\s\xc2\xa0]*)([\s\xc2\xa0]*)\p{N}{3}(?:[,.]\p{N}+)?([\s\xc2\xa0]*)([^\s\xc2\xa0]*)$/iu',
                $pattern,
                $matches
            );

            if (! empty($matches[1])) {
                self::$patterns[$locale][$currency] = ['%1$s' . $matches[2] . '%2$s', $matches[1]];
            } elseif (! empty($matches[4])) {
                self::$patterns[$locale][$currency] = ['%2$s' . $matches[3] . '%1$s', $matches[4]];
            } else {
                throw new \InvalidArgumentException(
                    \sprintf('Locale "%s" with currency "%s" does not provide a currency position.', $locale, $currency)
                );
            }
        }

        return \sprintf(self::$patterns[$locale][$currency][0], self::$patterns[$locale][$currency][1], $value);
    }

    /**
     * Removes the currency symbol.
     *
     * ICU cannot format() with currency, as this
     * produces a number with the `¤` symbol.
     * And, decimal doesn't include scale when 0.
     */
    private function removeCurrencySymbol(string $value, string $currency): string
    {
        $locale = \Locale::getDefault();

        if (! isset(self::$patterns[$locale][$currency])) {
            // Initialize the cache, ignore return.
            $this->addCurrencySymbol('123', $currency);
        }

        return \preg_replace('#(\s?' . \preg_quote(self::$patterns[$locale][$currency][1], '#') . '\s?)#u', '', $value);
    }
}
