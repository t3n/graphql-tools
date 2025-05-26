<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Schema;
use GraphQLTools\Stitching\SchemaRecreation;

use function count;

class TransformRootFields implements Transform
{
    /** @var callable  */
    private $transform;

    public function __construct(callable $transform)
    {
        $this->transform = $transform;
    }

    public function transformSchema(Schema $originalSchema): Schema
    {
        return VisitSchema::invoke(
            $originalSchema,
            [
                VisitSchemaKind::QUERY => function (ObjectType $type) {
                    return static::transformFields($type, function ($fieldName, $field) {
                        $transform = $this->transform;

                        return $transform('Query', $fieldName, $field);
                    });
                },
                VisitSchemaKind::MUTATION => function (ObjectType $type) {
                    return static::transformFields($type, function ($fieldName, $field) {
                        $transform = $this->transform;

                        return $transform('Mutation', $fieldName, $field);
                    });
                },
                VisitSchemaKind::SUBSCRIPTION => function (ObjectType $type) {
                    return static::transformFields($type, function ($fieldName, $field) {
                        $transform = $this->transform;

                        return $transform('Subscription', $fieldName, $field);
                    });
                },
            ],
        );
    }

    private static function transformFields(ObjectType $type, callable $transformer): ObjectType|null
    {
        $resolveType = SchemaRecreation::createResolveType(
            static function (string $name, NamedType $originalType) {
                return $originalType;
            },
        );
        $fields      = $type->getFields();
        $newFields   = [];

        foreach ($fields as $fieldName => $field) {
            $newField = $transformer($fieldName, $field);
            if ($newField === null) {
                $newFields[$fieldName] = SchemaRecreation::fieldToFieldConfig($field, $resolveType, true);
            } elseif ($newField !== false) {
                $newFields[$newField['name']] = $newField['field'];
            } else {
                unset($newField[$fieldName]);
            }
        }

        if (count($newFields) === 0) {
            return null;
        }

        return new ObjectType([
            'name' => $type->name,
            'description' => $type->description,
            'astNode' => $type->astNode,
            'fields' => $newFields,
        ]);
    }
}
