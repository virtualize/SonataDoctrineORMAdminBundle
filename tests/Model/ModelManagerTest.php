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

namespace Sonata\DoctrineORMAdminBundle\Tests\Model;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sonata\AdminBundle\Exception\LockException;
use Sonata\AdminBundle\Exception\ModelManagerException;
use Sonata\DoctrineORMAdminBundle\Datagrid\ProxyQuery;
use Sonata\DoctrineORMAdminBundle\Datagrid\ProxyQueryInterface;
use Sonata\DoctrineORMAdminBundle\Model\ModelManager;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\DoctrineType\ProductIdType;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\DoctrineType\UuidBinaryType;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\DoctrineType\UuidType;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\DoctrineType\ValueObjectWithMagicToStringImpl;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\DoctrineType\ValueObjectWithToStringImpl;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\AssociatedEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\ContainerEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\Embeddable\EmbeddedEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\Product;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\ProductId;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\SimpleEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\UuidBinaryEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\UuidEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Entity\VersionedEntity;
use Sonata\DoctrineORMAdminBundle\Tests\Fixtures\Util\NonIntegerIdentifierTestClass;
use Symfony\Component\PropertyAccess\PropertyAccess;

final class ModelManagerTest extends TestCase
{
    /**
     * @var ManagerRegistry&MockObject
     */
    private $registry;

    /**
     * @var ModelManager<object>
     */
    private $modelManager;

