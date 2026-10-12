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
              <li class="list-group-item d-flex align-items-center flex-wrap" style="gap: .5rem;">
                <img src="https://images.evetech.net/characters/{{ $link->character_id }}/portrait?size=64" width="32" height="32" class="rounded" alt="">
                <span class="mr-auto">{{ $character->name ?? 'Character '.$link->character_id }}</span>
                <form method="post" action="{{ route('devilsrage.accounts.characters.update', $link) }}" class="d-inline">
                  @csrf
                  <select name="launch_group" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Launch group">
                    <option value="">No launch group</option>
                    @foreach($groups as $key => $label)
                      <option value="{{ $key }}" @selected($link->launch_group === $key)>{{ $label }}</option>
                    @endforeach
                  </select>
                </form>
                <form method="post" action="{{ route('devilsrage.accounts.characters.remove', $link) }}" class="d-inline">
                  @csrf
                  <button type="submit" class="btn btn-xs btn-outline-secondary" title="Take out of this account"><i class="fas fa-times"></i></button>
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
      <div class="row">
        @foreach($groups as $key => $label)
          <div class="col-md-4">
            <h5><span class="dr-pill dr-{{ $key }}">{{ $label }}</span></h5>
            <ul class="list-unstyled mb-3">
              @forelse($groupMembers[$key] as $member)
                <li>{{ $member['character'] }} <span class="text-muted small">· {{ $member['account'] }}</span></li>
              @empty
                <li class="text-muted small">Nobody yet</li>
              @endforelse
            </ul>
          </div>
        @endforeach
      </div>
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
