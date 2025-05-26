<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Schema;

use function count;

class ConvertEnumValues implements Transform
{
    /** @param mixed[]|null $enumValueMap */
    public function __construct(private array|null $enumValueMap = null)
    {
    }

    public function transformSchema(Schema $schema): Schema
    {
        $enumValueMap = $this->enumValueMap;
        if (! $enumValueMap || count($enumValueMap) === 0) {
            return $schema;
        }

        return VisitSchema::invoke($schema, [
            VisitSchemaKind::ENUM_TYPE => static function (EnumType $enumType) use ($enumValueMap) {
                $externalToInternalValueMap = $enumValueMap[$enumType->name] ?? null;

                if ($externalToInternalValueMap) {
                    $values    = $enumType->getValues();
                    $newValues = [];
                    foreach ($values as $value) {
                        $newValue = $externalToInternalValueMap[$value->name] ?? $value->name;

                        $newValues[$value->name] = [
                            'value' => $newValue,
                            'deprecationReason' => $value->deprecationReason,
                            'description' => $value->description,
                            'astNode' => $value->astNode,
                        ];
                    }

                    return new EnumType([
                        'name' => $enumType->name,
                        'description' => $enumType->description,
                        'astNode' => $enumType->astNode,
                        'values' => $newValues,
                    ]);
                }

                return $enumType;
            },
        ]);
    }
}
