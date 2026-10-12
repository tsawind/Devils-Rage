@extends('web::layouts.grids.12')

@section('title', $plan->name)
@section('page_header', 'Skill plan: '.$plan->name)

@section('full')
  @include('devilsrage::partials.flash')

  <div class="row">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">{{ $isLeader ? 'Corp progress' : 'Your characters' }}</h3>
        </div>
        <div class="card-body p-0" style="overflow-x: auto;">
          <table class="table table-sm mb-0">
            <thead><tr><th>Character</th><th>Done</th><th>Left to train</th><th>Missing</th></tr></thead>
            <tbody>
              @forelse($status as $characterId => $row)
                @php($pct = $row['total_points'] > 0 ? (int) floor($row['done_points'] / $row['total_points'] * 100) : 0)
                <tr>
                  <td>{{ $names[$characterId] ?? $characterId }}</td>
                  <td style="color: {{ $pct >= 100 ? '#2f7a4f' : ($pct >= 70 ? '#a2650c' : '#b3261e') }}">{{ $pct }}%</td>
                  <td>{{ $row['missing'] === [] ? 'Done' : '≈ '.\DevilsRage\Seat\Skills\SkillMath::duration($row['seconds']) }}</td>
                  <td class="small text-muted">
                    {{ collect($row['missing'])->map(fn ($level, $skillId) => ($skillNames[$skillId] ?? $skillId).' '.$level)->take(8)->implode(', ') }}{{ count($row['missing']) > 8 ? ' …' : '' }}
                  </td>
                </tr>
              @empty
                <tr><td colspan="4" class="text-muted p-3">No characters.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title">{{ count($requirements) }} skills</h3></div>
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
@stop
