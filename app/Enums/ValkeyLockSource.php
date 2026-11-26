<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a managed Valkey store was locked (ADR-023). Unsuspending a project
 * only unlocks a store the suspension locked; an admin lock stays until an
 * admin lifts it.
 */
enum ValkeyLockSource: string
{
    /** `valkeys:lock`, for abuse. */
    case Admin = 'admin';

    /** Every project attached to the store was suspended. */
    case Suspension = 'suspension';
}
