<?php

declare(strict_types=1);

namespace App\Actions\Discord;

/**
 * Replies shared by the commands that ask the Devil's Rage SeAT.
 */
final class SeatMessages
{
    public const string NOT_LINKED = 'SeAT doesn\'t know your Discord yet. Link it in SeAT under Connector → Identities, then try again.';
}
