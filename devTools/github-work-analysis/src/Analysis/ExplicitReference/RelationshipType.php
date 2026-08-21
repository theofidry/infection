<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\ExplicitReference;

enum RelationshipType: string
{
    case REFERENCES = 'references';
    case CLOSES = 'closes';
}
