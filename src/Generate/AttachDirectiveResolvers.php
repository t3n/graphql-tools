<?php

declare(strict_types=1);

namespace GraphQLTools\Generate;

use GraphQL\Executor\Executor;
use GraphQL\Type\Definition\FieldDefinition;
use GraphQL\Type\Schema;
use GraphQLTools\SchemaDirectiveVisitor;
use function call_user_func;

class AttachDirectiveResolvers
{
    /**
     * @param mixed[] $directiveResolvers
     */
    public static function invoke(Schema $schema, array $directiveResolvers) : void
    {
        $schemaDirectives = [];
        foreach ($directiveResolvers as $directiveName => $resolver) {
            $schemaDirectives[$directiveName] = new class ($resolver) extends SchemaDirectiveVisitor {
                /** @var callable */
                private $resolver;

                public function __construct(callable $resolver)
                {
                    $this->resolver = $resolver;
                }

                /**
                 * @param mixed[] $details
                 */
                public function visitFieldDefinition(FieldDefinition $field, array $details) : void
                {
                    $resolver         = $this->resolver;
                    $originalResolver = $field->resolveFn ?? [Executor::class, 'defaultFieldResolver'];
                    $directiveArgs    = $this->args;

                    $field->resolveFn = static function (...$args) use ($originalResolver, $directiveArgs, $resolver) {
                        [$source, , $context, $info] = $args;

                        return call_user_func(
                            $resolver,
                            static function () use ($originalResolver, $args) {
                                return $originalResolver(...$args);
                            },
                            $source,
                            $directiveArgs,
                            $context,
                            $info
                        );
                    };
                }
            };
        }

        SchemaDirectiveVisitor::visitSchemaDirectives($schema, $schemaDirectives);
    }
}
