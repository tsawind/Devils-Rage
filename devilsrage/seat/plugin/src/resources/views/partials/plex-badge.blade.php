@if($held && $held['plex'] > 0)
  <span class="badge badge-light mr-1" title="{{ number_format($held['hangar']) }} PLEX in hangars (SeAT){{ $held['vault'] !== null ? ' + '.number_format($held['vault']).' in the vault'.($held['vault_at'] ? ' (typed '.$held['vault_at']->format('Y-m-d').')' : '') : ', vault not entered' }}">
    {{ number_format($held['plex']) }} PLEX
  </span>
@endif
