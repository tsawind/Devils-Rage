@extends('web::layouts.grids.12')

@section('title', 'My stuff')
@section('page_header', 'My stuff')

@php
  $isk = fn (float $value): string => $value >= 1e9 ? number_format($value / 1e9, 2).'b' : ($value >= 1e6 ? number_format($value / 1e6, 0).'m' : number_format($value / 1e3, 0).'k');
@endphp

@section('full')
  @include('devilsrage::partials.flash')

  <ul class="nav nav-tabs mb-2">
    <li class="nav-item"><a class="nav-link {{ $tab === 'assets' ? 'active' : '' }}" href="{{ route('devilsrage.stuff', array_filter(['character' => $only])) }}"><i class="fas fa-box"></i> Assets</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'clones' ? 'active' : '' }}" href="{{ route('devilsrage.stuff', array_filter(['tab' => 'clones', 'character' => $only])) }}"><i class="fas fa-user-clock"></i> Clones</a></li>
  </ul>

  <div class="card">
    <div class="card-body py-2">
      <form method="get" action="{{ route('devilsrage.stuff') }}" class="form-inline" style="gap: .5rem;">
        <input type="hidden" name="tab" value="{{ $tab }}">
        @if($tab === 'assets')
          <input type="search" name="q" value="{{ $query }}" class="form-control form-control-sm" style="min-width: 18rem;" placeholder="Search items, ships or names, e.g. Naglfar, Nanite, Crystal" autofocus>
          <label class="mb-0 small"><input type="checkbox" name="ships" value="1" @checked($shipsOnly)> ships only</label>
        @endif
        <select name="character" class="form-control form-control-sm" aria-label="Character">
          <option value="">All my characters ({{ $names->count() }})</option>
          @foreach($names as $id => $name)
            <option value="{{ $id }}" @selected($only === (int) $id)>{{ $name }}</option>
          @endforeach
        </select>
        <button type="submit" class="btn btn-sm btn-primary">{{ $tab === 'assets' ? 'Search' : 'Show' }}</button>
      </form>
    </div>
  </div>

  @if($tab === 'assets')
    @if($query !== '' || $shipsOnly)
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ count($results) }} found{{ count($results) >= $limit ? ' (first '.$limit.', search more precisely)' : '' }}</h3></div>
        <div class="card-body p-0" style="overflow-x: auto;">
          <table class="table table-sm table-hover mb-0" style="font-variant-numeric: tabular-nums;">
            <thead><tr><th>Item</th><th class="text-right">Qty</th><th>System</th><th>Where</th><th>Inside</th><th>Character</th><th class="text-right">≈ Value</th></tr></thead>
            <tbody>
              @forelse($results as $row)
                <tr>
                  <td><img src="https://images.evetech.net/types/{{ $row['type_id'] }}/icon?size=32" width="18" height="18" alt=""> {{ $row['item'] }} @if($row['is_ship'])<i class="fas fa-rocket text-muted small"></i>@endif</td>
                  <td class="text-right">{{ number_format($row['quantity']) }}</td>
                  <td>{{ $row['system'] }}</td>
                  <td class="small">{{ $row['location'] }}</td>
                  <td class="small text-muted">{{ $row['inside'] ?? '' }}</td>
                  <td class="small">{{ $row['character'] }}</td>
                  <td class="text-right">{{ $row['value'] > 0 ? $isk($row['value']) : '' }}</td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-muted p-3">Nothing matches.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    @else
      <div class="card">
        <div class="card-header"><h3 class="card-title">Where your stuff is (most valuable systems first)</h3></div>
        <div class="card-body p-0">
          <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
            <thead><tr><th>System</th><th class="text-right">Items</th><th class="text-right">≈ Value</th><th>Ships</th></tr></thead>
            <tbody>
              @forelse($systems as $system)
                <tr>
                  <td>{{ $systemNames[$system['system_id']] ?? $system['system_id'] }}</td>
                  <td class="text-right">{{ number_format($system['items']) }}</td>
                  <td class="text-right">{{ $isk($system['value']) }}</td>
                  <td class="small">{{ implode(', ', array_slice($system['ships'], 0, 8)) }}{{ count($system['ships']) > 8 ? ' …' : '' }}</td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-muted p-3">SeAT has no assets for your characters yet.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        <div class="card-footer small text-muted">Search above for an item or ship. Values use SeAT's market prices. Assets update about once an hour.</div>
      </div>
    @endif
  @else
    <div class="card">
      <div class="card-body p-0" style="overflow-x: auto;">
        <table class="table table-sm mb-0">
          <thead><tr><th>Character</th><th>Home station</th><th>Next clone jump</th><th>Active implants</th><th>Jump clones</th></tr></thead>
          <tbody>
            @forelse($clones as $row)
              <tr>
                <td class="font-weight-bold" style="white-space: nowrap;"><img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" width="20" height="20" class="rounded" alt=""> {{ $row['name'] }}</td>
                <td class="small">{{ $row['home'] }}</td>
                <td class="small" style="white-space: nowrap;">
                  @if($row['jump_ready'] === null || $row['jump_ready']->isPast())
                    <span class="badge badge-success">ready</span>
                  @else
                    <span class="badge badge-warning" title="{{ $row['jump_ready']->format('Y-m-d H:i') }} EVE">{{ $row['jump_ready']->diffForHumans(null, true) }}</span>
                  @endif
                </td>
                <td class="small">{{ $row['implants'] === [] ? '–' : implode(', ', $row['implants']) }}</td>
                <td class="small">
                  @forelse($row['clones'] as $clone)
                    <div><strong>{{ $clone['location'] }}</strong>@if($clone['name'] !== '') <span class="text-muted">"{{ $clone['name'] }}"</span>@endif
                      <span class="text-muted">· {{ $clone['implants'] === [] ? 'no implants' : implode(', ', $clone['implants']) }}</span></div>
                  @empty
                    <span class="text-muted">none</span>
                  @endforelse
                </td>
              </tr>
            @empty
              <tr><td colspan="5" class="text-muted p-3">No characters.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">Next clone jump ≈ 24 h after the last one, 1 h less per Infomorph Synchronizing level. Clones update about once an hour.</div>
    </div>
  @endif
@stop
