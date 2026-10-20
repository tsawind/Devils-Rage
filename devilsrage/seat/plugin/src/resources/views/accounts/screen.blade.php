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
          $rows[] = ['link' => $link, 'st' => $st];
          if (! $link->external) {
              $total++;
              $ready += $st['ok'] ? 1 : 0;
              if (! in_array($st['seat'], ['ok', 'public'], true)) {
                  $steps[] = ['kind' => 'seat', 'name' => $link->displayName(), 'url' => $seatLogin($account)];
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
      'ok' => ['SeAT ✓', 'ok', 'SeAT login works, all permissions'],
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
          <div class="acct {{ $todo ? 'todo' : '' }}" draggable="true" data-id="{{ $account->id }}" title="Drag ⋮⋮ to change the order">
            <span class="grip">⋮⋮</span>
            @for($i = 0; $i < $maxCharacters; $i++)
              @if(isset($account->characters[$i]))
                <img class="pic" src="https://images.evetech.net/characters/{{ $account->characters[$i]->character_id }}/portrait?size=64" alt="" loading="lazy">
              @else
                <span class="pic empty"></span>
              @endif
            @endfor
            <span class="nick"><b>{{ $account->nickname }}</b><br><span style="font-size: 11px; color: {{ $todo ? 'var(--warn)' : 'var(--ok)' }}">{{ $todo ? (count($card['steps']) ? count($card['steps']).' to fix' : 'empty') : '✓' }}</span></span>
          </div>
        @endforeach
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
        <div class="panel" id="panel-{{ $account->id }}" data-id="{{ $account->id }}" data-steps='@json($card['steps'])'>
          <div class="phead">
            <form method="post" action="{{ route('devilsrage.accounts.rename', $account) }}" style="display: flex; gap: 6px; align-items: center">
              @csrf
              <span class="muted">Account</span>
              <input class="name" name="nickname" value="{{ $account->nickname }}" maxlength="32" aria-label="Nickname" style="background: transparent; border: 1px solid transparent; width: 90px" onfocus="this.style.borderColor='#3a3a44'" onchange="this.form.submit()">
            </form>
            @include('devilsrage::partials.omega-badge', ['om' => $om])
            <span style="flex: 1"></span>
            <a class="muted" style="font-size: 12px" href="{{ route('devilsrage.accounts.index') }}#acct-{{ $account->id }}">Omega, MCT, PLEX</a>
          </div>
          <div class="hint"><span>🔑 Login hint</span><input type="text" data-hint="{{ $account->id }}" maxlength="80" placeholder="e.g. tsawind10 / gmail"><span>saved on this PC only</span></div>

          <div class="chars" data-account="{{ $account->id }}">
            @foreach($card['rows'] as $row)
              @php
                $link = $row['link'];
                $st = $row['st'];
                $lab = $labels[$st['seat']] ?? $labels['missing'];
                $corpPick = $link->launchGroups->firstWhere('locked', true);
              @endphp
              <div class="row" draggable="true" data-id="{{ $link->id }}">
                <span class="grip">⋮⋮</span>
                <img class="pic" src="https://images.evetech.net/characters/{{ $link->character_id }}/portrait?size=64" alt="" loading="lazy">
                <span class="cn" title="{{ $link->displayName() }}">{{ $link->displayName() }}</span>
                @if(in_array($st['seat'], ['ok', 'external'], true))
                  <span class="chip {{ $lab[1] }}" title="{{ $lab[2] }}">{{ $lab[0] }}</span>
                @elseif($st['seat'] === 'public')
                  <a class="chip pub login" href="{{ $seatLogin($account) }}" title="{{ $lab[2] }}. Click to upgrade it to full access.">{{ $lab[0] }}</a>
                @else
                  <a class="chip {{ $lab[1] }} login" href="{{ $seatLogin($account, (bool) $link->public_only) }}" title="{{ $lab[2] }}: pick {{ $link->displayName() }}, Authorize">{{ $lab[0] }}</a>
                @endif
                @if($link->external)
                  <span class="chip muted">—</span>
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
                <form method="post" action="{{ route('devilsrage.accounts.characters.remove', $link) }}" onsubmit="return confirm('Take {{ addslashes($link->displayName()) }} out of this account? It stays in SeAT.');">
                  @csrf
                  <button class="x" type="submit" title="Take out of this account">✕</button>
                </form>
              </div>
            @endforeach
            @for($slot = count($card['rows']); $slot < $maxCharacters; $slot++)
              <div class="row">
                <span></span><span class="pic empty"></span>
                @if($slot === count($card['rows']))
                  <span class="add" data-choice="{{ $account->id }}" style="grid-column: span 3">+ Add a character</span>
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
                @else
                  <span class="muted" style="grid-column: span 3">empty slot</span>
                @endif
              </div>
            @endfor
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
            <form method="post" action="{{ route('devilsrage.accounts.delete', $account) }}" onsubmit="return confirm('Remove account {{ addslashes($account->nickname) }}? Its characters stay in SeAT.');">
              @csrf
              <button class="x" type="submit" title="Remove this account (its characters stay in SeAT)">Remove account</button>
            </form>
          </div>
          <div class="steps" id="steps-{{ $account->id }}"></div>
        </div>
      @endforeach
    </div>
  </div>
</div>

<script>
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
    popup = window.open(url, 'dr-login', 'popup=yes,width=560,height=760');
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
