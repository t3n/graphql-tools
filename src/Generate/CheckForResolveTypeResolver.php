<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;

class CheckForResolveTypeResolver
{
    /**
     * @throws SchemaError
     */
    public static function invoke(Schema $schema, ?bool $requireResolversForResolveType = null) : void
    {
        /**
         * @var UnionType|InterfaceType $typeName
         */
        foreach ($schema->getTypeMap() as $typeName => $type) {
            if (! ($type instanceof UnionType || $type instanceof InterfaceType)) {
                continue;
            }

            if (! isset($type->config['resolveType'])) {
                if ($requireResolversForResolveType === false) {
                    continue;
                }

                if ($requireResolversForResolveType === true) {
                    throw new SchemaError('Type "' . $type->name . '" is missing a "resolveType" resolver');
                }

                throw new SchemaError(
                    'Type "' . $type->name . '" is missing a "__resolveType" resolver. ' .
                    'Pass false into "resolverValidationOptions.requireResolversForResolveType" ' .
                    'to disable this warning.'
                );
            }
        }
    }
}
