<?php

declare(strict_types=1);

use App\Http\Controllers\AppConfigController;
use App\Http\Controllers\AppChatLogController;
use App\Http\Controllers\AppLocationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BulkMapConnectionController;
use App\Http\Controllers\BulkSignatureController;
use App\Http\Controllers\BulkWaypointController;
use App\Http\Controllers\ChainCleanupController;
use App\Http\Controllers\ConnectionHoleTypeController;
use App\Http\Controllers\DiscordAccountController;
use App\Http\Controllers\DiscordChannelFeedController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\EveController;
use App\Http\Controllers\EveScoutConnectionController;
use App\Http\Controllers\EveSearchController;
use App\Http\Controllers\HoleCheckedController;
use App\Http\Controllers\CharacterColorController;
use App\Http\Controllers\GuestNameController;
use App\Http\Controllers\HomeSystemController;
use App\Http\Controllers\PopOutController;
use App\Http\Controllers\IgnoreListController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\MapAccessController;
use App\Http\Controllers\MapAlertController;
use App\Http\Controllers\MapAlertStateController;
use App\Http\Controllers\MapBackgroundImageController;
use App\Http\Controllers\MapDefaultBackgroundController;
use App\Http\Controllers\MapPresenceController;
use App\Http\Controllers\MapBookmarkFormatController;
use App\Http\Controllers\MapCombatController;
use App\Http\Controllers\MapConnectionController;
use App\Http\Controllers\MapCameraClientController;
use App\Http\Controllers\MapCameraController;
use App\Http\Controllers\MapCameraEventController;
use App\Http\Controllers\MapConnectionJumpController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapDiscordController;
use App\Http\Controllers\MapIgnoredSolarsystemController;
use App\Http\Controllers\MapImportController;
use App\Http\Controllers\MapNamesController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\JumpInController;
use App\Http\Controllers\MassSessionController;
use App\Http\Controllers\RollingCrewController;
use App\Http\Controllers\ShipProfileController;
use App\Http\Controllers\MapLayoutController;
use App\Http\Controllers\MapPreferencesController;
use App\Http\Controllers\MapRageRollController;
use App\Http\Controllers\MapRallyPingController;
use App\Http\Controllers\MapRouteSolarsystemController;
use App\Http\Controllers\MapRoutingSettingsController;
use App\Http\Controllers\MapScopeController;
use App\Http\Controllers\MapSearchController;
use App\Http\Controllers\MapSelectionController;
use App\Http\Controllers\MapSettingsController;
use App\Http\Controllers\MapShareLinkController;
use App\Http\Controllers\MapSolarsystemController;
use App\Http\Controllers\MapTransferController;
use App\Http\Controllers\MapUndoController;
use App\Http\Controllers\MapUserSettingController;
use App\Http\Controllers\MapWebhookController;
use App\Http\Controllers\MapWebhookRoleController;
use App\Http\Controllers\PasteSignatureController;
use App\Http\Controllers\PingController;
use App\Http\Controllers\PreferredCharacterController;
use App\Http\Controllers\RallyPointController;
use App\Http\Controllers\RouteMailController;
use App\Http\Controllers\RouteShareController;
use App\Http\Controllers\RouteWaypointController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SignatureArmController;
use App\Http\Controllers\SignatureController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SystemIntelController;
use App\Http\Controllers\SystemNoteController;
use App\Http\Controllers\TokenManagementController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\UserCharacterController;
use App\Http\Controllers\WaypointController;
use App\Http\Controllers\WindowSwitchController;
use App\Http\Middleware\SetMapShareToken;
use App\Http\Controllers\Seat\SeatCharactersController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LandingController::class, 'index'])->name('landing')->middleware('guest');
Route::get('documentation/{path?}', [DocumentationController::class, 'index'])->where('path', '.*')->name('documentation');
Route::get('login', [LoginController::class, 'show'])->name('login');
Route::get('auth', [AuthController::class, 'show'])->name('auth');
Route::get('eve', [EveController::class, 'show'])->name('eve.show');
Route::get('eve/callback', [EveController::class, 'store'])->name('eve.store');

// Patch 46: the desktop app's settings for everyone (no login: nothing in it is secret).
// Not under /app: that path belongs to Reverb (websockets) in front of the mapper.
Route::get('desktop-app/config', [AppConfigController::class, 'show'])->middleware('throttle:60,1')->name('app.config.show');

