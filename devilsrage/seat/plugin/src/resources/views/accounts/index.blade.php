@extends('web::layouts.grids.12')

@section('title', 'My accounts')
@section('page_header', 'My accounts')

@section('full')
  @include('devilsrage::partials.flash')

  <div class="card">
    <div class="card-body">
      <p class="mb-2">
        Group your characters into the EVE accounts they live on (up to {{ $maxCharacters }} per account), then put them in
        launch groups. EVE logs in one character per account at a time, so each launch group can hold one character per account.
      </p>
      <p class="mb-0 text-muted">
        Give each account a <strong>nickname</strong>, not your real login name. The nickname is the only thing anyone else sees about the account.
      </p>
    </div>
  </div>

  <div class="row">
    @foreach($accounts as $account)
      <div class="col-md-6 col-xl-4">
        <div class="card">
          <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0 mr-auto"><i class="fas fa-user-astronaut"></i> {{ $account->nickname }}</h3>
            <span class="text-muted small mr-2">{{ $account->characters->count() }} / {{ $maxCharacters }}</span>
            <form method="post" action="{{ route('devilsrage.accounts.delete', $account) }}" class="d-inline">
              @csrf
              <button type="submit" class="btn btn-xs btn-outline-danger" title="Remove this account (its characters stay in SeAT)">
                <i class="fas fa-trash"></i>
              </button>
            </form>
          </div>
          <ul class="list-group list-group-flush">
            @foreach($account->characters as $link)
              @php($character = $characters->get($link->character_id))
              @php($ticked = $link->launchGroups->pluck('id')->all())
              <li class="list-group-item">
                <div class="d-flex align-items-center" style="gap: .5rem;">
                  <div class="btn-group-vertical btn-group-sm">
                    <form method="post" action="{{ route('devilsrage.accounts.characters.move', $link) }}">
                      @csrf
                      <input type="hidden" name="direction" value="up">
                      <button type="submit" class="btn btn-xs btn-outline-secondary py-0" title="Move up" @disabled($loop->first)><i class="fas fa-caret-up"></i></button>
                    </form>
                    <form method="post" action="{{ route('devilsrage.accounts.characters.move', $link) }}">
                      @csrf
                      <input type="hidden" name="direction" value="down">
                      <button type="submit" class="btn btn-xs btn-outline-secondary py-0" title="Move down" @disabled($loop->last)><i class="fas fa-caret-down"></i></button>
                    </form>
                  </div>
                  <img src="https://images.evetech.net/characters/{{ $link->character_id }}/portrait?size=64" width="32" height="32" class="rounded" alt="">
                  <span class="mr-auto font-weight-bold">{{ $character->name ?? 'Character '.$link->character_id }}</span>
                  <form method="post" action="{{ route('devilsrage.accounts.characters.remove', $link) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-xs btn-outline-secondary" title="Take out of this account"><i class="fas fa-times"></i></button>
                  </form>
                </div>
                <form method="post" action="{{ route('devilsrage.accounts.characters.groups', $link) }}" class="d-flex flex-wrap mt-2 pl-4" style="gap: .25rem .75rem;">
                  @csrf
                  @foreach($groups as $group)
                    <div class="custom-control custom-checkbox">
                      <input type="checkbox" class="custom-control-input" id="dr-g-{{ $link->id }}-{{ $group->id }}" name="groups[]" value="{{ $group->id }}"
                             @checked(in_array($group->id, $ticked, true)) onchange="this.form.submit()">
                      <label class="custom-control-label" for="dr-g-{{ $link->id }}-{{ $group->id }}">
                        <span class="dr-pill dr-{{ $group->pillClass() }}">{{ $group->name }}</span>
                      </label>
                    </div>
                  @endforeach
                  <noscript><button type="submit" class="btn btn-xs btn-primary">Save</button></noscript>
                </form>
              </li>
            @endforeach
            @if($account->characters->count() < $maxCharacters)
              <li class="list-group-item">
                @if($unassigned->isEmpty())
                  <span class="text-muted small">All your characters are in an account.</span>
                @else
                  <form method="post" action="{{ route('devilsrage.accounts.characters.add', $account) }}" class="form-inline" style="gap: .5rem;">
                    @csrf
                    <select name="character_id" class="form-control form-control-sm" aria-label="Character to add">
                      @foreach($unassigned as $character)
                        <option value="{{ $character->character_id }}">{{ $character->name }}</option>
                      @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Add</button>
                  </form>
                @endif
              </li>
            @endif
          </ul>
          <div class="card-footer">
            <form method="post" action="{{ route('devilsrage.accounts.rename', $account) }}" class="form-inline" style="gap: .5rem;">
              @csrf
              <input type="text" name="nickname" value="{{ $account->nickname }}" maxlength="32" class="form-control form-control-sm" aria-label="Nickname">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Rename</button>
            </form>
          </div>
        </div>
      </div>
    @endforeach

    <div class="col-md-6 col-xl-4">
      <div class="card card-outline card-primary">
        <div class="card-header"><h3 class="card-title">Add an account</h3></div>
        <div class="card-body">
          <form method="post" action="{{ route('devilsrage.accounts.store') }}">
            @csrf
            <div class="form-group">
              <label for="dr-nickname">Nickname</label>
              <input id="dr-nickname" type="text" name="nickname" value="{{ old('nickname') }}" maxlength="32" class="form-control" placeholder="e.g. Ember, Alt box 2" required>
            </div>
            <button type="submit" class="btn btn-primary">Add account</button>
          </form>
        </div>
      </div>

      @if($unassigned->isNotEmpty())
        <div class="card">
          <div class="card-header"><h3 class="card-title">Not in an account yet</h3></div>
          <ul class="list-group list-group-flush">
            @foreach($unassigned as $character)
              <li class="list-group-item">{{ $character->name }}</li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">My launch groups</h3></div>
    <div class="card-body">
      <p class="text-muted small">
        Tick groups on each character above; a character can be in several.
        <span class="dr-pill dr-main">Main</span> <span class="dr-pill dr-second">Second</span> <span class="dr-pill dr-third">Third</span>
        hold one character per account (EVE logs in one character per account). Your own groups have no lock, but warn you when two characters of one account are in the same group.
      </p>
      <div class="row">
        @foreach($groups as $group)
          <div class="col-md-4 col-xl-3 mb-3">
            <div class="d-flex align-items-center mb-1" style="gap: .4rem;">
              <span class="dr-pill dr-{{ $group->pillClass() }}">{{ $group->name }}</span>
              @if($group->locked)<i class="fas fa-lock text-muted small" title="One character per account"></i>@endif
              @if($group->user_id !== null)
                <form method="post" action="{{ route('devilsrage.groups.delete', $group) }}" class="ml-auto">
                  @csrf
                  <button type="submit" class="btn btn-xs btn-link text-danger p-0" title="Remove this launch group"><i class="fas fa-trash"></i></button>
                </form>
              @endif
            </div>
            <ul class="list-unstyled mb-0">
              @forelse($members[$group->id]['members'] as $member)
                <li>{{ $member['character'] }} <span class="text-muted small">· {{ $member['account'] }}</span></li>
              @empty
                <li class="text-muted small">Nobody yet</li>
              @endforelse
            </ul>
            @foreach($members[$group->id]['clashes'] as $nickname)
              <div class="text-warning small"><i class="fas fa-exclamation-triangle"></i> Two characters of {{ $nickname }}: only one can be logged in.</div>
            @endforeach
          </div>
        @endforeach
      </div>
      <form method="post" action="{{ route('devilsrage.groups.store') }}" class="form-inline" style="gap: .5rem;">
        @csrf
        <label for="dr-group-name" class="sr-only">New launch group</label>
        <input id="dr-group-name" type="text" name="name" maxlength="40" class="form-control form-control-sm" placeholder="New group, e.g. Siege, PvE crew" required>
        <button type="submit" class="btn btn-sm btn-primary">Add launch group</button>
      </form>
    </div>
  </div>

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
          Admins and directors see your accounts (by nickname) and launch groups in the corp layout and the FC planner. Click to make it private.
        @else
          Only you see your accounts. FCs see your characters one by one, without the account grouping. Click to share.
        @endif
      </span>
    </div>
  </div>
@stop
