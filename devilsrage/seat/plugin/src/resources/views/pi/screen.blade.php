<!doctype html>
{{-- Patch 50: the app's PI screen (works in a browser too). --}}
@php
  $kindLabel = ['restart' => 'Restart', 'balance' => 'Balance', 'move' => 'Move heads', 'submit' => 'Submit once', 'haul' => 'Haul', 'timer' => 'Same timer'];
  $kindClass = ['restart' => 'bad', 'balance' => 'warn', 'move' => 'warn', 'submit' => 'muted', 'haul' => 'info', 'timer' => 'info'];
  $statusLabel = ['stopped' => ['Stopped', 'bad'], 'soon' => ['Stops soon', 'warn'], 'ok' => ['Running', 'ok'], 'factory' => ['Factories only', 'muted']];
  $setup = function (array $info): string {
      $p = $info['planet'];
      $parts = [ucfirst($p['type']).' · CC '.$p['level']];
      $from = implode(' + ', $info['extracted']);
      $to = $info['making'] ? implode(', ', $info['making']['names']).' (P'.$info['making']['tier'].')' : '';
      if ($from !== '' && $to !== '') {
          $parts[] = $from.' → '.$to;
      } elseif ($from !== '') {
          $parts[] = $from;
      } elseif ($to !== '') {
          $parts[] = $to;
      }
      return implode(' · ', $parts);
  };
  $factories = function (array $counts): string {
      $out = [];
      foreach (['basic' => 'basic', 'advanced' => 'advanced', 'hightech' => 'high-tech'] as $k => $label) {
          if ($counts[$k] > 0) {
              $out[] = $counts[$k].' '.$label;
          }
      }
      return $out === [] ? 'none' : implode(', ', $out);
  };
  $planetCount = 0;
  $stoppedCount = 0;
  $soonCount = 0;
  foreach ($colonies as $c) {
      foreach ($c['planets'] as $info) {
          $planetCount++;
          $stoppedCount += $info['status'] === 'stopped' ? 1 : 0;
          $soonCount += $info['status'] === 'soon' ? 1 : 0;
      }
  }
  $firstDue = null;
  foreach ($run['characters'] as $c) {
      foreach ($c['visits'] as $v) {
          $t = $v['planet']['next_stop'];
          if ($t !== null && $t > $now && ($firstDue === null || $t < $firstDue['time'])) {
              $firstDue = ['time' => $t, 'planet' => $v['planet']['planet']['name']];
          }
      }
  }
  $stepCount = 0;
  foreach ($run['characters'] as $c) {
      foreach ($c['visits'] as $v) {
          $stepCount += count($v['steps']);
      }
      $stepCount += $c['timer'] ? 1 : 0;
  }
@endphp
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>PI · Devil's Rage</title>
<style>
  :root { --bg:#0d0d10; --card:#16161a; --card2:#1b1b20; --line:#2a2a30; --text:#e2e2e8; --muted:#8f8f98;
          --red:#b3262a; --red-hi:#d6242c; --ok:#5fcf7a; --warn:#f0b44c; --bad:#f28a8f; --info:#8ec0f5; }
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { background: var(--bg); color: var(--text); font: 13.5px/1.45 "Segoe UI", system-ui, sans-serif; }
  a { color: #ff8a8f; }
  button, select, input { font: inherit; color: inherit; }
  .wrap { max-width: 1120px; margin: 0 auto; padding: 18px 20px 40px; }
  .top { display: flex; align-items: center; gap: 10px 16px; flex-wrap: wrap; margin-bottom: 16px; }
  .top h1 { font-size: 20px; font-weight: 600; margin: 0; }
  .tabs { display: flex; gap: 6px; flex-wrap: wrap; }
  .tabs button { background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 5px 13px; cursor: pointer; color: #c8c8d0; }
  .tabs button.on { border-color: var(--red-hi); background: #2a0f12; color: #fff; }
  .grow { flex: 1; }
  .muted { color: var(--muted); }
  .small { font-size: 12px; }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; }
  .pane { display: none; flex-direction: column; gap: 14px; }
  .pane.on { display: flex; }
  .hero { padding: 16px 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px 24px; }
  .hero .label { color: var(--muted); font-size: 11.5px; letter-spacing: .4px; text-transform: uppercase; }
  .hero .big { font-size: 22px; font-weight: 600; }
  .btn { background: transparent; border: 1px solid #3a3a44; border-radius: 8px; padding: 6px 11px; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
  .btn:hover { border-color: #5a5a66; }
  .btn.p { background: var(--red); border-color: var(--red-hi); color: #fff; font-weight: 600; padding: 9px 15px; }
  .btn.p:hover { background: var(--red-hi); }
  .btn.sm { padding: 3px 9px; font-size: 12px; }
  .btn svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
  .char .head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: 11px 16px; border-bottom: 1px solid #23232a; background: #18181d; border-radius: 12px 12px 0 0; }
  .num { width: 22px; height: 22px; border-radius: 50%; background: #2a0f12; border: 1px solid #6b1a1f; color: #ffb3b6; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
  .pic { width: 28px; height: 28px; border-radius: 4px; background: #26262c; }
  .char .head b { font-size: 16px; font-weight: 600; }
  .planet { padding: 12px 16px; border-top: 1px solid #202027; display: flex; flex-direction: column; gap: 9px; }
  .planet:first-of-type { border-top: 0; }
  .ptitle { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; }
  .ptitle b { font-size: 15px; font-weight: 600; }
  .step { display: flex; gap: 12px; align-items: flex-start; }
  .tag { flex: none; width: 92px; text-align: center; font-size: 10.5px; font-weight: 700; letter-spacing: .4px; text-transform: uppercase; padding: 2px 0; border-radius: 6px; border: 1px solid; }
  .tag.bad { color: var(--bad); background: #2a0f12; border-color: #6b1a1f; }
  .tag.warn { color: var(--warn); background: #2a1a07; border-color: #6b4310; }
  .tag.info { color: var(--info); background: #0f1a2a; border-color: #24426b; }
  .tag.muted { color: #c4c4cc; background: #1d1d22; border-color: #34343c; }
  .step .t { font-weight: 600; }
  .step .w { color: #b8b8c0; font-size: 12.5px; }
  .lines { display: grid; grid-template-columns: minmax(120px, 170px) minmax(80px, 1fr) minmax(120px, 170px); gap: 5px 10px; align-items: center; margin-top: 7px; font-size: 12px; max-width: 640px; }
  .meter { height: 8px; background: #26262d; border-radius: 4px; position: relative; overflow: visible; }
  .meter > i { position: absolute; left: 0; top: 0; bottom: 0; border-radius: 4px; }
  .meter > s { position: absolute; top: -3px; bottom: -3px; width: 2px; background: #e6e6ea; }
  .clear { border-style: dashed; background: #121216; padding: 11px 16px; display: flex; flex-wrap: wrap; gap: 6px 14px; align-items: center; }
  .chip { font-size: 11px; font-weight: 600; padding: 1px 8px; border-radius: 999px; border: 1px solid; white-space: nowrap; }
  .chip.ok { color: var(--ok); background: #122016; border-color: #24502d; }
  .chip.warn { color: var(--warn); background: #2a1a07; border-color: #6b4310; }
  .chip.bad { color: var(--bad); background: #2a0f12; border-color: #6b1a1f; }
  .chip.muted { color: #c4c4cc; background: #1d1d22; border-color: #34343c; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
  .stat { padding: 12px 14px; }
  .stat .label { color: var(--muted); font-size: 11.5px; letter-spacing: .4px; text-transform: uppercase; }
  .stat .v { font-size: 24px; font-weight: 600; }
  .scroll { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; color: var(--muted); font-weight: 500; font-size: 12px; padding: 8px 10px; }
  td b { white-space: nowrap; }
  td { padding: 9px 10px; border-top: 1px solid #202027; vertical-align: top; }
  th:first-child, td:first-child { padding-left: 16px; }
  .two { display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-start; }
  .two > .wide { flex: 999 1 540px; min-width: 0; }
  .two > .side { flex: 1 1 300px; min-width: 0; }
  .sect { padding: 11px 16px; border-bottom: 1px solid #23232a; display: flex; flex-wrap: wrap; gap: 4px 10px; align-items: baseline; }
  .sect h2 { margin: 0; font-size: 15px; font-weight: 600; }
  .form { padding: 16px 18px; display: flex; flex-direction: column; gap: 12px; }
  .opt { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; }
  .opt input { accent-color: var(--red-hi); width: 16px; height: 16px; margin-top: 3px; }
  .opt b { font-weight: 600; display: block; }
  select { background: #101014; border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; }
  .toast { color: var(--ok); font-size: 12px; }
  .note { font-size: 12px; color: var(--muted); max-width: 860px; margin: 0; }
  [hidden] { display: none !important; }
  @media (max-width: 640px) { .tag { width: 76px; } .lines { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <h1>Planetary Interaction</h1>
    <nav class="tabs" aria-label="PI views">
      <button type="button" data-tab="run">PI run</button>
      <button type="button" data-tab="colonies">My colonies</button>
      @if($leader)<button type="button" data-tab="corp">Corp overview</button>@endif
      <button type="button" data-tab="alerts">Alerts</button>
    </nav>
    <span class="grow"></span>
    @if($checked)
      <span class="muted small" title="SeAT updates each character's colonies from EVE on its own schedule">SeAT checked EVE <span data-ts="{{ $checked }}" data-fmt="ago"></span></span>
    @endif
  </div>

  {{-- ============================== PI run ============================== --}}
  <section class="pane" id="pane-run" aria-label="PI run">
    @if($planetCount === 0)
      <div class="card hero">
        <div>
          <div class="big">No colonies found</div>
          <div class="muted">None of your characters on SeAT has PI colonies yet, or SeAT hasn't fetched them. SeAT needs each character logged in with full access (Your accounts shows which).</div>
        </div>
      </div>
    @elseif($run['characters'] === [])
      <div class="card hero">
        <div class="grow">
          <div class="label">Your next PI run</div>
          <div class="big">Nothing to do right now</div>
          @if($run['next_stop'])
            <div class="muted">The next extractor stops <span data-ts="{{ $run['next_stop'] }}" data-fmt="rel"></span> (<span data-ts="{{ $run['next_stop'] }}" data-fmt="clock"></span>). It shows up here a day before.</div>
          @endif
        </div>
      </div>
    @else
      <div class="card hero">
        <div class="grow">
          <div class="label">Your next PI run</div>
          <div class="big">{{ $stepCount }} {{ $stepCount === 1 ? 'thing' : 'things' }} on {{ count($run['characters']) }} {{ count($run['characters']) === 1 ? 'character' : 'characters' }} · about {{ $run['minutes'] }} min</div>
          @if($firstDue)
            <div class="muted">Do it before <span data-ts="{{ $firstDue['time'] }}" data-fmt="clock"></span> ({{ $firstDue['planet'] }} stops <span data-ts="{{ $firstDue['time'] }}" data-fmt="rel"></span>).</div>
          @else
            <div class="muted">Something has already stopped, so the sooner the better.</div>
          @endif
        </div>
        <button type="button" class="btn p" id="parsec" hidden title="Opens Parsec on this PC and connects to your EVE computer. You still do everything in EVE yourself.">
          <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>Connect to my EVE PC (Parsec)
        </button>
      </div>

      @foreach($run['characters'] as $i => $c)
        <section class="card char">
          <div class="head">
            <span class="num">{{ $i + 1 }}</span>
            <img class="pic" src="https://images.evetech.net/characters/{{ $c['character_id'] }}/portrait?size=64" alt="">
            <b>Log in {{ $c['name'] }}</b>
            <span class="grow"></span>
            <span class="muted small">{{ count($c['visits']) }} {{ count($c['visits']) === 1 ? 'planet' : 'planets' }} · about {{ $c['minutes'] }} min</span>
          </div>
          @foreach($c['visits'] as $v)
            @php
              $info = $v['planet'];
            @endphp
            <div class="planet">
              <div class="ptitle">
                <b>{{ $info['planet']['name'] }}</b>
                <span class="muted small">{{ $setup($info) }}</span>
                <span class="grow"></span>
                <button type="button" class="btn sm" data-copy="{{ $info['planet']['name'] }}">Copy planet name</button>
              </div>
              @foreach($v['steps'] as $step)
                <div class="step">
                  <span class="tag {{ $kindClass[$step['kind']] ?? 'muted' }}">{{ $kindLabel[$step['kind']] ?? $step['kind'] }}</span>
                  <div class="grow">
                    <div class="t">{{ $step['title'] }}</div>
                    <div class="w">
                      @if($step['time_word'] === 'stopped')Stopped <span data-ts="{{ $step['time'] }}" data-fmt="ago"></span>.
                      @elseif($step['time_word'] === 'stops')Stops <span data-ts="{{ $step['time'] }}" data-fmt="rel"></span> (<span data-ts="{{ $step['time'] }}" data-fmt="clock"></span>).
                      @endif
                      {{ $step['why'] }}
                      @if($step['time_word'] === 'best') Earlier program from <span data-ts="{{ $step['time'] }}" data-fmt="date"></span>.@endif
                    </div>
                    @if($step['kind'] === 'balance' && $info['balance'])
                      <div class="lines">
                        @foreach($info['balance']['lines'] as $name => $line)
                          <span>{{ $name }} · {{ $line['heads'] }} heads</span>
                          <div class="meter" title="{{ $line['percent'] }}% of what its factories use"><i style="width: {{ min(100, round($line['percent'] / 1.5)) }}%; background: {{ $line['percent'] >= 100 ? '#4f8f5c' : '#e0a43a' }}"></i><s style="left: {{ round(100 / 1.5) }}%"></s></div>
                          <span class="muted">{{ number_format($line['per_hour']) }} / h of {{ number_format($line['need']) }} needed</span>
                        @endforeach
                      </div>
                    @endif
                  </div>
                </div>
              @endforeach
            </div>
          @endforeach
          @if($c['timer'])
            <div class="planet">
              <div class="step">
                <span class="tag info">{{ $kindLabel['timer'] }}</span>
                <div class="grow"><div class="t">{{ $c['timer']['title'] }}</div><div class="w">{{ $c['timer']['why'] }}</div></div>
              </div>
            </div>
          @endif
        </section>
      @endforeach
    @endif

    @foreach($run['clear'] as $c)
      <div class="card clear">
        <b>{{ $c['name'] }}</b>
        <span class="small" style="color: var(--ok)">Nothing to do.
          @if($c['next_stop']) {{ $c['planets'] === 1 ? 'Its planet runs' : 'All '.$c['planets'].' planets run' }} until <span data-ts="{{ $c['next_stop'] }}" data-fmt="clock"></span>.@endif
        </span>
      </div>
    @endforeach

    @if($planetCount > 0)
      <p class="note">Extractor stop times come straight from EVE. "Balance" compares each raw line with what its basic factories use (3,000 every 30 minutes each) and only appears when a factory line goes short. "Move heads" compares with earlier programs on the same spot, which we start recording now, so it needs a restart or two before it can say anything. Storage is as of the last time the planet was opened in game.</p>
    @endif
  </section>

  {{-- ============================== My colonies ============================== --}}
  <section class="pane" id="pane-colonies" aria-label="My colonies">
    <div class="stats">
      <div class="card stat"><div class="label">Colonies</div><div class="v">{{ $planetCount }}</div><div class="muted small">on {{ count($colonies) }} {{ count($colonies) === 1 ? 'character' : 'characters' }}</div></div>
      <div class="card stat" style="border-color: {{ $stoppedCount ? '#6b1a1f' : 'var(--line)' }}"><div class="label">Stopped now</div><div class="v">{{ $stoppedCount }}</div></div>
      <div class="card stat" style="border-color: {{ $soonCount ? '#6b4310' : 'var(--line)' }}"><div class="label">Stops within 24 h</div><div class="v">{{ $soonCount }}</div></div>
    </div>
    @foreach($colonies as $c)
      <section class="card">
        <div class="sect">
          <h2>{{ $c['name'] }}</h2>
          <span class="muted small">{{ count($c['planets']) }}@if($c['slots']) of {{ $c['slots'] }}@endif colonies</span>
        </div>
        <div class="scroll">
          <table>
            <thead><tr><th scope="col">Planet</th><th scope="col">Extractors</th><th scope="col">Making</th><th scope="col">Factories</th><th scope="col">Fullest storage</th><th scope="col">Last opened in game</th><th scope="col"><span hidden>Copy</span></th></tr></thead>
            <tbody>
              @foreach($c['planets'] as $info)
                <tr>
                  <td><b>{{ $info['planet']['name'] }}</b><div class="muted small">{{ ucfirst($info['planet']['type']) }} · CC {{ $info['planet']['level'] }}</div></td>
                  <td>
                    <span class="chip {{ $statusLabel[$info['status']][1] }}">{{ $statusLabel[$info['status']][0] }}</span>
                    <div class="small" style="margin-top: 3px">
                      @if($info['stopped_since'])Stopped <span data-ts="{{ $info['stopped_since'] }}" data-fmt="ago"></span>
                      @elseif($info['not_running'] > 0)No program running
                      @elseif($info['next_stop'])Stops <span data-ts="{{ $info['next_stop'] }}" data-fmt="rel"></span>
                      @endif
                    </div>
                    @if($info['extracted'])<div class="muted small">{{ implode(' + ', $info['extracted']) }}</div>@endif
                  </td>
                  <td>{{ $info['making'] ? implode(', ', $info['making']['names']).' (P'.$info['making']['tier'].')' : '—' }}
                    @if(count($info['steps']) > 1 || ($info['steps'] && $info['steps'][0]['kind'] !== 'restart'))
                      <div class="small" style="color: var(--warn); margin-top: 3px">{{ collect($info['steps'])->where('kind', '!=', 'restart')->pluck('title')->implode('; ') }}</div>
                    @endif
                  </td>
                  <td>{{ $factories($info['factory_counts']) }}</td>
                  <td>{{ $info['storage'] ? round($info['storage']['fill'] * 100).'% '.$info['storage']['kind'] : '—' }}</td>
                  <td class="muted">@if($info['planet']['last_update'])<span data-ts="{{ $info['planet']['last_update'] }}" data-fmt="ago"></span>@else — @endif</td>
                  <td style="text-align: right"><button type="button" class="btn sm" data-copy="{{ $info['planet']['name'] }}">Copy name</button></td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </section>
    @endforeach
    @if($withoutPi > 0)
      <p class="note">{{ $withoutPi }} other {{ $withoutPi === 1 ? 'character has' : 'characters have' }} no colonies on SeAT.</p>
    @endif
    <p class="note">Stop times are exact. Storage and factory numbers are only as fresh as "last opened in game": EVE shares them when the colony is opened.</p>
  </section>

  {{-- ============================== Corp overview ============================== --}}
  @if($leader && $corp)
    <section class="pane" id="pane-corp" aria-label="Corp overview">
      <div class="stats">
        <div class="card stat"><div class="label">Pilots doing PI</div><div class="v">{{ $corp['pilots'] }}</div><div class="muted small">{{ $corp['characters'] }} of {{ $corp['members'] }} characters</div></div>
        <div class="card stat"><div class="label">Colonies</div><div class="v">{{ $corp['colonies'] }}</div></div>
        <div class="card stat" style="border-color: {{ $corp['stopped'] ? '#6b1a1f' : 'var(--line)' }}"><div class="label">Stopped now</div><div class="v">{{ $corp['stopped'] }}</div><div class="muted small">{{ $corp['soon'] }} more within 24 h</div></div>
        <div class="card stat"><div class="label">Unused colony slots</div><div class="v">{{ $corp['unused'] }}</div><div class="muted small">on characters already doing PI</div></div>
      </div>
      <div class="two">
        <section class="card wide">
          <div class="sect"><h2>Needs attention</h2><span class="muted small">stopped, or stopping within 24 h</span></div>
          <div class="scroll">
            <table>
              <thead><tr><th scope="col">Pilot</th><th scope="col">Planet</th><th scope="col">What</th><th scope="col">Last opened</th></tr></thead>
              <tbody>
                @forelse($corp['attention'] as $row)
                  <tr>
                    <td><b>{{ $row['pilot'] }}</b><div class="muted small">{{ $row['character'] }}</div></td>
                    <td>{{ $row['planet'] }}</td>
                    <td>@if($row['status'] === 'stopped')<span class="chip bad">Stopped</span>@if($row['time']) <span data-ts="{{ $row['time'] }}" data-fmt="ago"></span>@endif
                        @else<span class="chip warn">Soon</span> <span data-ts="{{ $row['time'] }}" data-fmt="rel"></span>@endif</td>
                    <td class="muted">@if($row['last_update'])<span data-ts="{{ $row['last_update'] }}" data-fmt="ago"></span>@endif</td>
                  </tr>
                @empty
                  <tr><td colspan="4" class="muted">Everything is running.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </section>
        <div class="side" style="display: flex; flex-direction: column; gap: 14px">
          <section class="card">
            <div class="sect"><h2>What the corp makes</h2><span class="muted small">per day, from running colonies</span></div>
            <table>
              <thead><tr><th scope="col">Product</th><th scope="col">Tier</th><th scope="col" style="text-align: right; padding-right: 16px">Per day</th></tr></thead>
              <tbody>
                @forelse($corp['products'] as $name => $p)
                  <tr><td>{{ $name }}</td><td class="muted">{{ $p['tier'] === 0 ? 'Raw' : 'P'.$p['tier'] }}</td><td style="text-align: right; padding-right: 16px; font-variant-numeric: tabular-nums">{{ number_format($p['per_day']) }}</td></tr>
                @empty
                  <tr><td colspan="3" class="muted">Nothing yet.</td></tr>
                @endforelse
              </tbody>
            </table>
          </section>
          <section class="card">
            <div class="sect"><h2>Busiest systems</h2></div>
            <table><tbody>
              @foreach($corp['systems'] as $s)
                <tr><td>{{ $s['name'] }}</td><td style="text-align: right; padding-right: 16px">{{ $s['count'] }} colonies</td></tr>
              @endforeach
            </tbody></table>
          </section>
        </div>
      </div>
      <p class="note">Corp members' characters that SeAT has a login for. Factory output assumes the factories run all the time.</p>
    </section>
  @endif

  {{-- ============================== Alerts ============================== --}}
  <section class="pane" id="pane-alerts" aria-label="Alerts">
    <section class="card form" style="max-width: 720px">
      <div><h2 style="margin: 0; font-size: 16px; font-weight: 600">How often may we bother you?</h2>
        <div class="muted small">A Discord DM from the corp bot. Off unless you pick it, and never more than one a day.</div></div>
      <label class="opt"><input type="radio" name="mode" value="never" @checked($alerts['mode'] === 'never')><span><b>Never</b><span class="muted small">Just check this screen when you like.</span></span></label>
      <label class="opt"><input type="radio" name="mode" value="due" @checked($alerts['mode'] === 'due')><span><b>On the day a PI run is due</b><span class="muted small">One message with the whole list.</span></span></label>
      <label class="opt"><input type="radio" name="mode" value="stopped" @checked($alerts['mode'] === 'stopped')><span><b>Only when something has already stopped</b><span class="muted small">The quietest choice.</span></span></label>
      <label style="display: flex; align-items: center; gap: 8px">Send it at
        <select id="hour">
          @for($h = 0; $h < 24; $h++)
            <option value="{{ $h }}" @selected($alerts['hour'] === $h)>{{ str_pad((string) $h, 2, '0', STR_PAD_LEFT) }}:00</option>
          @endfor
        </select>
        <span class="muted small">your time (<span id="tzname"></span>)</span>
      </label>
      <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
        <button type="button" class="btn p" id="save">Save</button>
        <button type="button" class="btn" id="test" @disabled(! $hasDiscord)>Send me a test now</button>
        <span class="toast" id="toast" role="status"></span>
      </div>
      @unless($hasDiscord)
        <div class="small" style="color: var(--warn)">Your SeAT isn't linked to Discord yet, so DMs can't reach you. Link it in SeAT under Connector → Identities.</div>
      @endunless
    </section>
    <p class="note">The desktop app's Parsec button: Settings in the app → Parsec. It only opens Parsec on this PC; you still do everything in EVE yourself.</p>
  </section>
</div>

<script>
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const app = window.__DEVILS_RAGE_APP__ || null;

  // Tabs (remembered in the address, so Reload stays put).
  const tabs = [...document.querySelectorAll('[data-tab]')];
  const show = (name) => {
    if (!document.getElementById('pane-' + name)) name = 'run';
    tabs.forEach((b) => b.classList.toggle('on', b.dataset.tab === name));
    document.querySelectorAll('.pane').forEach((p) => p.classList.toggle('on', p.id === 'pane-' + name));
  };
  tabs.forEach((b) => b.addEventListener('click', () => { history.replaceState(null, '', '#' + b.dataset.tab); show(b.dataset.tab); }));
  show(location.hash.slice(1) || 'run');

  // Times in your own time zone, kept fresh.
  const dur = (s) => {
    s = Math.abs(s);
    const m = Math.round(s / 60);
    if (m < 60) return m + ' min';
    const h = Math.floor(m / 60), mm = m % 60;
    if (h < 48) return h + ' h' + (mm && h < 10 ? ' ' + mm + ' m' : '');
    const d = Math.floor(h / 24), hh = h % 24;
    return d + ' d' + (hh ? ' ' + hh + ' h' : '');
  };
  const fmt = (el) => {
    const ts = Number(el.dataset.ts) * 1000, diff = (ts - Date.now()) / 1000, when = new Date(ts);
    switch (el.dataset.fmt) {
      case 'rel': el.textContent = diff >= 0 ? 'in ' + dur(diff) : dur(diff) + ' ago'; break;
      case 'ago': el.textContent = diff > 0 ? 'in ' + dur(diff) : dur(diff) + ' ago'; break;
      case 'date': el.textContent = when.toLocaleDateString([], { day: 'numeric', month: 'short' }); break;
      default: {
        const sameDay = when.toDateString() === new Date().toDateString();
        const time = when.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        el.textContent = sameDay ? time + ' today' : when.toLocaleDateString([], { weekday: 'short' }) + ' ' + time;
      }
    }
  };
  const tick = () => document.querySelectorAll('[data-ts]').forEach(fmt);
  tick();
  setInterval(tick, 30000);

  // Copy a planet name (paste it in EVE's search).
  document.querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', async () => {
    const was = b.textContent;
    try { await navigator.clipboard.writeText(b.dataset.copy); b.textContent = 'Copied'; }
    catch (e) { b.textContent = 'Copy failed'; }
    setTimeout(() => { b.textContent = was; }, 1400);
  }));

  // Parsec: only in the desktop app (it starts Parsec on this PC).
  const parsec = document.getElementById('parsec');
  if (parsec && app && app.parsec) {
    parsec.hidden = false;
    parsec.addEventListener('click', () => { try { window.open('https://dr-app.invalid/parsec', '_blank'); } catch (e) {} });
  }

  // Alerts.
  const tz = (Intl.DateTimeFormat().resolvedOptions().timeZone) || null;
  document.getElementById('tzname').textContent = tz || 'UTC';
  const toast = (text, bad) => { const t = document.getElementById('toast'); t.textContent = text; t.style.color = bad ? 'var(--bad)' : 'var(--ok)'; };
  const post = (url, body) => fetch(url, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify(body || {}),
  });
  document.getElementById('save').addEventListener('click', async () => {
    const mode = (document.querySelector('input[name="mode"]:checked') || {}).value || 'never';
    const r = await post(@json(route('devilsrage.pi.alerts')), { mode, hour: Number(document.getElementById('hour').value), timezone: tz }).catch(() => null);
    toast(r && r.ok ? (mode === 'never' ? 'Saved. No messages.' : 'Saved.') : 'Could not save, try again.', !(r && r.ok));
  });
  const test = document.getElementById('test');
  test.addEventListener('click', async () => {
    test.disabled = true;
    const r = await post(@json(route('devilsrage.pi.alerts.test'))).catch(() => null);
    const j = r && r.ok ? await r.json().catch(() => ({})) : {};
    toast(j.sent ? 'Sent. Check your Discord DMs.' : 'Could not send the DM.', !j.sent);
    test.disabled = false;
  });
})();
</script>
</body>
</html>
