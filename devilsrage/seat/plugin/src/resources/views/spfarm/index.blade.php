@extends('web::layouts.grids.12')

@section('title', 'SP farming')
@section('page_header', 'SP farming')

@php
  $isk = fn (?float $value): string => $value === null ? '–' : (abs($value) >= 1e9 ? number_format($value / 1e9, 2).'b' : number_format($value / 1e6, 0).'m');
  $money = fn (?float $value): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
      $value === null ? '–' : '<span class="dr-money" data-isk="'.e((string) $value).'">'.e($isk($value)).'</span>'
  );
  $pct = fn (float $value): string => rtrim(rtrim(number_format($value * 100, 2), '0'), '.').'%';
  $short = ['Caldari Freighter' => 'CF', 'ORE Freighter' => 'ORE'];
  $good = ['Per/Wil', 'Int/Mem'];
  $marketNames = ['jita' => 'Jita 4-4', 'citadel' => 'Citadel'];
  $modeNames = ['instant' => 'Instant (into buy orders)', 'patient' => 'Patient (own sell / buy orders)'];
@endphp

@section('full')
  @include('devilsrage::partials.flash')

  {{-- Prices, taxes and the ISK / $ switch --}}
  <div class="card">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title mb-0">What one extraction earns</h3>
      <div class="btn-group btn-group-sm ml-auto" role="group" aria-label="Show money in">
        <button type="button" class="btn btn-primary" data-currency="isk">ISK</button>
        <button type="button" class="btn btn-outline-primary" data-currency="usd">$</button>
      </div>
    </div>
    <div class="card-body">
      <div class="small text-muted mb-2">
        Large Skill Injector: buy {{ $isk($injector['buy']) }} / sell {{ $isk($injector['sell']) }} ({{ $injector['source'] }}) ·
        Skill Extractor: buy {{ $isk($extractor['buy']) }} / sell {{ $isk($extractor['sell']) }} ({{ $extractor['source'] }}) ·
        PLEX {{ number_format($plex) }} ISK ({{ $plexSource }}) ·
        Omega {!! $money($omegaMonth) !!}/month (12-month price) · MCT {!! $money($mctMonth) !!}/month ·
        deals: <a href="https://www.theoz.space/deals" target="_blank" rel="noopener">theoz.space/deals</a>
      </div>

      <div style="overflow-x: auto;">
        <table class="table table-sm table-bordered mb-2" style="font-variant-numeric: tabular-nums;">
          <thead>
            <tr><th></th><th>Fees</th><th class="text-right">Injector brings</th><th class="text-right">Extractor costs</th><th class="text-right">Left per extraction</th></tr>
          </thead>
          <tbody>
            @foreach($matrix as $where => $modes)
              @foreach($modes as $how => $result)
                @php($picked = $where === $market && $how === $mode)
                <tr class="{{ $picked ? 'table-active font-weight-bold' : '' }}">
                  <td>
                    <a href="{{ route('devilsrage.spfarm.index', ['market' => $where, 'mode' => $how]) }}">{{ $marketNames[$where] }} · {{ $modeNames[$how] }}</a>
                  </td>
                  <td class="small">
                    tax {{ $pct($salesTax) }}@if($how === 'patient') + broker {{ $pct($fees[$where]) }} (both orders)@endif
                  </td>
                  <td class="text-right">{!! $money($result['income']) !!}</td>
                  <td class="text-right">{!! $money($result['extractor_cost']) !!}</td>
                  <td class="text-right {{ $result['profit'] >= 0 ? 'text-success' : 'text-danger' }}">{!! $money($result['profit']) !!}</td>
                </tr>
              @endforeach
            @endforeach
          </tbody>
        </table>
      </div>

      <form method="post" action="{{ route('devilsrage.spfarm.trader') }}" class="form-inline small" style="gap: .5rem;">
        @csrf
        <label for="dr-trader">Taxes from</label>
        <select id="dr-trader" name="character_id" class="form-control form-control-sm" onchange="this.form.submit()">
          @foreach($names as $characterId => $name)
            <option value="{{ $characterId }}" @selected($trader === (int) $characterId)>
              {{ $name }} (Accounting {{ $tradeLevels[$characterId]['accounting'] ?? 0 }}, Broker Relations {{ $tradeLevels[$characterId]['broker'] ?? 0 }})
            </option>
          @endforeach
        </select>
        <span class="text-muted">
          Sales tax is the same everywhere (7.5 %, −11 % per Accounting level). Broker fee: Jita 3 % −0.3 % per Broker Relations level;
          citadels charge what the owner sets ({{ $pct($fees['citadel']) }}, set by leaders). Click a row to use it for the numbers below.
          $ = the ISK bought as PLEX in the EVE Store.
        </span>
      </form>
    </div>
  </div>

  {{-- Accounts at a glance --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">My accounts at a glance</h3>
      <div class="card-tools small text-muted pt-1">Set Omega / MCT dates in <a href="{{ route('devilsrage.accounts.index') }}">My accounts</a></div>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums; white-space: nowrap;">
        <thead>
          <tr>
            <th>Character</th><th class="text-right">SP</th><th>Implants</th><th>Remap</th><th>Biology</th><th>PI</th>
            <th>Freighters</th><th>Training</th><th class="text-right">SP / h</th><th class="text-right">Quick ISK</th>
          </tr>
        </thead>
        @php($groups = $accounts->map(fn ($account) => ['title' => $account->nickname, 'om' => $omega[$account->id] ?? null, 'held' => $holdings[$account->id] ?? null, 'ids' => $account->characters->pluck('character_id')->map(fn ($id) => (int) $id)->all()]))
        @if($loose->isNotEmpty())
          @php($groups->push(['title' => 'Not in an account yet', 'om' => null, 'held' => null, 'ids' => $loose->keys()->map(fn ($id) => (int) $id)->all()]))
        @endif
        @foreach($groups as $group)
          <tbody>
            <tr class="table-secondary">
              <th colspan="10">
                {{ $group['title'] }}
                @include('devilsrage::partials.omega-badge', ['om' => $group['om']])
                @include('devilsrage::partials.plex-badge', ['held' => $group['held']])
                @if($group['held'] && $group['held']['isk'] > 0)<span class="badge badge-light">{!! $money($group['held']['isk']) !!} in wallets</span>@endif
              </th>
            </tr>
            @foreach($group['ids'] as $characterId)
              @php($d = $details[$characterId] ?? null)
              @continue($d === null)
              <tr>
                <td>
                  <img src="https://images.evetech.net/characters/{{ $characterId }}/portrait?size=32" width="20" height="20" class="rounded mr-1" alt="">
                  {{ $names[$characterId] ?? $characterId }}
                  @if(in_array($characterId, $farmIds, true))<span class="badge badge-info">farm</span>@endif
                </td>
                <td class="text-right" title="{{ number_format($d['unallocated']) }} unallocated">
                  {{ number_format($d['total_sp'] / 1e6, 1) }}m
                  @if($d['ready'] > 0)<span class="badge badge-light" title="Extractions possible above 5m SP">{{ $d['ready'] }}×</span>@endif
                </td>
                <td>
                  @forelse($d['implants'] as $attribute => $bonus)
                    <span class="badge {{ $bonus >= 5 ? 'badge-success' : 'badge-light' }}">{{ $attribute }}+{{ $bonus }}</span>
                  @empty
                    <span class="text-muted">none</span>
                  @endforelse
                </td>
                <td>
                  @if($d['remap'] === null)
                    <span class="text-muted">–</span>
                  @else
                    <span class="badge {{ in_array($d['remap'], $good, true) ? 'badge-success' : 'badge-secondary' }}">{{ $d['remap'] }}</span>
                  @endif
                </td>
                <td><span class="badge {{ $d['biology'] >= 5 ? 'badge-success' : 'badge-warning' }}">{{ $d['biology'] }}/5</span></td>
                <td>
                  @foreach($d['pi'] as $skill => $pi)
                    <span class="badge {{ $pi['have'] >= $pi['want'] ? 'badge-success' : 'badge-warning' }}" title="{{ $skill }}">{{ $skill === 'Command Center Upgrades' ? 'CCU' : 'IC' }} {{ $pi['have'] }}/{{ $pi['want'] }}</span>
                  @endforeach
                </td>
                <td>
                  @foreach($d['everyone'] as $skill => $level)
                    <span class="badge {{ $level >= 5 ? 'badge-success' : ($level > 0 ? 'badge-light' : 'badge-warning') }}" title="{{ $skill }}">{{ $short[$skill] ?? $skill }} {{ $level }}</span>
                  @endforeach
                </td>
                <td>
                  @if($d['training'])
                    <span title="Queue ends {{ $d['training']['queue_ends']->format('Y-m-d H:i') }}">
                      {{ \Illuminate\Support\Str::limit($d['training']['skill'], 26) }} {{ $d['training']['level'] }}
                      <span class="text-muted small">· queue {{ $d['training']['queue_ends']->diffForHumans(null, true) }}</span>
                    </span>
                  @else
                    <span class="badge badge-danger">not training</span>
                  @endif
                </td>
                <td class="text-right">{{ $d['sp_per_hour'] === null ? '–' : number_format($d['sp_per_hour']) }}</td>
                <td class="text-right">
                  @if($d['extract']['count'] > 0)
                    {{ $d['extract']['count'] }}× · {!! $money($d['extract']['isk']) !!}
                  @else
                    <span class="text-muted">–</span>
                  @endif
                </td>
              </tr>
            @endforeach
          </tbody>
        @endforeach
      </table>
    </div>
    <div class="card-footer small text-muted">
      Green = good: +5 implants, Per/Wil or Int/Mem remap, Biology V, Command Center Upgrades V and Interplanetary Consolidation IV,
      Caldari and ORE Freighter V. Remap is worked out from attributes with implants taken off. SP / h is the speed right now.
    </div>
  </div>

  {{-- Farm characters --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">My farm characters</h3>
      <div class="card-tools small text-muted pt-1">Selling: {{ $marketNames[$market] }} · {{ strtolower($modeNames[$mode]) }}</div>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <div class="d-flex flex-wrap p-3" style="gap: 1.5rem;">
        <div><div class="text-muted small">Profit per month</div><div class="h4 mb-0 {{ $totals['profit'] >= 0 ? 'text-success' : 'text-danger' }}">{!! $money($totals['profit']) !!}</div></div>
        <div><div class="text-muted small">Injectors per month</div><div class="h4 mb-0">{{ number_format($totals['injectors'], 1) }}</div></div>
        <div><div class="text-muted small">Sales after extractors</div><div class="h4 mb-0">{!! $money($totals['revenue']) !!}</div></div>
        <div><div class="text-muted small">Omega / MCT cost</div><div class="h4 mb-0">{!! $money($totals['cost']) !!}</div></div>
      </div>
      <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
        <thead>
          <tr><th>Character</th><th>Pays for</th><th class="text-right">SP / h</th><th class="text-right">Injectors / month</th><th class="text-right">Profit / month</th><th></th></tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            <tr>
              <td>
                <img src="https://images.evetech.net/characters/{{ $row['character_id'] }}/portrait?size=32" width="24" height="24" class="rounded mr-1" alt="">
                {{ $row['name'] }}
              </td>
              <td>
                <form method="post" action="{{ route('devilsrage.spfarm.store') }}">
                  @csrf
                  <input type="hidden" name="character_id" value="{{ $row['character_id'] }}">
                  <select name="slot" class="form-control form-control-sm" onchange="this.form.submit()" aria-label="Training slot">
                    @foreach($slots as $key => $label)
                      <option value="{{ $key }}" @selected($row['slot'] === $key)>{{ $label }}</option>
                    @endforeach
                  </select>
                </form>
              </td>
              <td class="text-right">{{ $row['detail']['sp_per_hour'] ?? null ? number_format($row['detail']['sp_per_hour']) : 'not training' }}</td>
              <td class="text-right">{{ $row['month'] === null ? '–' : number_format($row['month']['injectors'], 2) }}</td>
              <td class="text-right {{ ($row['month']['profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">{!! $money($row['month']['profit'] ?? null) !!}</td>
              <td>
                <form method="post" action="{{ route('devilsrage.spfarm.delete', $row['character_id']) }}">
                  @csrf
                  <button type="submit" class="btn btn-xs btn-outline-secondary" title="Take off the farm list"><i class="fas fa-times"></i></button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-muted p-3">No farm characters yet. Add one below.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      @if($others->isNotEmpty())
        <form method="post" action="{{ route('devilsrage.spfarm.store') }}" class="form-inline" style="gap: .5rem;">
          @csrf
          <select name="character_id" class="form-control form-control-sm" aria-label="Character">
            @foreach($others as $characterId => $name)
              <option value="{{ $characterId }}">{{ $name }}</option>
            @endforeach
          </select>
          <select name="slot" class="form-control form-control-sm" aria-label="Training slot">
            @foreach($slots as $key => $label)
              <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
          </select>
          <button type="submit" class="btn btn-sm btn-primary">Add farm character</button>
        </form>
      @endif
      <div class="small text-muted mt-2">
        Profit = injectors made × what one extraction leaves (the row picked above) − the Omega or MCT it pays for, bought with PLEX at today's price.
      </div>
    </div>
  </div>

  {{-- Calculator --}}
  <div class="card">
    <div class="card-header"><h3 class="card-title">Calculator: what can farming pay for?</h3></div>
    <div class="card-body" id="dr-calc">
      <div class="form-row small">
        <div class="form-group col-6 col-md-2">
          <label for="calc-accounts">Accounts</label>
          <select id="calc-accounts" class="form-control form-control-sm">
            @for($i = 1; $i <= 12; $i++)<option value="{{ $i }}" @selected($i === $calculator['accounts'])>{{ $i }}</option>@endfor
          </select>
        </div>
        <div class="form-group col-6 col-md-2">
          <label for="calc-chars">Farming per account</label>
          <select id="calc-chars" class="form-control form-control-sm">
            <option value="1">1 (Omega only)</option>
            <option value="2">2 (+1 MCT)</option>
            <option value="3">3 (+2 MCT)</option>
          </select>
        </div>
        <div class="form-group col-6 col-md-2">
          <label for="calc-omega">Omega bought as</label>
          <select id="calc-omega" class="form-control form-control-sm">
            @foreach(array_keys($calculator['omegaPlex']) as $months)
              <option value="{{ $months }}" @selected($months === 12)>{{ $months }} month{{ $months > 1 ? 's' : '' }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-group col-6 col-md-2">
          <label for="calc-implants">Implants</label>
          <select id="calc-implants" class="form-control form-control-sm">
            <option value="0">none</option><option value="3">+3</option><option value="4">+4</option><option value="5" selected>+5</option>
          </select>
        </div>
        <div class="form-group col-6 col-md-2">
          <label for="calc-remap">Remapped</label>
          <select id="calc-remap" class="form-control form-control-sm">
            <option value="1" selected>yes (27 / 21)</option><option value="0">no (20 / 20)</option>
          </select>
        </div>
        <div class="form-group col-6 col-md-2">
          <label for="calc-sell">Selling</label>
          <select id="calc-sell" class="form-control form-control-sm">
            @foreach($matrix as $where => $modes)
              @foreach(array_keys($modes) as $how)
                <option value="{{ $where }}|{{ $how }}" @selected($where === $market && $how === $mode)>{{ $marketNames[$where] }}, {{ $how }}</option>
              @endforeach
            @endforeach
          </select>
        </div>
      </div>
      <div class="row" id="calc-out" style="font-variant-numeric: tabular-nums;"></div>
      <div class="small text-muted mt-2">
        "You hold" = PLEX in hangars (SeAT) + PLEX typed in for the vault on My accounts + ISK in your wallets.
        Training speed = primary + secondary ÷ 2 per minute, with implants on both. Costs use the PLEX prices leaders keep below and today's PLEX price;
        the cash column uses EVE Store prices. A month is 30 days.
      </div>
    </div>
  </div>

  {{-- Quick ISK --}}
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">Quick ISK: what could be extracted now</h3>
      <div class="card-tools small pt-1">{{ $extractTotal }} extractions · {!! $money($extractIsk) !!} (Jita, instant)</div>
    </div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
        <thead>
          <tr><th>Character</th><th class="text-right">Extractions</th><th class="text-right">ISK</th><th class="text-right">Retrain</th><th>Skills it would come from (biggest first)</th></tr>
        </thead>
        <tbody>
          @php($candidates = collect($details)->filter(fn ($d) => $d['extract']['count'] > 0)->sortByDesc(fn ($d) => $d['extract']['count']))
          @forelse($candidates as $characterId => $d)
            <tr>
              <td>{{ $names[$characterId] ?? $characterId }}</td>
              <td class="text-right">{{ $d['extract']['count'] }}</td>
              <td class="text-right">{!! $money($d['extract']['isk']) !!}</td>
              <td class="text-right">{{ $d['extract']['retrain_days'] === null ? '–' : number_format($d['extract']['retrain_days'], 0).' days' }}</td>
              <td class="small">
                @foreach($d['extract']['skills'] as $skill)
                  {{ $skill['name'] }} {{ $skill['level'] }} <span class="text-muted">({{ number_format($skill['sp'] / 1000) }}k)</span>@if(! $loop->last), @endif
                @endforeach
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-muted p-3">Nothing to extract without touching protected skills (or nobody is above 5m SP).</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="card-footer small text-muted">
      Protected and never suggested: every skill a corp fit or skill plan needs (with prerequisites), Caldari and ORE Freighter, Minmatar Dreadnought,
      Capital Ships, Cybernetics, Biology and the PI skills. Retrain = days to train the SP back at the character's speed (Omega).
      Losing these skills is cheaper than losing Omega: a character that goes Alpha stops farming and can't fly most doctrine ships anyway.
    </div>
  </div>

  {{-- Farm skills --}}
  <div class="card">
    <div class="card-header"><h3 class="card-title">Farm skills (big, slow, worth having)</h3></div>
    <div class="card-body p-0" style="overflow-x: auto;">
      <table class="table table-sm mb-0" style="font-variant-numeric: tabular-nums;">
        <thead>
          <tr><th>Skill</th><th class="text-right">Rank</th><th class="text-right">SP to V</th><th class="text-right">Days to V at +5</th><th class="text-right">Book (Jita)</th><th class="text-right">My characters at V</th><th>Note</th></tr>
        </thead>
        @foreach($catalogue->groupBy('attributes')->sortKeys() as $pair => $skills)
          <tbody>
            <tr class="table-secondary"><th colspan="7">{{ $pair }} @if(in_array($pair, $good, true))<span class="badge badge-success">common remap</span>@endif</th></tr>
            @foreach($skills->sortByDesc('sp_v') as $skill)
              @php($atV = collect($details)->filter(fn ($d) => ($d['everyone'][$skill['name']] ?? $d['farm_levels'][$skill['name']] ?? 0) >= 5)->count())
              <tr>
                <td>{{ $skill['name'] }} @if($skill['everyone'])<span class="badge badge-primary">everyone</span>@endif</td>
                <td class="text-right">{{ rtrim(rtrim(number_format($skill['rank'], 1), '0'), '.') }}</td>
                <td class="text-right">{{ number_format($skill['sp_v'] / 1e6, 2) }}m</td>
                <td class="text-right">{{ number_format($skill['sp_v'] / 45 / 1440, 0) }}</td>
                <td class="text-right">{!! $skill['book'] > 0 ? $money($skill['book']) : '<span class="text-muted">no orders</span>' !!}</td>
                <td class="text-right">{{ $atV }} / {{ count($details) }}</td>
                <td class="small text-muted">{{ $skill['note'] }}</td>
              </tr>
            @endforeach
          </tbody>
        @endforeach
      </table>
    </div>
    <div class="card-footer small text-muted">
      Days to V assume a matching remap with +5 implants (45 SP a minute). Left out on purpose: Jump Freighters, Capital Industrial Ships (Rorqual),
      Titans and Marauders. Everyone keeps Minmatar Dreadnought V for Naglfars.
    </div>
  </div>

  {{-- Plans --}}
  <div class="card">
    <div class="card-header"><h3 class="card-title">Plans: what to do and where</h3></div>
    <div class="card-body">
      <details class="mb-2">
        <summary class="font-weight-bold">1. Start a farm character</summary>
        <ol class="small mt-2 mb-0">
          <li>Pick a character above 5m SP on an Omega account (or a second / third character on that account with an MCT).</li>
          <li>Remap it for its farm skills (Per/Wil for freighters and dreads, Int/Mem for capital modules and Cybernetics), plug in a +5 set (or +4) and train Biology V.</li>
          <li>Queue big skills from the table above, longest first. Check the book price before you buy: some are expensive.</li>
          <li>In SeAT: add the character under <em>My farm characters</em> here and say what it pays for. Put it in an account under <a href="{{ route('devilsrage.accounts.index') }}">My accounts</a> and enter the Omega / MCT end dates (or tick auto-renew).</li>
        </ol>
      </details>
      <details class="mb-2">
        <summary class="font-weight-bold">2. Monthly routine</summary>
        <ol class="small mt-2 mb-0">
          <li>Check this page: anything <span class="badge badge-danger">not training</span> or an Omega / MCT badge in yellow or red.</li>
          <li>Buy extractors (buy order if you're patient, otherwise off the market), extract the farm skills, sell the injectors in the row picked above.</li>
          <li>Keep the queue full; the queue column shows when it runs dry.</li>
          <li>When you renew, update the date in My accounts. SeAT DMs you on Discord 7 days and 1 day before anything not set to auto-renew runs out.</li>
        </ol>
      </details>
      <details class="mb-2">
        <summary class="font-weight-bold">3. Make Omega pay for itself</summary>
        <ol class="small mt-2 mb-0">
          <li>One farm character with +5 and a good remap makes about 3.9 injectors a month; the calculator shows whether that covers Omega at today's prices.</li>
          <li>Add an MCT character on the same account only if the calculator shows a profit for it: the MCT is paid out of that character's injectors.</li>
          <li>Buy PLEX in the global PLEX market when it's cheap and keep it in the PLEX vault for the renewal.</li>
          <li>Short on ISK? Use the Quick ISK table: it only offers skills nobody in the corp needs. Retrain them later or not at all.</li>
        </ol>
      </details>
      <details class="mb-0">
        <summary class="font-weight-bold">4. Work towards 24 months of Omega for everyone</summary>
        <ol class="small mt-2 mb-0">
          <li>Set the calculator to your accounts and 24 months. It shows the total and how many months of farming it takes.</li>
          <li>Save the profit as PLEX each month rather than ISK, so a price jump doesn't eat it.</li>
          <li>Buy the 24-month Omega when the saved PLEX covers it, account by account, starting with the one that runs out first.</li>
          <li>Watch theoz.space/deals and the New Eden Store for Omega sales; leaders can put a sale price in the numbers below.</li>
        </ol>
      </details>
    </div>
  </div>

  @if($canEditSettings)
    <div class="card card-outline card-secondary">
      <div class="card-header"><h3 class="card-title">Farm numbers (leaders, for everyone)</h3></div>
      <div class="card-body">
        <form method="post" action="{{ route('devilsrage.spfarm.settings') }}">
          @csrf
          <div class="form-row">
            @foreach($settings as $key => $setting)
              <div class="form-group col-md-3">
                <label for="dr-{{ $key }}" class="small">{{ $setting['label'] }}</label>
                <input id="dr-{{ $key }}" type="number" step="any" min="0" name="{{ $key }}" value="{{ $setting['value'] }}" class="form-control form-control-sm">
              </div>
            @endforeach
          </div>
          <button type="submit" class="btn btn-sm btn-primary">Save</button>
          <span class="small text-muted ml-2">Check the Omega / MCT PLEX costs in the in-game New Eden Store; set a PLEX price to override the market average.</span>
        </form>
      </div>
    </div>
  @endif
@stop

@push('javascript')
<script>
(function () {
  var calc = @json($calculator);
  var currency = 'isk';
  try { currency = localStorage.getItem('dr-farm-currency') || 'isk'; } catch (e) {}

  function usd(isk) { return calc.plex > 0 ? isk / calc.plex * calc.usdPerPlex : null; }
  function fmtIsk(v) {
    var a = Math.abs(v);
    return a >= 1e9 ? (v / 1e9).toFixed(2) + 'b' : Math.round(v / 1e6).toLocaleString() + 'm';
  }
  function fmtUsd(v) { return v === null ? '–' : (v < 0 ? '-$' : '$') + Math.abs(v).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
  function fmt(isk) { return currency === 'usd' ? fmtUsd(usd(isk)) : fmtIsk(isk); }

  function paintMoney() {
    document.querySelectorAll('.dr-money').forEach(function (el) { el.textContent = fmt(parseFloat(el.dataset.isk)); });
    document.querySelectorAll('[data-currency]').forEach(function (b) {
      var on = b.dataset.currency === currency;
      b.classList.toggle('btn-primary', on);
      b.classList.toggle('btn-outline-primary', !on);
    });
    runCalc();
  }

  function val(id) { return document.getElementById(id).value; }
  function box(title, body, cls) {
    return '<div class="col-md-3 col-6 mb-2"><div class="text-muted small">' + title + '</div><div class="h5 mb-0 ' + (cls || '') + '">' + body + '</div></div>';
  }

  function runCalc() {
    var accounts = parseInt(val('calc-accounts'), 10);
    var chars = parseInt(val('calc-chars'), 10);
    var months = val('calc-omega');
    var implants = parseInt(val('calc-implants'), 10);
    var remap = val('calc-remap') === '1';
    var sell = val('calc-sell').split('|');

    var primary = (remap ? 27 : 20) + implants;
    var secondary = (remap ? 21 : 20) + implants;
    var spMinute = primary + secondary / 2;
    var spMonth = spMinute * 60 * 24 * 30;
    var injectors = spMonth / 500000 * chars * accounts;
    var profitEach = calc.perExtraction[sell[0]][sell[1]].profit;
    var income = injectors * profitEach;

    var omegaPlexMonth = calc.omegaPlex[months] / months;
    var mctSlots = (chars - 1) * accounts;
    var costPlex = omegaPlexMonth * accounts + calc.mctPlex * mctSlots;
    var cost = costPlex * calc.plex;
    var net = income - cost;
    var cashMonth = calc.omegaUsd[months] / months * accounts + calc.mctUsd * mctSlots;

    var goalIsk = calc.omegaPlex[24] * calc.plex * accounts;
    var goalUsd = calc.omegaUsd[24] * accounts;
    var monthsToGoal = net > 0 ? goalIsk / net : null;
    var covered = cost > 0 ? income / cost : 0;
    var haveIsk = calc.have.plex * calc.plex + calc.have.isk;
    var haveMonths = cost > 0 ? haveIsk / cost : null;

    document.getElementById('calc-out').innerHTML =
      box('SP per character / month', (spMonth / 1e6).toFixed(2) + 'm (' + Math.round(spMinute * 60).toLocaleString() + '/h)') +
      box('Injectors / month', injectors.toFixed(1)) +
      box('Farm income / month', fmt(income)) +
      box('Omega + MCT / month (PLEX)', fmt(cost) + ' <span class="small text-muted">' + Math.round(costPlex) + ' PLEX</span>') +
      box('Left / month', fmt(net), net >= 0 ? 'text-success' : 'text-danger') +
      box('Farming covers', Math.round(covered * 100) + '% of the bill', covered >= 1 ? 'text-success' : 'text-warning') +
      box('Same bill in cash', fmtUsd(cashMonth) + '/month') +
      box('24 months Omega for all', fmt(goalIsk) + ' <span class="small text-muted">or ' + fmtUsd(goalUsd) + ' cash</span>') +
      box('You hold now', calc.have.plex.toLocaleString() + ' PLEX + ' + fmt(calc.have.isk) + ' <span class="small text-muted">= ' + fmt(haveIsk) + '</span>') +
      box('That pays the bill for', haveMonths === null ? '–' : haveMonths.toFixed(1) + ' months', haveMonths !== null && haveMonths < 1 ? 'text-warning' : '') +
      box('Months of farming to fund it', monthsToGoal === null ? 'never at this setup' : monthsToGoal.toFixed(1), monthsToGoal === null ? 'text-danger' : '');
  }

  document.querySelectorAll('[data-currency]').forEach(function (b) {
    b.addEventListener('click', function () {
      currency = b.dataset.currency;
      try { localStorage.setItem('dr-farm-currency', currency); } catch (e) {}
      paintMoney();
    });
  });
  document.querySelectorAll('#dr-calc select').forEach(function (s) { s.addEventListener('change', runCalc); });
  paintMoney();
})();
</script>
@endpush
