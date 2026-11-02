{{-- Patch 64: advanced PI. Setup options (effort against ISK), the corp planet map with its pair
     finder, and the two-screenshot scan combiner. Included by pi/screen.blade.php. --}}
<style>
  .opt-table td, .opt-table th { padding: 7px 10px; border-top: 1px solid #23232a; vertical-align: top; }
  .opt-table tr.today td { background: #18181d; }
  .opt-table .num { text-align: right; white-space: nowrap; }
  .opt-table .best { color: var(--ok); font-weight: 600; }
  .opt-note { color: var(--muted); font-size: 12px; }
  .opt-planets { margin: 0 14px 12px; }
  .opt-planets summary { cursor: pointer; color: var(--muted); font-size: 12px; padding: 4px 0; }
  .sect { display: flex; align-items: center; gap: 8px 14px; flex-wrap: wrap; padding: 12px 16px; border-bottom: 1px solid #23232a; }
  .sect h2 { font-size: 15px; margin: 0; }
  .map-tools { display: flex; gap: 10px 16px; flex-wrap: wrap; align-items: center; padding: 12px 16px; }
  .map-tools select { background: #101014; border: 1px solid var(--line); border-radius: 6px; padding: 5px 8px; }
  .map-wrap { padding: 0 16px 12px; }
  #pi-map { width: 100%; height: auto; background: radial-gradient(ellipse at center, #1c1c24 0%, #111116 100%); border: 1px solid var(--line); border-radius: 10px; display: block; }
  .legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; vertical-align: middle; margin-right: 4px; }
  .pairs div { padding: 6px 16px; border-top: 1px solid #23232a; display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
  .pairs b { min-width: 46px; }
  .scan-drop { border: 2px dashed #3a3a44; border-radius: 10px; padding: 18px; text-align: center; color: var(--muted); cursor: pointer; flex: 1; min-width: 220px; }
  .scan-drop.has { border-style: solid; border-color: var(--ok); color: var(--text); }
  .scan-row { display: flex; gap: 12px; flex-wrap: wrap; padding: 12px 16px; }
  #scan-out { max-width: 100%; display: block; border-radius: 8px; margin: 0 16px 14px; }
</style>

{{-- ============================== Setup options ============================== --}}
<section class="pane" id="pane-options" aria-label="Setup options">
  <div class="card">
    <div class="sect">
      <h2>Setup options</h2>
      <span class="muted small">Your P0 → P2 planets, heads left where they are. Output depends only on how long a program has run
        (measured in game), so restarting more often makes more. ISK at split prices{{ $taxPercent > 0 ? ' after customs tax' : '' }};
        your time counts about {{ \DevilsRage\Seat\Pi\PiOptions::VISIT_MINUTES }} minutes per planet per login.</span>
    </div>
    @if(empty($options['pilots']))
      <p class="muted" style="padding: 12px 16px; margin: 0">No P0 → P2 planets with extractor numbers yet. Once SeAT has seen your colonies (↻ Update now), they show up here.</p>
    @else
      @php
        $optRows = function (array $rows) use ($isk): string {
            $bestKey = null;
            $bestRate = 0.0;
            foreach ($rows as $r) {
                if (($r['extra_isk_per_hour'] ?? 0) > $bestRate) { $bestRate = $r['extra_isk_per_hour']; $bestKey = $r['key']; }
            }
            $html = '';
            foreach ($rows as $r) {
                $rate = $r['extra_isk_per_hour'] === null ? '—' : $isk($r['extra_isk_per_hour']).' / h';
                $needs = $r['needs_more'] > 0 ? $r['needs_more'].' planet'.($r['needs_more'] === 1 ? '' : 's').' need more factories' : 'fits what you have';
                $html .= '<tr class="'.($r['key'] === 'weekly' ? 'today' : '').'">'
                    .'<td><b>'.e($r['title']).'</b><div class="opt-note">'.e($r['note']).'</div></td>'
                    .'<td class="num">'.e(number_format($r['logins'], 1)).'</td>'
                    .'<td class="num">'.e(number_format($r['effort_hours'], 1)).' h</td>'
                    .'<td class="num"><b>'.e($isk($r['isk_week'])).'</b></td>'
                    .'<td class="num'.($r['key'] === $bestKey ? ' best' : '').'">'.e($rate).'</td>'
                    .'<td class="small">'.e($needs).'</td></tr>';
            }
            return $html;
        };
        $head = '<tr><th>Option</th><th class="num">Logins / week</th><th class="num">Your time / week</th><th class="num">ISK / week</th><th class="num" title="What each extra hour of your time earns compared with logging in weekly">Extra ISK per extra hour</th><th>Factories</th></tr>';
      @endphp
      @if(count($options['pilots']) > 1)
        <div class="sect"><b>All your pilots</b><span class="muted small">{{ array_sum(array_map(fn ($p) => count($p['planets']), $options['pilots'])) }} P0 → P2 planets</span></div>
        <div class="scroll"><table class="opt-table">{!! $head !!}{!! $optRows($options['total']) !!}</table></div>
      @endif
    @endif
  </div>

  @foreach($options['pilots'] ?? [] as $pilot)
    <div class="card">
      <div class="sect"><h2>{{ $pilot['name'] }}</h2><span class="muted small">{{ count($pilot['planets']) }} P0 → P2 planet{{ count($pilot['planets']) === 1 ? '' : 's' }}</span></div>
      <div class="scroll"><table class="opt-table">{!! $head !!}{!! $optRows($pilot['total']) !!}</table></div>
      <details class="opt-planets">
        <summary>Planet by planet: raw per hour, factories needed</summary>
        @foreach($pilot['planets'] as $planet)
          <div style="margin: 8px 0 4px"><b>{{ $planet['name'] }}</b> <span class="muted small">→ {{ $planet['p2'] }}</span></div>
          <table class="opt-table small">
            <tr><th>Option</th><th class="num">Raw / h (each line)</th><th class="num">P2 / week</th><th class="num">Basic / advanced needed</th><th class="num">Extra power</th><th class="num">Hauled / week</th></tr>
            @foreach($planet['options'] as $o)
              <tr class="{{ $o['key'] === 'weekly' ? 'today' : '' }}">
                <td>{{ $o['title'] }}</td>
                <td class="num">{{ implode(' / ', array_map('number_format', $o['raw'])) }}</td>
                <td class="num">{{ number_format($o['p2_week']) }} <span class="muted">({{ $isk($o['isk_week']) }})</span></td>
                <td class="num">{{ $o['basics'] }} / {{ $o['advanced'] }}</td>
                <td class="num {{ $o['extra_mw'] > 0 ? '' : 'muted' }}">{{ $o['extra_mw'] > 0 ? '+'.number_format($o['extra_mw']).' MW' : 'none' }}</td>
                <td class="num">{{ number_format($o['m3_week']) }} m³</td>
              </tr>
            @endforeach
          </table>
        @endforeach
        <p class="muted small">Extra power is for the factories only; a level 5 command center has 19,000 MW and a weekly P0 → P2 setup leaves about 400 MW spare, so a shorter routine usually means fewer heads or exporting the extra raw instead. Shorter programs have smaller heads that can sit tighter on a hotspot, which often adds more than shown.</p>
      </details>
    </div>
  @endforeach
</section>

{{-- ============================== Corp map ============================== --}}
<section class="pane" id="pane-map" aria-label="Corp map">
  <div class="card">
    <div class="sect">
      <h2>Corp planet map</h2>
      <span class="muted small">Every spot the corp's extractors have used in the last 180 days. Brighter = yields more per head; faded = not used lately;
        dashed ring = worked out (now well under its best). It only knows spots we've used: the in-game scan still shows new deposits.</span>
    </div>
    @if(empty($corpMap))
      <p class="muted" style="padding: 12px 16px; margin: 0">No extractor history yet. SeAT records every program each hour; spots appear after the next update.</p>
    @else
      <div class="map-tools">
        <label>Planet <select id="map-planet">
          @foreach($corpMap as $i => $p)<option value="{{ $i }}">{{ $p['name'] }}{{ $p['type'] ? ' · '.ucfirst($p['type']) : '' }} ({{ count($p['spots']) }} spots)</option>@endforeach
        </select></label>
        <label><span class="legend-dot" style="background:#ff6a5a"></span><select id="map-a"></select></label>
        <label><span class="legend-dot" style="background:#5ab4ff"></span><select id="map-b"></select></label>
        <label title="How far apart the two extractors' centres may be for one setup (links cost power: about 2,650 km of links in total with 12 heads on a level 5 command center)">Reach
          <input type="range" id="map-reach" min="200" max="6000" step="100" value="2000"> <span id="map-reach-v">2,000 km</span></label>
      </div>
      <div class="map-wrap"><svg id="pi-map" viewBox="0 0 720 360" preserveAspectRatio="xMidYMid meet" aria-label="Planet map"></svg>
        <div class="muted small" id="map-scale" style="margin-top: 4px"></div></div>
      <div class="sect"><b>Best pairs</b><span class="muted small">one spot of each resource within reach, both still yielding well and used lately</span></div>
      <div class="pairs" id="map-pairs"></div>
      <script type="application/json" id="map-data">{!! json_encode(['planets' => $corpMap, 'now' => $now, 'halfLife' => \DevilsRage\Seat\Pi\PiMap::HALF_LIFE_DAYS], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    @endif
  </div>
</section>

{{-- ============================== Scan combiner ============================== --}}
<section class="pane" id="pane-scan" aria-label="Scan combiner">
  <div class="card">
    <div class="sect">
      <h2>Scan combiner</h2>
      <span class="muted small">In EVE's planet scan, take a screenshot showing one resource, switch to the other <b>without moving the camera</b>, take a second.
        Drop both here: the page shows where <b>both</b> are hot. The pictures stay in your browser; nothing is uploaded.</span>
    </div>
    <div class="scan-row">
      <label class="scan-drop" id="scan-a-box"><input type="file" accept="image/*" id="scan-a" hidden>Screenshot 1 (first resource)<br><span class="small">click or drop</span></label>
      <label class="scan-drop" id="scan-b-box"><input type="file" accept="image/*" id="scan-b" hidden>Screenshot 2 (second resource)<br><span class="small">click or drop</span></label>
    </div>
    <div class="map-tools">
      <label title="Show only where both are at least this hot">Both at least <input type="range" id="scan-min" min="0" max="90" step="5" value="40"> <span id="scan-min-v">40%</span></label>
      <span class="muted small" id="scan-said">Hot = white &gt; red &gt; yellow &gt; green &gt; blue, like the scan view.</span>
    </div>
    <canvas id="scan-out" hidden></canvas>
  </div>
</section>

<script>
(() => {
  const fmtKm = (n) => Math.round(n).toLocaleString() + ' km';

  // ---------------- Corp map ----------------
  const dataEl = document.getElementById('map-data');
  if (dataEl) {
    const { planets, now, halfLife } = JSON.parse(dataEl.textContent);
    const svg = document.getElementById('pi-map');
    const pick = document.getElementById('map-planet'), selA = document.getElementById('map-a'), selB = document.getElementById('map-b');
    const reach = document.getElementById('map-reach'), reachV = document.getElementById('map-reach-v');
    const NS = 'http://www.w3.org/2000/svg';
    const fresh = (t) => Math.pow(0.5, Math.max(0, now - t) / 86400 / halfLife);
    const dist = (a, b, r) => Math.acos(Math.max(-1, Math.min(1, Math.cos(a.lat) * Math.cos(b.lat) + Math.sin(a.lat) * Math.sin(b.lat) * Math.cos(a.lon - b.lon)))) * r;
    const xy = (s) => [((s.lon % (2 * Math.PI)) + 2 * Math.PI) % (2 * Math.PI) / (2 * Math.PI) * 720, s.lat / Math.PI * 360];
    const ago = (t) => { const d = Math.round((now - t) / 86400); return d < 1 ? 'today' : d + ' d ago'; };
    const add = (tag, attrs, parent = svg) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); parent.append(e); return e; };

    function products(p) {
      const seen = new Map();
      p.spots.forEach((s) => seen.set(s.product, (seen.get(s.product) || { name: s.product_name, n: 0 })) && seen.get(s.product).n++);
      return [...seen.entries()].sort((a, b) => b[1].n - a[1].n);
    }
    function fillProducts() {
      const p = planets[pick.value], list = products(p);
      [selA, selB].forEach((sel, i) => {
        sel.innerHTML = '';
        list.forEach(([id, info]) => sel.append(new Option(info.name, id)));
        if (list[i]) sel.value = list[i][0];
      });
      draw();
    }
    function draw() {
      const p = planets[pick.value];
      const r = p.radius_km || 5000;
      const a = Number(selA.value), b = Number(selB.value);
      svg.innerHTML = '';
      for (let i = 1; i < 6; i++) add('line', { x1: 0, x2: 720, y1: i * 60, y2: i * 60, stroke: '#2a2a33', 'stroke-width': 1 });
      for (let i = 1; i < 12; i++) add('line', { y1: 0, y2: 360, x1: i * 60, x2: i * 60, stroke: '#2a2a33', 'stroke-width': 1 });
      const best = {};
      p.spots.forEach((s) => { best[s.product] = Math.max(best[s.product] || 1, s.latest); });
      const pairs = [];
      if (a !== b) {
        const as = p.spots.filter((s) => s.product === a), bs = p.spots.filter((s) => s.product === b);
        as.forEach((x) => bs.forEach((y) => {
          const km = dist(x, y, r);
          if (km <= Number(reach.value)) pairs.push({ x, y, km, score: Math.min(x.latest / best[a], y.latest / best[b]) * fresh(Math.min(x.last_used, y.last_used)) });
        }));
        pairs.sort((m, n) => n.score - m.score || m.km - n.km);
      }
      pairs.slice(0, 5).forEach((q, i) => {
        const [x1, y1] = xy(q.x), [x2, y2] = xy(q.y);
        add('line', { x1, y1, x2, y2, stroke: i === 0 ? '#adff2f' : '#7a9a4a', 'stroke-width': i === 0 ? 2.5 : 1.2, 'stroke-dasharray': i === 0 ? '' : '4 3' });
      });
      p.spots.forEach((s) => {
        const [x, y] = xy(s);
        const color = s.product === a ? '255,106,90' : s.product === b ? '90,180,255' : '150,150,160';
        const strength = Math.max(0.15, s.latest / best[s.product]);
        const alpha = (0.25 + 0.75 * fresh(s.last_used)) * strength;
        const c = add('circle', { cx: x, cy: y, r: 4 + Math.min(6, s.runs), fill: `rgba(${color},${alpha.toFixed(2)})`, stroke: `rgb(${color})`, 'stroke-width': 1.2, 'stroke-dasharray': s.worked > 0.3 ? '2 2' : '' });
        const t = document.createElementNS(NS, 'title');
        t.textContent = `${s.product_name}: ${s.latest.toLocaleString()} per head (best ${s.best.toLocaleString()}${s.worked > 0.3 ? ', worked out ' + Math.round(s.worked * 100) + '%' : ''}) · ${s.runs} program${s.runs === 1 ? '' : 's'} · last ${ago(s.last_used)}` + (s.who && s.who.length ? ' · ' + s.who.join(', ') : ` · ${s.pilots} pilot${s.pilots === 1 ? '' : 's'}`);
        c.append(t);
      });
      document.getElementById('map-scale').textContent = (p.radius_km ? 'Planet radius ' + fmtKm(p.radius_km) : 'Planet size unknown: distances assume a 5,000 km radius') + ' · north pole at the top, flat map (sizes stretch near the poles)';
      const host = document.getElementById('map-pairs');
      host.innerHTML = '';
      if (a === b) { host.innerHTML = '<div class="muted">Pick two different resources.</div>'; return; }
      if (!pairs.length) { host.innerHTML = '<div class="muted">No spots of both within ' + fmtKm(reach.value) + '. Try a longer reach, or scout new deposits in game.</div>'; return; }
      pairs.slice(0, 5).forEach((q, i) => {
        const row = document.createElement('div');
        row.innerHTML = `<b>${Math.round(q.score * 100)}%</b><span>${fmtKm(q.km)} apart</span>`
          + `<span class="muted small">${q.x.product_name} ${q.x.latest.toLocaleString()} / head, last ${ago(q.x.last_used)} · ${q.y.product_name} ${q.y.latest.toLocaleString()} / head, last ${ago(q.y.last_used)}</span>`
          + (i === 0 ? '<span class="small" style="color:#adff2f">best: the bright line on the map</span>' : '');
        host.append(row);
      });
    }
    pick.addEventListener('change', fillProducts);
    selA.addEventListener('change', draw);
    selB.addEventListener('change', draw);
    reach.addEventListener('input', () => { reachV.textContent = fmtKm(reach.value); draw(); });
    fillProducts();
  }

  // ---------------- Scan combiner ----------------
  const shots = { a: null, b: null };
  const out = document.getElementById('scan-out'), said = document.getElementById('scan-said');
  const minEl = document.getElementById('scan-min'), minV = document.getElementById('scan-min-v');
  // EVE's scan colours to heat 0..1: blue 0.2 → green 0.4 → yellow 0.6 → red 0.8 → white 1. Dark / grey = none.
  function heat(r, g, b) {
    const max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 510, s = max === min ? 0 : (max - min) / (255 - Math.abs(max + min - 255));
    if (l > 0.82 && s < 0.35) return 1;
    if (s < 0.3 || max < 60) return 0;
    let h = 0;
    const d = max - min;
    if (max === r) h = ((g - b) / d) % 6; else if (max === g) h = (b - r) / d + 2; else h = (r - g) / d + 4;
    h = (h * 60 + 360) % 360;
    if (h > 300) h = 0;            // magenta-ish reads as red
    if (h > 240) h = 240;
    const t = 1 - h / 240;          // 240° blue → 0, 0° red → 1
    return 0.2 + 0.6 * t * Math.min(1, max / 200);
  }
  function load(which, file) {
    if (!file) return;
    const img = new Image();
    img.onload = () => { shots[which] = img; document.getElementById(`scan-${which}-box`).classList.add('has'); document.getElementById(`scan-${which}-box`).firstChild.nextSibling.textContent = file.name; combine(); };
    img.src = URL.createObjectURL(file);
  }
  function combine() {
    if (!shots.a || !shots.b) return;
    const w = shots.a.naturalWidth, h = shots.a.naturalHeight;
    if (shots.b.naturalWidth !== w || shots.b.naturalHeight !== h) { said.textContent = 'The two screenshots are different sizes: take both at the same window size without moving the camera.'; out.hidden = true; return; }
    const scale = Math.min(1, 1600 / w);
    const cw = Math.round(w * scale), ch = Math.round(h * scale);
    const grab = (img) => { const c = document.createElement('canvas'); c.width = cw; c.height = ch; const x = c.getContext('2d'); x.drawImage(img, 0, 0, cw, ch); return x.getImageData(0, 0, cw, ch); };
    const A = grab(shots.a), B = grab(shots.b);
    out.width = cw; out.height = ch; out.hidden = false;
    const ctx = out.getContext('2d');
    const view = ctx.createImageData(cw, ch);
    const min = Number(minEl.value) / 100;
    let hits = 0;
    for (let i = 0; i < A.data.length; i += 4) {
      const ha = heat(A.data[i], A.data[i + 1], A.data[i + 2]), hb = heat(B.data[i], B.data[i + 1], B.data[i + 2]);
      const both = Math.min(ha, hb);
      // Background: screenshot 1, dimmed and grey, so the planet stays recognisable.
      const grey = (A.data[i] + A.data[i + 1] + A.data[i + 2]) / 3 * 0.35;
      if (both >= min && both > 0) {
        hits++;
        const k = (both - min) / Math.max(0.01, 1 - min);
        view.data[i] = 120 + 135 * k; view.data[i + 1] = 255; view.data[i + 2] = 40 + 60 * (1 - k);
      } else {
        view.data[i] = grey; view.data[i + 1] = grey; view.data[i + 2] = grey;
      }
      view.data[i + 3] = 255;
    }
    ctx.putImageData(view, 0, 0);
    said.textContent = hits ? `Green = both resources at least ${Math.round(min * 100)}% hot (${(hits / (cw * ch) * 100).toFixed(1)}% of the picture). Brightest green is where to put the two extractors close together.` : 'No place where both are that hot: lower the slider, or look for another pair of deposits.';
  }
  ['a', 'b'].forEach((which) => {
    const input = document.getElementById(`scan-${which}`), box = document.getElementById(`scan-${which}-box`);
    if (!input) return;
    input.addEventListener('change', () => load(which, input.files[0]));
    box.addEventListener('dragover', (e) => { e.preventDefault(); });
    box.addEventListener('drop', (e) => { e.preventDefault(); load(which, e.dataTransfer.files[0]); });
  });
  if (minEl) minEl.addEventListener('input', () => { minV.textContent = minEl.value + '%'; combine(); });
  // Paste a screenshot straight in (Print Screen, then Ctrl+V): fills the first empty slot.
  document.addEventListener('paste', (e) => {
    if (!document.getElementById('pane-scan')?.classList.contains('on')) return;
    const file = [...(e.clipboardData?.files || [])].find((f) => f.type.startsWith('image/'));
    if (file) load(shots.a ? 'b' : 'a', file);
  });
})();
</script>
