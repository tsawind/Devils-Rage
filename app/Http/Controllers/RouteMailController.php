<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\EsiToken;
use App\Models\Map;
use App\Models\MapShareLink;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use NicolasKion\Esi\DTO\EveMailRecipient;
use NicolasKion\Esi\Enums\EsiScope;
use NicolasKion\Esi\Enums\RecipientType;
use NicolasKion\Esi\Esi;
use Throwable;

/**
 * Patch 37: mail a route report to yourself (the character you fly) or to your main (from
 * SeAT), sent through EVE mail as your active character, with real system links and a
 * 24 h link to a page with the routes as they were. Forward it in game from there.
 */
final class RouteMailController extends Controller
{
    /** In the mail body, replaced by the route page's address. */
    public const string LINK_PLACEHOLDER = '{{route_link}}';

    public function store(Request $request, Map $map, #[CurrentUser] User $user, Esi $esi): RedirectResponse
    {
        Gate::authorize('update', $map);

        $validated = $request->validate([
            'to' => ['required', 'in:main,self'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:8000'],
            'report' => ['nullable', 'array'],
        ]);

        $sender = $user->active_character;
        if (! $sender instanceof Character) {
            throw ValidationException::withMessages(['to' => 'Pick a character first.']);
        }
        if (! $sender->getEsiTokenWithScope(EsiScope::SendMail) instanceof EsiToken) {
            throw ValidationException::withMessages(['to' => sprintf('%s needs the mail permission: Settings → EVE characters → Grant all permissions.', $sender->name)]);
        }

        $recipientId = $validated['to'] === 'self' ? $sender->id : $this->mainOf($sender);
        if ($recipientId === null) {
            throw ValidationException::withMessages(['to' => sprintf('SeAT has no main for %s yet: send it to this character instead.', $sender->name)]);
        }

        $body = (string) $validated['body'];
        if (str_contains($body, self::LINK_PLACEHOLDER)) {
            $link = $map->shareLinks()->create([
                'token' => MapShareLink::newToken(),
                'kind' => 'route',
                'label' => 'Route report',
                'created_by_user_id' => $user->id,
                'created_by_name' => Actor::name(),
                'expires_at' => now()->addHours(MapShareLink::ROUTE_HOURS),
                'payload' => $validated['report'] ?? null,
            ]);
            $body = str_replace(self::LINK_PLACEHOLDER, route('share.route', ['token' => $link->token]), $body);
        }

        try {
            $result = $esi->sendMail($sender, [new EveMailRecipient($recipientId, RecipientType::Character)], (string) $validated['subject'], mb_substr($body, 0, 8000));
        } catch (Throwable $throwable) {
            throw ValidationException::withMessages(['to' => 'EVE mail could not be reached: '.$throwable->getMessage()]);
        }

        if ($result->failed()) {
            throw ValidationException::withMessages(['to' => sprintf('EVE refused the mail (error %d; it limits how often mail can be sent): %s', $result->error->code, mb_substr((string) $result->error->body, 0, 200))]);
        }

        return back()->notify('Route report mailed', message: $validated['to'] === 'self' ? sprintf('Sent to %s.', $sender->name) : 'Sent to your main.');
    }

    private function mainOf(Character $character): ?int
    {
        if ($character->seat_main_id !== null) {
            return (int) $character->seat_main_id;
        }
        if ($character->seat_main_name === null) {
            return null;
        }
        $id = Character::query()->where('name', $character->seat_main_name)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
