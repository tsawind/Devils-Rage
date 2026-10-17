@extends('web::layouts.grids.12')

@section('title', $plan->name)
@section('page_header', 'Skill plan: '.$plan->name)

@php
  $sp = fn (int $points): string => $points >= 1e6 ? number_format($points / 1e6, 2).'M' : number_format($points / 1e3, 0).'k';
  $isk = fn (float $value): string => abs($value) >= 1e9 ? number_format($value / 1e9, 2).'b' : number_format($value / 1e6, 0).'m';
@endphp

@section('full')
  @include('devilsrage::partials.flash')

  <div class="row">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ $isLeader ? 'Corp progress' : 'Your characters' }} <span class="text-muted small">· plan {{ $sp($planSp) }} SP</span></h3>
          <div class="card-tools">
            <form method="get" class="form-inline" style="gap: .4rem;">
              @if($focus)<input type="hidden" name="character" value="{{ $focus['id'] }}">@endif
              @include('devilsrage::partials.group-filter')
            </form>
          </div>
        </div>
        <div class="card-body p-0" style="overflow-x: auto;">
          <table class="table table-sm table-hover mb-0" style="font-variant-numeric: tabular-nums;">
            <thead><tr><th>Character</th><th>Done</th><th>Left to train</th><th class="text-right">SP missing</th><th class="text-right" title="Large Skill Injectors to cover the missing SP (fewer SP per injector past 5M / 50M / 80M total SP)">Injectors</th><th>Missing</th></tr></thead>
            <tbody>
              @forelse($status as $characterId => $row)
                @php($pct = $row['total_points'] > 0 ? (int) floor($row['done_points'] / $row['total_points'] * 100) : 0)
                <tr @if($focus && $focus['id'] === $characterId) class="table-active" @endif>
                  <td><a href="{{ request()->fullUrlWithQuery(['character' => $characterId]) }}" title="Show what's missing and copy it for EVE">{{ $names[$characterId] ?? $characterId }}</a></td>
                  <td style="color: {{ $pct >= 100 ? '#2f7a4f' : ($pct >= 70 ? '#a2650c' : '#b3261e') }}">{{ $pct }}%</td>
                  <td>{{ $row['missing'] === [] ? 'Done' : '≈ '.\DevilsRage\Seat\Skills\SkillMath::duration($row['seconds']) }}</td>
                  <td class="text-right">{{ $row['missing_points'] > 0 ? $sp($row['missing_points']) : '' }}</td>
                  <td class="text-right">
                    @if(($injectors[$characterId] ?? 0) > 0)
                      <span title="{{ $injectorPrice > 0 ? '≈ '.$isk($injectors[$characterId] * $injectorPrice).' ISK at Jita sell' : 'No Jita price' }}">{{ $injectors[$characterId] }}</span>
                    @endif
                  </td>
                  <td class="small text-muted">
                    {{ collect($row['missing'])->map(fn ($level, $skillId) => ($skillNames[$skillId] ?? $skillId).' '.$level)->take(6)->implode(', ') }}{{ count($row['missing']) > 6 ? ' …' : '' }}
                  </td>
                </tr>
              @empty
                <tr><td colspan="6" class="text-muted p-3">No characters. Check the launch group filter.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @if($injectorPrice > 0)
          <div class="card-footer small text-muted">Large Skill Injector ≈ {{ $isk($injectorPrice) }} ISK (Jita sell). Click a character for their missing skills.</div>
        @endif
      </div>
    </div>
    <div class="col-lg-4">
      @if($focus)
        <div class="card card-outline card-primary">
          <div class="card-header">
            <h3 class="card-title">{{ $focus['name'] }}</h3>
            <div class="card-tools"><a href="{{ request()->fullUrlWithQuery(['character' => null]) }}" class="btn btn-xs btn-outline-secondary" title="Close">✕</a></div>
          </div>
          <div class="card-body small">
            @if($focus['row']['missing'] === [])
              <span class="text-success"><i class="fas fa-check"></i> Has every skill in the plan.</span>
            @else
              <div class="mb-2">
                {{ $sp($focus['row']['missing_points']) }} SP missing · ≈ {{ \DevilsRage\Seat\Skills\SkillMath::duration($focus['row']['seconds']) }} of training
                · or {{ $focus['injectors'] }} injector{{ $focus['injectors'] === 1 ? '' : 's' }}@if($injectorPrice > 0) (≈ {{ $isk($focus['injectors'] * $injectorPrice) }} ISK)@endif
                <div class="text-muted">Total SP now {{ $sp($focus['total_sp']) }}.</div>
              </div>
              <ul class="list-unstyled mb-2" style="max-height: 260px; overflow-y: auto;">
                @foreach($focus['row']['missing'] as $skillId => $level)
                  <li class="d-flex"><span class="mr-auto">{{ $skillNames[$skillId] ?? $skillId }}</span><span>{{ $focus['trained'][$skillId] ?? 0 }} → {{ $level }}</span></li>
                @endforeach
              </ul>
              <textarea id="dr-copy-missing" class="d-none">{{ $focus['text'] }}</textarea>
              <button type="button" class="btn btn-sm btn-primary" onclick="drCopy('dr-copy-missing', this)">⧉ Copy missing</button>
              <span class="text-muted">paste into EVE's skill planner</span>
            @endif
          </div>
        </div>
      @endif
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ count($requirements) }} skills · {{ $sp($planSp) }} SP</h3>
          <div class="card-tools">
            <textarea id="dr-copy-plan" class="d-none">{{ $planText }}</textarea>
            <button type="button" class="btn btn-xs btn-outline-primary" onclick="drCopy('dr-copy-plan', this)" title="One skill level per line, as EVE's skill planner pastes it">⧉ Copy plan for EVE</button>
          </div>
        </div>
        <ul class="list-group list-group-flush" style="max-height: 480px; overflow-y: auto;">
          @foreach($requirements as $skillId => $level)
            <li class="list-group-item py-1 d-flex"><span class="mr-auto">{{ $skillNames[$skillId] ?? $skillId }}</span><span>{{ $level }}</span></li>
          @endforeach
        </ul>
        @if($canManage)
          <div class="card-footer">
            <form method="post" action="{{ route('devilsrage.skillplans.delete', $plan) }}">
              @csrf
              <button type="submit" class="btn btn-sm btn-outline-danger">Remove plan</button>
            </form>
          </div>
        @endif
      </div>
    </div>
  </div>

  <script>
    function drCopy(id, button) {
      const text = document.getElementById(id).value;
      const done = () => { const label = button.textContent; button.textContent = '✓ Copied'; setTimeout(() => (button.textContent = label), 1500); };
      if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(done, () => window.prompt('Copy:', text));
      } else {
        window.prompt('Copy:', text);
      }
    }
  </script>
@stop
