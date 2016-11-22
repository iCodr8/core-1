<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApiPlatform\Core\Bridge\Doctrine\Orm\Extension;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Exception\PropertyNotFoundException;
use ApiPlatform\Core\Exception\ResourceClassNotFoundException;
use ApiPlatform\Core\Exception\RuntimeException;
use ApiPlatform\Core\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\QueryBuilder;

/**
 * Eager loads relations.
 *
 * @author Charles Sarrazin <charles@sarraz.in>
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Antoine Bluchet <soyuka@gmail.com>
 * @author Baptiste Meyer <baptiste.meyer@gmail.com>
 */
final class EagerLoadingExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private $propertyMetadataFactory;
    private $resourceMetadataFactory;
    private $maxJoins;
    private $forceEager;

    public function __construct(PropertyMetadataFactoryInterface $propertyMetadataFactory, ResourceMetadataFactoryInterface $resourceMetadataFactory, int $maxJoins = 30, bool $forceEager = true)
    {
        $this->propertyMetadataFactory = $propertyMetadataFactory;
        $this->resourceMetadataFactory = $resourceMetadataFactory;
        $this->maxJoins = $maxJoins;
        $this->forceEager = $forceEager;
    }

    /**
     * {@inheritdoc}
     */
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, string $operationName = null)
    {
        $options = [];

        if (null !== $operationName) {
            $options = ['collection_operation_name' => $operationName];
        }

        $forceEager = $this->isForceEager($resourceClass, $options);

        try {
            $groups = $this->getSerializerGroups($resourceClass, $options, 'normalization_context');

            $this->joinRelations($queryBuilder, $queryNameGenerator, $resourceClass, $forceEager, $queryBuilder->getRootAliases()[0], $groups);
        } catch (ResourceClassNotFoundException $resourceClassNotFoundException) {
            //ignore the not found exception
        }
    }

    /**
     * {@inheritdoc}
     * The context may contain serialization groups which helps defining joined entities that are readable.
     */
    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, string $operationName = null, array $context = [])
    {
        $options = [];

        if (null !== $operationName) {
            $options = ['item_operation_name' => $operationName];
        }

        $forceEager = $this->isForceEager($resourceClass, $options);

        if (isset($context['groups'])) {
            $groups = ['serializer_groups' => $context['groups']];
        } elseif (isset($context['resource_class'])) {
            $groups = $this->getSerializerGroups($context['resource_class'], $options, isset($context['api_denormalize']) ? 'denormalization_context' : 'normalization_context');
        } else {
            $groups = $this->getSerializerGroups($resourceClass, $options, 'normalization_context');
        }

        $this->joinRelations($queryBuilder, $queryNameGenerator, $resourceClass, $forceEager, $queryBuilder->getRootAliases()[0], $groups);
    }

    /**
     * Joins relations to eager load.
     *
     * @param QueryBuilder                $queryBuilder
     * @param QueryNameGeneratorInterface $queryNameGenerator
     * @param string                      $resourceClass
     * @param bool                        $forceEager
     * @param string                      $parentAlias
     * @param array                       $propertyMetadataOptions
     * @param bool                        $wasLeftJoin             if the relation containing the new one had a left join, we have to force the new one to left join too
     * @param int                         $joinCount               the number of joins
     *
     * @throws RuntimeException when the max number of joins has been reached
     */
    private function joinRelations(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, bool $forceEager, string $parentAlias, array $propertyMetadataOptions = [], bool $wasLeftJoin = false, int &$joinCount = 0)
    {
        if ($joinCount > $this->maxJoins) {
            throw new RuntimeException('The total number of joined relations has exceeded the specified maximum. Raise the limit if necessary.');
        }

        $entityManager = $queryBuilder->getEntityManager();
        $classMetadata = $entityManager->getClassMetadata($resourceClass);

        foreach ($classMetadata->associationMappings as $association => $mapping) {
            try {
                $propertyMetadata = $this->propertyMetadataFactory->create($resourceClass, $association, $propertyMetadataOptions);
            } catch (PropertyNotFoundException $propertyNotFoundException) {
                //skip properties not found
                continue;
            } catch (ResourceClassNotFoundException $resourceClassNotFoundException) {
                //skip associations that are not resource classes
                continue;
            }

            if (false === $forceEager && ClassMetadataInfo::FETCH_EAGER !== $mapping['fetch']) {
                continue;
            }

            if (false === $propertyMetadata->isReadableLink() || false === $propertyMetadata->isReadable()) {
                continue;
            }

            $joinColumns = $mapping['joinColumns'] ?? $mapping['joinTable']['joinColumns'] ?? null;
            if (false !== $wasLeftJoin || !isset($joinColumns[0]['nullable']) || false !== $joinColumns[0]['nullable']) {
                $method = 'leftJoin';
            } else {
                $method = 'innerJoin';
            }

            $associationAlias = $queryNameGenerator->generateJoinAlias($association);
            $queryBuilder->{$method}(sprintf('%s.%s', $parentAlias, $association), $associationAlias);
            ++$joinCount;

            $this->joinRelations($queryBuilder, $queryNameGenerator, $mapping['targetEntity'], $forceEager, $associationAlias, $propertyMetadataOptions, $method === 'leftJoin', $joinCount);
        }
    }

    /**
     * Gets serializer groups if available, if not it returns the $options array.
     *
     * @param string $resourceClass
     * @param array  $options       represents the operation name so that groups are the one of the specific operation
     * @param string $context       normalization_context or denormalization_context
     *
     * @return array
     */
    private function getSerializerGroups(string $resourceClass, array $options, string $context): array
    {
        $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);

        if (isset($options['collection_operation_name'])) {
            $context = $resourceMetadata->getCollectionOperationAttribute($options['collection_operation_name'], $context, null, true);
        } elseif (isset($options['item_operation_name'])) {
            $context = $resourceMetadata->getItemOperationAttribute($options['item_operation_name'], $context, null, true);
        } else {
            $context = $resourceMetadata->getAttribute($context);
        }

        if (empty($context['groups'])) {
            return $options;
        }

        return ['serializer_groups' => $context['groups']];
    }

    /**
     * Does an operation force eager?
     *
     * @param string $resourceClass
     * @param array  $options
     *
     * @return bool
     */
    private function isForceEager(string $resourceClass, array $options): bool
    {
        $resourceMetadata = $this->resourceMetadataFactory->create($resourceClass);

        if (isset($options['collection_operation_name'])) {
            $forceEager = $resourceMetadata->getCollectionOperationAttribute($options['collection_operation_name'], 'force_eager', null, true);
        } elseif (isset($options['item_operation_name'])) {
            $forceEager = $resourceMetadata->getItemOperationAttribute($options['item_operation_name'], 'force_eager', null, true);
        } else {
            $forceEager = $resourceMetadata->getAttribute('force_eager');
        }

        return is_bool($forceEager) ? $forceEager : $this->forceEager;
    }
}
