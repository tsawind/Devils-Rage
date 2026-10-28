{{-- Patch 63: "Make more ISK" on the PI run: what would add the most ISK, biggest first. --}}
@if(! empty($suggestions))
  <section class="card money">
    <div class="sect">
      <h2>Make more ISK</h2>
      <span class="muted small">biggest first · split prices at {{ $hubs[$hub] ?? 'Jita' }}, 30-day month</span>
      <span class="grow"></span>
      <span class="small"><span class="muted">Now</span> <b>{{ $isk($totals['split'] * 30) }}</b> <span class="muted">/ month · fully fed</span> <b>{{ $isk($totals['potential'] * 30) }}</b></span>
    </div>
    @foreach($suggestions as $s)
      <div class="money-row">
        <div class="money-gain">
          @if($s['per_month'] > 0)<b>+{{ $isk($s['per_month']) }}</b><span class="muted small"> / month</span>
          @else<b>{{ $isk($s['now']) }}</b><span class="muted small"> now</span>@endif
        </div>
        <div>
          <div><b>{{ $s['title'] }}</b></div>
          <div class="muted small">{{ $s['why'] }}</div>
        </div>
      </div>
    @endforeach
  </section>
@endif
