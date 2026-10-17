<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateGuestNameRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Patch 39: a guest (here through a share link, not on SeAT) sets the name people see.
 */
final class GuestNameController extends Controller
{
    public function update(UpdateGuestNameRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        User::query()->whereKey($user->id)->update(['display_name' => mb_trim($request->string('name')->toString())]);

        return back();
    }
}
