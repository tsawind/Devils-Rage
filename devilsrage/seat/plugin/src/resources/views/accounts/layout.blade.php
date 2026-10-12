@extends('web::layouts.grids.12')

@section('title', 'Corp layout')
@section('page_header', 'Corp layout')

@section('full')
  @include('devilsrage::partials.flash')

  <div class="card">
    <div class="card-body d-flex flex-wrap align-items-center" style="gap: .75rem;">
      @foreach($groupCounts as $name => $count)
        <span class="dr-pill dr-{{ ['Main' => 'main', 'Second' => 'second', 'Third' => 'third'][$name] ?? 'custom' }}">{{ $name }}: {{ $count }}</span>
      @endforeach
      <span class="text-muted small ml-auto">
        Shown: pilots who share their layout (corp groups only; pilots' own groups are private). {{ $privateCount }} kept it private.
        @if($ungroupedPilots > 0) {{ $ungroupedPilots }} corp pilot(s) haven't set up accounts yet. @endif
      </span>
    </div>
  </div>

  @forelse($accounts as $userId => $userAccounts)
    @php($pilot = $users->get($userId))
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <img src="https://images.evetech.net/characters/{{ $pilot?->main_character_id ?? 1 }}/portrait?size=64" width="24" height="24" class="rounded mr-1" alt="">
          {{ $pilot?->main_character?->name ?? $pilot?->name ?? 'Unknown pilot' }}
        </h3>
      </div>
      <div class="card-body">
        <div class="row">
          @foreach($userAccounts as $account)
            <div class="col-md-4 mb-2">
              <strong>{{ $account->nickname }}</strong>
              @include('devilsrage::partials.omega-badge', ['om' => $omega[$account->id] ?? null])
              <span class="text-muted small">{{ $account->characters->count() }}/3</span>
              <ul class="list-unstyled mb-0 mt-1">
                @forelse($account->characters as $link)
                  <li class="d-flex align-items-center flex-wrap" style="gap: .3rem;">
                    <span class="mr-1">{{ $link->character->name ?? $link->character_id }}</span>
                    @forelse($link->launchGroups as $group)
                      <span class="dr-pill dr-{{ $group->pillClass() }}">{{ $group->name }}</span>
                    @empty
                      <span class="dr-pill dr-none">No group</span>
                    @endforelse
                  </li>
                @empty
                  <li class="text-muted small">No characters</li>
                @endforelse
              </ul>
            </div>
          @endforeach
        </div>
      </div>
    </div>
  @empty
    <div class="card"><div class="card-body text-muted">Nobody has set up accounts yet. Pilots do that under Devil's Rage → My accounts.</div></div>
  @endforelse
@stop
