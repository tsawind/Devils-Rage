<!doctype html>
{{-- Patch 49b: the app's "Your accounts" screen (works in a browser too). Same data as My accounts. --}}
@php
  $statusOf = function (int $id) use ($seatStatus, $mapperStatus): array {
      $seat = $seatStatus[$id] ?? 'missing';
      $mapper = $mapperStatus === null ? null : ($mapperStatus[$id] ?? false);
      $seatOk = in_array($seat, ['ok', 'public'], true);
      return ['seat' => $seat, 'mapper' => $mapper, 'ok' => $seatOk && $mapper !== false];
  };
  $screenBack = route('devilsrage.your-accounts', ['done' => 1]);
  $mapperAdd = fn (bool $public = false): string => $mapperUrl.'/characters/add-from-seat?return='.urlencode($screenBack).($public ? '&public=1' : '');
  $seatLogin = fn ($account, bool $public = false): string => route('devilsrage.accounts.login', array_filter([$account?->id, 'screen' => 1, 'public' => $public ? 1 : null]));
  $cards = [];
  $total = 0;
  $ready = 0;
  foreach ($accounts as $account) {
      $rows = [];
      $steps = [];
      foreach ($account->characters as $link) {
          $st = $link->external ? ['seat' => 'external', 'mapper' => null, 'ok' => true] : $statusOf((int) $link->character_id);
          // Patch 74: an ally / partial character only needs the mapper when it shares its location.
          $level = $link->accessLevel();
          $sharesLocation = ! in_array($level, ['ally', 'partial'], true) || in_array('location', \DevilsRage\Seat\Services\AccessScopes::groupsOf((array) $link->access_scopes, $allScopes), true);
          if (! $sharesLocation && ! $link->external) {
              $st['mapper'] = null;
              $st['ok'] = in_array($st['seat'], ['ok', 'public'], true);
          }
          $rows[] = ['link' => $link, 'st' => $st];
          if (! $link->external) {
              $total++;
              $ready += $st['ok'] ? 1 : 0;
              if (! in_array($st['seat'], ['ok', 'public'], true)) {
                  $steps[] = ['kind' => 'seat', 'name' => $link->displayName(), 'url' => in_array($level, ['ally', 'partial'], true) ? route('devilsrage.accounts.login-access', $link) : $seatLogin($account, $level === 'public')];
              }
              if ($st['mapper'] === false) {
                  $steps[] = ['kind' => 'mapper', 'name' => $link->displayName(), 'url' => $mapperAdd($link->public_only)];
              }
          }
      }
      $cards[] = ['account' => $account, 'rows' => $rows, 'steps' => $steps];
  }
  foreach ($unassigned as $character) {
      $total++;
      $ready += $statusOf((int) $character->character_id)['ok'] ? 1 : 0;
  }
  $labels = [
      'ok' => ['SeAT ✓', 'ok', 'SeAT login works, with everything it chose to share'],
      'public' => ['Public only', 'pub', 'Logged in with public data only: proves it is yours, shares nothing else'],
      'scopes' => ['SeAT renew', 'todo', 'SeAT login is missing permissions: log it in again'],
      'expired' => ['SeAT expired', 'bad', 'SeAT login expired: log it in again'],
      'missing' => ['SeAT add', 'todo', 'Not logged in to SeAT'],
      'external' => ['Not verified', 'muted', 'Added by name, never logged in: public data only'],
  ];
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Your accounts · Devil's Rage</title>
<style>
  :root { --bg:#0d0d10; --card:#16161a; --card2:#1b1b20; --line:#2a2a30; --text:#e2e2e8; --muted:#8a8a92;
          --red:#b3262a; --red-hi:#d6242c; --ok:#3fbf5f; --warn:#f0b44c; --bad:#f27a7a; --pub:#7ab8f2; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { background: var(--bg); color: var(--text); font: 13.5px/1.45 "Segoe UI", system-ui, sans-serif; }
  a { color: inherit; }
  button, select, input { font: inherit; color: inherit; }
  .wrap { max-width: 1180px; margin: 0 auto; padding: 18px 20px 40px; }
  .top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
  .top h1 { font-size: 20px; font-weight: 600; margin: 0; }
  .muted { color: var(--muted); }
  .bar { flex: 1; min-width: 120px; max-width: 260px; height: 6px; background: #26262c; border-radius: 3px; }
  .bar > div { height: 6px; background: var(--ok); border-radius: 3px; }
  .btn { background: transparent; border: 1px solid #3a3a44; border-radius: 6px; padding: 6px 11px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
  .btn:hover { border-color: #5a5a66; }
  .btn.p { background: var(--red); border-color: var(--red); color: #fff; }
  .btn.p:hover { background: var(--red-hi); }
  .btn.sm { padding: 3px 8px; font-size: 12px; }
  .note { border-radius: 8px; padding: 9px 12px; margin-bottom: 12px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
  .note.ok { background: #13261a; border: 1px solid #22502f; }
  .note.warn { background: #2a2110; border: 1px solid #5a4314; }
  .grid { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 14px; align-items: start; }
  .list { display: flex; flex-direction: column; gap: 6px; }
  .acct { background: var(--card); border: 1px solid var(--line); border-left: 3px solid var(--ok); padding: 8px 10px; cursor: pointer; display: flex; align-items: center; gap: 6px; }
  .acct.todo { border-left-color: var(--warn); }
  .acct.sel { border-color: var(--red); border-left-color: var(--red-hi); background: var(--card2); }
  .acct .nick { margin-left: auto; text-align: right; }
  .grip { color: #55555e; cursor: grab; user-select: none; padding: 0 2px; }
  .pic { width: 30px; height: 30px; border-radius: 4px; background: #2a2a32; object-fit: cover; }
  .pic.empty { background: transparent; border: 1px dashed #44444c; }
  .drop { outline: 1px dashed var(--red-hi); }
  .panel { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px; display: none; }
  .panel.show { display: block; }
  .phead { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px; }
  .phead .name { font-size: 17px; font-weight: 600; }
  .hint { display: flex; align-items: center; gap: 6px; color: var(--muted); font-size: 12px; margin-bottom: 10px; }
  .hint input { background: #101014; border: 1px solid var(--line); border-radius: 4px; padding: 2px 6px; width: 260px; }
  .row { display: grid; grid-template-columns: 18px 34px minmax(0, 1fr) 110px 100px 92px 22px; gap: 8px; align-items: center; padding: 7px 4px; border-bottom: 1px solid #222228; }
  .row .cn { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .chip { font-size: 11.5px; padding: 2px 7px; border-radius: 4px; text-align: center; text-decoration: none; white-space: nowrap; }
  .chip.ok { color: var(--ok); }
  .chip.todo { background: #3a2c12; color: var(--warn); }
  .chip.bad { background: #3a1416; color: var(--bad); }
  .chip.pub { background: #14283a; color: var(--pub); }
  .chip.muted { color: var(--muted); }
  a.chip:hover { filter: brightness(1.2); }
  select.grp { background: #101014; border: 1px solid var(--line); border-radius: 4px; padding: 2px 4px; font-size: 12px; }
  .x { background: none; border: 0; color: #66666f; cursor: pointer; }
  .x:hover { color: var(--bad); }
  .add { color: var(--red-hi); cursor: pointer; }
  .choice { display: none; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0; }
  .choice.show { display: grid; }
  .opt { background: #101014; border: 1px solid var(--line); border-radius: 8px; padding: 10px; text-decoration: none; }
  .opt:hover { border-color: var(--red-hi); }
  .opt b { display: block; font-weight: 600; margin-bottom: 2px; }
  .steps { background: #101014; border: 1px solid #3a3a44; border-radius: 8px; padding: 10px 12px; margin-top: 12px; display: none; }
  .steps.show { display: block; }
  .steps div.s { padding: 2px 0; color: #77777f; }
  .steps div.s.now { color: var(--text); }
  .steps div.s.done { color: var(--ok); }
  .foot { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
  .badge { font-size: 11px; padding: 1px 6px; border-radius: 4px; margin-right: 4px; }
  .badge-success { background: #1d2b1d; color: #8fd48f; } .badge-warning { background: #3a2c12; color: var(--warn); }
  .badge-danger { background: #3a1416; color: var(--bad); } .badge-secondary { background: #26262c; color: var(--muted); }
  .badge-info { background: #14283a; color: var(--pub); }
  .newacct { display: none; gap: 6px; margin-top: 6px; }
  .newacct.show { display: flex; }
  .newacct input { background: #101014; border: 1px solid var(--line); border-radius: 4px; padding: 4px 6px; width: 120px; }
  .pills { grid-column: 3 / -1; display: flex; flex-wrap: wrap; gap: 4px; margin-top: -2px; }
  .pills label { cursor: pointer; margin: 0; }
  .chatlog { grid-column: 3 / -1; font-size: 11.5px; color: var(--warn); margin-top: -2px; }
  .pills input { display: none; }
  .pills span { font-size: 11px; padding: 1px 8px; border-radius: 999px; background: #1c1a2a; color: #77708f; border: 1px solid transparent; }
  .pills input:checked + span { background: #2a2340; color: #b9a8ff; border-color: #5a4a9a; }
  /* ★ corp groups (everyone's) in green, your own in purple. */
  .pills label.corp span { background: #15231a; color: #6f9a7b; }
  .pills label.corp input:checked + span { background: #1d3a26; color: #8fe0a6; border-color: #3f8a55; }
  .groups .g.corp { background: #1d3a26; color: #8fe0a6; }
  .groups { margin-top: 14px; padding-top: 10px; border-top: 1px solid #222228; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 12px; }
  .groups .g { background: #2a2340; color: #b9a8ff; border-radius: 999px; padding: 1px 4px 1px 9px; display: inline-flex; align-items: center; gap: 2px; }
  .groups input { background: #101014; border: 1px solid var(--line); border-radius: 4px; padding: 3px 6px; width: 170px; }
  .omega { display: none; background: #101014; border: 1px solid #3a3a44; border-radius: 8px; padding: 10px 12px; margin: 4px 0 10px; }
  .omega.show { display: block; }
  .omega .orow { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; font-size: 13px; }
  .omega .orow:last-child { margin-bottom: 0; }
  .omega .orow > label:first-child { width: 7.5rem; color: var(--muted); }
  .omega input[type=date], .omega input[type=number], .omega select { background: #0b0b0e; color: var(--text); border: 1px solid var(--line); border-radius: 4px; padding: 3px 6px; color-scheme: dark; }
  .omega .chk { display: inline-flex; gap: 4px; align-items: center; color: var(--muted); }
  .badge-light { background: #26262c; color: var(--text); }
  .confirm { position: fixed; inset: 0; background: rgba(0,0,0,.55); display: none; align-items: center; justify-content: center; z-index: 50; }
  .confirm.show { display: flex; }
  .confirm .box { background: var(--card2); border: 1px solid #3a3a44; border-radius: 10px; padding: 16px 18px; max-width: 420px; }
  .confirm .box p { margin: 0 0 12px; }
  .confirm .box div { display: flex; gap: 8px; justify-content: flex-end; }
  /* Patch 74: Ω / α on the tiles, a red dot for something to fix, incognito characters greyed. */
  .acct { border-left-color: #2f2f36; }
  .acct.todo { border-left-color: #2f2f36; }
  .acct.sel { border-left-color: var(--red-hi); }
  .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--red-hi); margin-right: 5px; vertical-align: 2px; }
  .om { font-size: 11px; font-weight: 600; }
  .om.om { color: #e8c35a; } .om.soon { color: var(--warn); } .om.alpha { color: #9aa4b5; } .om.ended { color: var(--bad); } .om.unknown { color: var(--muted); font-weight: 400; }
  .pills label:not(:has(input:checked)) { display: none; }
  .pills.open label { display: inline; }
  .pills .more { background: none; border: 1px dashed #3a3a44; border-radius: 999px; color: var(--muted); font-size: 11px; padding: 0 7px; cursor: pointer; order: 99; }
  .pills .more:hover { color: var(--text); border-color: #5a5a66; }
  .pills { display: flex; }
  .incog-toggle { background: none; border: 0; padding: 0 2px; cursor: pointer; opacity: .35; font-size: 12px; }
  .incog-toggle:hover, .incog-toggle.on { opacity: 1; }
  .row.hidden-pc .cn { color: var(--muted); }
  .row.incog { opacity: .75; }
  .row.incog .pic { filter: grayscale(1); opacity: .6; }
  .row.incog .cn { color: var(--muted); }
  .incog-box { grid-column: 4 / -1; display: flex; gap: 6px; align-items: center; font-size: 12px; color: var(--muted); }
  .incog-box input { accent-color: #8a8a92; }
  .pic.ghost { filter: grayscale(1); opacity: .5; }
  body:not(.advanced) #pc-only, body:not(.advanced) .row.incog, body:not(.advanced) .incog-toggle, body:not(.advanced) details.profs .prow button { display: none !important; }
  #pc-only { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
  #pc-only h4 { margin: 4px 0 0; font-size: 11px; letter-spacing: .6px; text-transform: uppercase; color: var(--muted); font-weight: 600; }
  .acct.pc { border-style: dashed; opacity: .8; }
  /* Patch 74: the account list scrolls on its own; training portraits pulse; options. */
  .grid > div:first-child { position: sticky; top: 8px; }
  #acct-list { max-height: calc(100vh - 150px); overflow-y: auto; padding-right: 4px; scrollbar-width: thin; scrollbar-color: #3a3a44 transparent; }
  .opts { display: flex; gap: 16px; font-size: 12px; color: var(--muted); margin: -6px 0 6px; }
  .opts input { accent-color: var(--red-hi); vertical-align: -2px; }
  .hide-alpha .acct.is-alpha { display: none; }
  .login-name { color: var(--muted); font-weight: 400; font-size: 11px; margin-left: 5px; display: none; }
  .show-login .login-name { display: inline; }
  @keyframes dr-train { 0%, 100% { box-shadow: 0 0 0 0 rgba(63, 191, 95, .0); } 50% { box-shadow: 0 0 0 3px rgba(63, 191, 95, .55); } }
  .pic.training { animation: dr-train 2.4s ease-in-out infinite; }
  .sheet-pop { position: fixed; z-index: 60; background: var(--card2); border: 1px solid #3a3a44; border-radius: 8px; padding: 8px 10px; font-size: 12px; max-width: 320px; box-shadow: 0 6px 18px rgba(0,0,0,.5); pointer-events: none; }
  .sheet-pop.menu { pointer-events: auto; }
  .sheet-pop b { color: var(--text); }
  .sheet-pop .ok { color: var(--ok); } .sheet-pop .bad { color: var(--warn); }
  .pp { display: flex; flex-direction: column; align-items: center; gap: 1px; }
  .pp small { font-size: 9.5px; color: var(--muted); line-height: 1; white-space: nowrap; }
  .pp.tile small { font-size: 9px; min-height: 9px; }
  .acct { padding-top: 5px; padding-bottom: 5px; }
  .acct .pic.empty { margin-bottom: 10px; }
  details.profs { grid-column: 3 / -1; font-size: 11px; color: var(--muted); margin-top: -2px; }
  details.profs summary { cursor: pointer; list-style: none; }
  details.profs summary::-webkit-details-marker { display: none; }
  details.profs summary::after { content: ' ▾'; }
  details.profs[open] summary::after { content: ' ▴'; }
  details.profs .prow { display: flex; gap: 8px; align-items: center; padding: 2px 0 2px 10px; }
  details.profs .prow span:first-child { width: 150px; }
  details.profs .prow button { background: none; border: 1px solid #3a3a44; border-radius: 4px; color: var(--muted); font-size: 11px; padding: 0 6px; cursor: pointer; }
  details.profs .prow button:hover { color: var(--text); }
  .ownprof { font-size: 12px; color: var(--muted); margin: -4px 0 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .ownprof details { width: 100%; }
  .charinfo { grid-column: 3 / -1; font-size: 11px; color: var(--muted); margin-top: -2px; }
  /* Patch 74: what each character shares. A ring on the portrait for anything less than a Devil's. */
  .access { grid-column: 3 / -1; display: flex; gap: 8px; align-items: center; font-size: 11.5px; color: var(--muted); }
  .access select.lvl { background: #101014; border: 1px solid var(--line); border-radius: 4px; padding: 1px 4px; font-size: 11.5px; max-width: 240px; }
  /* 19 Nov: one look everywhere: Ally blue, Public purple, Spy red with an ✕ (Devils plain). */
  .pp { position: relative; }
  .row.lvl-ally .pp .pic, .pp.lvl-ally .pic { outline: 2px solid #3d8bfd; outline-offset: 1px; }
  .row.lvl-public .pp .pic, .pp.lvl-public .pic { outline: 2px solid #a370f7; outline-offset: 1px; }
  .row.lvl-spy .pp .pic, .pp.lvl-spy .pic { outline: 2px solid var(--bad); outline-offset: 1px; filter: grayscale(.6); }
  .row.lvl-spy .pp::after, .pp.lvl-spy::after { content: '✕'; position: absolute; top: -5px; right: -5px; width: 15px; height: 15px; border-radius: 50%; background: var(--bad); color: #fff; font: 700 10px/15px sans-serif; text-align: center; pointer-events: none; }
  .row.lvl-spy .cn::before { content: '🔒 '; }
  .lvl-key { display: inline-flex; gap: 10px; font-size: 11px; color: var(--muted); }
  .lvl-key i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 3px; vertical-align: -1px; }
  .eveapps { font-size: 12px; color: var(--muted); margin: -4px 0 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .eveapps a { color: #7ab8f2; }
  .ownprof.setup { border: 1px solid #3a3a44; border-radius: 8px; padding: 8px 10px; background: #101014; margin: 0 0 10px; }
  .ownprof.setup .opt { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; width: 100%; }
  .checklist { grid-column: 3 / -1; background: #101014; border: 1px solid #3a3a44; border-radius: 8px; padding: 8px 10px; margin: 4px 0; font-size: 12px; }
  .checklist label { display: flex; gap: 6px; align-items: baseline; padding: 2px 0; }
  .checklist label small { color: var(--muted); }
  .checklist .acts { display: flex; gap: 8px; margin-top: 6px; align-items: center; flex-wrap: wrap; }
  body:not(.advanced) .adv-only { display: none !important; }
  .said-err { display: block; width: 100%; background: #3a1416; color: var(--bad); font-weight: 700; font-size: 15px; border-radius: 6px; padding: 8px 10px; margin-top: 6px; }
  .errors { background: #3a1416; color: var(--bad); border-radius: 6px; padding: 8px 10px; margin-bottom: 10px; }
  @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } .row { grid-template-columns: 18px 30px minmax(0,1fr) 90px 80px; } .row select, .row .x { display: none; } }
</style>
</head>
<body>
<div class="wrap">

  @if($done)
    {{-- A login finished in the small window: tell the screen behind it, then close. --}}
    <script>
      try { if (window.opener && !window.opener.closed) { window.opener.postMessage('dr-login-done', location.origin); window.close(); } } catch (e) {}
    </script>
  @endif

  <div class="top">
    <h1>Your accounts</h1>
    <span class="muted">{{ $ready }} of {{ $total }} ready</span>
    <div class="bar" title="Logged in to SeAT and the mapper"><div style="width: {{ $total ? round(100 * $ready / $total) : 0 }}%"></div></div>
    <span style="flex: 1"></span>
    <a class="btn" href="{{ route('devilsrage.accounts.index') }}" title="The full page in SeAT: launch groups, Omega, MCT, PLEX">More in SeAT</a>
    <button class="btn" type="button" id="next-account" title="Logs you out of EVE's login page, so the next login asks for the next account's username and password">Next EVE account</button>
    <button class="btn" type="button" id="new-account-btn">+ New account</button>
  </div>
  <div class="opts">
    <label title="Hide accounts that are Alpha, whose Omega ended, or with no Omega known (Ω ?)"><input type="checkbox" id="opt-alpha"> Hide Alpha accounts</label>
    <label title="In the app: show each account's EVE login name next to its number (from its last login on this PC; this PC only)"><input type="checkbox" id="opt-login"> Show EVE login names</label>
    <label title="Edit, archive or delete what's set up: each character's ticks, profiles and settings files, incognito characters and this PC's other characters"><input type="checkbox" id="opt-advanced"> Advanced editing / deleting</label>
    <span class="lvl-key" title="The ring on a portrait says how much that character shares"><span><i style="background:#3d8bfd"></i>Ally</span><span><i style="background:#a370f7"></i>Public</span><span><i style="background:var(--bad)"></i>Spy</span></span>
  </div>

  @if($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
  @endif
  @if(session('success'))
    <div class="note ok">{{ session('success') }}</div>
  @endif

  @if($justLoggedIn)
    @php
      $jl = $statusOf($justLoggedIn['character_id']);
    @endphp
    <div class="note ok">
      <span>✓ <b>{{ $justLoggedIn['name'] }}</b> logged in{{ $justLoggedIn['public'] ? ' (public only)' : '' }}{{ $justLoggedIn['placed'] ? ' · account '.$justLoggedIn['placed'] : '' }}.</span>
      @if(! $justLoggedIn['placed'])
        <span class="muted">Not in an account yet: pick it in an empty slot.</span>
      @endif
    </div>
  @endif

  @if($mainStatus === 'scopes' || $mainStatus === 'expired')
    <div class="note warn">
      <span><b>Your main's SeAT login {{ $mainStatus === 'expired' ? 'has expired' : 'is missing permissions' }}.</b>
        SeAT gives every character you add your main's permissions, so fix the main first.</span>
      <a class="btn sm" href="{{ route('devilsrage.accounts.login-main') }}">Log in my main again</a>
    </div>
  @endif

  <form class="newacct" id="new-account" method="post" action="{{ route('devilsrage.accounts.store') }}">
    @csrf
    <input name="nickname" maxlength="32" placeholder="nickname, e.g. 13" required aria-label="Nickname">
    <button class="btn sm p" type="submit">Add account</button>
    <span class="muted" style="font-size: 12px">A nickname only, never your login name.</span>
  </form>

  <div class="grid" style="margin-top: 8px">
    <div>
      <div class="list" id="acct-list">
        @foreach($cards as $card)
          @php
            $account = $card['account'];
            $todo = count($card['steps']) + ($account->characters->count() === 0 ? 1 : 0);
          @endphp
          @php
            $tileOm = $omega[$account->id] ?? null;
            [$tileSym, $tileCls, $tileTip] = match (true) {
                $tileOm === null => ['', '', ''],
                (bool) $tileOm['alpha'] => ['α', 'alpha', 'Alpha (a character trains at Alpha speed)'],
                $tileOm['state'] === 'expired' => ['α', 'ended', 'Omega ended '.$tileOm['until']->format('Y-m-d')],
                $tileOm['state'] === 'auto' => ['Ω auto', 'om', 'Omega renews by itself'],
                in_array($tileOm['state'], ['ok', 'soon'], true) => ['Ω '.$tileOm['days_left'].'d', $tileOm['state'] === 'soon' ? 'soon' : 'om', 'Omega until '.$tileOm['until']->format('Y-m-d')],
                default => ['Ω ?', 'unknown', 'No Omega date entered (Omega, MCT, PLEX)'],
            };
          @endphp
          <div class="acct {{ $todo ? 'todo' : '' }} {{ in_array($tileCls, ['alpha', 'ended', 'unknown'], true) ? 'is-alpha' : '' }}" draggable="true" data-id="{{ $account->id }}" data-launcher="{{ $account->launcher_user_id }}" title="Drag ⋮⋮ to change the order{{ $todo ? ' · '.(count($card['steps']) ? count($card['steps']).' to fix' : 'empty') : '' }}">
            <span class="grip">⋮⋮</span>
            @for($i = 0; $i < $maxCharacters; $i++)
              @if(isset($account->characters[$i]))
                @php
                  $tileSheet = $sheet[(int) $account->characters[$i]->character_id] ?? null;
                @endphp
                <span class="pp tile lvl-{{ $account->characters[$i]->accessLevel() }}"><img class="pic {{ ($tileSheet['training'] ?? null) ? 'training' : '' }}" data-sheet='@json($tileSheet)' data-name="{{ $account->characters[$i]->displayName() }}" src="https://images.evetech.net/characters/{{ $account->characters[$i]->character_id }}/portrait?size=64" alt="" loading="lazy"><small>{{ ($tileSheet['total_sp'] ?? 0) >= 1000000 ? number_format($tileSheet['total_sp'] / 1000000, 1).'M' : (($tileSheet['total_sp'] ?? 0) > 0 ? number_format($tileSheet['total_sp'] / 1000).'k' : '') }}</small></span>
              @else
                <span class="pic empty"></span>
              @endif
            @endfor
            <span class="nick"><b>@if($todo)<i class="dot" title="{{ count($card['steps']) ? count($card['steps']).' to fix' : 'empty' }}"></i>@endif
              {{ $account->nickname }}</b><br><span class="om {{ $tileCls }}" title="{{ $tileTip }}">{{ $tileSym }}</span></span>
          </div>
        @endforeach
        <div id="pc-only" hidden></div>
        @if($unassigned->isNotEmpty())
          <div class="muted" style="font-size: 12px; margin-top: 6px">Not in an account: {{ $unassigned->pluck('name')->implode(', ') }}</div>
        @endif
        <div class="muted" style="font-size: 11px">Drag ⋮⋮ to reorder. The order is used everywhere (SeAT, FC planner).</div>
      </div>
    </div>

    <div>
      @if($cards === [])
        <div class="panel show">
          <p>No accounts yet. Press <b>+ New account</b>, give it a nickname (e.g. 01), then log its characters in.</p>
        </div>
      @endif
      @foreach($cards as $card)
        @php
          $account = $card['account'];
          $om = $omega[$account->id] ?? null;
        @endphp
        <div class="panel" id="panel-{{ $account->id }}" data-id="{{ $account->id }}" data-launcher="{{ $account->launcher_user_id }}" data-profile="{{ \DevilsRage\Seat\Http\Controllers\LaunchGroupsController::profileFor((string) $account->nickname) }}" data-nick="{{ $account->nickname }}" data-free="{{ $maxCharacters - count($card['rows']) }}" data-external="{{ route('devilsrage.accounts.characters.external', $account) }}" data-steps='@json($card['steps'])'>
          <div class="phead">
            <form method="post" action="{{ route('devilsrage.accounts.rename', $account) }}" style="display: flex; gap: 6px; align-items: center">
              @csrf
              <span class="muted">Account</span>
              <input class="name" name="nickname" value="{{ $account->nickname }}" maxlength="32" aria-label="Nickname" style="background: transparent; border: 1px solid transparent; width: 90px" onfocus="this.style.borderColor='#3a3a44'" onchange="this.form.submit()">
            </form>
            @include('devilsrage::partials.omega-badge', ['om' => $om])
            <span style="flex: 1"></span>
            @include('devilsrage::partials.plex-badge', ['held' => $holdings[$account->id] ?? null])
            <button class="btn sm" type="button" data-omega="{{ $account->id }}" title="Omega, MCT and PLEX for this account">Omega, MCT, PLEX</button>
          </div>
          {{-- Omega / MCT / PLEX, the same as SeAT's My accounts (saves to the same place) --}}
          <form class="omega" id="omega-{{ $account->id }}" method="post" action="{{ route('devilsrage.accounts.omega', $account) }}">
            @csrf
            <div class="orow">
              <label for="om-until-{{ $account->id }}">Omega until</label>
              <input id="om-until-{{ $account->id }}" type="date" name="omega_until" value="{{ $account->omega_until?->format('Y-m-d') }}" title="When Omega runs out (empty = unknown or Alpha)">
              <label class="chk"><input type="checkbox" name="omega_auto" value="1" @checked($account->omega_auto)> renews by itself</label>
            </div>
            <div class="orow">
              <label for="om-mct-{{ $account->id }}">MCT</label>
              <select id="om-mct-{{ $account->id }}" name="mct_slots" title="Multiple Character Training slots">
                @foreach([0 => 'none', 1 => '1 slot', 2 => '2 slots'] as $slots => $label)
                  <option value="{{ $slots }}" @selected((int) $account->mct_slots === $slots)>{{ $label }}</option>
                @endforeach
              </select>
              <span class="muted">until</span>
              <input type="date" name="mct_until" value="{{ $account->mct_until?->format('Y-m-d') }}" aria-label="MCT until" title="When MCT runs out">
            </div>
            <div class="orow">
              <label for="om-vault-{{ $account->id }}">PLEX in vault</label>
              <input id="om-vault-{{ $account->id }}" type="number" min="0" step="1" name="plex_vault" value="{{ $account->plex_vault }}" style="width: 7rem" title="EVE doesn't share the PLEX vault with SeAT: type it here">
              <span class="muted">+ {{ number_format($holdings[$account->id]['hangar'] ?? 0) }} in hangars (from SeAT)</span>
            </div>
            <div class="orow">
              <span class="muted" style="font-size: 12px">Reminders go out by Discord DM 7 days and 1 day before an end date, unless it renews by itself.</span>
              <span style="flex: 1"></span>
              <button class="btn sm" type="button" data-omega="{{ $account->id }}">Cancel</button>
              <button class="btn sm p" type="submit">Save</button>
            </div>
          </form>

          <div class="hint"><span>🔑 Login hint</span><input type="text" data-hint="{{ $account->id }}" maxlength="80" placeholder="e.g. the account's e-mail, a password hint"><span>saved on this PC only</span></div>
          @php
            $appsChar = collect($card['rows'])->map(fn ($r) => $r['link'])->first(fn ($l) => ! $l->external)?->character_id;
          @endphp
          @if($appsChar)
            <div class="eveapps"><span>🔐 Apps with access to these characters:</span><a href="https://developers.eveonline.com/authorized-apps?character={{ $appsChar }}" target="_blank" rel="noopener" data-apps="{{ $appsChar }}">EVE's third-party apps page ↗</a><span class="apps-tip">Top right there should say this account's login; if not, log out there and click again. Revoke old corps, old tools and temporary allies; keep devilsrage SeAT and devilsrage mapper.</span></div>
          @endif

          <div class="chars" data-account="{{ $account->id }}">
            @foreach($card['rows'] as $row)
              @php
                $link = $row['link'];
                $st = $row['st'];
                $lab = $labels[$st['seat']] ?? $labels['missing'];
                $corpPick = $link->launchGroups->firstWhere('locked', true);
              @endphp
              @php
                $lvl = $link->accessLevel();
                $shareGroups = in_array($lvl, ['ally', 'partial'], true) ? \DevilsRage\Seat\Services\AccessScopes::groupsOf((array) $link->access_scopes, $allScopes) : [];
              @endphp
              <div class="row lvl-{{ $lvl }}" draggable="true" data-id="{{ $link->id }}" data-char="{{ $link->character_id }}" data-level="{{ $lvl }}" data-groups='@json($shareGroups)' data-access="{{ route('devilsrage.accounts.characters.access', $link) }}">
                <span class="grip">⋮⋮</span>
                @php
                  $rowSheet = $sheet[(int) $link->character_id] ?? null;
                @endphp
                <span class="pp"><img class="pic {{ ($rowSheet['training'] ?? null) ? 'training' : '' }}" data-sheet='@json($rowSheet)' data-name="{{ $link->displayName() }}" src="https://images.evetech.net/characters/{{ $link->character_id }}/portrait?size=64" alt="" loading="lazy">@if(($rowSheet['total_sp'] ?? 0) > 0)<small title="{{ number_format($rowSheet['total_sp']) }} skill points">{{ $rowSheet['total_sp'] >= 1000000 ? number_format($rowSheet['total_sp'] / 1000000, 1).'M' : number_format($rowSheet['total_sp'] / 1000).'k' }}</small>@endif</span>
                <span class="cn" title="{{ $link->displayName() }}">{{ $link->displayName() }} <button class="incog-toggle" type="button" data-name="{{ $link->displayName() }}" hidden title="This PC only: hide it from the mapper and SeAT features in the app (spy alts)">🕶</button></span>
                @if(in_array($st['seat'], ['ok', 'external'], true))
                  <span class="chip {{ $lab[1] }}" title="{{ $lab[2] }}">{{ $lab[0] }}</span>
                @elseif($st['seat'] === 'public')
                  <a class="chip pub login" href="{{ $seatLogin($account) }}" title="{{ $lab[2] }}. Click to upgrade it to full access.">{{ $lab[0] }}</a>
                @else
                  <a class="chip {{ $lab[1] }} login" href="{{ $seatLogin($account, (bool) $link->public_only) }}" title="{{ $lab[2] }}: pick {{ $link->displayName() }}, Authorize">{{ $lab[0] }}</a>
                @endif
                @if($link->external || $st['mapper'] === null && in_array($lvl, ['ally', 'partial'], true))
                  <span class="chip muted" title="{{ $link->external ? '' : 'Doesn\'t share its location, so no mapper login is needed' }}">—</span>
                @elseif($st['mapper'] === true)
                  <span class="chip ok" title="In the mapper">Mapper ✓</span>
                @elseif($st['mapper'] === false)
                  <a class="chip todo login" href="{{ $mapperAdd((bool) $link->public_only) }}" title="Not in the mapper: pick {{ $link->displayName() }}, Authorize">Mapper add</a>
                @else
                  <span class="chip muted" title="Couldn't ask the mapper">Mapper ?</span>
                @endif
                <form method="post" action="{{ route('devilsrage.accounts.characters.corpgroup', $link) }}">
                  @csrf
                  <select class="grp" name="group" onchange="this.form.submit()" aria-label="Corp launch group">
                    <option value="">—</option>
                    @foreach($corpGroups as $group)
                      <option value="{{ $group->id }}" @selected($corpPick && $corpPick->id === $group->id)>{{ $group->name }}</option>
                    @endforeach
                  </select>
                </form>
                <form method="post" action="{{ route('devilsrage.accounts.characters.remove', $link) }}" class="ask" data-ask="Take {{ $link->displayName() }} out of this account? It stays in SeAT and the mapper.">
                  @csrf
                  <button class="x" type="submit" title="Take out of this account">✕</button>
                </form>
                @if($ownGroups->isNotEmpty())
                  @php
                    $ticked = $link->launchGroups->pluck('id')->all();
                  @endphp
                  <form class="pills" method="post" action="{{ route('devilsrage.accounts.characters.groups', $link) }}" title="Launch groups this character is in. + shows the rest.">
                    @csrf
                    <button class="more" type="button" title="Show every launch group to tick">+</button>
                    @foreach($ownGroups as $group)
                      <label class="{{ $group->user_id === null ? 'corp' : '' }}"><input type="checkbox" name="groups[]" value="{{ $group->id }}" @checked(in_array($group->id, $ticked, true)) onchange="this.form.submit()"><span>{{ $group->user_id === null ? '★ ' : '' }}{{ $group->name }}</span></label>
                    @endforeach
                  </form>
                @endif
                @unless($link->external)
                  <div class="access">
                    <select class="lvl" aria-label="What it shares" title="How much this character shares with the corp">
                      @foreach(['devil' => 'Devil: shares everything', 'ally' => 'Ally: location, skills, fits, fleets, kills', 'public' => 'Public: name, corp and alliance only'] as $value => $label)
                        <option value="{{ $value }}" @selected($lvl === $value)>{{ $label }}</option>
                      @endforeach
                      <option value="spy">Spy: locked on this PC, never shared</option>
                    </select>
                    <span class="shares">@if($lvl === 'devil')Shares everything @elseif($lvl === 'public')Shares name, corp and alliance only @else Shares: {{ $shareGroups ? implode(', ', array_map(fn ($g) => $accessGroups[$g][0] ?? $g, $shareGroups)) : 'nothing yet' }} @endif</span>
                    @if($lvl === 'ally')<button class="btn sm adv-only edit-ticks" type="button">Edit what it shares</button>@endif
                  </div>
                @endunless
                @if(! $link->external && in_array((int) $link->character_id, $chatLogOff ?? [], true))
                  <div class="chatlog" title="Without chat logs the app can't move {{ $link->displayName() }} on the map instantly (it waits for ESI) or tell whose probe scan it copied.">⚠ Log Chat to File is off in EVE. Turn it on: Esc → Gameplay → Chat → Log Chat to File.</div>
                @endif
              </div>
            @endforeach
            @if(count($card['rows']) < $maxCharacters)
              <div class="row addrow">
                <span></span><span class="pic empty"></span>
                <span class="add" data-choice="{{ $account->id }}" style="grid-column: span 3">+ Add a character <span class="muted">({{ $maxCharacters - count($card['rows']) }} free)</span></span>
                @if($unassigned->isNotEmpty())
                  <form method="post" action="{{ route('devilsrage.accounts.characters.add', $account) }}" style="grid-column: span 2; display: flex; gap: 4px">
                    @csrf
                    <select class="grp" name="character_id" aria-label="Character already on SeAT" onchange="if (this.value) this.form.submit()">
                      <option value="">or one already on SeAT…</option>
                      @foreach($unassigned as $character)
                        <option value="{{ $character->character_id }}">{{ $character->name }}</option>
                      @endforeach
                    </select>
                  </form>
                @endif
              </div>
            @endif
          </div>

          <div class="choice" id="choice-{{ $account->id }}">
            <a class="opt login" href="{{ $seatLogin($account) }}"><b>Full access <span class="chip ok">recommended</span></b><span class="muted" style="font-size: 12px">Skills, assets, location for the mapper: everything SeAT and the mapper use.</span></a>
            <a class="opt login" href="{{ $seatLogin($account, true) }}"><b>Public only</b><span class="muted" style="font-size: 12px">Proves it's yours; we only see name, corp and alliance. For alts whose other group doesn't allow sharing. You can upgrade it later.</span></a>
          </div>

          <div class="foot">
            @if($card['steps'])
              <button class="btn p" type="button" data-fix="{{ $account->id }}">Fix this account ({{ count($card['steps']) }} step{{ count($card['steps']) === 1 ? '' : 's' }})</button>
            @elseif($account->characters->isNotEmpty())
              <span style="color: var(--ok)">✓ Everything on this account works.</span>
            @endif
            <span class="muted" style="font-size: 12px">EVE's login opens in a small window. Pick the character it names, Authorize; this screen updates by itself.</span>
            <span style="flex: 1"></span>
            <form method="post" action="{{ route('devilsrage.accounts.delete', $account) }}" class="ask" data-ask="Remove account {{ $account->nickname }}? Its characters stay in SeAT and the mapper.">
              @csrf
              <button class="x" type="submit" title="Remove this account (its characters stay in SeAT)">Remove account</button>
            </form>
          </div>
          <div class="steps" id="steps-{{ $account->id }}"></div>

          <div class="groups">
            <span class="muted" title="Only you see these, e.g. a 7-pilot DST launch. Tick them on characters above; the same groups as in SeAT.">Your launch groups:</span>
            @foreach($ownGroups as $group)
              @if($group->user_id === null)
                {{-- Patch 66: a corp launch group from the directors: everyone can tick it. --}}
                @if($group->key !== null)
                  <span class="g corp" title="Used by the Assets page and the corp: can't be deleted"><span>★ {{ $group->name }}</span></span>
                @elseif(\Illuminate\Support\Facades\Gate::allows('devilsrage-leader'))
                  <form method="post" action="{{ route('devilsrage.groups.corp.delete', $group) }}" class="ask g corp" data-ask="Delete the corp launch group {{ $group->name }} for everyone? Characters stay where they are." data-ask2="⚠ Last check: {{ $group->name }} disappears from every pilot's characters and launch groups in the whole corp. Delete it?">
                    @csrf
                    <span title="Corp launch group (everyone can use it)">★ {{ $group->name }}</span><button class="x" type="submit" title="Delete this corp group">✕</button>
                  </form>
                @else
                  <span class="g corp" title="Corp launch group from the directors: tick it on the characters you bring"><span>★ {{ $group->name }}</span></span>
                @endif
              @else
              <form method="post" action="{{ route('devilsrage.groups.delete', $group) }}" class="ask g" data-ask="Delete your launch group {{ $group->name }}? Characters stay where they are.">
                @csrf
                <span>{{ $group->name }}</span><button class="x" type="submit" title="Delete this group">✕</button>
              </form>
              @endif
            @endforeach
            <form method="post" action="{{ route('devilsrage.groups.store') }}" style="display: flex; gap: 4px">
              @csrf
              <input name="name" maxlength="40" placeholder="New group, e.g. DST launch" required aria-label="New launch group">
              <button class="btn sm" type="submit">Add</button>
            </form>
            @can('devilsrage-leader')
              <form method="post" action="{{ route('devilsrage.groups.corp.store') }}" style="display: flex; gap: 4px" title="Directors: a launch group every pilot can tick (e.g. Roll fleet, PI run)">
                @csrf
                <input name="name" maxlength="40" placeholder="★ Corp group for everyone" required aria-label="New corp launch group">
                <button class="btn sm" type="submit">Add for corp</button>
              </form>
            @endcan
            <span class="app-sync muted" style="font-size: 12px" hidden></span>
          </div>
        </div>
      @endforeach
    </div>
  </div>
</div>

<template id="checklist-tpl">
  <div class="checklist">
    <div class="muted" style="margin-bottom: 4px">What this character shares (only these permissions are asked for at EVE's login):</div>
    @foreach($accessGroups as $key => [$label, $why])
      <label><input type="checkbox" value="{{ $key }}"> <span>{{ $label }} <small>· {{ $why }}</small></span></label>
    @endforeach
    <div class="acts"><button class="btn sm p" type="button" data-go>Save and log it in</button><button class="btn sm" type="button" data-no>Cancel</button><span class="muted" data-say></span></div>
    <div class="muted" style="margin-top: 6px; font-size: 11px">Sharing less later: tick less and log it in again (SeAT then keeps only that). To take every permission back from EVE itself: <a href="https://community.eveonline.com/support/third-party-applications/" target="_blank" rel="noopener">EVE's third-party applications page</a>.</div>
  </div>
</template>

<div class="confirm" id="confirm" role="dialog" aria-modal="true">
  <div class="box"><p id="confirm-text"></p><div><button class="btn" type="button" id="confirm-no">Cancel</button><button class="btn p" type="button" id="confirm-yes">Yes, remove</button></div></div>
</div>

<script>
// Patch 66: inside the desktop app, hand your accounts and launch groups to the app (its
// Launch groups page, pedal cycling and Client settings use them), and offer to bring in
// groups made on this PC (e.g. imported from EVE-O Preview).
(() => {
  const app = window.__DEVILS_RAGE_APP__;
  if (!app || !app.capture) return;
  const bridge = 'http://drcap.localhost';
  const said = (text) => document.querySelectorAll('.app-sync').forEach((el) => { el.hidden = !text; el.textContent = text || ''; });
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}';
  async function sync() {
    const data = await fetch(@json(route('devilsrage.groups.json')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).then((r) => r.json());
    let r;
    // text/plain keeps it a "simple" request (no CORS preflight the app's local address can't answer).
    try { r = await fetch(bridge + '/launch-sync', { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: JSON.stringify(data) }); }
    catch (e) { throw new Error('no answer from the app (' + (app.version || 'old version') + ')'); }
    if (r.status === 403 || r.status === 404) throw new Error('this app (' + (app.version || '?') + ') is too old: update it to dev.68 or newer');
    if (!r.ok) throw new Error('the app said ' + r.status + ' ' + (await r.text().catch(() => '')).slice(0, 120));
    return data;
  }
  async function offerImport() {
    const local = await fetch(bridge + '/launch-local').then((r) => (r.ok ? r.json() : [])).catch(() => []);
    if (!local.length) { said('✓ The app has your launch groups.'); return; }
    said('');
    document.querySelectorAll('.app-sync').forEach((holder) => {
      holder.hidden = false;
      holder.innerHTML = '';
      const btn = document.createElement('button');
      btn.className = 'btn sm';
      btn.type = 'button';
      btn.textContent = `Bring in ${local.length} group${local.length === 1 ? '' : 's'} made in the app`;
      btn.title = local.map((g) => g.name + ': ' + g.members.length + ' characters').join('\n');
      btn.addEventListener('click', async () => {
        btn.disabled = true;
        const r = await fetch(@json(route('devilsrage.groups.import')), { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ groups: local }) }).then((x) => x.json()).catch(() => null);
        if (!r) { said('Couldn\'t bring them in. Reload and try again.'); return; }
        await fetch(bridge + '/launch-local-done', { method: 'POST', headers: { 'Content-Type': 'text/plain;charset=UTF-8' }, body: '{}' }).catch(() => {});
        location.reload();
      });
      holder.append(btn);
    });
  }
  // From app dev.71 the app reads them itself when this page opens; this is only the old way.
  sync().then(offerImport).catch(() => said(''));
})();
</script>
<script>
// Patch 74: in the desktop app, Your accounts also shows what's on this PC. The EVE launcher's
// logs say which characters sit on which EVE account: characters SeAT knows are put into their
// account by themselves; the rest (spies, extra alts) show greyed in that account, incognito:
// the app keeps them on this PC and this page never sends them anywhere unless you untick
// Incognito (then it goes on SeAT by name, public info only).
(() => {
  const app = window.__DEVILS_RAGE_APP__;
  if (!app || !app.capture) return;
  // Asks go to the app like the mapper's (a fake address the app catches); answers come back as an event.
  const ask = (what) => { try { window.open('https://dr-app.invalid/' + what, '_blank'); } catch (e) {} };
  const answer = () => new Promise((resolve) => {
    const done = (e) => { window.removeEventListener('devils-rage-app:pc-accounts', done); resolve(e.detail || null); };
    window.addEventListener('devils-rage-app:pc-accounts', done);
    setTimeout(() => { window.removeEventListener('devils-rage-app:pc-accounts', done); resolve(null); }, 20000);
  });
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const mine = new Set(@json($mineIds));
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const panels = [...document.querySelectorAll('.panel[data-launcher]')];
  const placed = new Set([...document.querySelectorAll('.row[data-char]')].map((r) => Number(r.dataset.char)));
  const portrait = (id) => `https://images.evetech.net/characters/${id}/portrait?size=64`;

  async function names(ids) {
    if (!ids.length) return {};
    try {
      const r = await fetch('https://esi.evetech.net/latest/universe/names/?datasource=tranquility', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(ids) });
      const list = r.ok ? await r.json() : [];
      return Object.fromEntries(list.map((x) => [x.id, x.name]));
    } catch (e) { return {}; }
  }

  // Shared again / hidden by hand (characters SeAT knows), this PC only.
  function toggles(hidden) {
    document.querySelectorAll('.incog-toggle').forEach((b) => {
      const set = (on) => {
        b.classList.toggle('on', on);
        b.closest('.row').classList.toggle('hidden-pc', on);
        b.title = on ? 'Incognito on this PC: the app keeps it from the mapper and SeAT features. Click to share it again.' : 'Click to make it incognito on this PC (hidden from the mapper and SeAT features in the app)';
      };
      set(hidden.has(b.dataset.name.toLowerCase()));
      b.hidden = false;
      b.addEventListener('click', async (e) => {
        e.stopPropagation();
        const on = !b.classList.contains('on');
        const back = answer();
        ask(`incognito-hide?name=${encodeURIComponent(b.dataset.name)}&hidden=${on ? 1 : 0}`);
        const pc = await back;
        if (pc) set((pc.hidden || []).map((n) => String(n).toLowerCase()).includes(b.dataset.name.toLowerCase()));
      });
    });
  }

  // Untick Incognito: put it on SeAT by name (public info only) in an account.
  function shareBox(holder, name, accountPanels) {
    const box = document.createElement('label');
    box.className = 'incog-box';
    box.innerHTML = `<input type="checkbox" checked> Incognito (this PC only) <span class="muted">· untick to share it with the corp</span>`;
    const tick = box.querySelector('input');
    tick.addEventListener('change', () => {
      if (tick.checked) return;
      const ask = document.createElement('span');
      const options = accountPanels.filter((p) => Number(p.dataset.free) > 0);
      if (!options.length) { ask.textContent = ' No account has a free slot.'; box.append(ask); tick.checked = true; return; }
      ask.innerHTML = ` Share <b>${esc(name)}</b>${options.length > 1 ? ' in account <select class="grp">' + options.map((p) => `<option value="${esc(p.dataset.external)}">${esc(p.dataset.nick)}</option>`).join('') + '</select>' : ''}? It goes on SeAT by name (public info only). <button class="btn sm p" type="button">Share</button> <button class="btn sm" type="button">Cancel</button>`;
      box.append(ask);
      const [yes, no] = ask.querySelectorAll('button');
      no.addEventListener('click', (e) => { e.preventDefault(); ask.remove(); tick.checked = true; });
      yes.addEventListener('click', (e) => {
        e.preventDefault();
        const form = document.createElement('form');
        form.method = 'post';
        form.action = ask.querySelector('select')?.value || options[0].dataset.external;
        form.innerHTML = `<input type="hidden" name="_token" value="${esc(csrf)}"><input type="hidden" name="name" value="${esc(name)}">`;
        document.body.append(form);
        form.submit();
      });
    });
    holder.append(box);
  }

  const guessed = new Set();
  function incognitoRow(id, name, accountPanels) {
    const row = document.createElement('div');
    row.className = 'row incog';
    row.dataset.char = id;
    row.innerHTML = `<span></span><img class="pic" src="${portrait(id)}" alt="" loading="lazy"><span class="cn" title="On this PC only">${esc(name || 'Character ' + id)} <span class="muted" style="font-size: 11px">· incognito${guessed.has(id) ? ' · placed from its profile folder' : ''}</span></span>`;
    shareBox(row, name, accountPanels);
    return row;
  }

  function ghostOnTile(tile, id) {
    const empty = tile?.querySelector('.pic.empty');
    if (!empty) return;
    const img = document.createElement('img');
    img.className = 'pic ghost';
    img.src = portrait(id);
    img.alt = '';
    empty.replaceWith(img);
  }

  // EVE accounts on this PC with no account here: their own tiles under "This PC only".
  function pcOnly(user, chars, nameOf, profile) {
    const list = document.getElementById('pc-only');
    if (list.hidden) { list.hidden = false; list.innerHTML = '<h4 title="EVE accounts the launcher used on this PC that aren\'t on SeAT. Only this PC sees them.">This PC only</h4>'; }
    const tile = document.createElement('div');
    tile.className = 'acct pc';
    tile.dataset.id = 'pc-' + user;
    tile.innerHTML = `<span class="grip"> </span>${chars.map((id) => `<img class="pic ghost" src="${portrait(id)}" alt="">`).join('')}<span class="nick"><b>${profile ? esc(profile) : 'PC'}</b><br><span class="om unknown">incognito</span></span>`;
    list.append(tile);
    const panel = document.createElement('div');
    panel.className = 'panel';
    panel.dataset.id = 'pc-' + user;
    panel.innerHTML = `<div class="phead"><span class="name">An EVE account on this PC</span><span class="muted">${profile ? 'profile settings_' + esc(profile) + ' · ' : ''}not on SeAT · only this PC sees it</span></div><div class="chars"></div><div class="foot"><span class="muted" style="font-size: 12px">Untick Incognito to share a character with the corp (it goes on SeAT by name into the account you pick). To make this a whole account: + New account, then share its characters into it.</span></div>`;
    const box = panel.querySelector('.chars');
    chars.forEach((id) => box.append(incognitoRow(id, nameOf[id], panels)));
    panels[0]?.parentNode.append(panel);
    tile.addEventListener('click', () => {
      document.querySelectorAll('.acct').forEach((a) => a.classList.toggle('sel', a === tile));
      document.querySelectorAll('.panel').forEach((p) => p.classList.toggle('show', p === panel));
    });
  }

  const profileLines = (pc) => {
  // Every character here: its profile folders on this PC, newest first, with dates; the newest
    // two on the line, the rest in the dropdown, each with 📂 / Archive / Delete.
    const day = (ms) => (ms ? new Date(ms).toISOString().slice(0, 10) : '?');
    for (const [id, c] of Object.entries(pc.chars || {})) {
      const row = document.querySelector(`.row[data-char="${id}"]`);
      if (!row || row.querySelector('.profs')) continue;
      const name = row.querySelector('.cn')?.childNodes[0]?.textContent?.trim() || '';
      const profs = c.profiles || [];
      const d = document.createElement('details');
      d.className = 'profs';
      d.innerHTML = `<summary title="Its settings files on this PC, by profile folder">last played here ${day(c.last)} · ${profs.slice(0, 2).map((p) => `settings_${esc(p.name)} (${day(p.at).slice(5)})`).join(', ')}${profs.length > 2 ? ` · +${profs.length - 2} more` : ''}</summary>`;
      for (const p of profs) {
        const line = document.createElement('div');
        line.className = 'prow';
        line.innerHTML = `<span>settings_${esc(p.name)}</span><span>${day(p.at)}</span><button type="button" title="Show this file in File Explorer">📂</button><button type="button" title="Recommended: move this file to the archive folder (nothing is deleted)">Archive</button><button type="button" title="Delete this file for good (asks twice)">Delete</button><span class="said"></span>`;
        const [open, arch, del] = line.querySelectorAll('button');
        open.addEventListener('click', () => ask(`pc-open-folder?id=${id}&profile=${encodeURIComponent(p.name)}`));
        arch.addEventListener('click', () => fileAction('archive', id, name, p.name, line));
        del.addEventListener('click', () => fileAction('delete', id, name, p.name, line));
        d.append(line);
      }
      const pills = row.querySelector('.pills');
      (pills || row.querySelector('.cn')).after(d);
    }
  };

  // 23 Nov: an answer from the app; anything that isn't a success (Close every EVE client…) in big red.
  window.drSaid = (el, text) => {
    el.textContent = text || '';
    el.classList.toggle('said-err', !!text && !/^(Made |Moved |Deleted |Moving|Deleting|settings_\S+ is this account|Forgotten)/.test(text));
  };
  // Archive (recommended) or delete one settings file (asks twice), answer shown in `box`.
  function fileAction(kind, id, name, profile, box) {
    const said = box.querySelector('.said') || box;
    const go = () => {
      said.textContent = kind === 'archive' ? 'Moving…' : 'Deleting…';
      const done = (e) => {
        if (String(e.detail?.id) !== String(id)) return;
        window.removeEventListener('devils-rage-app:pc-archived', done);
        drSaid(said, e.detail.said);
        if (/^(Moved|Deleted) [1-9]/.test(said.textContent)) box.style.opacity = '.4';
      };
      window.addEventListener('devils-rage-app:pc-archived', done);
      ask(`pc-other-${kind}?id=${id}&name=${encodeURIComponent(name || '')}&profile=${encodeURIComponent(profile || '')}`);
    };
    if (kind === 'archive') { go(); return; }
    said.innerHTML = `Delete settings_${esc(profile)}'s file for ${esc(name || 'this character')}? Archive keeps it. <button type="button">Delete</button> <button type="button">Cancel</button>`;
    const [y1, n1] = said.querySelectorAll('button');
    n1.addEventListener('click', () => { said.textContent = ''; });
    y1.addEventListener('click', () => {
      said.innerHTML = `<b>Last check:</b> its window layout there is gone for good. <button type="button">Yes, delete</button> <button type="button">Cancel</button>`;
      const [y2, n2] = said.querySelectorAll('button');
      n2.addEventListener('click', () => { said.textContent = ''; });
      y2.addEventListener('click', go);
    });
  }

  // Every other character with settings on this PC that no account list has (old, deleted,
  // never launched through the launcher here). Hide takes it off this list (this PC only).
  function otherChars(list) {
    const rows = list.filter((c) => !placed.has(Number(c.id)));
    if (!rows.length) return;
    const holder = document.getElementById('pc-only');
    if (holder.hidden) { holder.hidden = false; holder.innerHTML = '<h4 title="Only this PC sees these">This PC only</h4>'; }
    const tile = document.createElement('div');
    tile.className = 'acct pc';
    tile.dataset.id = 'pc-others';
    tile.innerHTML = `<span class="grip"> </span>${rows.slice(0, 3).map((c) => `<img class="pic ghost" src="${portrait(c.id)}" alt="">`).join('')}<span class="nick"><b>${rows.length}</b><br><span class="om unknown">no account</span></span>`;
    tile.title = 'Characters with settings on this PC whose EVE account isn\'t known (old, deleted, or not launched through the launcher here). Log one in and it goes to its account.';
    holder.append(tile);
    const panel = document.createElement('div');
    panel.className = 'panel';
    panel.dataset.id = 'pc-others';
    panel.innerHTML = `<div class="phead"><span class="name">Other characters on this PC</span><span class="muted">${rows.length} · account not known · only this PC sees them</span></div><div class="chars"></div><div class="foot"><span class="muted" style="font-size: 12px">From EVE's profile folders. Log a character in (any way) and the app learns its EVE account for certain, then it moves to that account. Hide takes one off this list on this PC; its settings files stay (the profile clean-up will archive old ones).</span></div>`;
    const box = panel.querySelector('.chars');
    for (const c of rows) {
      const row = document.createElement('div');
      row.className = 'row incog';
      const last = c.last ? new Date(c.last).toISOString().slice(0, 10) : '?';
      row.innerHTML = `<span></span><img class="pic" src="${portrait(c.id)}" alt="" loading="lazy"><span class="cn">${esc(c.name || 'Character ' + c.id)} <span class="muted" style="font-size: 11px">· last played ${last} · ${(c.profiles || []).map((p) => 'settings_' + esc(p)).join(', ')}</span></span><span class="incog-box"><button class="btn sm" type="button" title="Take it off this list; its files stay">Hide</button><button class="btn sm" type="button" title="Recommended: move its settings files out of the profile folders into the archive folder beside them (nothing is deleted)">Archive files</button><button class="btn sm" type="button" title="Delete its settings files for good (asks twice)">Delete</button><button class="btn sm" type="button" title="Show its settings file in File Explorer">📂</button></span>`;
      const [hide, remove, del, open] = row.querySelectorAll('button');
      open.addEventListener('click', () => ask(`pc-open-folder?id=${c.id}`));
      del.addEventListener('click', () => {
        const box = del.parentNode;
        const kids = [...box.childNodes];
        const back = () => { box.innerHTML = ''; kids.forEach((k) => box.append(k)); };
        box.innerHTML = `<span>Delete ${esc(c.name || 'its')} settings files for good? Archive keeps them instead.</span> <button class="btn sm" type="button">Delete</button> <button class="btn sm p" type="button">Cancel</button>`;
        const [yes1, no1] = box.querySelectorAll('button');
        no1.addEventListener('click', back);
        yes1.addEventListener('click', () => {
          box.innerHTML = `<span><b>Last check:</b> ${esc(c.name || 'this character')}'s window layout and settings are gone for good.</span> <button class="btn sm" type="button">Yes, delete</button> <button class="btn sm p" type="button">Cancel</button>`;
          const [yes2, no2] = box.querySelectorAll('button');
          no2.addEventListener('click', back);
          yes2.addEventListener('click', () => {
            box.textContent = 'Deleting…';
            const done = (e) => {
              if (String(e.detail?.id) !== String(c.id)) return;
              window.removeEventListener('devils-rage-app:pc-archived', done);
              drSaid(box, e.detail.said);
              if (/^(Moved|Deleted)/.test(box.textContent)) setTimeout(() => row.remove(), 2500);
            };
            window.addEventListener('devils-rage-app:pc-archived', done);
            ask(`pc-other-delete?id=${c.id}&name=${encodeURIComponent(c.name || '')}`);
          });
        });
      });
      hide.addEventListener('click', () => { ask(`pc-other-hide?id=${c.id}&hidden=1`); row.remove(); });
      remove.addEventListener('click', () => {
        const box = remove.parentNode;
        box.innerHTML = `<span>Move ${esc(c.name || 'its')} settings files to the archive folder?</span> <button class="btn sm p" type="button">Move</button> <button class="btn sm" type="button">Cancel</button>`;
        const [yes, no] = box.querySelectorAll('button');
        no.addEventListener('click', () => { box.innerHTML = ''; box.append(hide, remove); });
        yes.addEventListener('click', () => {
          box.textContent = 'Moving…';
          const done = (e) => {
            if (String(e.detail?.id) !== String(c.id)) return;
            window.removeEventListener('devils-rage-app:pc-archived', done);
            drSaid(box, e.detail.said);
            if (/^Moved/.test(box.textContent)) setTimeout(() => row.remove(), 2500);
          };
          window.addEventListener('devils-rage-app:pc-archived', done);
          ask(`pc-other-archive?id=${c.id}&name=${encodeURIComponent(c.name || '')}`);
        });
      });
      box.append(row);
    }
    panels[0]?.parentNode.append(panel);
    tile.addEventListener('click', () => {
      document.querySelectorAll('.acct').forEach((a) => a.classList.toggle('sel', a === tile));
      document.querySelectorAll('.panel').forEach((p) => p.classList.toggle('show', p === panel));
    });
  }

  (async () => {
    const back = answer();
    ask('pc-accounts');
    const pc = await back;
    if (!pc) return;
    toggles(new Set((pc.hidden || []).map((n) => String(n).toLowerCase())));
    // Spies (this PC only): locked rows.
    const spies = new Set((pc.spies || []).map((n) => String(n).toLowerCase()));
    document.querySelectorAll('.row[data-level]').forEach((row) => {
      const name = row.querySelector('.incog-toggle')?.dataset.name || '';
      if (spies.has(name.toLowerCase())) window.drMarkSpy?.(row, true);
    });
    // What each EVE account's sign-in token said: its login name (the login hint, when empty;
    // kept on this PC only) and Omega / Alpha.
    const info = pc.info || {};
    for (const panel of panels) {
      const i = panel.dataset.launcher ? info[panel.dataset.launcher] : null;
      if (!i) continue;
      if (i.login) {
        const nick = document.querySelector(`.acct[data-id="${panel.dataset.id}"] .nick b`);
        const head = panel.querySelector('.phead form');
        for (const where of [nick, head]) {
          if (!where || where.querySelector('.login-name')) continue;
          const l = document.createElement('span');
          l.className = 'login-name';
          l.textContent = i.login;
          l.title = 'EVE login name (from its last login on this PC; only this PC sees it)';
          where.append(l);
        }
      }
      const om = document.querySelector(`.acct[data-id="${panel.dataset.id}"] .om`);
      const tier = String(i.tier || '').toLowerCase();
      const when = i.at ? ' (EVE said so at its last login, ' + new Date(i.at * 1000).toISOString().slice(0, 10) + ')' : ' (EVE said so at its last login)';
      if (om && tier.includes('alpha')) { om.textContent = 'α'; om.className = 'om alpha'; om.title = 'Alpha' + when; om.closest('.acct')?.classList.add('is-alpha'); }
      else if (om && tier.includes('omega') && (om.textContent.trim() === 'Ω ?' || om.textContent.trim() === '')) { om.textContent = 'Ω'; om.className = 'om om'; om.title = 'Omega' + when + '; enter its end date under Omega, MCT, PLEX for the days left'; om.closest('.acct')?.classList.remove('is-alpha'); }
    }
    // Running clients: the EVE account each is signed in to (certain). A character here whose client
    // runs on another EVE account than this account's gets a warning.
    // Every character seen logged in here, with the EVE account its client was signed in to:
    // a ✓ when that's this account, a warning when it's another.
    for (const r of [...(pc.seen || []), ...(pc.running || [])]) {
      const b = [...document.querySelectorAll('.incog-toggle')].find((x) => x.dataset.name.toLowerCase() === String(r.name).toLowerCase());
      const panel = b?.closest('.panel');
      if (!panel || !panel.dataset.launcher) continue;
      if (panel.dataset.launcher === String(r.user_id)) {
        if (!b.parentNode.querySelector('.checked')) {
          const ok = document.createElement('span');
          ok.className = 'chip ok checked';
          ok.textContent = '✓ EVE account';
          ok.title = `Logged in on this EVE account (${r.user_id}), as its own client said: certain`;
          b.after(ok);
        }
        continue;
      }
      if (b.closest('.row').querySelector('.wrongacct')) continue;
      const warn = document.createElement('div');
      warn.className = 'chatlog wrongacct';
      warn.textContent = `⚠ ${r.name}'s client is signed in to another EVE account (${r.user_id}) than account ${panel.dataset.nick}'s (${panel.dataset.launcher}). Wrong account here, or logged in with the wrong login?`;
      b.closest('.row').append(warn);
    }
    const accounts = pc.accounts || [];

    // Known characters not yet in their EVE account's place: group them (only SeAT's own ids are sent).
    const byLauncher = new Map(panels.filter((p) => p.dataset.launcher).map((p) => [p.dataset.launcher, p]));
    const byProfile = new Map(panels.filter((p) => p.dataset.profile).map((p) => [p.dataset.profile, p]));
    // Its account here: the one tied to this EVE account, else the one for its own profile folder (account 11 ↔ settings_11).
    const panelFor = (a) => byLauncher.get(String(a.user_id)) || (a.profile ? byProfile.get(String(a.profile)) : undefined);
    // Only what the launcher itself said is used to move characters on SeAT (the profile folders are a good guess, not certain).
    const wanted = accounts
      .map((a) => ({ user_id: a.user_id, character_ids: a.characters.filter((c) => c.how !== 'profiles').map((c) => c.id).filter((id) => mine.has(id)).slice(0, 3) }))
      .filter((a) => a.character_ids.length && a.character_ids.some((id) => !byLauncher.get(String(a.user_id))?.querySelector(`.row[data-char="${id}"]`)));
    const key = JSON.stringify(wanted);
    let tried = null;
    try { tried = sessionStorage.getItem('dr-launcher-import'); } catch (e) {}
    if (wanted.length && tried !== key) {
      try { sessionStorage.setItem('dr-launcher-import', key); } catch (e) {}
      const r = await fetch(@json(route('devilsrage.accounts.launcher')), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ accounts: wanted }) }).then((x) => x.json()).catch(() => null);
      if (r && (r.moved > 0 || r.created > 0)) { location.reload(); return; }
    }

    // Each account's own profile folder: a one-time setup step until it has one; then one line
    // (changing or forgetting it is under Advanced editing).
    window.__drPc = pc;
    const folders = pc.folders || {};
    const how = `<details><summary>How, and why</summary><p style="margin: 6px 0">In the <b>EVE launcher</b>: click this account → <b>Account Settings</b> → <b>Select Client Profile</b> → pick its profile. Not in the list? Close the launcher fully (its tray icon too) and open it again. Every client of this account then starts with it: from the launcher by hand, from a launch group, or from the app.</p><p style="margin: 6px 0">Why: one profile per account keeps its windows, overview and keys apart from every other account's, so a launch with another account's profile can't change them, and the app knows which settings belong to it. Shared profiles (Alphas, Default) mix many accounts' files and the last client to close wins.</p><p style="margin: 6px 0">The name is yours to pick: it only shows in the EVE launcher and as a folder on this PC. The app never sends it anywhere.</p></details>`;
    for (const panel of panels) {
      const i = panel.dataset.launcher ? (pc.info || {})[panel.dataset.launcher] : null;
      if (!i || panel.querySelector('.ownprof')) continue;
      const user = Number(panel.dataset.launcher);
      const box = document.createElement('div');
      box.className = 'ownprof';
      const send = (query, after) => {
        const done = (e) => {
          if (String(e.detail?.user) !== String(user)) return;
          window.removeEventListener('devils-rage-app:pc-profile', done);
          after(e.detail.said || '');
        };
        window.addEventListener('devils-rage-app:pc-profile', done);
        ask(`pc-account-profile?user=${user}&${query}`);
      };
      const reload = (said) => { sessionStorage.setItem('dr-said-' + user, said); location.reload(); };
      let said = '';
      try { said = sessionStorage.getItem('dr-said-' + user) || ''; sessionStorage.removeItem('dr-said-' + user); } catch (e) {}
      if (i.own) {
        box.innerHTML = `<span>Profile: <b>settings_${esc(i.own)}</b> <span style="color: var(--ok)">✓ its own</span>${i.profile && i.profile !== i.own ? ` · <span style="color: var(--warn)">last started with settings_${esc(i.profile)}: pick ${esc(i.own)} in the launcher</span>` : ''}</span><button class="btn sm adv-only" type="button" data-change>Change</button><button class="btn sm adv-only" type="button" data-forget title="The folder stays; the app just stops calling it this account's own">Forget</button><span class="said">${esc(said)}</span>${how}`;
        box.querySelector('[data-change]').addEventListener('click', () => { box.remove(); drawSetup(panel, i, true); });
        box.querySelector('[data-forget]').addEventListener('click', () => send('forget=1', reload));
        panel.querySelector('.hint').after(box);
        drSaid(box.querySelector('.said'), said);
        continue;
      }
      drawSetup(panel, i, false, said);
    }
    function drawSetup(panel, i, changing, said = '') {
      const user = Number(panel.dataset.launcher);
      const send = (query, after) => {
        const done = (e) => {
          if (String(e.detail?.user) !== String(user)) return;
          window.removeEventListener('devils-rage-app:pc-profile', done);
          after(e.detail.said || '');
        };
        window.addEventListener('devils-rage-app:pc-profile', done);
        ask(`pc-account-profile?user=${user}&${query}`);
      };
      const reload = (text) => { try { sessionStorage.setItem('dr-said-' + user, text); } catch (e) {} location.reload(); };
      // Profiles this account's characters were played with, newest first.
      const ids = [...panel.querySelectorAll('.row[data-char]')].map((r) => r.dataset.char);
      const seenAt = new Map();
      const mark = (n, at) => { if (n) seenAt.set(n, Math.max(seenAt.get(n) || 0, at || 0)); };
      for (const id of ids) for (const p of (pc.chars || {})[id]?.profiles || []) mark(p.name, p.at);
      // 23 Nov: and every folder holding this account's own file.
      for (const [n, users] of Object.entries(folders)) if (users.map(Number).includes(user)) mark(n, (pc.folder_times || {})[n]?.[String(user)]);
      if (i.profile && !seenAt.has(i.profile)) seenAt.set(i.profile, Infinity);
      const froms = [...seenAt.entries()].sort((a, b) => (b[0] === i.profile) - (a[0] === i.profile) || b[1] - a[1]).map(([n]) => n);
      // Other accounts with a file in a folder; an old leftover copy (a month older than this
      // account's, or untouched for 90 days) isn't really sharing it.
      const sharers = (n) => {
        const times = (pc.folder_times || {})[n] || {};
        const mine = times[String(user)] || 0;
        return (folders[n] || []).filter((u) => Number(u) !== user).map((u) => {
          const at = times[String(u)] || 0;
          return { user: u, at, stale: !at || (mine && mine - at > 30 * 86400000) || Date.now() - at > 90 * 86400000 };
        });
      };
      const live = (n) => sharers(n).filter((o) => !o.stale);
      // Already its own: the profile it starts with holds only this account's files (leftovers aside).
      const onlyMine = (n) => (folders[n] || []).map(Number).includes(user) && !live(n).length;
      const already = froms.find(onlyMine);
      const shared = i.profile && !onlyMine(i.profile) ? live(i.profile).length : 0;
      const ago = (t) => { if (!t || t === Infinity) return ''; const d = Math.floor((Date.now() - t) / 86400000); return d <= 0 ? 'today' : d === 1 ? 'yesterday' : `${d} days ago`; };
      const useOpts = froms.map((n) => {
        const others = live(n).length;
        const bits = [n === i.profile ? 'starts with it now' : '', ago(seenAt.get(n)) ? 'used ' + ago(seenAt.get(n)) : '', others ? `${others} other account${others === 1 ? '' : 's'} too` : ''].filter(Boolean).join(' · ');
        return `<option value="${esc(n)}"${n === (already || i.profile) ? ' selected' : ''}>settings_${esc(n)}${bits ? ' (' + esc(bits) + ')' : ''}</option>`;
      }).join('');
      // Copy from: this account's profiles first, then every other profile on this PC.
      const every = new Set([...Object.keys(folders), ...Object.values(pc.chars || {}).flatMap((c) => (c.profiles || []).map((f) => f.name)), 'Default']);
      const rest = [...every].filter((n) => n && !froms.includes(n)).sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
      const first = i.profile && froms.includes(i.profile) ? i.profile : froms[0];
      const opt = (n) => `<option value="${esc(n)}"${n === first ? ' selected' : ''}>settings_${esc(n)}${n === i.profile ? ' (started with last)' : ''}</option>`;
      const opts = (froms.length ? `<optgroup label="This account's">${froms.map(opt).join('')}</optgroup>` : '')
        + (rest.length ? `<optgroup label="Other profiles on this PC">${rest.map(opt).join('')}</optgroup>` : '')
        + `<option value="fresh">Fresh: EVE's default settings</option>`;
      const taken = new Set([...every].map((n) => n.toLowerCase()));
      const suggest = [(i.login || '').replace(/[^A-Za-z0-9_-]/g, '')].find((n) => n && !taken.has(n.toLowerCase())) || '';
      const box = document.createElement('div');
      box.className = 'ownprof setup';
      box.innerHTML = `<b style="width: 100%">${changing ? 'Change its profile' : 'One-time setup: give this account its own EVE profile'}</b>
        ${froms.length ? `<div class="opt"><span>${already ? `<b>settings_${esc(already)}</b> already holds only this account's settings.` : 'Use one it already has:'}</span><select class="us">${useOpts}</select><button class="btn sm p" type="button" data-use>Use it</button></div>` : ''}
        ${shared ? `<div class="opt" style="color: var(--warn)">It starts with settings_${esc(i.profile)}, which ${shared} other account${shared === 1 ? '' : 's'} use${shared === 1 ? 's' : ''} too.</div>` : ''}
        <div class="opt"><span>${froms.length ? 'Or make a new one:' : 'Make one:'}</span><label>settings_<input class="nm" maxlength="40" size="14" value="${esc(suggest)}" placeholder="e.g. 01, main"></label><label>from <select class="fr">${opts}</select></label><button class="btn sm" type="button" data-make title="Copies that profile's game settings plus only this account's files; nothing else changes">Make it</button></div>
        <div class="opt" style="font-size: 11px">Making one: close every EVE client and the EVE launcher first (tray icon too). Then open the launcher and pick it for this account (How, below). Any name works: it only shows in the launcher and on this PC.${changing ? ' <button class="btn sm" type="button" data-cancel>Cancel</button>' : ''}</div>
        <span class="said">${esc(said)}</span>${how}`;
      box.querySelector('[data-use]')?.addEventListener('click', () => send('use=' + encodeURIComponent(box.querySelector('.us').value), reload));
      box.querySelector('[data-cancel]')?.addEventListener('click', () => location.reload());
      box.querySelector('[data-make]').addEventListener('click', () => {
        const name = box.querySelector('.nm').value.trim();
        if (!/^[A-Za-z0-9_-]{1,40}$/.test(name)) { drSaid(box.querySelector('.said'), 'Letters, numbers, - and _ only.'); return; }
        if (taken.has(name.toLowerCase())) { drSaid(box.querySelector('.said'), `settings_${name} is already there: pick it under "Use one it already has", or give a new name.`); return; }
        send(`name=${encodeURIComponent(name)}&from=${encodeURIComponent(box.querySelector('.fr').value)}`, (text) => (/^Made /.test(text) ? reload(text) : drSaid(box.querySelector('.said'), text)));
      });
      panel.querySelector('.hint').after(box);
      drSaid(box.querySelector('.said'), said);
    }
    profileLines(pc);
    otherChars(pc.others || []);

    // The rest: incognito, greyed in their account (or under This PC only).
    const unknown = accounts.map((a) => ({ account: a, user: String(a.user_id), ids: a.characters.map((c) => c.id).filter((id) => !mine.has(id) && !placed.has(id)) })).filter((a) => a.ids.length);
    if (!unknown.length) return;
    // Names from the app (it asks EVE's public lookup from your PC); this page's own lookup fills any gaps.
    accounts.forEach((a) => a.characters.forEach((c) => { if (c.how === 'profiles') guessed.add(c.id); }));
    const nameOf = Object.fromEntries(accounts.flatMap((a) => a.characters.filter((c) => c.name).map((c) => [c.id, c.name])));
    Object.assign(nameOf, await names(unknown.flatMap((a) => a.ids).filter((id) => !nameOf[id])));
    for (const { account, user, ids } of unknown) {
      const panel = panelFor(account);
      if (!panel) { pcOnly(user, ids, nameOf, account.profile); continue; }
      const box = panel.querySelector('.chars');
      const tile = document.querySelector(`.acct[data-id="${panel.dataset.id}"]`);
      ids.slice(0, Math.max(0, Number(panel.dataset.free))).forEach((id) => {
        box.insertBefore(incognitoRow(id, nameOf[id], [panel]), box.querySelector('.addrow'));
        ghostOnTile(tile, id);
      });
    }
    profileLines(pc);
  })();
})();

// Patch 74: what each character shares. Picking a level saves it and opens EVE's login for exactly
// that; Ally / Partial first show the checklist. Spy (the app) locks the row on this PC.
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const inApp = !!window.__DEVILS_RAGE_APP__;
  const ally = @json(\DevilsRage\Seat\Services\AccessScopes::template('ally'));
  const ask = (what) => { try { window.open('https://dr-app.invalid/' + what, '_blank'); } catch (e) {} };
  const openLogin = (url) => { const w = window.open(url, 'dr-login', 'popup=yes,width=820,height=900'); if (!w) location.href = url; };
  const nameOf = (row) => row.querySelector('.incog-toggle')?.dataset.name || '';
  // Ask twice, inline, in `box`; then `yes`.
  const twice = (box, first, second, yes, no) => {
    const keep = [...box.childNodes];
    const back = () => { box.innerHTML = ''; keep.forEach((k) => box.append(k)); no?.(); };
    box.innerHTML = `<span>${first}</span> <button class="btn sm" type="button">Yes</button> <button class="btn sm p" type="button">Cancel</button>`;
    const [y1, n1] = box.querySelectorAll('button');
    n1.addEventListener('click', back);
    y1.addEventListener('click', () => {
      box.innerHTML = `<span><b>Last check:</b> ${second}</span> <button class="btn sm" type="button">Yes</button> <button class="btn sm p" type="button">Cancel</button>`;
      const [y2, n2] = box.querySelectorAll('button');
      n2.addEventListener('click', back);
      y2.addEventListener('click', () => { back(); yes(); });
    });
  };
  // A spy row: marked, and locked until unlocked (asks twice); only on this PC.
  window.drMarkSpy = (row, on) => {
    row.classList.toggle('lvl-spy', on);
    document.querySelectorAll('.pp.tile img[data-name]').forEach((img) => { if (img.dataset.name.toLowerCase() === nameOf(row).toLowerCase()) img.parentNode.classList.toggle('lvl-spy', on); });
    row.querySelector('select.lvl').value = on ? 'spy' : row.dataset.level;
    row.querySelector('.shares').textContent = on ? 'Spy: locked on this PC, never shared' : row.querySelector('.shares').dataset.was || row.querySelector('.shares').textContent;
    row.querySelectorAll('form, .pills, select.grp, .x, .profs button').forEach((el) => { el.style.pointerEvents = on ? 'none' : ''; el.style.opacity = on ? '.45' : ''; });
    let lock = row.querySelector('.unlock');
    if (on && !lock) {
      lock = document.createElement('button');
      lock.className = 'btn sm unlock';
      lock.type = 'button';
      lock.textContent = '🔒 Unlock to change';
      lock.addEventListener('click', () => twice(lock.parentNode, `Unlock ${nameOf(row)} for changes?`, `${nameOf(row)} is marked as a spy. Changes can share it.`, () => {
        row.querySelectorAll('form, .pills, select.grp, .x, .profs button').forEach((el) => { el.style.pointerEvents = ''; el.style.opacity = ''; });
        row.querySelector('.unlock')?.remove();
      }));
      row.querySelector('.access').append(lock);
    }
    if (!on) row.querySelector('.unlock')?.remove();
  };
  document.querySelectorAll('.row[data-level]').forEach((row) => {
    const select = row.querySelector('select.lvl');
    const shares = row.querySelector('.shares');
    shares.dataset.was = shares.textContent;
    if (!inApp) select.querySelector('option[value="spy"]')?.remove();
    const save = async (level, groups) => {
      const r = await fetch(row.dataset.access, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify({ level, groups }) }).then((x) => x.json().then((j) => ({ ok: x.ok, j }))).catch(() => null);
      if (!r || !r.ok) return { error: r?.j?.error || 'Couldn\'t save. Reload and try again.' };
      return r.j;
    };
    const choose = async (level) => {
      row.querySelector('.checklist')?.remove();
      if (level === 'spy') {
        twice(row.querySelector('.access'), `Mark ${nameOf(row)} as a spy on this PC?`, 'it is hidden from the mapper and SeAT features in the app, and its row is locked here.', () => {
          ask(`pc-spy?name=${encodeURIComponent(nameOf(row))}&on=1`);
          window.drMarkSpy(row, true);
        }, () => { select.value = row.dataset.level; });
        return;
      }
      const mine = JSON.parse(row.dataset.groups || '[]');
      // Ally: its own ticks, else the template. The checklist only in Advanced editing.
      if (level === 'ally' && !document.body.classList.contains('advanced')) {
        const r = await save('ally', row.dataset.level === 'ally' && mine.length ? mine : ally);
        if (r.error) { shares.textContent = r.error; return; }
        openLogin(r.login);
        return;
      }
      if (level === 'ally') {
        const list = document.getElementById('checklist-tpl').content.firstElementChild.cloneNode(true);
        const ticked = row.dataset.level === 'ally' && mine.length ? mine : ally;
        list.querySelectorAll('input[type=checkbox]').forEach((b) => { b.checked = ticked.includes(b.value); });
        list.querySelector('[data-no]').addEventListener('click', () => { list.remove(); select.value = row.dataset.level; });
        list.querySelector('[data-go]').addEventListener('click', async () => {
          const groups = [...list.querySelectorAll('input:checked')].map((b) => b.value);
          const r = await save(level, groups);
          if (r.error) { list.querySelector('[data-say]').textContent = r.error; return; }
          openLogin(r.login);
        });
        row.append(list);
        return;
      }
      const r = await save(level, []);
      if (r.error) { shares.textContent = r.error; return; }
      openLogin(r.login);
    };
    row.querySelector('.edit-ticks')?.addEventListener('click', () => { document.body.classList.add('advanced'); choose('ally'); });
    select.addEventListener('change', () => {
      const level = select.value;
      if (row.classList.contains('lvl-spy') && level !== 'spy') {
        twice(row.querySelector('.access'), `Take the spy mark off ${nameOf(row)}?`, 'it can be shared again.', () => {
          ask(`pc-spy?name=${encodeURIComponent(nameOf(row))}&on=0`);
          window.drMarkSpy(row, false);
          choose(level);
        }, () => { select.value = 'spy'; });
        return;
      }
      choose(level);
    });
  });
})();

// 19 Nov: EVE's third-party apps page. In the app it opens in its own window, with a strip
// saying which EVE account should be signed in there (and a Switch account button).
(() => {
  if (!window.__DEVILS_RAGE_APP__) return;
  document.querySelectorAll('a[data-apps]').forEach((a) => {
    const tip = a.parentNode.querySelector('.apps-tip');
    if (tip) tip.textContent = 'Opens in its own window: the strip at its bottom says whether the right EVE account is signed in there.';
    a.addEventListener('click', (e) => {
      e.preventDefault();
      const login = a.closest('.panel')?.querySelector('.login-name')?.textContent || '';
      try { window.open(`https://dr-app.invalid/eve-apps?character=${a.dataset.apps}&login=${encodeURIComponent(login)}`, '_blank'); } catch (x) {}
    });
  });
})();

// Options (remembered on this PC).
(() => {
  const opt = (id, key, apply) => {
    const box = document.getElementById(id);
    let on = false;
    try { on = localStorage.getItem(key) === '1'; } catch (e) {}
    box.checked = on;
    apply(on);
    box.addEventListener('change', () => { try { localStorage.setItem(key, box.checked ? '1' : '0'); } catch (e) {} apply(box.checked); });
  };
  opt('opt-alpha', 'dr-hide-alpha', (on) => document.body.classList.toggle('hide-alpha', on));
  opt('opt-login', 'dr-show-login', (on) => document.body.classList.toggle('show-login', on));
  opt('opt-advanced', 'dr-advanced', (on) => document.body.classList.toggle('advanced', on));
  if (!window.__DEVILS_RAGE_APP__) document.getElementById('opt-login').closest('label').hidden = true;
})();

// A portrait: hover for what it trains, implants and remap; right-click keeps it open.
(() => {
  const ATTR = { Per: 'Perception', Wil: 'Willpower', Int: 'Intelligence', Mem: 'Memory', Cha: 'Charisma' };
  const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const roman = ['', 'I', 'II', 'III', 'IV', 'V'];
  const left = (iso) => {
    const ms = new Date(iso) - Date.now();
    if (!(ms > 0)) return 'now';
    const d = Math.floor(ms / 864e5), h = Math.floor(ms % 864e5 / 36e5), m = Math.floor(ms % 36e5 / 6e4);
    return d ? `${d}d ${h}h` : h ? `${h}h ${m}m` : `${m}m`;
  };
  const html = (name, s) => {
    if (!s) return `<b>${esc(name)}</b><br><span class="muted">Nothing from SeAT yet.</span>`;
    const t = s.training;
    const lines = [`<b>${esc(name)}</b>${s.total_sp ? ` <span class="muted">· ${Number(s.total_sp).toLocaleString()} SP</span>` : ''}`];
    lines.push(t ? `Training <b>${esc(t.skill)} ${roman[t.level] || t.level}</b>, ${left(t.ends)} left<br><span class="muted">Queue ends in ${left(t.queue_ends)}</span>` : '<span class="bad">Not training</span>');
    const imp = Object.entries(s.implants || {});
    const set = imp.length ? Math.min(...imp.map(([, v]) => v)) : 0;
    lines.push(imp.length ? `Implants: ${imp.map(([k, v]) => `${k} +${v}`).join(', ')}${imp.length === 5 ? ` <span class="ok">(full +${set} set)</span>` : ''}` : '<span class="muted">No attribute implants</span>');
    if (s.remap === 'even') lines.push('Attributes: not remapped (even)');
    else if (s.remap) lines.push(`Mapped for <b>${s.remap.split('/').map((a) => ATTR[a] || a).join(' / ')}</b>` + (s.remap_fits === true ? ` <span class="ok">✓ fits ${esc(t?.skill || '')}</span>` : s.remap_fits === false ? ` <span class="bad">⚠ ${esc(t?.skill || 'this skill')} wants ${esc(t?.wants || '?')}</span>` : ''));
    const remaps = [];
    if (s.bonus_remaps) remaps.push(`${s.bonus_remaps} bonus remap${s.bonus_remaps === 1 ? '' : 's'}`);
    if (s.remap_ready) remaps.push(new Date(s.remap_ready) <= new Date() ? 'yearly remap ready' : `yearly remap in ${left(s.remap_ready)}`);
    if (remaps.length) lines.push(`<span class="muted">${remaps.join(' · ')}</span>`);
    return lines.join('<br>');
  };
  let pop = null;
  const close = () => { pop?.remove(); pop = null; };
  const show = (img, menu) => {
    close();
    let sheet = null;
    try { sheet = JSON.parse(img.dataset.sheet || 'null'); } catch (e) {}
    pop = document.createElement('div');
    pop.className = 'sheet-pop' + (menu ? ' menu' : '');
    pop.innerHTML = html(img.dataset.name || '', sheet);
    document.body.append(pop);
    const r = img.getBoundingClientRect();
    pop.style.left = Math.min(window.innerWidth - pop.offsetWidth - 8, r.right + 8) + 'px';
    pop.style.top = Math.min(window.innerHeight - pop.offsetHeight - 8, r.top) + 'px';
  };
  document.querySelectorAll('img.pic[data-sheet]').forEach((img) => {
    img.addEventListener('mouseenter', () => { if (!pop?.classList.contains('menu')) show(img, false); });
    img.addEventListener('mouseleave', () => { if (!pop?.classList.contains('menu')) close(); });
    img.addEventListener('contextmenu', (e) => { e.preventDefault(); e.stopPropagation(); show(img, true); });
  });
  document.addEventListener('click', (e) => { if (pop && !pop.contains(e.target)) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
})();

// Launch groups on a character: only the ones it's in; + shows the rest.
document.querySelectorAll('.pills .more').forEach((b) => b.addEventListener('click', () => {
  const open = b.closest('.pills').classList.toggle('open');
  b.textContent = open ? '−' : '+';
}));
</script>
<script>
(() => {
  // Removing asks first, on the page itself (the app has no browser pop-up dialogs).
  const box = document.getElementById('confirm');
  let pending = null;
  document.querySelectorAll('form.ask').forEach((form) => form.addEventListener('submit', (e) => {
    if (form.dataset.sure === '1') return;
    e.preventDefault();
    pending = form;
    document.getElementById('confirm-text').textContent = form.dataset.ask;
    box.classList.add('show');
    document.getElementById('confirm-no').focus();
  }));
  const close = () => { box.classList.remove('show'); if (pending) { delete pending.dataset.asked2; } pending = null; };
  document.getElementById('confirm-no').addEventListener('click', close);
  box.addEventListener('click', (e) => { if (e.target === box) close(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  document.getElementById('confirm-yes').addEventListener('click', () => {
    if (!pending) return;
    // Corp-wide removals ask twice.
    if (pending.dataset.ask2 && pending.dataset.asked2 !== '1') {
      pending.dataset.asked2 = '1';
      document.getElementById('confirm-text').textContent = pending.dataset.ask2;
      return;
    }
    pending.dataset.sure = '1';
    pending.submit();
  });
})();

(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const store = {
    get: (k, s = localStorage) => { try { return s.getItem(k); } catch (e) { return null; } },
    set: (k, v, s = localStorage) => { try { v ? s.setItem(k, v) : s.removeItem(k); } catch (e) {} },
  };
  const post = (url, ids) => fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
    body: JSON.stringify({ ids }),
  });

  // Which account is open (remembered for this visit), and the list.
  const list = document.getElementById('acct-list');
  const select = (id) => {
    document.querySelectorAll('.acct').forEach((a) => a.classList.toggle('sel', a.dataset.id === String(id)));
    document.querySelectorAll('.panel[data-id]').forEach((p) => p.classList.toggle('show', p.dataset.id === String(id)));
    store.set('dr-open-account', String(id), sessionStorage);
  };
  const accts = [...document.querySelectorAll('.acct')];
  const remembered = store.get('dr-open-account', sessionStorage);
  const first = accts.find((a) => a.dataset.id === remembered) || accts.find((a) => a.classList.contains('todo')) || accts[0];
  if (first) select(first.dataset.id);
  accts.forEach((a) => a.addEventListener('click', () => select(a.dataset.id)));

  // Drag accounts and characters into order (saved straight away).
  const sortable = (container, itemSelector, save) => {
    let dragged = null;
    container.querySelectorAll(itemSelector).forEach((item) => {
      item.addEventListener('dragstart', (e) => { dragged = item; e.dataTransfer.effectAllowed = 'move'; e.stopPropagation(); });
      item.addEventListener('dragover', (e) => { if (dragged && dragged !== item && dragged.parentNode === item.parentNode) { e.preventDefault(); item.classList.add('drop'); } });
      item.addEventListener('dragleave', () => item.classList.remove('drop'));
      item.addEventListener('drop', (e) => {
        e.preventDefault(); e.stopPropagation(); item.classList.remove('drop');
        if (!dragged || dragged === item) return;
        const items = [...container.querySelectorAll(itemSelector)];
        const before = items.indexOf(dragged) > items.indexOf(item);
        item.parentNode.insertBefore(dragged, before ? item : item.nextSibling);
        save([...container.querySelectorAll(itemSelector)].map((el) => Number(el.dataset.id)));
      });
      item.addEventListener('dragend', () => { dragged = null; });
    });
  };
  sortable(list, '.acct', (ids) => post(@json(route('devilsrage.accounts.order')), ids));
  document.querySelectorAll('.chars').forEach((box) => {
    const url = @json(route('devilsrage.accounts.characters.order', ['account' => '__ID__'])).replace('__ID__', box.dataset.account);
    sortable(box, '.row[data-id]', (ids) => post(url, ids));
  });

  // Login hints: this browser on this PC only, never sent anywhere.
  document.querySelectorAll('input[data-hint]').forEach((input) => {
    const key = 'dr-login-hint-' + input.dataset.hint;
    input.value = store.get(key) || '';
    input.addEventListener('input', () => store.set(key, input.value.trim()));
  });

  // Omega / MCT / PLEX editor (opens in place; Save posts to the same place SeAT uses).
  document.querySelectorAll('[data-omega]').forEach((b) => b.addEventListener('click', () => {
    document.getElementById('omega-' + b.dataset.omega).classList.toggle('show');
  }));

  // New account / add a character choice.
  document.getElementById('new-account-btn').addEventListener('click', () => {
    const f = document.getElementById('new-account');
    f.classList.toggle('show');
    f.querySelector('input').focus();
  });
  document.querySelectorAll('[data-choice]').forEach((el) => el.addEventListener('click', () => {
    document.getElementById('choice-' + el.dataset.choice).classList.toggle('show');
  }));

  // EVE logins open in a small window; when it comes back here it closes and this screen reloads.
  let popup = null;
  const openLogin = (url) => {
    popup = window.open(url, 'dr-login', 'popup=yes,width=820,height=900');
    if (!popup) location.href = url;
  };
  window.addEventListener('message', (e) => {
    if (e.origin === location.origin && e.data === 'dr-login-done') location.reload();
  });
  document.querySelectorAll('a.login').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openLogin(a.href); }));
  document.getElementById('next-account').addEventListener('click', () => {
    openLogin('https://login.eveonline.com/account/logoff');
  });

  // Fix this account: its steps one at a time (each reload shows the next one).
  const runSteps = (panel) => {
    const steps = JSON.parse(panel.dataset.steps || '[]');
    const box = document.getElementById('steps-' + panel.dataset.id);
    if (!steps.length) { store.set('dr-fixing', '', sessionStorage); box.classList.remove('show'); return; }
    const next = steps[0];
    box.innerHTML = steps.map((s, i) => `<div class="s ${i === 0 ? 'now' : ''}">${i === 0 ? '→' : '·'} ${s.kind === 'seat' ? 'SeAT' : 'Mapper'}: pick <b>${s.name.replace(/[<>&]/g, '')}</b>, Authorize</div>`).join('')
      + `<div style="margin-top: 8px; display: flex; gap: 8px"><button class="btn p sm" type="button">Open EVE's login</button><button class="btn sm" type="button">Stop</button></div>`;
    const [go, stop] = box.querySelectorAll('button');
    go.addEventListener('click', () => openLogin(next.url));
    stop.addEventListener('click', () => { store.set('dr-fixing', '', sessionStorage); box.classList.remove('show'); });
    box.classList.add('show');
  };
  document.querySelectorAll('[data-fix]').forEach((b) => b.addEventListener('click', () => {
    const panel = document.getElementById('panel-' + b.dataset.fix);
    store.set('dr-fixing', b.dataset.fix, sessionStorage);
    runSteps(panel);
    const steps = JSON.parse(panel.dataset.steps || '[]');
    if (steps[0]) openLogin(steps[0].url);
  }));
  const fixing = store.get('dr-fixing', sessionStorage);
  if (fixing && document.getElementById('panel-' + fixing)) runSteps(document.getElementById('panel-' + fixing));
})();
</script>
</body>
</html>
