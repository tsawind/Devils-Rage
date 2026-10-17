@extends('web::layouts.grids.12')

@section('title', 'Skill plans')
@section('page_header', 'Skill plans')

@section('full')
  @include('devilsrage::partials.flash')

  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Corp skill plans · your characters</h3>
      <div class="card-tools">
        <form method="get" class="form-inline" style="gap: .4rem;">
          @include('devilsrage::partials.group-filter')
        </form>
      </div>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th>Plan</th>
            <th>Skills</th>
            @foreach($names as $name)
              <th>{{ $name }}</th>
            @endforeach
          </tr>
        </thead>
        <tbody>
          @forelse($plans as $plan)
            <tr>
              <td><a href="{{ route('devilsrage.skillplans.show', $plan) }}">{{ $plan->name }}</a>
                @if($plan->description)<div class="text-muted small">{{ $plan->description }}</div>@endif
              </td>
              <td>{{ $plan->skills->count() }}</td>
              @foreach($names as $characterId => $name)
                @php($row = $progress[$plan->id][$characterId] ?? null)
                @php($pct = $row && $row['total_points'] > 0 ? (int) floor($row['done_points'] / $row['total_points'] * 100) : 0)
                <td style="color: {{ $pct >= 100 ? '#2f7a4f' : ($pct >= 70 ? '#a2650c' : '#b3261e') }}" title="{{ $row && $row['missing'] !== [] ? \DevilsRage\Seat\Skills\SkillMath::duration($row['seconds']).' left' : 'Done' }}">
                  {{ $pct }}%
                </td>
              @endforeach
            </tr>
          @empty
            <tr><td colspan="{{ 2 + $names->count() }}" class="text-muted p-3">No skill plans yet.@if($canManage) Add the first one below.@endif</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @if($isLeader)
      <div class="card-footer small text-muted">Open a plan to see the whole corp's progress.</div>
    @endif
  </div>

  @if($canManage)
    <div class="card card-outline card-primary">
      <div class="card-header"><h3 class="card-title">Add or replace a plan</h3></div>
      <div class="card-body">
        <form method="post" action="{{ route('devilsrage.skillplans.store') }}">
          @csrf
          <div class="form-row">
            <div class="form-group col-md-4">
              <label for="dr-plan-name">Name</label>
              <input id="dr-plan-name" type="text" name="name" value="{{ old('name') }}" maxlength="80" class="form-control" required placeholder="e.g. Muninn core">
              <small class="form-text text-muted">Same name as an existing plan replaces it.</small>
            </div>
            <div class="form-group col-md-8">
              <label for="dr-plan-description">Description</label>
              <input id="dr-plan-description" type="text" name="description" value="{{ old('description') }}" maxlength="1000" class="form-control" placeholder="Optional">
            </div>
          </div>
          <div class="form-group">
            <label for="dr-plan-skills">Skills</label>
            <textarea id="dr-plan-skills" name="skills" rows="10" class="form-control" style="font-family: monospace;" required placeholder="Paste from EVE: Skill planner → plan → Copy to clipboard&#10;Spaceship Command 4&#10;Minmatar Cruiser 5&#10;Heavy Assault Cruisers IV">{{ old('skills') }}</textarea>
            <small class="form-text text-muted">One skill per line, level as 1–5 or I–V. Several levels of the same skill count as the highest one.</small>
          </div>
          <button type="submit" class="btn btn-primary">Save plan</button>
        </form>
      </div>
    </div>
  @endif
@stop
