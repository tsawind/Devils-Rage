<div class="col-md-4 col-xl-3 mb-3">
  <div class="d-flex align-items-center mb-1" style="gap: .4rem;">
    <span class="dr-pill dr-{{ $group->pillClass() }}">{{ $group->name }}</span>
    @if($deletable)
      <form method="post" action="{{ route('devilsrage.groups.delete', $group) }}" class="ml-auto">
        @csrf
        <button type="submit" class="btn btn-xs btn-link text-danger p-0" title="Remove this launch group"><i class="fas fa-trash"></i></button>
      </form>
    @endif
  </div>
  <ul class="list-unstyled mb-0">
    @forelse($members['members'] as $member)
      <li>{{ $member['character'] }} <span class="text-muted small">· {{ $member['account'] }}</span></li>
    @empty
      <li class="text-muted small">Nobody yet</li>
    @endforelse
  </ul>
  @foreach($members['clashes'] as $nickname)
    <div class="text-warning small"><i class="fas fa-exclamation-triangle"></i> Two characters of {{ $nickname }}: only one can be logged in.</div>
  @endforeach
</div>
