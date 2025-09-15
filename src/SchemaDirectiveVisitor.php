<?php

declare(strict_types=1);

namespace GraphQLTools;

use Exception;
use GraphQL\Executor\Values;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;

use function assert;
use function count;
use function preg_match_all;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;

class SchemaDirectiveVisitor extends SchemaVisitor
{
    public string $name;
    /** @var mixed[] */
    public array $args;
    public mixed $visitedType;
    public mixed $context;

    /** @param mixed[] $config */
    public function init(array $config): void
    {
        $this->name        = $config['name'];
        $this->args        = $config['args'];
        $this->visitedType = $config['visitedType'];
        $this->schema      = $config['schema'];
        $this->context     = $config['context'];
    }

    public static function getDirectiveDeclaration(string $directiveName, Schema $schema): Directive|null
    {
        return $schema->getDirective($directiveName);
    }

    /**
     * @param mixed[] $directiveVisitors
     *
     * @return callable[]
     */
    public static function visitSchemaDirectives(Schema $schema, array $directiveVisitors, mixed $context = []): array
    {
        $declaredDirectives = static::getDeclaredDirectives($schema, $directiveVisitors);

        $createdVisitors = [];
        foreach ($directiveVisitors as $directiveName => $_) {
            $createdVisitors[$directiveName] = [];
        }

        $visitorSelector = static function (
            $type,
            string $methodName,
        ) use (
            $directiveVisitors,
            $declaredDirectives,
            $schema,
            $context,
            &$createdVisitors,
        ): array {
            $visitors       = [];
            $astNode        = $type instanceof Schema ? $type->astNode : ($type->astNode ?? null);
            $directiveNodes = $astNode ? $astNode->directives : null;

            if (! $directiveNodes) {
                return $visitors;
            }

            foreach ($directiveNodes as $directiveNode) {
                $directiveName = $directiveNode->name->value;
                if (! isset($directiveVisitors[$directiveName])) {
                    break;
                }

                $visitorClass = $directiveVisitors[$directiveName];
                assert($visitorClass instanceof SchemaDirectiveVisitor);

                if (! $visitorClass::implementsVisitorMethod($methodName)) {
                    break;
                }

                $decl = $declaredDirectives[$directiveName] ?? null;
                $args = null;

                if ($decl) {
                    $args = Values::getArgumentValues($decl, $directiveNode);
                } else {
                    $args = [];
                    foreach ($directiveNode->arguments as $arg) {
                        $args[$arg->name->value] = AST::valueFromASTUntyped($arg->value);
                    }
                }

                $newVisitor = clone$visitorClass;
                $newVisitor->init([
                    'name' => $directiveName,
                    'args' => $args,
                    'visitedType' => $type,
                    'schema' => $schema,
                    'context' => $context,
                ]);

                $visitors[] = $newVisitor;
            }

            if (count($visitors) > 0) {
                foreach ($visitors as $visitor) {
                    $createdVisitors[$visitor->name][] = $visitor;
                }
            }

            return $visitors;
        };

        SchemaVisitor::doVisitSchema($schema, $visitorSelector);
        SchemaVisitor::healSchema($schema);

        return $createdVisitors;
    }

    /**
     * @param mixed[] $directiveVisitors
     *
     * @return Directive[]
     */
    protected static function getDeclaredDirectives(Schema $schema, array $directiveVisitors): array
    {
        /** @var Directive[] $declaredDirectives */
        $declaredDirectives = [];

        foreach ($schema->getDirectives() as $decl) {
            $declaredDirectives[$decl->name] = $decl;
        }

        foreach ($directiveVisitors as $directiveName => $visitorClass) {
            $decl = $visitorClass::getDirectiveDeclaration($directiveName, $schema);
            if (! $decl) {
                continue;
            }

            $declaredDirectives[$directiveName] = $decl;
        }

        foreach ($declaredDirectives as $name => $decl) {
            if (! isset($directiveVisitors[$name])) {
                continue;
            }

            $visitorClass = $directiveVisitors[$name];

            foreach ($decl->locations as $loc) {
                $visitorMethodName = static::directiveLocationToVisitorMethodName($loc);
                if (
                    SchemaVisitor::implementsVisitorMethod($visitorMethodName)
                    && ! $visitorClass::implementsVisitorMethod($visitorMethodName)
                ) {
                    throw new Exception(
                        'SchemaDirectiveVisitor for @' . $name . ' must implement ' . $visitorMethodName . ' method',
                    );
                }
            }
        }

        return $declaredDirectives;
    }

    protected static function directiveLocationToVisitorMethodName(string $loc): string
    {
        preg_match_all('/([^_]*)_?/', $loc, $matches);

        $methodName = 'visit';

        foreach ($matches[1] as $match) {
            if (strlen($match) <= 0) {
                continue;
            }

            $methodName .= strtoupper($match[0]) . strtolower(substr($match, 1));
        }

        return $methodName;
    }
}
