<?php

declare(strict_types=1);

namespace GraphQLTools;

use Exception;
use GraphQL\Language\VisitorOperation;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\EnumValueDefinition;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NamedType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Schema;
use ReflectionMethod;
use Throwable;
use function array_values;
use function method_exists;
use function preg_match;
use function substr;

class SchemaVisitor
{
    /** @var Schema */
    public $schema;

    public static function implementsVisitorMethod(string $methodName) : bool
    {
        if (! preg_match('/^visit/', $methodName)) {
            return false;
        }

        if (! method_exists(static::class, $methodName)) {
            return false;
        }

        if (static::class === self::class) {
            // The SchemaVisitor class implements every visitor method.
            return true;
        }

        $reflection = new ReflectionMethod(static::class, $methodName);
        if ($reflection->getDeclaringClass()->getName() === self::class) {
            // If $methodName was just inherited from SchemaVisitor,
            // then this class does not really implement the method.
            return false;
        }

        return true;
    }

    public static function removeNode() : VisitorOperation
    {
        $operation             = new VisitorOperation();
        $operation->removeNode = true;
        return $operation;
    }

    /**
     * @return void|mixed
     */
    public function visitSchema(Schema $schema)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitScalar(ScalarType $scalar)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitObject(ObjectType $object)
    {
    }

    /**
     * @param mixed[] $details
     *
     * @return void|mixed
     */
    public function visitFieldDefinition(FieldDefinition $field, array $details)
    {
    }

    /**
     * @param mixed[] $details
     *
     * @return void|mixed
     */
    public function visitArgumentDefinition(FieldArgument $argument, array $details)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitInterface(InterfaceType $iface)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitUnion(UnionType $union)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitEnum(EnumType $type)
    {
    }

    /**
     * @param mixed[] $details
     *
     * @return void|mixed
     */
    public function visitEnumValue(EnumValueDefinition $value, array $details)
    {
    }

    /**
     * @return void|mixed
     */
    public function visitInputObject(InputObjectType $object)
    {
    }

    /**
     * @param mixed[] $details
     *
     * @return void|mixed
     */
    public function visitInputFieldDefinition(InputObjectField $field, array $details)
    {
    }

    public static function doVisitSchema(Schema $schema, callable $visitorSelector) : Schema
    {
        $callMethod = static function (string $methodName, $type, ...$args) use ($visitorSelector) {
            foreach ($visitorSelector($type, $methodName) as $visitor) {
                $newType = $visitor->$methodName($type, ...$args);

                if ($newType === null) {
                    continue;
                }

                if ($methodName === 'visitSchema' || $type instanceof Schema) {
                    throw new Exception('Method ' . $methodName . ' cannot replace schema with ' . $newType);
                }

                if ($newType === false) {
                    $type = null;
                    break;
                }

                $type = $newType;
            }

            return $type;
        };

        $visit = static function ($type) use ($callMethod, &$visit, &$visitFields) {
            if ($type instanceof Schema) {
                $callMethod('visitSchema', $type);

                $typeMap = $type->getTypeMap();
                static::updateEachKey($typeMap, static function (Type $namedType, string $ypeName) use (&$visit) {
                    if (substr($namedType->name, 0, 2) !== '__') {
                        return $visit($namedType);
                    }

                    return null;
                });

                Utils::forceSet($type, 'resolvedTypes', $typeMap);

                return $type;
            }

            if ($type instanceof ObjectType) {
                $newObject = $callMethod('visitObject', $type);
                if ($newObject) {
                    $visitFields($newObject);
                }

                return $newObject;
            }

            if ($type instanceof InterfaceType) {
                $newInterface = $callMethod('visitInterface', $type);
                if ($newInterface) {
                    $visitFields($newInterface);
                }
                return $newInterface;
            }

            if ($type instanceof InputObjectType) {
                /** @var InputObjectType $newInputObject */
                $newInputObject = $callMethod('visitInputObject', $type);

                if ($newInputObject) {
                    $fields = $newInputObject->getFields();
                    static::updateEachKey($fields, static function ($field) use ($callMethod, $newInputObject) {
                        return $callMethod('visitInputFieldDefinition', $field, ['objectType' => $newInputObject]);
                    });
                    Utils::forceSet($newInputObject, 'fields', $fields);
                }

                return $newInputObject;
            }

            if ($type instanceof ScalarType) {
                return $callMethod('visitScalar', $type);
            }

            if ($type instanceof UnionType) {
                return $callMethod('visitUnion', $type);
            }

            if ($type instanceof EnumType) {
                /** @var EnumType $newEnum */
                $newEnum = $callMethod('visitEnum', $type);

                if ($newEnum) {
                    $values = $newEnum->getValues();
                    static::updateEachKey(
                        $values,
                        static function (EnumValueDefinition $value) use ($callMethod, $newEnum) {
                            return $callMethod('visitEnumValue', $value, ['enumType' => $newEnum]);
                        }
                    );
                    Utils::forceSet($newEnum, 'values', array_values($values));
                }

                return $newEnum;
            }

            throw new Exception('Unexpected schema type:' . $type);
        };

        /**
         * @param ObjectType|InterfaceType $type
         */
        $visitFields = static function ($type) use ($callMethod) : void {
            $fields = $type->getFields();
            static::updateEachKey($fields, static function (FieldDefinition $field) use ($callMethod, $type) {
                $newField = $callMethod('visitFieldDefinition', $field, ['objectType' => $type]);

                if ($newField instanceof FieldDefinition) {
                    static::updateEachKey(
                        $newField->args,
                        static function (FieldArgument $arg) use ($callMethod, $newField, $type) {
                            return $callMethod('visitArgumentDefinition', $arg, [
                                'field' => $newField,
                                'objectType' => $type,
                            ]);
                        }
                    );
                }

                return $newField;
            });
            Utils::forceSet($type, 'fields', $fields);
        };

        $visit($schema);
        return $schema;
    }

