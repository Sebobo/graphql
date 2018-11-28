<?php

declare(strict_types=1);

namespace t3n\GraphQL\Service;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Schema;
use GraphQL\Utils\SchemaPrinter;
use GraphQLTools\Generate\ConcatenateTypeDefs;
use GraphQLTools\GraphQLTools;
use InvalidArgumentException;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Utility\Files;
use t3n\GraphQL\Resolvers;
use t3n\GraphQL\SchemaEnvelopeInterface;
use TypeError;
use function is_array;
use function md5;
use function sprintf;
use function substr;

/**
 * @Flow\Scope("singleton")
 */
class SchemaService
{
    /**
     * @Flow\InjectConfiguration("endpoints")
     * @var mixed[]
     */
    protected $endpoints;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $schemaCache;

    /**
     * @var Schema[]
     */
    protected $firstLevelCache = [];

    public function getSchemaForEndpoint(string $endpoint) : Schema
    {
        if (isset($this->firstLevelCache[$endpoint])) {
            return $this->firstLevelCache[$endpoint];
        }

        $endpointConfiguration = $this->endpoints[$endpoint] ?? null;

        if (! $endpointConfiguration) {
            throw new InvalidArgumentException(sprintf('No schema found for endpoint "%s"', $endpoint));
        }

        if (isset($endpointConfiguration['schemas'])) {
            $schema = $this->getMergedSchemaFromConfigurations($endpointConfiguration);
        } else {
            $schema = $this->getMergedSchemaFromConfigurations([ 'schemas' => [$endpointConfiguration] ]);
        }

        $this->firstLevelCache[$endpoint] = $schema;
        return $schema;
    }

    protected function getSchemaFromEnvelope(string $envelopeClassName) : Schema
    {
        $envelope = $this->objectManager->get($envelopeClassName);
        if (! $envelope instanceof SchemaEnvelopeInterface) {
            throw new TypeError(sprintf('%s has to implement %s', $envelopeClassName, SchemaEnvelopeInterface::class));
        }

        return $envelope->getSchema();
    }

    /**
     * @param mixed[] $options
     */
    protected function getSchemaFromConfiguration(array $configuration) : array
    {
        $options = [
            'typeDefs' => ''
        ];

        if (substr($configuration['typeDefs'], 0, 11) === 'resource://') {
            $options['typeDefs'] = Files::getFileContents($configuration['typeDefs']);
        } else {
            $options['typeDefs'] = $configuration['typeDefs'];
        }

        $resolvers = Resolvers::create();
        if (isset($configuration['resolverPathPattern'])) {
            $resolvers->withPathPattern($configuration['resolverPathPattern']);
        }

        if (isset($configuration['resolvers']) && is_array($configuration['resolvers'])) {
            foreach ($configuration['resolvers'] as $typeName => $resolverClass) {
                $resolvers->withType($typeName, $resolverClass);
            }
        }

        $options['resolvers'] = $resolvers;

        if (isset($configuration['schemaDirectives']) && is_array($configuration['schemaDirectives'])) {
            $options['schemaDirectives'] = [];
            foreach ($configuration['schemaDirectives'] as $directiveName => $schemaDirectiveVisitor) {
                $options['schemaDirectives'][$directiveName] = new $schemaDirectiveVisitor();
            }
        }

        return $options;
    }

    protected function getMergedSchemaFromConfigurations(array $configuration) : Schema
    {
        $executableSchemas = [];

        $options = [
            'typeDefs' => [],
            'resolvers' => [],
            'schemaDirectives' => []
        ];

        foreach ($configuration['schemas'] as $schemaConfiguration) {
            if (isset($schemaConfiguration['schemaEnvelope'])) {
                $executableSchemas[] = $this->getSchemaFromEnvelope($schemaConfiguration['schemaEnvelope']);
            } else {
                $schemaInfo = $this->getSchemaFromConfiguration($schemaConfiguration);
                $options['typeDefs'][] = $schemaInfo['typeDefs'];
                $options['resolvers'] = array_merge_recursive($options['resolvers'], $schemaInfo['resolvers']->toArray());
                $options['schemaDirectives'] = array_merge($options['schemaDirectives'], $schemaInfo['schemaDirectives'] ?? []);
            }
        }

        if (isset($configuration['schemaDirectives'])) {
            foreach ($configuration['schemaDirectives'] as $directiveName => $schemaDirectiveVisitor) {
                $options['schemaDirectives'][$directiveName] = new $schemaDirectiveVisitor();
            }
        }

        $schema = null;
        if (count($options['typeDefs']) > 0) {
            $cacheIdentifier = md5(serialize($options['typeDefs']));
            if ($this->schemaCache->has($cacheIdentifier)) {
                $options['typeDefs'] = $this->schemaCache->get($cacheIdentifier);
            } else {
                $options['typeDefs'] = Parser::parse(ConcatenateTypeDefs::invoke($options['typeDefs']));
                $this->schemaCache->set($cacheIdentifier, $options['typeDefs']);
            }
            $schema = GraphQLTools::makeExecutableSchema($options);
        }

        if (count($executableSchemas) === 0) {
            return $schema;
        }

        if ($schema) {
            $executableSchemas[] = $schema;
        }

        if (count($executableSchemas) > 1) {
            return GraphQLTools::mergeSchemas([
                'schemas' => $executableSchemas
            ]);
        }

        return $executableSchemas[0];
    }
}