// Patch 49: SeAT's My accounts asks which characters are logged in to the mapper (bot key only).
Route::get('seat-api/characters', [SeatCharactersController::class, 'index'])->middleware('throttle:120,1')->name('seat-api.characters');

Route::middleware('auth')->group(function () {
    // Patch 49: "add to the mapper" from SeAT's My accounts, back to SeAT afterwards.
    Route::get('characters/add-from-seat', [SeatCharactersController::class, 'create'])->name('characters.add-from-seat');

    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    // Patch 39: a guest's name in the mapper.
    Route::put('guest-name', [GuestNameController::class, 'update'])->name('guest-name.update');
    // Patch 41: a character's colour (tab, map pin, its window, pop-out).
    Route::put('characters/{character}/color', [CharacterColorController::class, 'update'])->name('characters.color.update');
    Route::post('window-switch', [WindowSwitchController::class, 'store'])->name('window-switch.store');
    // Patch 46: the desktop app's instant location (EVE's Local chat log).
    Route::post('desktop-app/location', [AppLocationController::class, 'store'])->middleware('throttle:240,1')->name('app.location.store');
    // Patch 54: which of your characters write chat logs (EVE's "Log Chat to File").
    Route::post('desktop-app/chat-logs', [AppChatLogController::class, 'store'])->middleware('throttle:30,1')->name('app.chat-logs.store');
    Route::get('desktop-app/admin', [AppConfigController::class, 'edit'])->name('app.admin.edit');
    Route::put('desktop-app/admin', [AppConfigController::class, 'update'])->name('app.admin.update');
    // Patch 39: the scanner pop-out's system and signatures.
    Route::get('map-solarsystems/{mapSolarsystem}/pop-out', [PopOutController::class, 'system'])->name('map-solarsystems.pop-out');
    Route::get('discord/connect', [DiscordAccountController::class, 'redirect'])->name('discord.connect');
    Route::get('discord/callback', [DiscordAccountController::class, 'callback'])->name('discord.callback');
    Route::get('discord/link/{token}', [DiscordAccountController::class, 'link'])->name('discord.link');
    Route::post('discord/link', [DiscordAccountController::class, 'confirmLink'])->name('discord.link.confirm');
    Route::delete('discord', [DiscordAccountController::class, 'destroy'])->name('discord.destroy');

    Route::get('maps/{map}/ping', [PingController::class, 'show'])->name('maps.ping');
    Route::resource('maps', MapController::class)->except(['show', 'create'])->names([
        'index' => 'home',
        'store' => 'maps.store',
        'edit' => 'maps.edit',
        'update' => 'maps.update',
        'destroy' => 'maps.destroy',
    ]);

    Route::prefix('maps/{map}/settings')->name('maps.settings.')->group(function () {
        Route::get('general', [MapSettingsController::class, 'show'])->name('general.show');
        Route::post('toggle-public', [MapSettingsController::class, 'togglePublic'])->name('toggle-public');
        Route::post('generate-share-token', [MapSettingsController::class, 'generateShareToken'])->name('generate-share-token');
        Route::delete('revoke-share-token', [MapSettingsController::class, 'revokeShareToken'])->name('revoke-share-token');
        Route::post('home-system', [HomeSystemController::class, 'store'])->name('home-system');
        Route::post('rally-point', [RallyPointController::class, 'store'])->name('rally-point');
        Route::get('preferences', [MapPreferencesController::class, 'show'])->name('preferences.show');

        Route::get('access', [MapAccessController::class, 'show'])->name('access.show');
        Route::post('access', [MapAccessController::class, 'store'])->name('access.store');

        Route::get('routing', [MapRoutingSettingsController::class, 'show'])->name('routing.show');

        Route::get('mapping', [MapIgnoredSolarsystemController::class, 'show'])->name('mapping.show');

        Route::get('discord', [MapDiscordController::class, 'show'])->name('discord.show');

        Route::get('transfer', [MapTransferController::class, 'show'])->name('transfer.show');
        Route::get('transfer/export', [MapTransferController::class, 'export'])->name('transfer.export');
        Route::post('transfer/import', [MapTransferController::class, 'import'])->name('transfer.import');
    });

    Route::post('maps/import', [MapImportController::class, 'store'])->name('maps.import.store');

    Route::delete('logout', [AuthController::class, 'destroy'])->name('logout');

    Route::resource('map-solarsystems', MapSolarsystemController::class)->only(['store', 'update', 'destroy']);
    Route::resource('map-connections', MapConnectionController::class)->only(['store', 'update', 'destroy']);
    Route::resource('map-connection-jumps', MapConnectionJumpController::class)->only(['store', 'update', 'destroy']);
    Route::delete('maps/{map}/stale-connections', [BulkMapConnectionController::class, 'destroy'])
        ->name('maps.stale-connections.destroy');
    Route::post('maps/{map}/combat', [MapCombatController::class, 'store'])->name('maps.combat.store');
    Route::delete('maps/{map}/combat', [MapCombatController::class, 'destroy'])->name('maps.combat.destroy');
    Route::delete('map-solarsystems/{mapSolarsystem}/combat', [MapCombatController::class, 'clear'])->name('map-solarsystems.combat.clear');
    Route::delete('maps/{map}/combat-chains/{color}', [MapCombatController::class, 'clearChain'])->name('maps.combat-chains.destroy');
    Route::post('map-solarsystems/{mapSolarsystem}/cleanup', [ChainCleanupController::class, 'store'])->name('map-solarsystems.cleanup.store');
    Route::post('map-solarsystems/{mapSolarsystem}/notes', [SystemNoteController::class, 'store'])->name('map-solarsystems.notes.store');
    // Patch 55: a system's intel: structures / POS, D-scans, fits, screenshots, notes, and who lives there.
    Route::get('map-solarsystems/{mapSolarsystem}/intel', [SystemIntelController::class, 'show'])->name('map-solarsystems.intel.show');
    Route::post('map-solarsystems/{mapSolarsystem}/intel/structures', [SystemIntelController::class, 'storeStructure'])->name('map-solarsystems.intel.structures.store');
    Route::post('map-solarsystems/{mapSolarsystem}/intel/structures/import', [SystemIntelController::class, 'importStructures'])->middleware('throttle:60,1')->name('map-solarsystems.intel.structures.import');
    Route::put('map-solarsystems/{mapSolarsystem}/intel/structures/{structure}', [SystemIntelController::class, 'updateStructure'])->name('map-solarsystems.intel.structures.update');
    Route::delete('map-solarsystems/{mapSolarsystem}/intel/structures/{structure}', [SystemIntelController::class, 'destroyStructure'])->name('map-solarsystems.intel.structures.destroy');
    Route::post('map-solarsystems/{mapSolarsystem}/intel/entries', [SystemIntelController::class, 'storeEntry'])->middleware('throttle:60,1')->name('map-solarsystems.intel.entries.store');
    Route::delete('map-solarsystems/{mapSolarsystem}/intel/entries/{entry}', [SystemIntelController::class, 'destroyEntry'])->name('map-solarsystems.intel.entries.destroy');
    Route::post('map-solarsystems/{mapSolarsystem}/intel/screenshots', [SystemIntelController::class, 'storeScreenshot'])->middleware('throttle:30,1')->name('map-solarsystems.intel.screenshots.store');
    Route::get('map-solarsystems/{mapSolarsystem}/intel/screenshots/{entry}', [SystemIntelController::class, 'screenshot'])->name('map-solarsystems.intel.screenshot');
    Route::post('map-solarsystems/{mapSolarsystem}/cleanup-convert', [ChainCleanupController::class, 'convert'])->name('map-solarsystems.cleanup.convert');
    Route::delete('map-solarsystems/{mapSolarsystem}/cleanup-return', [ChainCleanupController::class, 'returnDone'])->name('map-solarsystems.cleanup.return');
    Route::post('map-solarsystems/{mapSolarsystem}/names', [MapNamesController::class, 'update'])->name('map-solarsystems.names.update');
    Route::put('map-solarsystems/{mapSolarsystem}/system', [MapSolarsystemController::class, 'changeSystem'])->name('map-solarsystems.system.update');
    Route::post('map-undo/{token}', [MapUndoController::class, 'store'])->whereUuid('token')->name('map-undo.store');
    Route::put('map-selection', [MapSelectionController::class, 'update'])->name('map-selection.update');
    Route::delete('map-selection', [MapSelectionController::class, 'destroy'])->name('map-selection.destroy');

    Route::post('eve-scout-connections', [EveScoutConnectionController::class, 'store'])->name('eve-scout-connections.store');

    Route::resource('map-solarsystems.signatures', SignatureController::class)->only(['store', 'update', 'destroy'])->shallow();
    Route::resource('paste-signatures', PasteSignatureController::class)->only(['store']);
    Route::post('signatures/{signature}/arm', [SignatureArmController::class, 'store'])->name('signatures.arm.store');
    Route::get('map-connections/{mapConnection}/jumps', [MapConnectionJumpController::class, 'index'])->name('map-connections.jumps.index');
    Route::post('map-connections/{mapConnection}/watched', [HoleCheckedController::class, 'watch'])->name('map-connections.watched');
    Route::post('map-connections/{mapConnection}/fresh', [HoleCheckedController::class, 'fresh'])->name('map-connections.fresh');
    Route::post('map-connections/{mapConnection}/checked', [HoleCheckedController::class, 'connection'])->name('map-connections.checked');
    Route::post('map-connections/{mapConnection}/kept-range', [HoleCheckedController::class, 'kept'])->name('map-connections.kept-range');
    Route::post('signatures/{signature}/checked', [HoleCheckedController::class, 'signature'])->name('signatures.checked');
    Route::post('signatures/{signature}/watched', [HoleCheckedController::class, 'watchSignature'])->name('signatures.watched');
    Route::post('map-connections/{mapConnection}/hole-type', [ConnectionHoleTypeController::class, 'store'])->name('map-connections.hole-type.store');
    Route::post('map-connections/{mapConnection}/relink', [ConnectionHoleTypeController::class, 'relink'])->name('map-connections.relink');
    Route::post('signatures/{signature}/absorb', [ConnectionHoleTypeController::class, 'absorb'])->name('signatures.absorb');
    Route::delete('signatures/{signature}/arm', [SignatureArmController::class, 'destroy'])->name('signatures.arm.destroy');

    Route::put('user-characters/{character}', [UserCharacterController::class, 'update'])->name('user-characters.update');
    Route::delete('user-characters/{character}', [UserCharacterController::class, 'delete'])->name('user-characters.delete');

    Route::post('preferred-character/{character}', [PreferredCharacterController::class, 'store'])->name('preferred-character.store');

    Route::post('tracking', [TrackingController::class, 'store'])->name('tracking.store');

    Route::post('waypoints', [WaypointController::class, 'store'])->name('waypoints.store');
    Route::post('waypoints/bulk', [BulkWaypointController::class, 'store'])->name('waypoints.bulk');
    Route::post('waypoints/route', [RouteWaypointController::class, 'store'])->name('waypoints.route');
    // Patch 37: viewer links (Share button) and the mailed route report.
    Route::post('maps/{map}/share-links', [MapShareLinkController::class, 'store'])->name('maps.share-links.store');
    Route::patch('share-links/{mapShareLink}', [MapShareLinkController::class, 'update'])->name('share-links.update');
    Route::delete('share-links/{mapShareLink}', [MapShareLinkController::class, 'destroy'])->name('share-links.destroy');
    Route::post('maps/{map}/route-mail', [RouteMailController::class, 'store'])->name('maps.route-mail.store');

    Route::prefix('scopes')->name('scopes.')->group(function () {
        Route::get('/', [ScopeController::class, 'index'])->name('index');
        Route::get('add', [ScopeController::class, 'show'])->name('show');
        Route::delete('{character}', [ScopeController::class, 'destroy'])->name('destroy');
    });

    Route::get('maps/{map}/scopes/add', [MapScopeController::class, 'show'])->name('map-scopes.show');

    Route::resource('map-route-solarsystems', MapRouteSolarsystemController::class)->only(['store', 'update', 'destroy']);
    Route::post('statistics', [StatisticsController::class, 'store'])->name('statistics.store');

    Route::post('ignore-systems', [IgnoreListController::class, 'store'])->name('ignore-systems.store');
    Route::delete('ignore-system/{solarsystem_id}', [IgnoreListController::class, 'destroy'])->name('ignore-systems.destroy');
    Route::delete('ignore-systems', [IgnoreListController::class, 'destroyAll'])->name('ignore-systems.destroy-all');

    Route::resource('map-webhooks', MapWebhookController::class)->only(['store', 'update', 'destroy']);
    Route::resource('map-webhook-roles', MapWebhookRoleController::class)->only(['store', 'update', 'destroy']);
    Route::resource('map-alerts', MapAlertController::class)->only(['store', 'update', 'destroy']);
    Route::post('maps/{map}/rally-ping', [MapRallyPingController::class, 'store'])->name('maps.rally-ping.store');
    Route::post('maps/{map}/rage-roll', [MapRageRollController::class, 'store'])->name('maps.rage-roll.store');
    Route::put('maps/{map}/rage-roll/targets', [MapRageRollController::class, 'targets'])->name('maps.rage-roll.targets');
    Route::post('maps/{map}/rage-roll/collapsed', [MapRageRollController::class, 'collapsed'])->name('maps.rage-roll.collapsed');
    Route::post('maps/{map}/rage-roll/new-static', [MapRageRollController::class, 'newStatic'])->name('maps.rage-roll.new-static');
    Route::delete('maps/{map}/rage-roll', [MapRageRollController::class, 'destroy'])->name('maps.rage-roll.destroy');
    Route::delete('discord-channel-feeds/{discord_channel_feed}', [DiscordChannelFeedController::class, 'destroy'])->name('discord-channel-feeds.destroy');
    Route::put('map-alerts/{map_alert}/state', [MapAlertStateController::class, 'update'])->name('map-alerts.state.update');
    Route::get('eve/ship-search', [EveSearchController::class, 'index'])->name('eve.ship-search');
    Route::get('maps/{map}/search', [MapSearchController::class, 'index'])->name('maps.search');

    Route::post('map-ignored-solarsystems', [MapIgnoredSolarsystemController::class, 'store'])->name('map-ignored-solarsystems.store');
    Route::delete('maps/{map}/ignored-solarsystems/{solarsystem_id}', [MapIgnoredSolarsystemController::class, 'destroy'])->name('map-ignored-solarsystems.destroy');
    Route::delete('maps/{map}/ignored-solarsystems', [MapIgnoredSolarsystemController::class, 'destroyAll'])->name('map-ignored-solarsystems.destroy-all');

    Route::delete('map-solarsystems/{mapSolarsystem}/signatures', [BulkSignatureController::class, 'destroy'])
        ->name('map-solarsystems.signatures.destroy');

    Route::resource('tokens', TokenManagementController::class)->only(['index', 'store', 'destroy']);

    Route::post('maps/{map}/background-image', [MapBackgroundImageController::class, 'store'])->name('maps.background-image.store');
    Route::delete('maps/{map}/background-image', [MapBackgroundImageController::class, 'destroy'])->name('maps.background-image.destroy');
    Route::post('maps/{map}/default-background', [MapDefaultBackgroundController::class, 'store'])->name('maps.default-background.store');
    Route::patch('maps/{map}/default-background', [MapDefaultBackgroundController::class, 'update'])->name('maps.default-background.update');
    Route::delete('maps/{map}/default-background', [MapDefaultBackgroundController::class, 'destroy'])->name('maps.default-background.destroy');

    Route::put('maps/{map}/layout', [MapLayoutController::class, 'update'])->name('maps.layout.update');

    // Patch 37: the fleet you're in (read with the boss's token) and jump-in plans for the search.
    Route::get('maps/{map}/fleet', [FleetController::class, 'show'])->middleware('throttle:30,1')->name('maps.fleet.show');
    Route::get('maps/{map}/jump-in', [JumpInController::class, 'show'])->middleware('throttle:60,1')->name('maps.jump-in.show');
    Route::get('maps/{map}/ship-profiles', [ShipProfileController::class, 'index'])->name('maps.ship-profiles.index');
    Route::put('maps/{map}/ship-profiles', [ShipProfileController::class, 'update'])->name('maps.ship-profiles.update');
    Route::delete('maps/{map}/ship-profiles', [ShipProfileController::class, 'destroy'])->name('maps.ship-profiles.destroy');
    Route::post('maps/{map}/ship-profiles/refresh', [ShipProfileController::class, 'refresh'])->middleware('throttle:20,1')->name('maps.ship-profiles.refresh');

    // Patch 37: "⚖ Adding mass…" sessions, what people saw on holes, and rolling crews.
    Route::get('maps/{map}/mass-sessions', [MassSessionController::class, 'index'])->name('maps.mass-sessions.index');
    Route::post('maps/{map}/mass-sessions', [MassSessionController::class, 'store'])->name('maps.mass-sessions.store');
    Route::patch('maps/{map}/mass-sessions/{massSession}', [MassSessionController::class, 'update'])->name('maps.mass-sessions.update');
    Route::delete('maps/{map}/mass-sessions/{massSession}', [MassSessionController::class, 'destroy'])->name('maps.mass-sessions.destroy');
    Route::post('maps/{map}/mass-sessions/{massSession}/observe', [MassSessionController::class, 'observe'])->name('maps.mass-sessions.observe');
    Route::post('maps/{map}/rolling-crews', [RollingCrewController::class, 'store'])->name('maps.rolling-crews.store');
    Route::put('maps/{map}/rolling-crews/{rollingCrew}', [RollingCrewController::class, 'update'])->name('maps.rolling-crews.update');
    Route::delete('maps/{map}/rolling-crews/{rollingCrew}', [RollingCrewController::class, 'destroy'])->name('maps.rolling-crews.destroy');
    Route::get('maps/{map}/rolling-crews/lookup', [RollingCrewController::class, 'lookup'])->name('maps.rolling-crews.lookup');

    Route::put('maps/{map}/bookmark-format', [MapBookmarkFormatController::class, 'update'])->name('maps.bookmark-format.update');

    // Patch 62: cameras (streaming an EVE window / screen through the video relay).
    Route::get('maps/{map}/cameras/share', [MapCameraController::class, 'share'])->name('maps.cameras.share');
    Route::post('maps/{map}/cameras', [MapCameraController::class, 'store'])->middleware('throttle:120,1')->name('maps.cameras.store');
    Route::patch('map-cameras/{mapCamera}', [MapCameraController::class, 'update'])->middleware('throttle:240,1')->name('map-cameras.update');
    Route::delete('map-cameras/{mapCamera}', [MapCameraController::class, 'destroy'])->name('map-cameras.destroy');
    // Patch 62q: your own camera straight from your share window (WebRTC offer / answer passed along).
    Route::post('map-cameras/{mapCamera}/direct', [MapCameraController::class, 'direct'])->middleware('throttle:120,1')->name('map-cameras.direct');
    Route::post('map-cameras/{mapCamera}/events', [MapCameraEventController::class, 'store'])->middleware('throttle:60,1')->name('map-cameras.events.store');
    Route::patch('map-camera-events/{mapCameraEvent}', [MapCameraEventController::class, 'update'])->name('map-camera-events.update');
    Route::post('maps/{map}/camera-events/dismiss', [MapCameraEventController::class, 'dismiss'])->middleware('throttle:60,1')->name('maps.camera-events.dismiss');
    Route::post('map-camera-events/{mapCameraEvent}/files', [MapCameraEventController::class, 'attach'])->middleware('throttle:60,1')->name('map-camera-events.attach');
    // Patch 62k: your share window's clients, run from the map (pilot tabs, the hole watcher).
    Route::get('maps/{map}/camera-clients', [MapCameraClientController::class, 'index'])->middleware('throttle:120,1')->name('maps.camera-clients.index');
    Route::put('maps/{map}/camera-clients', [MapCameraClientController::class, 'report'])->middleware('throttle:120,1')->name('maps.camera-clients.report');
    Route::post('maps/{map}/camera-clients/control', [MapCameraClientController::class, 'control'])->middleware('throttle:60,1')->name('maps.camera-clients.control');
});

