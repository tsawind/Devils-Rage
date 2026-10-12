@extends('web::layouts.grids.12')

@section('title', "Devil's Rage")
@section('page_header', "Devil's Rage")

@php
  $fmt = fn (float $value): string => abs($value) >= 1e9 ? number_format($value / 1e9, 2).'b' : number_format($value / 1e6, 0).'m';
@endphp

@section('full')
  @include('devilsrage::partials.flash')

  <div class="row">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-users"></i> Your accounts</h3>
          <div class="card-tools"><a href="{{ route('devilsrage.accounts.index') }}" class="btn btn-xs btn-outline-secondary">My accounts</a></div></div>
        <div class="card-body">
          <div class="d-flex flex-wrap mb-2" style="gap: 1.25rem;">
            <div><div class="text-muted small">Accounts</div><div class="h4 mb-0">{{ $accounts->count() }}</div></div>
            <div><div class="text-muted small">Characters</div><div class="h4 mb-0">{{ $characterCount }}</div></div>
            <div><div class="text-muted small">PLEX</div><div class="h4 mb-0">{{ number_format($plex) }}</div></div>
            <div><div class="text-muted small">ISK</div><div class="h4 mb-0">{{ $fmt((float) $isk) }}</div></div>
          </div>
          <div class="small">
            Omega: <span class="badge badge-success">{{ ($states['ok'] ?? 0) + ($states['auto'] ?? 0) }} ok</span>
            <span class="badge badge-warning">{{ $states['soon'] ?? 0 }} ending soon</span>
            <span class="badge badge-danger">{{ $states['expired'] ?? 0 }} ended</span>
            <span class="badge badge-secondary">{{ $states['unknown'] ?? 0 }} no date</span>
          </div>
          @if($soonest)
            <div class="small mt-1">Next to run out: <strong>{{ $soonest['until']->format('Y-m-d') }}</strong> ({{ $soonest['days_left'] }} days)</div>
          @endif
          @if($accounts->isEmpty())
            <div class="small text-muted mt-2">Group your characters into accounts under <a href="{{ route('devilsrage.accounts.index') }}">My accounts</a>.</div>
          @endif
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-graduation-cap"></i> Training</h3></div>
        <div class="card-body small">
          @if($idle === [] && $endingSoon === [])
            <span class="text-success"><i class="fas fa-check"></i> Every character is training, no queue ends within a day.</span>
          @endif
          @if($idle !== [])
            <div class="mb-1"><span class="badge badge-danger">Not training</span> {{ implode(', ', $idle) }}</div>
          @endif
          @foreach($endingSoon as $row)
            <div><span class="badge badge-warning">Queue ends {{ $row['ends']->diffForHumans() }}</span> {{ $row['name'] }}</div>
          @endforeach
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-bolt"></i> Quick links</h3></div>
        <div class="card-body d-flex flex-wrap" style="gap: .4rem;">
          <a href="{{ route('devilsrage.stuff') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-box"></i> Find my stuff</a>
          <a href="{{ route('devilsrage.stuff', ['tab' => 'clones']) }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-user-clock"></i> Clones</a>
          <a href="{{ route('devilsrage.skillplans.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-graduation-cap"></i> Skill plans</a>
          <a href="{{ route('devilsrage.spfarm.index') }}" class="btn btn-sm btn-outline-primary"><i class="fas fa-seedling"></i> SP farming</a>
          @if($isLeader)
            <a href="{{ route('devilsrage.layout') }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sitemap"></i> Corp layout</a>
            <a href="{{ route('devilsrage.planner') }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-chess"></i> FC planner</a>
          @endif
          <a href="{{ route('devilsrage.menu') }}" class="btn btn-sm btn-outline-secondary"><i class="fas fa-bars"></i> Arrange menu</a>
          <a href="{{ route('seatcore::home', ['seat' => 1]) }}" class="btn btn-sm btn-link">SeAT home</a>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="card">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-rocket"></i> Doctrines: how many of your characters can fly each fit</h3></div>
        <div class="card-body p-0">
          @forelse($doctrines as $doctrine)
            <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
              <thead><tr class="table-secondary"><th colspan="3">{{ $doctrine['name'] }}</th></tr></thead>
              <tbody>
                @foreach($doctrine['fits'] as $fit)
                  <tr>
                    <td>{{ $fit['name'] }} <span class="text-muted small">{{ $fit['ship'] }}</span></td>
                    <td class="text-right" style="width: 8rem;">
                      @if($fit['can'] > 0)<span class="badge badge-success">{{ $fit['can'] }} can fly</span>@else<span class="badge badge-secondary">none yet</span>@endif
                    </td>
                    <td class="small text-muted" style="width: 40%;">{{ $fit['closest'] ? 'closest: '.$fit['closest'] : '' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          @empty
            <div class="p-3 text-muted">No corp doctrines yet.</div>
          @endforelse
        </div>
        <div class="card-footer small text-muted">Updated every 15 minutes. Corp skill plans: <a href="{{ route('devilsrage.skillplans.index') }}">Skill plans</a>.</div>
      </div>
    </div>
  </div>
@stop
