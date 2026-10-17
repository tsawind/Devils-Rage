@extends('web::layouts.grids.12')

@section('title', 'Reminders')
@section('page_header', 'One-time reminders')

@section('full')
  @include('devilsrage::partials.flash')

  <p class="text-muted small">Each pilot or Discord member gets a reminder once; every send is logged below. Placeholders: {name}, {missing}, {link}.</p>

  <div class="row">
    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h3 class="card-title">On SeAT, still missing something <span class="badge badge-secondary">{{ count($pilots) }}</span></h3></div>
        <form method="post" action="{{ route('devilsrage.reminders.send') }}">
          @csrf
          <input type="hidden" name="kind" value="seat">
          <div class="card-body p-0" style="max-height: 420px; overflow-y: auto;">
            <table class="table table-sm mb-0">
              <thead><tr><th style="width: 2rem;"><input type="checkbox" onclick="document.querySelectorAll('.dr-seat').forEach((box) => { if (!box.disabled) box.checked = this.checked; })"></th><th>Pilot</th><th>Missing</th><th>Reminder</th></tr></thead>
              <tbody>
                @forelse($pilots as $pilot)
                  <tr>
                    <td><input type="checkbox" class="dr-seat" name="targets[]" value="{{ $pilot['id'] }}" @disabled($pilot['log'] !== null || ! $pilot['discord'])></td>
                    <td>{{ $pilot['name'] }}</td>
                    <td class="small">{{ implode(' · ', $pilot['missing']) }}</td>
                    <td class="small">
                      @if($pilot['log'])
                        <span class="{{ $pilot['log']->sent ? 'text-success' : 'text-danger' }}" title="{{ $pilot['log']->error }}">{{ $pilot['log']->sent ? 'sent' : 'failed' }} {{ $pilot['log']->created_at?->diffForHumans() }}</span>
                      @elseif(! $pilot['discord'])
                        <span class="text-muted">no Discord linked</span>
                      @endif
                    </td>
                  </tr>
                @empty
                  <tr><td colspan="4" class="text-muted p-3">Everyone is set up.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
          <div class="card-footer">
            <textarea name="text" rows="4" class="form-control form-control-sm mb-2">{{ $seatText }}</textarea>
            <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Send the reminder to the ticked pilots? Each gets it once.')">Send to ticked</button>
          </div>
        </form>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Devils on Discord, not on SeAT @if($discordOnly !== null)<span class="badge badge-secondary">{{ count($discordOnly) }}</span>@endif</h3></div>
        @if($discordOnly === null)
          <div class="card-body text-muted small">The bot can't read the server's members (bot token, server and the Server Members Intent in the Discord developer portal).</div>
        @else
          <form method="post" action="{{ route('devilsrage.reminders.send') }}">
            @csrf
            <input type="hidden" name="kind" value="discord">
            <div class="card-body p-0" style="max-height: 420px; overflow-y: auto;">
              <table class="table table-sm mb-0">
                <thead><tr><th style="width: 2rem;"><input type="checkbox" onclick="document.querySelectorAll('.dr-discord').forEach((box) => { if (!box.disabled) box.checked = this.checked; })"></th><th>Discord member</th><th>Reminder</th></tr></thead>
                <tbody>
                  @forelse($discordOnly as $member)
                    <tr>
                      <td><input type="checkbox" class="dr-discord" name="targets[]" value="{{ $member['id'] }}" @disabled($member['log'] !== null)>
                        <input type="hidden" name="names[{{ $member['id'] }}]" value="{{ $member['name'] }}"></td>
                      <td>{{ $member['name'] }}</td>
                      <td class="small">
                        @if($member['log'])
                          <span class="{{ $member['log']->sent ? 'text-success' : 'text-danger' }}" title="{{ $member['log']->error }}">{{ $member['log']->sent ? 'sent' : 'failed' }} {{ $member['log']->created_at?->diffForHumans() }}</span>
                        @endif
                      </td>
                    </tr>
                  @empty
                    <tr><td colspan="3" class="text-muted p-3">Every Devil is on SeAT.</td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
            <div class="card-footer">
              <textarea name="text" rows="3" class="form-control form-control-sm mb-2">{{ $discordText }}</textarea>
              <button type="submit" class="btn btn-sm btn-primary" onclick="return confirm('Send the reminder to the ticked members? Each gets it once.')">Send to ticked</button>
            </div>
          </form>
        @endif
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">In the corp, on neither list @if($neither !== null)<span class="badge badge-secondary">{{ count($neither) }}</span>@endif</h3></div>
        <div class="card-body small">
          @if($neither === null)
            <span class="text-muted">SeAT has no member tracking for the corp (needs a director's login).</span>
          @elseif($neither === [])
            <span class="text-success">Nobody.</span>
          @else
            {{ implode(', ', $neither) }}
            <div class="text-muted mt-1">Not on SeAT and no Discord name matches theirs: ask them in game.</div>
          @endif
        </div>
      </div>
    </div>
  </div>
@stop