    public static function setUpBeforeClass(): void
    {
        if (!Type::hasType(UuidType::NAME)) {
            Type::addType(UuidType::NAME, UuidType::class);
        }
        if (!Type::hasType(UuidBinaryType::NAME)) {
            Type::addType(UuidBinaryType::NAME, UuidBinaryType::class);
        }
        if (!Type::hasType(ProductIdType::NAME)) {
            Type::addType(ProductIdType::NAME, ProductIdType::class);
        }
    }

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->modelManager = new ModelManager($this->registry, PropertyAccess::createPropertyAccessor());
    }

    /**
     * @phpstan-return iterable<array-key, array{class-string}>
     */
    public function valueObjectDataProvider(): iterable
    {
        return [
            'value object with toString implementation' => [ValueObjectWithToStringImpl::class],
            'value object with magic toString implementation' => [ValueObjectWithMagicToStringImpl::class],
        ];
    }

    /**
     * @param class-string $vbClassName
     *
     * @dataProvider valueObjectDataProvider
     */
    public function testGetIdentifierValuesWhenIdentifierIsValueObjectWithToStringMethod(string $vbClassName): void
    {
        $entity = new UuidBinaryEntity(new $vbClassName('a7ef873a-e7b5-11e9-81b4-2a2ae2dbcce4'));

        $platform = $this->createMock(MySqlPlatform::class);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->method('getIdentifierValues')->willReturn([$entity->getId()]);
        $classMetadata->method('getTypeOfField')->willReturn(UuidBinaryType::NAME);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $entityManager->method('getConnection')->willReturn($connection);

        $this->registry->method('getManagerForClass')->willReturn($entityManager);

        self::assertSame(
            ['a7ef873a-e7b5-11e9-81b4-2a2ae2dbcce4'],
            $this->modelManager->getIdentifierValues($entity)
        );
    }

    public function testInstantiateWithDeprecatedRegistryInterface(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->with(\stdClass::class)
            ->willReturn($em);
        self::assertSame($em, $this->modelManager->getEntityManager(\stdClass::class));
    }

    /**
     * @dataProvider supportsQueryDataProvider
     */
    public function testSupportsQuery(bool $expected, object $object): void
    {
        self::assertSame($expected, $this->modelManager->supportsQuery($object));
    }

    /**
     * @phpstan-return iterable<array-key, array{bool, object}>
     */
    public function supportsQueryDataProvider(): iterable
    {
        yield [true, new ProxyQuery($this->createMock(QueryBuilder::class))];
        yield [true, $this->createMock(QueryBuilder::class)];
        yield [false, new \stdClass()];
    }

    /**
     * @phpstan-return iterable<array-key, array{bool}>
     */
    public function getVersionDataProvider(): iterable
    {
        return [
            [true],
            [false],
        ];
    }

    /**
     * @dataProvider getVersionDataProvider
     */
    public function testGetVersion(bool $isVersioned): void
    {
        $object = new VersionedEntity();

        $this->setGetMetadataExpectation(\get_class($object), $this->getMetadata(\get_class($object), $isVersioned));

        if ($isVersioned) {
            $object->version = 123;

            self::assertNotNull($this->modelManager->getLockVersion($object));
        } else {
            self::assertNull($this->modelManager->getLockVersion($object));
        }
    }

    /**
     * @phpstan-return iterable<array-key, array{bool, bool}>
     */
    public function lockDataProvider(): iterable
    {
        return [
            [true,  false],
            [true,  true],
            [false, false],
        ];
    }

    /**
     * @dataProvider lockDataProvider
     */
    public function testLock(bool $isVersioned, bool $expectsException): void
    {
        $object = new VersionedEntity();

        $metadata = $this->getMetadata(\get_class($object), $isVersioned);

        $em = $this->setGetMetadataExpectation(\get_class($object), $metadata);

        $em->expects($isVersioned ? self::once() : self::never())
            ->method('lock');

        if ($expectsException) {
            $em->expects(self::once())
                ->method('lock')
                ->will(self::throwException(OptimisticLockException::lockFailed($object)));

            $this->expectException(LockException::class);
        }

        $this->modelManager->lock($object, 123);
    }

    public function testGetIdentifierValuesForIdInObjectTypeBinaryToStringSupport(): void
    {
        $uuid = new NonIntegerIdentifierTestClass('efbcfc4b-8c43-4d42-aa4c-d707e55151ac');

        $entity = new UuidEntity($uuid);

        $meta = $this->createMock(ClassMetadata::class);
        $meta->expects(self::any())
            ->method('getIdentifierValues')
            ->willReturn([$entity->getId()]);
        $meta->expects(self::any())
            ->method('getTypeOfField')
            ->willReturn(UuidBinaryType::NAME); //'uuid_binary'

        $platform = $this->createMock(PostgreSQL94Platform::class);
        $platform->expects(self::any())
            ->method('hasDoctrineTypeMappingFor')
            ->with(UuidBinaryType::NAME)
            ->willReturn(true);
        $platform->expects(self::any())
            ->method('getDoctrineTypeMapping')
            ->with(UuidBinaryType::NAME)
            ->willReturn('binary');

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::any())
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())
            ->method('getClassMetadata')
            ->willReturn($meta);
        $em->expects(self::any())
            ->method('getConnection')
            ->willReturn($conn);

        $this->registry->expects(self::any())
            ->method('getManagerForClass')
            ->willReturn($em);

        $result = $this->modelManager->getIdentifierValues($entity);

        self::assertSame($entity->getId()->toString(), $result[0]);
    }

    public function testNonIntegerIdentifierType(): void
    {
        $uuid = new NonIntegerIdentifierTestClass('efbcfc4b-8c43-4d42-aa4c-d707e55151ac');
        $entity = new UuidEntity($uuid);

        $meta = $this->createMock(ClassMetadata::class);
        $meta->expects(self::any())
            ->method('getIdentifierValues')
            ->willReturn([$entity->getId()]);
        $meta->expects(self::any())
            ->method('getTypeOfField')
            ->willReturn(UuidType::NAME);

        $platform = $this->createMock(PostgreSQL94Platform::class);
        $platform->expects(self::any())
            ->method('hasDoctrineTypeMappingFor')
            ->with(UuidType::NAME)
            ->willReturn(false);
        $platform->expects(self::never())
            ->method('getDoctrineTypeMapping');

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::any())
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())
            ->method('getClassMetadata')
            ->willReturn($meta);
        $em->expects(self::any())
            ->method('getConnection')
            ->willReturn($conn);

        $this->registry->expects(self::any())
            ->method('getManagerForClass')
            ->willReturn($em);

        $result = $this->modelManager->getIdentifierValues($entity);

        self::assertSame($entity->getId()->toString(), $result[0]);
    }

    public function testIntegerIdentifierType(): void
    {
        $id = new ProductId(12345);
        $entity = new Product($id, 'Some product');

        $meta = $this->createMock(ClassMetadata::class);
        $meta->expects(self::any())
            ->method('getIdentifierValues')
            ->willReturn([$entity->getId()]);
        $meta->expects(self::any())
            ->method('getTypeOfField')
            ->willReturn(ProductIdType::NAME);

        $platform = $this->createMock(PostgreSQL94Platform::class);
        $platform->expects(self::any())
            ->method('hasDoctrineTypeMappingFor')
            ->with(ProductIdType::NAME)
            ->willReturn(false);
        $platform->expects(self::never())
            ->method('getDoctrineTypeMapping');

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::any())
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())
            ->method('getClassMetadata')
            ->willReturn($meta);
        $em->expects(self::any())
            ->method('getConnection')
            ->willReturn($conn);

        $this->registry->expects(self::any())
            ->method('getManagerForClass')
            ->willReturn($em);

        $result = $this->modelManager->getIdentifierValues($entity);

        self::assertSame((string) $entity->getId()->getId(), $result[0]);
    }

    public function testAssociationIdentifierType(): void
    {
        $entity = new ContainerEntity(new AssociatedEntity(new EmbeddedEntity(), 42), new EmbeddedEntity());

        $meta = $this->createMock(ClassMetadata::class);
        $meta->expects(self::any())
            ->method('getIdentifierValues')
            ->willReturn([$entity->getAssociatedEntity()->getPlainField()]);
        $meta->expects(self::any())
            ->method('getTypeOfField')
            ->willReturn(null);

        $platform = $this->createMock(PostgreSQL94Platform::class);
        $platform->expects(self::never())
            ->method('hasDoctrineTypeMappingFor');

        $conn = $this->createMock(Connection::class);
        $conn->expects(self::any())
            ->method('getDatabasePlatform')
            ->willReturn($platform);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::any())
            ->method('getClassMetadata')
            ->willReturn($meta);
        $em->expects(self::any())
            ->method('getConnection')
            ->willReturn($conn);

        $this->registry->expects(self::any())
            ->method('getManagerForClass')
            ->willReturn($em);

        $result = $this->modelManager->getIdentifierValues($entity);

        self::assertSame(42, $result[0]);
    }

    public function testReverseTransform(): void
    {
        $object = new SimpleEntity();
        $class = SimpleEntity::class;

        $objectManager = $this->createMock(EntityManagerInterface::class);

        $classMetadata = new ClassMetadata($class);
        $classMetadata->reflClass = new \ReflectionClass($class);

        $objectManager->expects(self::once())
            ->method('getClassMetadata')
            ->with($class)
            ->willReturn($classMetadata);
        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->with($class)
            ->willReturn($objectManager);

        $this->modelManager->reverseTransform($object, [
            'schmeckles' => 42,
            'multi_word_property' => 'hello',
        ]);
        self::assertSame(42, $object->getSchmeckles());
        self::assertSame('hello', $object->getMultiWordProperty());
    }

    public function testGetEntityManagerException(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->modelManager->getEntityManager(VersionedEntity::class);
    }

    /**
     * @dataProvider createUpdateRemoveData
     */
    public function testCreate(\Throwable $exception): void
    {
        $entityManger = $this->createMock(EntityManagerInterface::class);

        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->willReturn($entityManger);

        $entityManger->expects(self::once())
            ->method('persist');

        $entityManger->expects(self::once())
            ->method('flush')
            ->willThrowException($exception);

        $this->expectException(ModelManagerException::class);

        $this->modelManager->create(new VersionedEntity());
    }

    /**
     * @phpstan-return iterable<array-key, array{\Throwable}>
     */
    public function createUpdateRemoveData(): iterable
    {
        return [
            'PDOException' => [
                new \PDOException(),
            ],
            'DBALException' => [
                new Exception(),
            ],
        ];
    }

    /**
     * @dataProvider createUpdateRemoveData
     */
    public function testUpdate(\Throwable $exception): void
    {
        $entityManger = $this->createMock(EntityManagerInterface::class);

        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->willReturn($entityManger);

        $entityManger->expects(self::once())
            ->method('persist');

        $entityManger->expects(self::once())
            ->method('flush')
            ->willThrowException($exception);

        $this->expectException(ModelManagerException::class);

        $this->modelManager->update(new VersionedEntity());
    }

    /**
     * @dataProvider createUpdateRemoveData
     */
    public function testRemove(\Throwable $exception): void
    {
        $entityManger = $this->createMock(EntityManagerInterface::class);

        $this->registry->expects(self::once())
            ->method('getManagerForClass')
            ->willReturn($entityManger);

        $entityManger->expects(self::once())
            ->method('remove');

        $entityManger->expects(self::once())
            ->method('flush')
            ->willThrowException($exception);

        $this->expectException(ModelManagerException::class);

        $this->modelManager->delete(new VersionedEntity());
    }

    /**
     * @param string[]          $expectedParameters
     * @param string[]          $identifierFieldNames
     * @param array<int|string> $ids
     *
     * @dataProvider addIdentifiersToQueryProvider
     *
     * @phpstan-param non-empty-array<int|string> $ids
     */
    public function testAddIdentifiersToQuery(array $expectedParameters, array $identifierFieldNames, array $ids): void
    {
        $em = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        /** @var QueryBuilder&MockObject $queryBuilder */
        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->setConstructorArgs([$em])
            ->setMethodsExcept(['getParameters', 'setParameter'])
            ->getMock();

        $queryBuilder
            ->expects(self::exactly(\count($expectedParameters)))
            ->method('getRootAliases')
            ->willReturn(['p']);

        $queryBuilder
            ->expects(self::once())
            ->method('andWhere')
            ->with(self::stringContains(sprintf('( p.%s = :field_', $identifierFieldNames[0])));

        $proxyQuery = new ProxyQuery($queryBuilder);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('getIdentifierFieldNames')
            ->willReturn($identifierFieldNames);
        $this->setGetMetadataExpectation(Product::class, $metadata);

        $this->modelManager->addIdentifiersToQuery(Product::class, $proxyQuery, $ids);

        self::assertCount(\count($expectedParameters), $proxyQuery->getQueryBuilder()->getParameters());

        foreach ($proxyQuery->getParameters() as $offset => $parameter) {
            self::assertSame($expectedParameters[$offset], $parameter->getValue());
        }
    }

    /**
     * @phpstan-return iterable<array-key, array{string[], string[], non-empty-array<int|string>}>
     */
    public function addIdentifiersToQueryProvider(): iterable
    {
        yield [['1', '2'], ['id'], [1, 2]];
        yield [['112', '2020'], ['id'], ['112', '2020']];
        yield [['1', '42', '2', '256'], ['id', 'foreignId'], ['1~42', '2~256']];
        yield [['a', '4', 'b', '52'], ['id', 'foreignId'], ['a~4', 'b~52']];
        yield [['048b78d8-eced-47bb-8dff-31d7d32352a0', '1986'], ['mixed'], ['048b78d8-eced-47bb-8dff-31d7d32352a0', '1986']];
        yield [
            [
                '048b78d8-eced-47bb-8dff-31d7d32352a0',
                '3d6e98f5-8e43-4a81-b39b-3303c0aa5841',
            ], [
                'guid',
            ], [
                '048b78d8-eced-47bb-8dff-31d7d32352a0',
                '3d6e98f5-8e43-4a81-b39b-3303c0aa5841',
            ],
        ];
        yield [
            [
                '048b78d8-eced-47bb-8dff-31d7d32352a0',
                '3d6e98f5-8e43-4a81-b39b-3303c0aa5841',
                'dfc1c309-8628-4e1a-8ce3-d3727dedaac6',
                'f31b0cb3-a7a5-4297-ba3d-d810b286b002',
            ], [
                'guid',
                'foreingGuid',
            ], [
                '048b78d8-eced-47bb-8dff-31d7d32352a0~3d6e98f5-8e43-4a81-b39b-3303c0aa5841',
                'dfc1c309-8628-4e1a-8ce3-d3727dedaac6~f31b0cb3-a7a5-4297-ba3d-d810b286b002',
            ],
        ];
    }

    /**
     * @psalm-suppress InvalidArgument
     */
    public function testAddIdentifiersToQueryWithEmptyIdentifiers(): void
    {
        $datagrid = $this->createStub(ProxyQueryInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Array passed as argument 3 to "Sonata\DoctrineORMAdminBundle\Model\ModelManager::addIdentifiersToQuery()" must not be empty.');

        // @phpstan-ignore-next-line
        $this->modelManager->addIdentifiersToQuery(\stdClass::class, $datagrid, []);
    }

    /**
     * @phpstan-template T of object
     * @phpstan-param class-string<T> $class
     * @phpstan-return ClassMetadata<T>
     */
    private function getMetadata(string $class, bool $isVersioned = false): ClassMetadata
    {
        $metadata = new ClassMetadata($class);

        $metadata->isVersioned = $isVersioned;

        if ($isVersioned) {
            $versionField = 'version';
            $metadata->versionField = $versionField;
            $metadata->reflFields[$versionField] = new \ReflectionProperty($class, $versionField);
        }

        return $metadata;
    }

    /**
     * @return EntityManagerInterface&MockObject
     *
     * @phpstan-template T of object
     * @phpstan-param class-string<T> $class
     * @phpstan-param ClassMetadata<T> $classMetadata
     */
    private function setGetMetadataExpectation(string $class, ClassMetadata $classMetadata): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $this->registry->expects(self::atLeastOnce())
            ->method('getManagerForClass')
            ->with($class)
            ->willReturn($em);

        $em->expects(self::atLeastOnce())
            ->method('getClassMetadata')
            ->with($class)
            ->willReturn($classMetadata);

        return $em;
    }
}