// Public map access (no auth required)
Route::get('maps/{map}', [MapController::class, 'show'])->middleware(SetMapShareToken::class)->name('maps.show');
// Patch 62: cameras for members and the guest links a camera allows.
Route::get('maps/{map}/cameras', [MapCameraController::class, 'wall'])->name('maps.cameras.wall');
Route::get('maps/{map}/camera-feed', [MapCameraController::class, 'index'])->middleware('throttle:120,1')->name('maps.cameras.index');
Route::post('map-cameras/{mapCamera}/watch', [MapCameraController::class, 'watch'])->middleware('throttle:60,1')->name('map-cameras.watch');
Route::get('map-camera-events/{mapCameraEvent}/{which}', [MapCameraEventController::class, 'file'])->whereIn('which', ['clip', 'still'])->name('map-camera-events.file');
// Patch 37: every open map checks in (viewer-link visitors too); managers read who is here.
Route::post('maps/{map}/presence', [MapPresenceController::class, 'store'])->middleware('throttle:20,1')->name('maps.presence.store');
Route::get('maps/{map}/presence', [MapPresenceController::class, 'index'])->middleware('auth')->name('maps.presence.index');
Route::put('maps/{map}/user-settings', [MapUserSettingController::class, 'update'])->name('maps.user-settings.update');
Route::get('share/{token}', [MapController::class, 'showByToken'])->name('maps.share');
// Patch 37: a mailed route report's page (24 h, no login).
Route::get('r/{token}', [RouteShareController::class, 'show'])->name('share.route');
