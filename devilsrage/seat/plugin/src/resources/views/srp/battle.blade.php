@extends('web::layouts.grids.12')

@section('title', 'SRP a battle')
@section('page_header', 'SRP a battle')

@section('full')
  @include('devilsrage::partials.flash')
  @if(session('warning'))
    <div class="alert alert-warning">{{ session('warning') }}</div>
  @endif

  <div class="card">
    <div class="card-body">
      <form method="get" action="{{ route('devilsrage.srp.battle') }}">
        <div class="form-group">
          <label for="dr-srp-link">Battle or kill link</label>
          <input id="dr-srp-link" type="text" name="link" class="form-control" value="{{ $link }}" placeholder="https://zkillboard.com/related/31002077/202610211800/  or  https://br.evetools.org/related/31002077/1792605600">
          <small class="form-text text-muted">zKillboard or br.evetools "related" links list every loss in that system an hour either side. Kill links work too: zKillboard, ESI ("Copy external kill link") or in-game.</small>
        </div>
        <div class="form-group">
          <label for="dr-srp-kills">More kill links (optional, one per line)</label>
          <textarea id="dr-srp-kills" name="kills" rows="2" class="form-control">{{ $extra }}</textarea>
        </div>
        <button type="submit" class="btn btn-primary">Find losses</button>
      </form>
    </div>
  </div>

  @foreach($notes as $note)
    <div class="alert alert-warning py-2">{{ $note }}</div>
  @endforeach

  @if(! $srpInstalled)
    <div class="alert alert-danger">The SRP plugin isn't installed on this SeAT.</div>
  @elseif($link !== '' || $extra !== '')
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          Our losses <span class="badge badge-secondary">{{ count($rows) }}</span>
          @if($battle)
            <small class="text-muted ml-2">system {{ $battle['system'] }}, {{ gmdate('Y-m-d H:i', $battle['from']) }} – {{ gmdate('H:i', $battle['to']) }} EVE time</small>
          @endif
          @if($others > 0)
            <small class="text-muted ml-2">· {{ $others }} other kill{{ $others === 1 ? '' : 's' }} (not our pilots)</small>
          @endif
        </h3>
      </div>
      <form method="post" action="{{ route('devilsrage.srp.battle.file') }}">
        @csrf
        <input type="hidden" name="link" value="{{ $link }}">
        <input type="hidden" name="kills" value="{{ $extra }}">
        <div class="card-body p-0">
          <table class="table table-sm table-hover mb-0">
            <thead>
              <tr>
                <th style="width: 2rem;"><input type="checkbox" onclick="document.querySelectorAll('.dr-srp').forEach((box) => { if (!box.disabled) box.checked = this.checked; })"></th>
                <th>Time</th><th>Pilot</th><th>Main</th><th>Ship</th><th>Kill</th><th>SRP</th>
              </tr>
            </thead>
            <tbody>
              @forelse($rows as $row)
                @php($canFile = ! $row['filed'] && ($settler || $row['mine']))
                <tr>
                  <td><input type="checkbox" class="dr-srp" name="kill_ids[]" value="{{ $row['kill_id'] }}" @disabled(! $canFile) @checked($canFile)></td>
                  <td>{{ $row['time'] }}</td>
                  <td>{{ $row['pilot'] }}</td>
                  <td>{{ $row['main'] }}</td>
                  <td>{{ $row['ship'] }}</td>
                  <td><a href="https://zkillboard.com/kill/{{ $row['kill_id'] }}/" target="_blank" rel="noopener">{{ $row['kill_id'] }}</a></td>
                  <td>
                    @if($row['filed'])
                      <span class="badge badge-success">filed</span>
                    @elseif(! $settler && ! $row['mine'])
                      <span class="badge badge-secondary" title="Only SRP settlers file for other pilots">not yours</span>
                    @else
                      <span class="badge badge-light">to file</span>
                    @endif
                  </td>
                </tr>
              @empty
                <tr><td colspan="7" class="text-muted">No losses of our pilots found. SeAT only knows killmails of characters it tracks; paste any missing ones as kill links above.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
        @if(count($rows) > 0)
          <div class="card-footer">
            <button type="submit" class="btn btn-success">File SRP for the ticked losses</button>
            <span class="text-muted small ml-2">Priced by the SRP plugin's rules; they show up in SRP for approval as usual.</span>
          </div>
        @endif
      </form>
    </div>
  @endif
@endsection
