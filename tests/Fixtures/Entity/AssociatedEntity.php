<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity;

final class AssociatedEntity
{
    /**
     * @var int
     */
    private $plainField;

    /**
     * @var Embeddable\EmbeddedEntity
     */
    private $embeddedEntity;

    public function __construct(Embeddable\EmbeddedEntity $embeddedEntity, int $plainField)
    {
        $this->embeddedEntity = $embeddedEntity;
        $this->plainField = $plainField;
    }

    /**
     * @return int
     */
    public function getPlainField()
    {
        return $this->plainField;
    }
}
