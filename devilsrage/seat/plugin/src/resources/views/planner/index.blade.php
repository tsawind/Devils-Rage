@extends('web::layouts.grids.12')

@section('title', 'FC planner')
@section('page_header', 'FC planner')

@section('full')
  @include('devilsrage::partials.flash')
  <style>
    .dr-cell { display: inline-block; padding: .2rem .45rem; border-radius: 4px; font-size: .8rem; font-weight: 500; white-space: nowrap; }
    .dr-ok { background: rgba(47, 122, 79, .15); color: #2f7a4f; }
    .dr-close { background: rgba(162, 101, 12, .15); color: #a2650c; }
    .dr-far { background: rgba(179, 38, 30, .13); color: #b3261e; }
    tr.dr-blocked td { opacity: .45; }
    tr.dr-picked td { background: rgba(47, 122, 79, .08); }
    .dr-planner th, .dr-planner td { vertical-align: middle !important; }
    .dr-bar { display: inline-block; width: 60px; height: 6px; border-radius: 3px; background: rgba(120,120,120,.25); overflow: hidden; vertical-align: middle; margin-left: .3rem; }
    .dr-bar i { display: block; height: 100%; background: currentColor; }
  </style>

  <div class="card">
    <div class="card-body">
      <form method="get" action="{{ route('devilsrage.planner') }}" class="form-inline" style="gap: .75rem;">
        <label for="dr-doctrine">Doctrine</label>
        <select id="dr-doctrine" name="doctrine" class="form-control form-control-sm" onchange="this.form.submit()">
          @forelse($doctrines as $item)
            <option value="{{ $item->id }}" @selected($doctrine && $doctrine->id === $item->id)>{{ $item->name }}</option>
          @empty
            <option value="">No doctrines yet (Fitting → Doctrine)</option>
          @endforelse
        </select>
        <label for="dr-plan">Skill plan</label>
        <select id="dr-plan" name="plan" class="form-control form-control-sm" onchange="this.form.submit()">
          <option value="">None</option>
          @foreach($plans as $item)
            <option value="{{ $item->id }}" @selected($plan && $plan->id === $item->id)>{{ $item->name }}</option>
          @endforeach
        </select>
        <label for="dr-group">Launch group</label>
        <select id="dr-group" name="group" class="form-control form-control-sm" onchange="this.form.submit()">
          <option value="">Everyone</option>
          @foreach($groups as $key => $label)
            <option value="{{ $key }}" @selected($group === $key)>{{ $label }}</option>
          @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Show</button></noscript>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex flex-wrap align-items-center" style="gap: .75rem;">
      <h3 class="card-title mr-auto">{{ $doctrine->name ?? 'No doctrine' }} · {{ count($rows) }} characters</h3>
      <span class="small">Picked: <strong id="dr-picked-count">0</strong></span>
      <span class="dr-cell dr-ok">Can fly</span>
      <span class="dr-cell dr-close">Under 7 days</span>
      <span class="dr-cell dr-far">Further off</span>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm table-hover mb-0 dr-planner">
        <thead>
          <tr>
            <th title="Pick for this fleet">Pick</th>
            <th>Pilot</th>
            <th>Account</th>
            <th>Character</th>
            <th>Group</th>
            @foreach($fits as $fit)
              <th>{{ $fit->name }}<br><span class="text-muted small font-weight-normal">{{ $fit->ship->typeName }}</span></th>
            @endforeach
            @if($plan)<th>{{ $plan->name }}</th>@endif
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $characterId => $row)
            <tr data-account="{{ $row['account_key'] }}" data-name="{{ $row['name'] }}">
              <td><input type="checkbox" class="dr-pick" aria-label="Pick {{ $row['name'] }}"></td>
              <td>{{ $row['pilot'] }}</td>
              <td>{{ $row['account'] ?? '–' }}</td>
              <td>{{ $row['name'] }} <span class="dr-clash text-danger small"></span></td>
              <td><span class="dr-pill dr-{{ $row['group'] ?? 'none' }}">{{ $groups[$row['group']] ?? '–' }}</span></td>
              @foreach($fits as $fit)
                @php($cell = $status[$fit->fitting_id][$characterId] ?? null)
                @php($hasHull = in_array((int) $fit->ship_type_id, $hulls->get($characterId, []), true))
                <td>
                  @if($cell === null)
                    –
                  @elseif($cell['missing'] === [])
                    <span class="dr-cell dr-ok">Can fly</span>
                  @else
                    <span class="dr-cell {{ $cell['seconds'] < $closeSeconds ? 'dr-close' : 'dr-far' }}"
                          title="{{ collect($cell['missing'])->map(fn ($level, $skillId) => ($skillNames[$skillId] ?? $skillId).' '.$level)->take(12)->implode(', ') }}">
                      {{ count($cell['missing']) }} skills · {{ \DevilsRage\Seat\Skills\SkillMath::duration($cell['seconds']) }}
                    </span>
                  @endif
                  @if($hasHull)<i class="fas fa-rocket text-muted ml-1" title="Has this hull"></i>@endif
                </td>
              @endforeach
              @if($plan)
                @php($p = $planStatus[$characterId] ?? null)
                @php($pct = $p && $p['total_points'] > 0 ? (int) floor($p['done_points'] / $p['total_points'] * 100) : 0)
                <td style="color: {{ $pct >= 100 ? '#2f7a4f' : ($pct >= 70 ? '#a2650c' : '#b3261e') }}">
                  {{ $pct }}%<span class="dr-bar"><i style="width: {{ $pct }}%"></i></span>
                </td>
              @endif
            </tr>
          @empty
            <tr><td colspan="{{ 5 + $fits->count() + ($plan ? 1 : 0) }}" class="text-muted p-3">No characters to show. Check the launch group filter, or DR_CORPORATION_ID.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer text-muted small">
      Training times use each character's current attributes (≈, no boosters). Pick characters for the fleet: other characters on the same account are greyed out,
      because EVE logs in one character per account. Pilots who keep their layout private show each character on its own.
    </div>
  </div>
@stop

@push('javascript')
  <script>
    (function () {
      function refresh() {
        var picked = {};
        document.querySelectorAll('tr[data-account]').forEach(function (row) {
          if (row.querySelector('.dr-pick').checked) { picked[row.dataset.account] = row.dataset.name; }
        });
        var count = 0;
        document.querySelectorAll('tr[data-account]').forEach(function (row) {
          var box = row.querySelector('.dr-pick');
          var owner = picked[row.dataset.account];
          var blocked = owner !== undefined && !box.checked;
          if (box.checked) { count++; }
          row.classList.toggle('dr-picked', box.checked);
          row.classList.toggle('dr-blocked', blocked);
          box.disabled = blocked;
          row.querySelector('.dr-clash').textContent = blocked ? 'same account as ' + owner : '';
        });
        document.getElementById('dr-picked-count').textContent = count;
      }
      document.querySelectorAll('.dr-pick').forEach(function (box) { box.addEventListener('change', refresh); });
    })();
  </script>
@endpush
