<?php

declare(strict_types=1);

namespace GraphQLTools\Transforms;

class VisitSchemaKind
{
    public const TYPE              = 'VisitSchemaKind.TYPE';
    public const SCALAR_TYPE       = 'VisitSchemaKind.SCALAR_TYPE';
    public const ENUM_TYPE         = 'VisitSchemaKind.ENUM_TYPE';
    public const COMPOSITE_TYPE    = 'VisitSchemaKind.COMPOSITE_TYPE';
    public const OBJECT_TYPE       = 'VisitSchemaKind.OBJECT_TYPE';
    public const INPUT_OBJECT_TYPE = 'VisitSchemaKind.INPUT_OBJECT_TYPE';
    public const ABSTRACT_TYPE     = 'VisitSchemaKind.ABSTRACT_TYPE';
    public const UNION_TYPE        = 'VisitSchemaKind.UNION_TYPE';
    public const INTERFACE_TYPE    = 'VisitSchemaKind.INTERFACE_TYPE';
    public const ROOT_OBJECT       = 'VisitSchemaKind.ROOT_OBJECT';
    public const QUERY             = 'VisitSchemaKind.QUERY';
    public const MUTATION          = 'VisitSchemaKind.MUTATION';
    public const SUBSCRIPTION      = 'VisitSchemaKind.SUBSCRIPTION';
}
