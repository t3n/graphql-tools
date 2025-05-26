<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Error\Error;
use GraphQL\Language\VisitorOperation;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use GraphQLTools\Stitching\SchemaRecreation;

use function array_filter;
use function array_key_exists;
use function array_pop;
use function array_push;
use function array_unshift;
use function array_values;
use function count;
use function substr;

class VisitSchema
{
    /** @param callable[] $visitor */
    public static function invoke(Schema $schema, array $visitor, bool $stripResolvers = false): Schema
    {
        $types       = [];
        $resolveType = SchemaRecreation::createResolveType(static function (string $name) use (&$types) {
            if (! array_key_exists($name, $types)) {
                throw new Error('Can\'t find type ' . $name . '.');
            }

            return $types[$name];
        });

        $queryType        = $schema->getQueryType();
        $mutationType     = $schema->getMutationType();
        $subscriptionType = $schema->getSubscriptionType();
        $typeMap          = $schema->getTypeMap();

        foreach ($typeMap as $typeName => $type) {
            if (! ($type instanceof NamedType) || substr($type->name, 0, 2) === '__') {
                continue;
            }

            $specifiers  = static::getTypeSpecifiers($type, $schema);
            $typeVisitor = static::getVisitor($visitor, $specifiers);

            if ($typeVisitor) {
                $result = $typeVisitor($type, $schema);

                if ($result === null) {
                    $types[$typeName] = SchemaRecreation::recreateType($type, $resolveType, ! $stripResolvers);
                } elseif ($result instanceof VisitorOperation) {
                    if ($result->doContinue) {
                        $types[$typeName] = SchemaRecreation::recreateType($type, $resolveType, ! $stripResolvers);
                    } elseif ($result->removeNode) {
                        $types[$typeName] = null;
                    }
                } else {
                    $types[$typeName] = SchemaRecreation::recreateType($result, $resolveType, ! $stripResolvers);
                }
            } else {
                $types[$typeName] = SchemaRecreation::recreateType($type, $resolveType, ! $stripResolvers);
            }
        }

        $filteredTypes = array_filter(array_values($types), static function ($type) {
            return $type !== null;
        });

        return new Schema([
            'query' => $queryType ? ($types[$queryType->name] ?? null) : null,
            'mutation' => $mutationType ? ($types[$mutationType->name] ?? null) : null,
            'subscription' => $subscriptionType ? ($types[$subscriptionType->name] ?? null) : null,
            'types' => $filteredTypes,
        ]);
    }

    /** @return callable[] */
    protected static function getTypeSpecifiers(Type $type, Schema $schema): array
    {
        $specifiers = [VisitSchemaKind::TYPE];
        if ($type instanceof ObjectType) {
            array_unshift($specifiers, VisitSchemaKind::COMPOSITE_TYPE, VisitSchemaKind::OBJECT_TYPE);

            $query        = $schema->getQueryType();
            $mutation     = $schema->getMutationType();
            $subscription = $schema->getSubscriptionType();

            if ($type === $query) {
                array_push($specifiers, VisitSchemaKind::ROOT_OBJECT, VisitSchemaKind::QUERY);
            } elseif ($type === $mutation) {
                array_push($specifiers, VisitSchemaKind::ROOT_OBJECT, VisitSchemaKind::MUTATION);
            } elseif ($type === $subscription) {
                array_push($specifiers, VisitSchemaKind::ROOT_OBJECT, VisitSchemaKind::SUBSCRIPTION);
            }
        } elseif ($type instanceof InputObjectType) {
            $specifiers[] = VisitSchemaKind::INPUT_OBJECT_TYPE;
        } elseif ($type instanceof InterfaceType) {
            array_push(
                $specifiers,
                VisitSchemaKind::COMPOSITE_TYPE,
                VisitSchemaKind::ABSTRACT_TYPE,
                VisitSchemaKind::INPUT_OBJECT_TYPE,
            );
        } elseif ($type instanceof UnionType) {
            array_push(
                $specifiers,
                VisitSchemaKind::COMPOSITE_TYPE,
                VisitSchemaKind::ABSTRACT_TYPE,
                VisitSchemaKind::UNION_TYPE,
            );
        } elseif ($type instanceof EnumType) {
            $specifiers[] = VisitSchemaKind::ENUM_TYPE;
        } elseif ($type instanceof ScalarType) {
            $specifiers[] = VisitSchemaKind::SCALAR_TYPE;
        }

        return $specifiers;
    }

    /**
     * @param mixed[] $visitor
     * @param mixed[] $specifiers
     */
    public static function getVisitor(array $visitor, array $specifiers): callable|null
    {
        $typeVisitor = null;
        $stack       = $specifiers;
        while (! $typeVisitor && count($stack) > 0) {
            $next        = array_pop($stack);
            $typeVisitor = $visitor[$next] ?? null;
        }

        return $typeVisitor;
    }

    public static function skipNode(): VisitorOperation
    {
        $r             = new VisitorOperation();
        $r->doContinue = true;

        return $r;
    }

    public static function removeNode(): VisitorOperation
    {
        $r             = new VisitorOperation();
        $r->removeNode = true;

        return $r;
    }
}
