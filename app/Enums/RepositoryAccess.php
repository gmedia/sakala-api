<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the agent obtains repository access for a command. Values must match
 * `sakala-agent-protocol::RepositoryAccess` (protocol revision 4).
 */
enum RepositoryAccess: string
{
    case Public = 'public';
    case TemporaryCredential = 'temporary_credential';
}
