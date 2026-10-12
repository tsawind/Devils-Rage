@if($om)
  @if($om['alpha'])
    <span class="badge badge-warning mr-1" title="A character on this account trains at Alpha speed">Alpha?</span>
  @elseif($om['state'] === 'auto')
    <span class="badge badge-success mr-1" title="Omega renews by itself">Omega · auto</span>
  @elseif($om['state'] === 'expired')
    <span class="badge badge-danger mr-1" title="Omega ended {{ $om['until']->format('Y-m-d') }}">Omega ended</span>
  @elseif($om['state'] === 'soon')
    <span class="badge badge-warning mr-1" title="Omega until {{ $om['until']->format('Y-m-d') }}">Omega {{ $om['days_left'] }}d</span>
  @elseif($om['state'] === 'ok')
    <span class="badge badge-success mr-1" title="Omega until {{ $om['until']->format('Y-m-d') }}">Omega {{ $om['days_left'] }}d</span>
  @else
    <span class="badge badge-secondary mr-1" title="No Omega date entered">Omega ?</span>
  @endif
  @if($om['mct_slots'] > 0)
    @php($mctClass = ['auto' => 'badge-info', 'ok' => 'badge-info', 'soon' => 'badge-warning', 'expired' => 'badge-danger'][$om['mct_state']] ?? 'badge-secondary')
    @php($mctText = ['auto' => '· auto', 'expired' => 'ended', 'unknown' => '?', 'none' => ''][$om['mct_state']] ?? $om['mct_days_left'].'d')
    <span class="badge {{ $mctClass }} mr-1" title="{{ $om['mct_slots'] }} MCT slot(s){{ $om['mct_until'] ? ' until '.$om['mct_until']->format('Y-m-d') : '' }}">
      MCT×{{ $om['mct_slots'] }} {{ $mctText }}
    </span>
  @endif
@endif
