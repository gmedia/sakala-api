<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\UsageSignalRecord;
use App\Models\User;
use App\Models\Workspace;

/**
 * Audit rows store actor and subject types as class names, plus a few plain
 * strings (`system`, `github_app`, `github_installation`). The admin API speaks
 * short aliases instead, so class names never become part of the contract.
 */
final class AuditMorphAlias
{
    /** @var array<string, class-string> */
    private const CLASSES = [
        'user' => User::class,
        'project' => Project::class,
        'deployment' => Deployment::class,
        'workspace' => Workspace::class,
        'limit_profile' => LimitProfile::class,
        'agent_node' => AgentNode::class,
        'agent_command' => AgentCommand::class,
        'feedback' => Feedback::class,
        'usage_signal' => UsageSignalRecord::class,
    ];

    /** Aliases stored as they are. */
    private const PLAIN = ['system', 'github_app', 'github_installation'];

    /** @return list<string> */
    public static function aliases(): array
    {
        return [...array_keys(self::CLASSES), ...self::PLAIN];
    }

    /** The stored value for an alias. */
    public static function toStored(string $alias): string
    {
        return self::CLASSES[$alias] ?? $alias;
    }

    /** The alias for a stored value; unknown values pass through. */
    public static function toAlias(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $alias = array_search($stored, self::CLASSES, true);

        return $alias === false ? $stored : $alias;
    }
}
