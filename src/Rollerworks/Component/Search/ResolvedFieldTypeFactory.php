<?php

/*
 * This file is part of the Rollerworks Search Component package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Rollerworks\Component\Search;

class ResolvedFieldTypeFactory implements ResolvedFieldTypeFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function createResolvedType(FieldTypeInterface $type, array $typeExtensions, ResolvedFieldTypeInterface $parent = null)
    {
        return new ResolvedFieldType($type, $typeExtensions, $parent);
    }
}
