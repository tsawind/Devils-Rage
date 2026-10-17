@php($drRealLeader = app(\DevilsRage\Seat\Services\Leadership::class)->isRealLeader(auth()->user()))
@if($drRealLeader || \DevilsRage\Seat\Services\ViewMode::ownOnly())
  {{-- Patch 39: leaders can look at these pages as a corp member would (their own characters, no leader rights). --}}
  <div class="d-flex justify-content-end mb-2" style="gap: .5rem;">
    @if(\DevilsRage\Seat\Services\ViewMode::asMember())
      <span class="badge badge-warning align-self-center">Viewing as a member</span>
    @elseif(\DevilsRage\Seat\Services\ViewMode::ownOnly())
      <span class="badge badge-success align-self-center">My characters only</span>
    @endif
    @if($drRealLeader)
      <form method="post" action="{{ route('devilsrage.viewmode') }}" class="m-0">
        @csrf
        <input type="hidden" name="as_member" value="{{ \DevilsRage\Seat\Services\ViewMode::asMember() ? '0' : '1' }}">
        <button type="submit" class="btn btn-sm {{ \DevilsRage\Seat\Services\ViewMode::asMember() ? 'btn-warning' : 'btn-outline-secondary' }}"
                title="{{ \DevilsRage\Seat\Services\ViewMode::asMember() ? 'Back to your leader view' : 'See these pages as a corp member: your own characters, no leader rights, until you switch back' }}">
          <i class="fas {{ \DevilsRage\Seat\Services\ViewMode::asMember() ? 'fa-user-shield' : 'fa-user' }}"></i>
          {{ \DevilsRage\Seat\Services\ViewMode::asMember() ? 'Back to leader view' : 'View as member' }}
        </button>
      </form>
    @endif
  </div>
@endif
@if(session('success'))
  <div class="alert alert-success">{{ session('success') }}</div>
@endif
@if($errors->any())
  <div class="alert alert-danger">
    @foreach($errors->all() as $error)
      <div>{{ $error }}</div>
    @endforeach
  </div>
@endif
<style>
  .dr-pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; letter-spacing: .02em; }
  .dr-main { background: rgba(158, 42, 43, .15); color: #b03a3b; }
  .dr-second { background: rgba(45, 95, 143, .15); color: #2d6aa3; }
  .dr-third { background: rgba(140, 115, 40, .18); color: #8a7024; }
  .dr-custom { background: rgba(90, 70, 150, .14); color: #6a55b0; }
  .dr-none { background: rgba(120, 120, 120, .12); color: #777; }
</style>
