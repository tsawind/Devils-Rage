@extends('web::layouts.grids.12')

@section('title', 'SP farming')
@section('page_header', 'SP farming')

@php
  $isk = fn (?float $value): string => $value === null ? '–' : (abs($value) >= 1e9 ? number_format($value / 1e9, 2).'b' : number_format($value / 1e6, 0).'m');
@endphp

@section('full')
  @include('devilsrage::partials.flash')

  <div class="card">
    <div class="card-body d-flex flex-wrap align-items-center" style="gap: 1.5rem;">
      <div><div class="text-muted small">Profit per month</div><div class="h4 mb-0 {{ $totals['profit'] >= 0 ? 'text-success' : 'text-danger' }}">{{ $isk($totals['profit']) }} ISK</div></div>
      <div><div class="text-muted small">Injectors per month</div><div class="h4 mb-0">{{ number_format($totals['injectors'], 1) }}</div></div>
      <div><div class="text-muted small">Sales after extractors</div><div class="h4 mb-0">{{ $isk($totals['revenue']) }}</div></div>
      <div><div class="text-muted small">Omega / MCT cost</div><div class="h4 mb-0">{{ $isk($totals['cost']) }}</div></div>
      <div class="ml-auto small text-muted">
        PLEX {{ $isk($prices['plex']) }} ({{ $prices['source']['plex'] }}) ·
        Injector {{ $isk($prices['injector']) }} ({{ $prices['source']['injector'] }}) ·
        Extractor {{ $isk($prices['extractor']) }} ({{ $prices['source']['extractor'] }})<br>
        Omega {{ $isk($prices['omega_month']) }}/month · MCT {{ $isk($prices['mct_month']) }}/month ·
        deals and PLEX sales: <a href="https://www.theoz.space/deals" target="_blank" rel="noopener">theoz.space/deals</a>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><h3 class="card-title">My farm characters</h3></div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
        <thead>
          <tr><th>Character</th><th>Slot</th><th class="text-right">Skill points</th><th class="text-right">Extract now</th><th class="text-right">SP / day</th><th class="text-right">Injectors / month</th><th class="text-right">Profit / month</th><th></th></tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            <tr>
              <td>
                <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" width="24" height="24" class="rounded mr-1" alt="">
                {{ $row['name'] }}
                @if($row['alpha'])<span class="badge badge-warning" title="Training at Alpha speed: this account may have lost Omega">Alpha?</span>@endif
              </td>
              <td>
                <form method="post" action="{{ route('devilsrage.spfarm.store') }}">
                  @csrf
                  <input type="hidden" name="character_id" value="{{ $row['character_id'] }}">
                  <select name="slot" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Training slot">
                    @foreach($slots as $key => $label)
                      <option value="{{ $key }}" @selected($row['slot'] === $key)>{{ $label }}</option>
                    @endforeach
                  </select>
                </form>
              </td>
              <td class="text-right">{{ number_format($row['sp']) }}</td>
              <td class="text-right">{{ $row['ready'] }}</td>
              <td class="text-right">{{ $row['sp_per_day'] === null ? 'not training' : number_format($row['sp_per_day']) }}</td>
              <td class="text-right">{{ $row['month'] === null ? '–' : number_format($row['month']['injectors'], 2) }}</td>
              <td class="text-right {{ ($row['month']['profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{{ $isk($row['month']['profit'] ?? null) }}</td>
              <td>
                <form method="post" action="{{ route('devilsrage.spfarm.delete', $row['character_id']) }}">
                  @csrf
                  <button type="submit" class="btn btn-xs btn-outline-secondary" title="Take off the farm list"><i class="fas fa-times"></i></button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="8" class="text-muted p-3">No farm characters yet. Add one below.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      @if($others->isNotEmpty())
        <form method="post" action="{{ route('devilsrage.spfarm.store') }}" class="form-inline" style="gap: .5rem;">
          @csrf
          <select name="character_id" class="form-control form-control-sm" aria-label="Character">
            @foreach($others as $characterId => $name)
              <option value="{{ $characterId }}">{{ $name }}</option>
            @endforeach
          </select>
          <select name="slot" class="form-control form-control-sm" aria-label="Training slot">
            @foreach($slots as $key => $label)
              <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Add farm character</button>
        </form>
      @endif
      <div class="small text-muted mt-2">
        SP / day is the speed of the skill training right now (put farms on their best skills, e.g. with a +5 implant set).
        Extract now = 500k skill extractions available above the 5,000,000 SP floor. Profit = injectors × (injector − extractor price) − the slot's PLEX cost.
      </div>
    </div>
  </div>

  @if($canEditSettings)
    <div class="card card-outline card-secondary">
      <div class="card-header"><h3 class="card-title">Farm numbers (leaders, for everyone)</h3></div>
      <div class="card-body">
        <form method="post" action="{{ route('devilsrage.spfarm.settings') }}">
          @csrf
          <div class="form-row">
            @foreach($settings as $key => $setting)
              <div class="form-group col-md-4">
                <label for="dr-{{ $key }}" class="small">{{ $setting['label'] }}</label>
                <input id="dr-{{ $key }}" type="number" step="any" min="0" name="{{ $key }}" value="{{ $setting['value'] }}" class="form-control form-control-sm">
              </div>
            @endforeach
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
          <span class="small text-muted ml-2">Check the Omega / MCT PLEX costs in the in-game New Eden Store; set a price here to use a sale price instead of the market average.</span>
        </form>
      </div>
    </div>
  @endif
@stop
