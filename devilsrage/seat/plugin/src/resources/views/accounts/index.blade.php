@extends('web::layouts.grids.12')

@section('title', 'My accounts')
@section('page_header', 'My accounts')

@section('full')
  @include('devilsrage::partials.flash')

  <style>
    .dr-accounts { display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
    .dr-acct { border: 1px solid rgba(120,120,120,.25); border-radius: 6px; }
    .dr-acct-head { display: flex; align-items: center; gap: .35rem; padding: .35rem .6rem; border-bottom: 1px solid rgba(120,120,120,.2); font-size: .85rem; }
    .dr-char { display: flex; align-items: center; gap: .35rem; padding: .25rem .6rem; font-size: .85rem; min-height: 32px; }
    .dr-char + .dr-char { border-top: 1px dashed rgba(120,120,120,.15); }
    .dr-char .dr-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 11rem; }
    .dr-char select.form-control { height: 24px; padding: 0 .3rem; font-size: .75rem; width: auto; }
    .dr-move button { line-height: .7; padding: 0 .2rem; font-size: .7rem; }
    .dr-empty { color: #8a8a8a; font-style: italic; }
    .dr-tog { cursor: pointer; margin: 0; }
    .dr-tog input { display: none; }
    .dr-tog .dr-pill { opacity: .35; }
    .dr-tog input:checked + .dr-pill { opacity: 1; box-shadow: 0 0 0 1px currentColor inset; }
    .dr-gear { border-top: 1px solid rgba(120,120,120,.2); font-size: .8rem; }
    .dr-gear > summary { padding: .25rem .6rem; cursor: pointer; color: #6c757d; list-style: none; }
    .dr-gear > summary::-webkit-details-marker { display: none; }
    .dr-gear[open] > summary { color: inherit; }
    .dr-gear .dr-gear-body { padding: .3rem .6rem .6rem; display: flex; flex-direction: column; gap: .35rem; }
    .dr-gear .form-control { height: 26px; padding: 0 .3rem; font-size: .8rem; }
    .dr-gear .dr-row { display: flex; align-items: center; flex-wrap: wrap; gap: .3rem; }
    .dr-gear .dr-row > label:first-child { width: 2.8rem; margin: 0; font-weight: 600; }
  </style>

  <div class="card">
    <div class="card-body py-2 d-flex flex-wrap align-items-center" style="gap: .75rem;">
      <form method="post" action="{{ route('devilsrage.accounts.store') }}" class="form-inline" style="gap: .4rem;">
        @csrf
        <label for="dr-nickname" class="mb-0 font-weight-bold">New account</label>
        <input id="dr-nickname" type="text" name="nickname" value="{{ old('nickname') }}" maxlength="32" class="form-control form-control-sm" placeholder="nickname, e.g. 34" required>
        <button type="submit" class="btn btn-sm btn-primary">Add</button>
      </form>
      @if($unassigned->isNotEmpty())
        <span class="small"><strong>Not in an account:</strong> {{ $unassigned->pluck('name')->implode(', ') }}</span>
      @endif
      <span class="small text-muted ml-auto">
        Up to {{ $maxCharacters }} characters per account. The corp group (Main / Second / Third) is what FCs see; the coloured pills are your own groups, only you see them.
        Nicknames only, never your login name. ⚙ = Omega, MCT, PLEX, rename, remove.
      </span>
    </div>
  </div>

  <div class="dr-accounts">
    @foreach($accounts as $account)
      @php($om = $omega[$account->id] ?? null)
      <div class="dr-acct">
        <div class="dr-acct-head">
          <i class="fas fa-user-astronaut text-muted"></i>
          <strong class="mr-auto">{{ $account->nickname }}</strong>
          @include('devilsrage::partials.omega-badge', ['om' => $om])
          @include('devilsrage::partials.plex-badge', ['held' => $holdings[$account->id] ?? null])
          <span class="text-muted small">{{ $account->characters->count() }}/{{ $maxCharacters }}</span>
        </div>

        @foreach($account->characters as $link)
          @php($ticked = $link->launchGroups->pluck('id')->all())
          @php($corpPick = $link->launchGroups->firstWhere('locked', true))
          <div class="dr-char">
            <span class="dr-move d-inline-flex flex-column">
              <form method="post" action="{{ route('devilsrage.accounts.characters.move', $link) }}">@csrf<input type="hidden" name="direction" value="up"><button type="submit" class="btn btn-link text-muted" title="Move up" @disabled($loop->first)><i class="fas fa-caret-up"></i></button></form>
              <form method="post" action="{{ route('devilsrage.accounts.characters.move', $link) }}">@csrf<input type="hidden" name="direction" value="down"><button type="submit" class="btn btn-link text-muted" title="Move down" @disabled($loop->last)><i class="fas fa-caret-down"></i></button></form>
            </span>
            <img src="https://images.evetech.net/characters/{{ $link->character_id }}/portrait?size=32" width="22" height="22" class="rounded" alt="">
            <span class="dr-name" title="{{ $link->displayName() }}{{ $link->external ? ' · not on SeAT · '.($link->external_corporation ?? '?').($link->external_alliance ? ' / '.$link->external_alliance : '') : '' }}">{{ $link->displayName() }}</span>
            @if($link->external)<span class="badge badge-light" title="Not on our SeAT: public data only, fits ticked by hand">🔗</span>@endif
            @if($om && in_array($link->character_id, $om['alpha_characters'], true))<span class="badge badge-warning" title="Training at Alpha speed right now">α?</span>@endif
            <form method="post" action="{{ route('devilsrage.accounts.characters.corpgroup', $link) }}" class="ml-auto">
              @csrf
              <select name="group" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Corp launch group">
                <option value="">—</option>
                @foreach($corpGroups as $group)
                  <option value="{{ $group->id }}" @selected($corpPick && $corpPick->id === $group->id)>{{ $group->name }}</option>
                @endforeach
              </select>
            </form>
            @if($ownGroups->isNotEmpty())
              <form method="post" action="{{ route('devilsrage.accounts.characters.groups', $link) }}" class="d-inline-flex flex-wrap" style="gap: .15rem;">
                @csrf
                @foreach($ownGroups as $group)
                  <label class="dr-tog" title="{{ $group->name }}">
                    <input type="checkbox" name="groups[]" value="{{ $group->id }}" @checked(in_array($group->id, $ticked, true)) onchange="this.form.submit()">
                    <span class="dr-pill dr-{{ $group->pillClass() }}">{{ \Illuminate\Support\Str::limit($group->name, 10, '') }}</span>
                  </label>
                @endforeach
              </form>
            @endif
          </div>
        @endforeach

        @for($slot = $account->characters->count(); $slot < $maxCharacters; $slot++)
          <div class="dr-char dr-empty">
            <i class="far fa-square"></i>
            @if($slot === $account->characters->count() && $unassigned->isNotEmpty())
              <form method="post" action="{{ route('devilsrage.accounts.characters.add', $account) }}" class="d-inline-flex" style="gap: .3rem;">
                @csrf
                <select name="character_id" class="form-control form-control-sm" aria-label="Character to add">
                  <option value="">empty slot: add a character…</option>
                  @foreach($unassigned as $character)
                    <option value="{{ $character->character_id }}">{{ $character->name }}</option>
                  @endforeach
                </select>
                <button type="submit" class="btn btn-xs btn-outline-primary">Add</button>
              </form>
            @else
              empty slot
            @endif
          </div>
        @endfor

        <details class="dr-gear">
          <summary><i class="fas fa-cog"></i> Omega, MCT, PLEX, edit</summary>
          <div class="dr-gear-body">
            <form method="post" action="{{ route('devilsrage.accounts.omega', $account) }}" class="d-flex flex-column" style="gap: .3rem;">
              @csrf
              <div class="dr-row">
                <label for="dr-omega-{{ $account->id }}">Omega</label>
                <input id="dr-omega-{{ $account->id }}" type="date" name="omega_until" value="{{ $account->omega_until?->format('Y-m-d') }}" class="form-control" title="When Omega runs out">
                <label class="mb-0"><input type="checkbox" name="omega_auto" value="1" @checked($account->omega_auto)> auto-renew</label>
              </div>
              <div class="dr-row">
                <label for="dr-mct-{{ $account->id }}">MCT</label>
                <select id="dr-mct-{{ $account->id }}" name="mct_slots" class="form-control" title="Multiple Character Training slots">
                  @foreach([0 => 'none', 1 => '1 slot', 2 => '2 slots'] as $slots => $label)
                    <option value="{{ $slots }}" @selected((int) $account->mct_slots === $slots)>{{ $label }}</option>
                  @endforeach
                </select>
                <input type="date" name="mct_until" value="{{ $account->mct_until?->format('Y-m-d') }}" class="form-control" aria-label="MCT until" title="When MCT runs out">
                <label class="mb-0"><input type="checkbox" name="mct_auto" value="1" @checked($account->mct_auto)> auto</label>
              </div>
              <div class="dr-row">
                <label for="dr-vault-{{ $account->id }}">PLEX</label>
                <input id="dr-vault-{{ $account->id }}" type="number" min="0" step="1" name="plex_vault" value="{{ $account->plex_vault }}" class="form-control" style="width: 6rem;" placeholder="in vault" title="PLEX in the account's PLEX vault (EVE doesn't share it with SeAT)">
                <span class="text-muted">in vault · {{ number_format($holdings[$account->id]['hangar'] ?? 0) }} in hangars</span>
                <button type="submit" class="btn btn-xs btn-primary ml-auto">Save</button>
              </div>
            </form>

            @if($account->characters->count() < $maxCharacters)
              <form method="post" action="{{ route('devilsrage.accounts.characters.external', $account) }}" class="dr-row">
                @csrf
                <label for="dr-ext-{{ $account->id }}" title="A character that isn't on our SeAT">Other</label>
                <input id="dr-ext-{{ $account->id }}" type="text" name="name" maxlength="37" class="form-control" style="width: 11rem;" placeholder="exact character name">
                <button type="submit" class="btn btn-xs btn-outline-secondary" title="Adds a character that isn't on our SeAT (public data only)">Add not-on-SeAT</button>
              </form>
            @endif

            @foreach($account->characters->where('external', true) as $link)
              <form method="post" action="{{ route('devilsrage.accounts.characters.fits', $link) }}" class="dr-row">
                @csrf
                <label title="Corp fits {{ $link->displayName() }} can fly">Fits</label>
                <span class="small">{{ $link->displayName() }} can fly:</span>
                <select name="fits[]" multiple size="3" class="form-control" style="height: auto; min-width: 12rem;" aria-label="Corp fits {{ $link->displayName() }} can fly">
                  @foreach($fittings as $fitId => $fitName)
                    <option value="{{ $fitId }}" @selected(in_array($fitId, $externalFits[$link->id] ?? [], true))>{{ $fitName }}</option>
                  @endforeach
                </select>
                <button type="submit" class="btn btn-xs btn-outline-secondary">Save fits</button>
              </form>
            @endforeach

            <div class="dr-row">
              <label>Edit</label>
              <form method="post" action="{{ route('devilsrage.accounts.rename', $account) }}" class="d-inline-flex" style="gap: .3rem;">
                @csrf
                <input type="text" name="nickname" value="{{ $account->nickname }}" maxlength="32" class="form-control" style="width: 6rem;" aria-label="Nickname">
                <button type="submit" class="btn btn-xs btn-outline-secondary">Rename</button>
              </form>
              @foreach($account->characters as $link)
                <form method="post" action="{{ route('devilsrage.accounts.characters.remove', $link) }}">
                  @csrf
                  <button type="submit" class="btn btn-xs btn-outline-secondary" title="Take {{ $link->displayName() }} out of this account"><i class="fas fa-times"></i> {{ \Illuminate\Support\Str::limit($link->displayName(), 12) }}</button>
                </form>
              @endforeach
              <form method="post" action="{{ route('devilsrage.accounts.delete', $account) }}" class="ml-auto" onsubmit="return confirm('Remove this account? Its characters stay in SeAT.');">
                @csrf
                <button type="submit" class="btn btn-xs btn-outline-danger" title="Remove this account (its characters stay in SeAT)"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </div>
        </details>
      </div>
    @endforeach
  </div>

  <details class="card" @if($errors->has('name')) open @endif>
    <summary class="card-header" style="cursor: pointer;"><h3 class="card-title">Launch groups: who is in each, and your own groups</h3></summary>
    <div class="card-body">
      <h5 class="mb-1">Corp groups <i class="fas fa-lock text-muted small" title="One character per account"></i></h5>
      <p class="text-muted small mb-2">Main, Second and Third are what the corp sees. Each account holds one character per group, because EVE logs in one character per account.</p>
      <div class="row">
        @foreach($corpGroups as $group)
          @include('devilsrage::partials.group-members', ['group' => $group, 'members' => $members[$group->id], 'deletable' => false])
        @endforeach
      </div>
      <h5 class="mb-1 mt-2">My own groups <i class="fas fa-user-secret text-muted small" title="Only you see these"></i></h5>
      <p class="text-muted small mb-2">Only you see these, e.g. a 7-pilot DST launch with its own overview setup. A character can be in several. They warn you when two characters of one account share a group.</p>
      <div class="row">
        @forelse($ownGroups as $group)
          @include('devilsrage::partials.group-members', ['group' => $group, 'members' => $members[$group->id], 'deletable' => true])
        @empty
          <div class="col-12 text-muted small mb-2">No groups of your own yet.</div>
        @endforelse
      </div>
      <form method="post" action="{{ route('devilsrage.groups.store') }}" class="form-inline" style="gap: .5rem;">
        @csrf
        <label for="dr-group-name" class="sr-only">New launch group</label>
        <input id="dr-group-name" type="text" name="name" maxlength="40" class="form-control form-control-sm" placeholder="New group, e.g. Siege, DST launch" required>
        <button type="submit" class="btn btn-sm btn-primary">Add launch group</button>
      </form>
    </div>
  </details>

  <div class="card">
    <div class="card-body d-flex align-items-center flex-wrap" style="gap: 1rem;">
      <form method="post" action="{{ route('devilsrage.accounts.share') }}" class="d-inline">
        @csrf
        <input type="hidden" name="share" value="{{ $share ? 0 : 1 }}">
        <button type="submit" class="btn {{ $share ? 'btn-success' : 'btn-outline-secondary' }}">
          <i class="fas {{ $share ? 'fa-eye' : 'fa-eye-slash' }}"></i>
          {{ $share ? 'Shared with admins and directors' : 'Private' }}
        </button>
      </form>
      <span class="text-muted">
        @if($share)
          Admins and directors see your accounts (by nickname), corp launch groups and Omega status in the corp layout and the FC planner. Your own groups stay private. Click to make it private.
        @else
          Only you see your accounts. FCs see your characters one by one, without the account grouping. Click to share.
        @endif
      </span>
    </div>
  </div>
@stop
