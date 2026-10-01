<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Signatures\ArmSignatureAction;
use App\Models\Signature;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Patch 13: arm a hole as your next jump (right-click → Arm, a single paste,
 * a full paste with one hole on grid), "Arm as…" another number, or disarm.
 */
final class SignatureArmController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(Request $request, Signature $signature, ArmSignatureAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $signature);

        $validated = $request->validate([
            'alias' => ['required', 'string', 'max:32'],
            'fallback_alias' => ['nullable', 'string', 'max:32'],
            'swap' => ['boolean'],
        ]);

        $action->arm($user, $signature, (string) $validated['alias'], $validated['fallback_alias'] ?? null, (bool) ($validated['swap'] ?? false));

        return back();
    }

    /**
     * @throws Throwable
     */
    public function destroy(Signature $signature, ArmSignatureAction $action, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $signature);

        // Your own arm, or one that has run out.
        if ($signature->armed_by_user_id !== null && $signature->armed_by_user_id !== $user->id && $signature->isArmed()) {
            throw ValidationException::withMessages(['signature' => sprintf('%s armed this hole.', $signature->armed_by_name ?? 'Someone else')]);
        }

        $action->disarm($signature);

        return back();
    }
}
