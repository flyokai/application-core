<?php

namespace Flyokai\SearchCriteria\Criteria;

enum Operator: string
{
    case Eq = 'eq';
    case Neq = 'neq';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
    case In = 'in';
    case NotIn = 'notIn';
    case Like = 'like';
    case NotLike = 'notLike';
    case IsNull = 'isNull';
    case IsNotNull = 'isNotNull';
    case Between = 'between';
}
