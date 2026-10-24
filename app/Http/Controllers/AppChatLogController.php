<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReportAppChatLogsRequest;
use App\Models\Character;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Patch 54: which of your characters have EVE's "Log Chat to File" on, as the desktop app sees
 * it. Your accounts (SeAT) shows a warning on the ones that don't. Only your own characters are
 * touched; names the app saw that aren't yours are ignored.
 */
final class AppChatLogController extends Controller
{
    public function store(ReportAppChatLogsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var array<int, array{name: string, logging: bool}> $reported */
        $reported = $request->validated('characters');
        $byName = collect($reported)->mapWithKeys(fn (array $row): array => [mb_strtolower($row['name']) => (bool) $row['logging']]);

        $updated = 0;
        Character::query()
            ->where('user_id', $user->id)
            ->get(['id', 'name'])
            ->each(function (Character $character) use ($byName, &$updated): void {
                $logging = $byName->get(mb_strtolower($character->name));
                if ($logging === null) {
                    return;
                }
                $character->update(['chat_log_to_file' => $logging, 'chat_log_checked_at' => now()]);
                $updated++;
            });

        return response()->json(['updated' => $updated]);
    }
}
