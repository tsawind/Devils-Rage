<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\MapRally\SendRallyPingAction;
use App\Http\Requests\SendRallyPingRequest;
use App\Models\Map;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class MapRallyPingController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function store(SendRallyPingRequest $request, Map $map, #[CurrentUser] User $user, SendRallyPingAction $action): RedirectResponse
    {
        /** @var array{kind?: string|null, map_webhook_id: int, mention: string, sections?: list<array{title: string, text: string}>|null, note?: string|null} $data */
        $data = $request->validated();
        $action->handle($map, $user, $data);

        return back()->notify('Rally ping sent', message: 'Posted to Discord.');
    }
}
