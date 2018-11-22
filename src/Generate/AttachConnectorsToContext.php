<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use Exception;
use GraphQL\Type\Schema;
use GraphQLTools\Utils;
use stdClass;
use TypeError;
use function call_user_func;
use function class_exists;
use function count;
use function gettype;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;

class AttachConnectorsToContext
{
    /**
     * @param mixed[] $connectors
     */
    public static function invoke(Schema $schema, array $connectors) : void
    {
        if (count($connectors) === 0) {
            throw new TypeError('Expected connectors to not be an empty array');
        }

        if (Utils::isNumericArray($connectors)) {
            throw new TypeError('Expected an associative array, got numeric array');
        }

        if (isset($schema->_apolloConnectorsAttached)) {
            throw new Exception('Connectors already attached to context, cannot attach more than once');
        }

        $schema->_apolloConnectorsAttached = true;

        $attachconnectorFn = static function ($root, array $args, &$ctx) use ($connectors) {
            if (! is_object($ctx)) {
                $contextType = gettype($ctx);
                throw new Exception('Cannot attach connector because context is not an object: ' . $contextType);
            }

            if (! isset($ctx->connectors)) {
                $ctx->connectors = new stdClass();
            } elseif (is_array($ctx->connectors)) {
                $ctx->connectors = (object) $ctx->connectors;
            }

            foreach ($connectors as $connectorName => $connector) {
                if (is_callable($connector)) {
                    $connectorInstance = call_user_func($connector, $ctx);
                } elseif (is_string($connector) && class_exists($connector)) {
                    $connectorInstance = new $connector($ctx);
                } else {
                    throw new Exception('Connector must be a function or an class');
                }

                $ctx->connectors->$connectorName = $connectorInstance;
            }

            return $root;
        };

        AddSchemaLevelResolveFunction::invoke($schema, $attachconnectorFn);
    }
}
