@if($om)
  @if($om['alpha'])
    <span class="badge badge-warning mr-1" title="A character on this account trains at Alpha speed">Alpha?</span>
  @elseif($om['state'] === 'expired')
    <span class="badge badge-danger mr-1" title="Omega ended {{ $om['until']->format('Y-m-d') }}">Omega ended</span>
  @elseif($om['state'] === 'soon')
    <span class="badge badge-warning mr-1" title="Omega until {{ $om['until']->format('Y-m-d') }}">Omega {{ $om['days_left'] }}d</span>
  @elseif($om['state'] === 'ok')
    <span class="badge badge-success mr-1" title="Omega until {{ $om['until']->format('Y-m-d') }}">Omega {{ $om['days_left'] }}d</span>
  @else
    <span class="badge badge-secondary mr-1" title="No Omega date entered">Omega ?</span>
  @endif
@endif