    public static function healSchema(Schema $schema) : Schema
    {
        $healType = static function ($type) use (&$healType, $schema) : Type {
            if ($type instanceof ListOfType) {
                $type = new ListOfType($healType($type->getWrappedType()));
            } elseif ($type instanceof NonNull) {
                $type = new NonNull($healType($type->getWrappedType()));
            } elseif ($type instanceof NamedType) {
                $namedType = $type;
                try {
                    $officialType = $schema->getType($namedType->name);
                } catch (Throwable $exception) {
                    $officialType = null;
                }

                if ($officialType && $namedType !== $officialType) {
                    return $officialType;
                }
            }
            return $type;
        };

        /**
         * @param ObjectType|InterfaceType $type
         */
        $healFields = static function ($type) use ($healType) : void {
            foreach ($type->getFields() as $field) {
                Utils::forceSet($field, 'type', $healType($field->getType()));
                if (! $field->args) {
                    continue;
                }

                foreach ($field->args as $arg) {
                    Utils::forceSet($arg, 'type', $healType($arg->getType()));
                }
            }
        };

        $heal = static function ($type) use ($healType, $healFields, &$heal) : void {
            if ($type instanceof Schema) {
                $originalTypeMap    = $type->getTypeMap();
                $actualNamedTypeMap = [];

                foreach ($originalTypeMap as $typeName => $namedType) {
                    if (substr($typeName, 0, 2) === '__') {
                        continue;
                    }

                    $actualName = $namedType->name;
                    if (substr($actualName, 0, 2) === '__') {
                        continue;
                    }

                    if (isset($actualNamedTypeMap[$actualName])) {
                        throw new Exception('Duplicate schema type name ' . $actualName);
                    }

                    $actualNamedTypeMap[$actualName] = $namedType;
                }

                foreach ($actualNamedTypeMap as $typeName => $namedType) {
                    $originalTypeMap[$typeName] = $namedType;
                }
                Utils::forceSet($type, 'resolvedTypes', $originalTypeMap);

                foreach ($type->getDirectives() as $decl) {
                    if (! $decl->args) {
                        continue;
                    }

                    foreach ($decl->args as $arg) {
                        Utils::forceSet($arg, 'type', $healType($arg->getType()));
                    }
                }

                foreach ($originalTypeMap as $typeName => $namedType) {
                    if (substr($typeName, 0, 2) === '__') {
                        continue;
                    }

                    $heal($namedType);
                }

                foreach ($originalTypeMap as $typeName => $namedType) {
                    if (substr($typeName, 0, 2) === '__' || isset($actualNamedTypeMap[$typeName])) {
                        continue;
                    }

                    unset($originalTypeMap[$typeName]);
                }
                Utils::forceSet($type, 'resolvedTypes', $originalTypeMap);
            } elseif ($type instanceof ObjectType) {
                $healFields($type);
                foreach ($type->getInterfaces() as $iface) {
                    $heal($iface);
                }
            } elseif ($type instanceof InterfaceType) {
                $healFields($type);
            } elseif ($type instanceof InputObjectType) {
                foreach ($type->getFields() as $field) {
                    Utils::forceSet($field, 'type', $healType($field->getType()));
                }
                // phpcs:ignore
            } elseif ($type instanceof ScalarType) {
                // nothing to do
            } elseif ($type instanceof UnionType) {
                $types = [];
                foreach ($type->getTypes() as $t) {
                    $types[] = $healType($t);
                }
                Utils::forceSet($type, 'types', $types);
            // phpcs:ignore
            } elseif ($type instanceof EnumType) {
                // nothing to do
            } else {
                throw new Exception('Unexpected schema type ' . $type);
            }
        };

        $heal($schema);
        return $schema;
    }

    /**
     * @param mixed[] $arr
     */
    protected static function updateEachKey(array &$arr, callable $callback) : void
    {
        foreach ($arr as $key => $value) {
            $result = $callback($value, $key);

            if ($result === null) {
                continue;
            }

            if ($result instanceof VisitorOperation && $result->removeNode) {
                unset($arr[$key]);
                continue;
            }

            $arr[$key] = $result;
        }
    }
}
