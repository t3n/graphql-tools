<?php

declare(strict_types=1);

namespace GraphQLTools\Tests\SchemaGeneratorTest;

class TestHelper
{
    public static function getTestSchema(): string
    {
        return '
            type RootQuery {
                usecontext: String
                useTestConnector: String
                useContextConnector: String
                species(name: String): String
                stuff: String
            }
            schema {
                query: RootQuery
            }
        ';
    }

    /** @return mixed[] */
    public static function getTestResolvers(): array
    {
        return [
            '__schema' => static function () {
                return [
                    'stuff' => 'stuff',
                    'species' => 'ROOT',
                ];
            },
            'RootQuery' => [
                'usecontext' => static function ($r, $a, $ctx) {
                    return $ctx->usecontext;
                },
                'useTestConnector' => static function ($r, $a, $ctx) {
                    return $ctx->connectors->TestConnector->get();
                },
                'useContextConnector' => static function ($r, $a, $ctx) {
                    return $ctx->connectors->ContextConnector->get();
                },
                'species' => static function ($root, $args) {
                    $name = $args['name'];

                    return $root['species'] . $name;
                },
            ],
        ];
    }

    public static function getTestConnector(): object
    {
        return new class
        {
            public function get(): string
            {
                return 'works';
            }
        };
    }

    public static function getContextConnector(object|null $ctx): object
    {
        return new class ($ctx)
        {
            private string|null $str = null;

            public function __construct(object|null $ctx)
            {
                $this->str = $ctx->str ?? null;
            }

            public function get(): string|null
            {
                return $this->str;
            }
        };
    }

    /** @return callable[] */
    public static function getTestConnectors(): array
    {
        return [
            'TestConnector' => [static::class, 'getTestConnector'],
            'ContextConnector' => [static::class, 'getContextConnector'],
        ];
    }
}
