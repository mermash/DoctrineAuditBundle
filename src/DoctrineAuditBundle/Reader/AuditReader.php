<?php

namespace DH\DoctrineAuditBundle\Reader;

use DH\DoctrineAuditBundle\AuditConfiguration;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Statement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Pagerfanta\Adapter\DoctrineDbalSingleTableAdapter;
use Pagerfanta\Pagerfanta;

class AuditReader implements AuditReaderInterface
{
    const UPDATE = 'update';
    const ASSOCIATE = 'associate';
    const DISSOCIATE = 'dissociate';
    const INSERT = 'insert';
    const REMOVE = 'remove';

    const PAGE_SIZE = 50;

    /**
     * @var AuditConfiguration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var ?string
     */
    private $filter;

    /**
     * AuditReader constructor.
     *
     * @param AuditConfiguration     $configuration
     * @param EntityManagerInterface $entityManager
     */
    public function __construct(AuditConfiguration $configuration, EntityManagerInterface $entityManager)
    {
        $this->configuration = $configuration;
        $this->entityManager = $entityManager;
    }

    /**
     * @return AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Set the filter for AuditEntry retrieving.
     *
     * @param string $filter
     *
     * @return AuditReader
     */
    public function filterBy(string $filter): self
    {
        if (!\in_array($filter, [self::UPDATE, self::ASSOCIATE, self::DISSOCIATE, self::INSERT, self::REMOVE], true)) {
            $this->filter = null;
        } else {
            $this->filter = $filter;
        }

        return $this;
    }

    /**
     * Returns current filter.
     *
     * @return null|string
     */
    public function getFilter(): ?string
    {
        return $this->filter;
    }

    /**
     * Returns an array of audit table names indexed by entity FQN.
     *
     * @throws \Doctrine\ORM\ORMException
     *
     * @return array
     */
    public function getEntities(): array
    {
        $metadataDriver = $this->entityManager->getConfiguration()->getMetadataDriverImpl();
        $entities = [];
        if (null !== $metadataDriver) {
            $entities = $metadataDriver->getAllClassNames();
        }
        $audited = [];
        foreach ($entities as $entity) {
            if ($this->configuration->isAuditable($entity)) {
                $audited[$entity] = $this->getEntityTableName($entity);
            }
        }
        ksort($audited);

        return $audited;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param null|int      $page
     * @param null|int      $pageSize
     *
     * @return array
     */
    public function getAudits($entity, $id = null, ?int $page = null, ?int $pageSize = null): array
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id, $page, $pageSize);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param null|int      $page
     * @param null|int      $pageSize
     *
     * @return Pagerfanta
     */
    public function getAuditsPager($entity, $id = null, int $page = 1, int $pageSize = self::PAGE_SIZE): Pagerfanta
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $adapter = new DoctrineDbalSingleTableAdapter($queryBuilder, 'at.id');

        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta
            ->setMaxPerPage($pageSize)
            ->setCurrentPage($page)
        ;

        return $pagerfanta;
    }

    /**
     * Returns the amount of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     *
     * @throws \Doctrine\ORM\NonUniqueResultException
     *
     * @return int
     */
    public function getAuditsCount($entity, $id = null): int
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity, $id);

        $result = $queryBuilder
            ->resetQueryPart('select')
            ->resetQueryPart('orderBy')
            ->select('COUNT(id)')
            ->execute()
            ->fetchColumn(0)
        ;

        return false === $result ? 0 : $result;
    }

    /**
     * Returns an array of audited entries/operations.
     *
     * @param object|string $entity
     * @param int|string    $id
     * @param int           $page
     * @param int           $pageSize
     *
     * @return QueryBuilder
     */
    private function getAuditsQueryBuilder($entity, $id = null, ?int $page = null, ?int $pageSize = null): QueryBuilder
    {
        if (null !== $page && $page < 1) {
            throw new \InvalidArgumentException('$page must be greater or equal than 1.');
        }

        if (null !== $pageSize && $pageSize < 1) {
            throw new \InvalidArgumentException('$pageSize must be greater or equal than 1.');
        }

        $connection = $this->entityManager->getConnection();

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity), 'at')
            ->orderBy('created_at', 'DESC')
            ->addOrderBy('id', 'DESC')
        ;

        if (null !== $pageSize) {
            $queryBuilder
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
            ;
        }

        if (null !== $id) {
            $queryBuilder
                ->andWhere('object_id = :object_id')
                ->setParameter('object_id', $id);
        }

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter);
        }

        return $queryBuilder;
    }

    /**
     * @param object|string $entity
     * @param int|string    $id
     *
     * @return mixed
     */
    public function getAudit($entity, $id)
    {
        $connection = $this->entityManager->getConnection();

        /**
         * @var \Doctrine\DBAL\Query\QueryBuilder
         */
        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($this->getEntityAuditTableName($entity))
            ->where('id = :id')
            ->setParameter('id', $id);

        if (null !== $this->filter) {
            $queryBuilder
                ->andWhere('type = :filter')
                ->setParameter('filter', $this->filter);
        }

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }

    private function getClassMetadata($entity): ClassMetadata
    {
        return $this
            ->entityManager
            ->getClassMetadata($entity);
    }

    /**
     * Returns the table name of $entity.
     *
     * @param object|string $entity
     *
     * @return string
     */
    public function getEntityTableName($entity): string
    {
        return $this->getClassMetadata($entity)
            ->getTableName();
    }

    /**
     * Returns the audit table name for $entity
     *
     * @param $entity
     *
     * @return string
     */
    public function getEntityAuditTableName($entity): string
    {
        $entityName = \is_string($entity) ? $entity : \get_class($entity);
        $schema = $this->getClassMetadata($entityName)->getSchemaName() ? $this->getClassMetadata($entityName)->getSchemaName() . '.' : '';

        return sprintf('%s%s%s%s', $schema, $this->configuration->getTablePrefix(), $this->getEntityTableName($entityName), $this->configuration->getTableSuffix());
    }

    /**
     * Returns an array of entity audits by context id.
     *
     * @param string $context
     * @return array
     */
    public function getContextAudits(string $context): array
    {
        $res = [];
        foreach (array_keys($this->getEntities()) as $i => $entityClass) {
            $res[$entityClass] = $this->getContextAudit($entityClass, $context);
        }
        return array_filter($res);
    }

    /**
     * Returns an array of entity audits by context id.
     *
     * @param $entity
     * @param string $context
     * @return array
     */
    public function getContextAudit($entity, string $context): array
    {
        $queryBuilder = $this->getAuditsQueryBuilder($entity);
        $queryBuilder
            ->andWhere("context = :context")
            ->setParameter('context', $context);

        /** @var Statement $statement */
        $statement = $queryBuilder->execute();
        $statement->setFetchMode(\PDO::FETCH_CLASS, AuditEntry::class);

        return $statement->fetchAll();
    }
}