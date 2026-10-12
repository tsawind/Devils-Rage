<?php

declare(strict_types=1);

use DevilsRage\Seat\Http\Controllers\AccountsController;
use DevilsRage\Seat\Http\Controllers\BotApiController;
use DevilsRage\Seat\Http\Controllers\PlannerController;
use DevilsRage\Seat\Http\Controllers\SkillPlansController;
use DevilsRage\Seat\Http\Middleware\BotApiKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'devilsrage',
], function (): void {
    Route::get('accounts', [AccountsController::class, 'index'])->name('devilsrage.accounts.index');
    Route::post('accounts', [AccountsController::class, 'storeAccount'])->name('devilsrage.accounts.store');
    Route::post('accounts/share', [AccountsController::class, 'share'])->name('devilsrage.accounts.share');
    Route::post('accounts/{account}/rename', [AccountsController::class, 'renameAccount'])->name('devilsrage.accounts.rename');
    Route::post('accounts/{account}/delete', [AccountsController::class, 'deleteAccount'])->name('devilsrage.accounts.delete');
    Route::post('accounts/{account}/characters', [AccountsController::class, 'addCharacter'])->name('devilsrage.accounts.characters.add');
    Route::post('account-characters/{accountCharacter}', [AccountsController::class, 'updateCharacter'])->name('devilsrage.accounts.characters.update');
    Route::post('account-characters/{accountCharacter}/remove', [AccountsController::class, 'removeCharacter'])->name('devilsrage.accounts.characters.remove');

    Route::get('layout', [AccountsController::class, 'layout'])->name('devilsrage.layout');
    Route::get('planner', [PlannerController::class, 'index'])->name('devilsrage.planner');

    Route::get('skill-plans', [SkillPlansController::class, 'index'])->name('devilsrage.skillplans.index');
    Route::post('skill-plans', [SkillPlansController::class, 'store'])->name('devilsrage.skillplans.store');
    Route::get('skill-plans/{plan}', [SkillPlansController::class, 'show'])->name('devilsrage.skillplans.show');
    Route::post('skill-plans/{plan}/delete', [SkillPlansController::class, 'destroy'])->name('devilsrage.skillplans.delete');
});

// Read-only API for the mapper's Discord bot (key in X-DR-Key; see BotApiKey).
Route::group([
    'middleware' => [BotApiKey::class, ThrottleRequests::class.':240,1'],
    'prefix' => 'devilsrage/api',
], function (): void {
    Route::get('discord/{discordId}/jump-ships', [BotApiController::class, 'jumpShips']);
    Route::get('discord/{discordId}/assets', [BotApiController::class, 'assets']);
    Route::get('discord/{discordId}/can-fly/{fittingId}', [BotApiController::class, 'canFly'])->whereNumber('fittingId');
    Route::get('fits', [BotApiController::class, 'fits']);
    Route::get('fits/{fittingId}', [BotApiController::class, 'fit'])->whereNumber('fittingId');
});
