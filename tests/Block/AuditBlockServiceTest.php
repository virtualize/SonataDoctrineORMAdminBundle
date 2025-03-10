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

namespace Sonata\DoctrineORMAdminBundle\Tests\Block;

use PHPUnit\Framework\MockObject\MockObject;
use SimpleThings\EntityAudit\AuditReader as SimpleThingsAuditReader;
use SimpleThings\EntityAudit\Revision;
use Sonata\BlockBundle\Block\BlockContextInterface;
use Sonata\BlockBundle\Model\Block;
use Sonata\BlockBundle\Test\BlockServiceTestCase;
use Sonata\DoctrineORMAdminBundle\Block\AuditBlockService;

/**
 * @author Marko Kunic <kunicmarko20@gmail.com>
 */
final class AuditBlockServiceTest extends BlockServiceTestCase
{
    /**
     * @var SimpleThingsAuditReader&MockObject
     */
    private $simpleThingsAuditReader;

    /**
     * @var AuditBlockService
     */
    private $blockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->simpleThingsAuditReader = $this->createMock(SimpleThingsAuditReader::class);

        $this->blockService = new AuditBlockService(
            $this->twig,
            $this->simpleThingsAuditReader
        );
    }

    public function testExecute(): void
    {
        $blockContext = $this->createMock(BlockContextInterface::class);

        $blockContext->expects(self::once())->method('getBlock')->willReturn($block = new Block());
        $blockContext->expects(self::once())->method('getSetting')->with('limit')->willReturn($limit = 10);

        $this->simpleThingsAuditReader
            ->expects(self::once())
            ->method('findRevisionHistory')
            ->with($limit, 0)
            ->willReturn([$revision = new Revision('test', new \DateTime(), 'test')]);

        $this->simpleThingsAuditReader
            ->expects(self::once())
            ->method('findEntitiesChangedAtRevision')
            ->willReturn([]);

        $blockContext->expects(self::once())->method('getTemplate')->willReturn('template');
        $blockContext->expects(self::once())->method('getSettings')->willReturn([]);

        $this->twig
            ->expects(self::once())
            ->method('render')
            ->with('template', [
                'block' => $block,
                'settings' => [],
                'revisions' => [['revision' => $revision, 'entities' => []]],
            ])
            ->willReturn('content');

        $response = $this->blockService->execute($blockContext);

        self::assertSame('content', $response->getContent());
    }

    public function testDefaultSettings(): void
    {
        $blockContext = $this->getBlockContext($this->blockService);

        self::assertSettings([
            'attr' => [],
            'extra_cache_keys' => [],
            'limit' => 10,
            'template' => '@SonataDoctrineORMAdmin/Block/block_audit.html.twig',
            'ttl' => 0,
            'use_cache' => true,
        ], $blockContext);
    }
}
