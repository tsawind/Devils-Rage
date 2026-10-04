<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Signatures\PasteSignaturesAction;
use App\Data\SignaturesData;
use App\Models\User;
use App\Support\Undo\MapUndoSnapshots;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class PasteSignatureController extends Controller
{
    /**
     * @throws Throwable
     */
    public function store(Request $request, SignaturesData $data, PasteSignaturesAction $action, MapUndoSnapshots $undo, #[CurrentUser] User $user): RedirectResponse
    {
        Gate::authorize('update', $data->mapSolarsystem);

        // Patch 21: the system's signatures as they were, so Undo can put them back (and drop what the paste added).
        $token = MapUndoSnapshots::tokenFrom($request);
        if ($token !== null) {
            $undo->capture($data->mapSolarsystem->map, $user->id, $token, signatureSystemIds: [$data->mapSolarsystem->id], prune: true);
        }

        $action->handle($data);

        return back()->notify('Signature pasted successfully!', message: 'You successfully pasted a signature from clipboard.');
    }
}
