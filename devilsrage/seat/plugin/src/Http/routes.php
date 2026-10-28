<?php

declare(strict_types=1);

use DevilsRage\Seat\Http\Controllers\AccountsController;
use DevilsRage\Seat\Http\Controllers\BotApiController;
use DevilsRage\Seat\Http\Controllers\HomeController;
use DevilsRage\Seat\Http\Controllers\PiController;
use DevilsRage\Seat\Http\Controllers\PlannerController;
use DevilsRage\Seat\Http\Controllers\RemindersController;
use DevilsRage\Seat\Http\Controllers\SkillPlansController;
use DevilsRage\Seat\Http\Controllers\SpFarmController;
use DevilsRage\Seat\Http\Controllers\SrpBattleController;
use DevilsRage\Seat\Http\Controllers\SuggestionsController;
use DevilsRage\Seat\Http\Middleware\BotApiKey;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;

Route::group([
    'middleware' => ['web', 'auth', 'locale'],
    'prefix' => 'devilsrage',
], function (): void {
    Route::get('home', [HomeController::class, 'home'])->name('devilsrage.home');
    Route::get('stuff', [HomeController::class, 'stuff'])->name('devilsrage.stuff');
    Route::get('menu', [HomeController::class, 'menu'])->name('devilsrage.menu');
    Route::post('view-mode', [HomeController::class, 'viewMode'])->name('devilsrage.viewmode');
    Route::post('menu', [HomeController::class, 'saveMenu'])->name('devilsrage.menu.save');
    Route::get('accounts', [AccountsController::class, 'index'])->name('devilsrage.accounts.index');
    // Patch 49: log a character in from My accounts (and come back here).
    Route::get('accounts/login/{account?}', [AccountsController::class, 'login'])->name('devilsrage.accounts.login');
    Route::get('accounts/login-main', [AccountsController::class, 'loginMain'])->name('devilsrage.accounts.login-main');
    // Patch 49b: the app's Your accounts screen, and dragging accounts / characters into order.
    Route::get('your-accounts', [AccountsController::class, 'screen'])->name('devilsrage.your-accounts');
    Route::post('accounts/order', [AccountsController::class, 'orderAccounts'])->name('devilsrage.accounts.order');
    Route::post('accounts/{account}/characters/order', [AccountsController::class, 'orderCharacters'])->name('devilsrage.accounts.characters.order');
    // Patch 50: PI screen (the app's PI tab; works in a browser too) and its opt-in daily message.
    Route::get('pi', [PiController::class, 'screen'])->name('devilsrage.pi');
    // Patch 54: SRP for a whole fight (zKillboard / br.evetools related link, or kill links).
    Route::get('srp-battle', [SrpBattleController::class, 'index'])->middleware('can:srp.request')->name('devilsrage.srp.battle');
    Route::post('srp-battle', [SrpBattleController::class, 'file'])->middleware('can:srp.request')->name('devilsrage.srp.battle.file');
    Route::post('pi/alerts', [PiController::class, 'saveAlerts'])->name('devilsrage.pi.alerts');
    Route::post('pi/alerts/test', [PiController::class, 'testAlert'])->name('devilsrage.pi.alerts.test');
    Route::post('pi/update', [PiController::class, 'forceUpdate'])->name('devilsrage.pi.update');
    Route::post('pi/tax', [PiController::class, 'saveTax'])->name('devilsrage.pi.tax');
    // Patch 59: PI colony templates (kept and traded on the PI screen).
    Route::post('pi/templates', [PiController::class, 'saveTemplate'])->name('devilsrage.pi.templates.store');
    Route::post('pi/templates/{template}', [PiController::class, 'updateTemplate'])->whereNumber('template')->name('devilsrage.pi.templates.update');
    Route::post('pi/templates/{template}/delete', [PiController::class, 'deleteTemplate'])->whereNumber('template')->name('devilsrage.pi.templates.delete');
    Route::post('accounts', [AccountsController::class, 'storeAccount'])->name('devilsrage.accounts.store');
    Route::post('accounts/share', [AccountsController::class, 'share'])->name('devilsrage.accounts.share');
    Route::post('accounts/name', [AccountsController::class, 'saveName'])->name('devilsrage.accounts.name');
    Route::post('accounts/{account}/rename', [AccountsController::class, 'renameAccount'])->name('devilsrage.accounts.rename');
    Route::post('accounts/{account}/delete', [AccountsController::class, 'deleteAccount'])->name('devilsrage.accounts.delete');
    Route::post('accounts/{account}/characters', [AccountsController::class, 'addCharacter'])->name('devilsrage.accounts.characters.add');
    Route::post('accounts/{account}/external', [AccountsController::class, 'addExternal'])->name('devilsrage.accounts.characters.external');
    Route::post('account-characters/{accountCharacter}/fits', [AccountsController::class, 'setExternalFits'])->name('devilsrage.accounts.characters.fits');
    Route::post('account-characters/{accountCharacter}/groups', [AccountsController::class, 'setGroups'])->name('devilsrage.accounts.characters.groups');
    Route::post('account-characters/{accountCharacter}/corp-group', [AccountsController::class, 'setCorpGroup'])->name('devilsrage.accounts.characters.corpgroup');
    Route::post('accounts/{account}/omega', [AccountsController::class, 'setOmega'])->name('devilsrage.accounts.omega');
    Route::post('account-characters/{accountCharacter}/move', [AccountsController::class, 'moveCharacter'])->name('devilsrage.accounts.characters.move');
    Route::post('account-characters/{accountCharacter}/remove', [AccountsController::class, 'removeCharacter'])->name('devilsrage.accounts.characters.remove');

    Route::post('launch-groups', [AccountsController::class, 'storeGroup'])->name('devilsrage.groups.store');
    Route::post('launch-groups/{launchGroup}/delete', [AccountsController::class, 'deleteGroup'])->name('devilsrage.groups.delete');

    Route::get('layout', [AccountsController::class, 'layout'])->name('devilsrage.layout');
    Route::get('planner', [PlannerController::class, 'index'])->name('devilsrage.planner');

    Route::get('sp-farm', [SpFarmController::class, 'index'])->name('devilsrage.spfarm.index');
    Route::post('sp-farm', [SpFarmController::class, 'store'])->name('devilsrage.spfarm.store');
    Route::post('sp-farm/trader', [SpFarmController::class, 'trader'])->name('devilsrage.spfarm.trader');
    Route::post('sp-farm/settings', [SpFarmController::class, 'settings'])->name('devilsrage.spfarm.settings');
    Route::post('sp-farm/{characterId}/delete', [SpFarmController::class, 'destroy'])->whereNumber('characterId')->name('devilsrage.spfarm.delete');

    Route::get('skill-plans', [SkillPlansController::class, 'index'])->name('devilsrage.skillplans.index');
    Route::post('skill-plans', [SkillPlansController::class, 'store'])->name('devilsrage.skillplans.store');
    Route::get('skill-plans/{plan}', [SkillPlansController::class, 'show'])->name('devilsrage.skillplans.show');
    Route::post('skill-plans/{plan}/delete', [SkillPlansController::class, 'destroy'])->name('devilsrage.skillplans.delete');

    // Patch 39: suggestions board and one-time reminders.
    Route::get('suggestions', [SuggestionsController::class, 'index'])->name('devilsrage.suggestions.index');
    Route::post('suggestions', [SuggestionsController::class, 'store'])->name('devilsrage.suggestions.store');
    Route::post('suggestions/{suggestion}/vote', [SuggestionsController::class, 'vote'])->name('devilsrage.suggestions.vote');
    Route::post('suggestions/{suggestion}/comment', [SuggestionsController::class, 'comment'])->name('devilsrage.suggestions.comment');
    Route::post('suggestions/{suggestion}/status', [SuggestionsController::class, 'status'])->name('devilsrage.suggestions.status');
    Route::post('suggestions/{suggestion}/delete', [SuggestionsController::class, 'destroy'])->name('devilsrage.suggestions.delete');
    Route::get('reminders', [RemindersController::class, 'index'])->name('devilsrage.reminders.index');
    Route::post('reminders', [RemindersController::class, 'send'])->name('devilsrage.reminders.send');
});

// Read-only API for the mapper's Discord bot (key in X-DR-Key; see BotApiKey).
Route::group([
    'middleware' => [BotApiKey::class, ThrottleRequests::class.':240,1'],
    'prefix' => 'devilsrage/api',
], function (): void {
    Route::get('discord/{discordId}/jump-ships', [BotApiController::class, 'jumpShips']);
    Route::get('discord/{discordId}/assets', [BotApiController::class, 'assets']);
    Route::get('discord/{discordId}/can-fly/{fittingId}', [BotApiController::class, 'canFly'])->whereNumber('fittingId');
    Route::get('discord/{discordId}/accounts', [BotApiController::class, 'accounts']);
    Route::get('discord/{discordId}/extract', [BotApiController::class, 'extract']);
    Route::get('discord/{discordId}/fuel', [BotApiController::class, 'fuel']);
    Route::get('discord/{discordId}/health', [BotApiController::class, 'health']);
    Route::get('people', [BotApiController::class, 'people']);
    Route::get('fits', [BotApiController::class, 'fits']);
    Route::get('fits/{fittingId}', [BotApiController::class, 'fit'])->whereNumber('fittingId');
});
