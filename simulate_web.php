<?php
/**
 * HiveSense web simulator - inserts fake readings straight into the DB (skips /ingest).
 * Upload to the project root, open /simulate_web.php, enter the key.
 * DELETE THIS FILE when you're done testing.
 */

const SIM_KEY = 'I am groot';   // <-- set your own secret before uploading

require_once __DIR__ . '/app/core/config.php';
require_once __DIR__ . '/app/models/Base_Model.php';
require_once __DIR__ . '/app/models/Alert_Model.php';

// ════════════════════════════════════════════════════════════
//  API (POST JSON)
// ════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $in = json_decode(file_get_contents('php://input'), true) ?? [];

    if (SIM_KEY === 'CHANGE_ME' || !hash_equals(SIM_KEY, (string)($in['key'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Bad key (or SIM_KEY not changed).']);
        exit();
    }

    $sensorId = ($in['sensor'] ?? '') !== '' ? (int)$in['sensor'] : null;
    $mode     = $in['mode'] ?? 'normal';
    $action   = $in['action'] ?? 'live';

    // Exact overrides: blank = use the mode / normal drift, a number = use that exact value
    $exactCo2  = (isset($in['co2'])  && $in['co2']  !== '' && is_numeric($in['co2']))  ? (float)$in['co2']  : null;
    $exactFood = (isset($in['food']) && $in['food'] !== '' && is_numeric($in['food'])) ? (float)$in['food'] : null;

    $targets = [
        'normal' => [34.5, 60, 600],
        'hot'    => [40.0, 60, 600],
        'cold'   => [29.0, 60, 600],
        'humid'  => [34.5, 90, 600],
        'dry'    => [34.5, 42, 600],
        'co2'    => [34.5, 60, 3300],
    ];
    if (!isset($targets[$mode])) $mode = 'normal';
    [$tT, $tH, $tC] = $targets[$mode];

    // Carry state between requests so the values drift smoothly
    $st   = $in['state'] ?? [];
    $temp = (float)($st['temp'] ?? 34.5);
    $hum  = (float)($st['hum']  ?? 60);
    $co2  = (float)($st['co2']  ?? 600);
    $food = (float)($st['food'] ?? FOOD_MAX_G * 0.9);   // grams

    $rnd  = fn(float $r) => (mt_rand() / mt_getrandmax() * 2 - 1) * $r;
    $step = fn(float $v, float $target, float $noise, float $min, float $max)
              => max($min, min($max, $v + ($target - $v) * 0.15 + $rnd($noise)));

    $db   = new Base_Model();
    $conn = $db->connection;
    $stmt = $conn->prepare(
        'INSERT INTO hs_readings (sensor_id, temperature, humidity, co2, food_level, timestamp)
         VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? SECOND))'
    );
    $alertModel = new Alert_Model();

    if ($action === 'backfill') {
        $every    = max(30, (int)($in['step'] ?? 60));              // seconds between readings
        $total    = max(1, min(300, (int)($in['count'] ?? 200)));   // rows in this group
        $startAgo = max(0, (int)($in['start_ago'] ?? 0));           // age (sec) of the OLDEST row in this group
        $gapFrom  = (int)($in['gap_from'] ?? 0);                    // outage starts this many sec ago (0 = no outage)
        $gapLen   = (int)($in['gap_len'] ?? 0);                     // outage length in sec
        $alerts   = false;
    } else {
        $total    = max(1, min(10, (int)($in['count'] ?? 1)));
        $every    = max(0, (int)($in['step'] ?? 0));
        $startAgo = ($total - 1) * $every;
        $gapFrom  = $gapLen = 0;
        $alerts   = true;
    }

    $last = null;
    $inserted = 0;
    for ($j = 0; $j < $total; $j++) {
        $secondsAgo = max(0, $startAgo - $j * $every);
        // Power outage: no readings exist inside the gap
        if ($gapLen > 0 && $secondsAgo <= $gapFrom && $secondsAgo > $gapFrom - $gapLen) continue;
        $wave = $action === 'backfill' ? sin(($secondsAgo / 3600) / 24 * 2 * M_PI) * 1.2 : 0;
        $temp = $step($temp, $tT + $wave, 0.25, -40, 80);
        $hum  = $step($hum,  $tH, 0.8, 0, 100);

        // CO2: exact value if given, otherwise drift toward the mode target
        $co2  = $exactCo2 !== null ? $exactCo2 : $step($co2, $tC, 25, 300, 10000);

        // Food: exact value if given, otherwise drains 0-1 g per reading
        $food = $exactFood !== null ? $exactFood : max(0, $food - mt_rand(0, 100) / 100);

        $t = round($temp, 2); $h = round($hum, 2); $c = round($co2, 1); $f = round($food, 1);

        $stmt->bind_param('iddddi', $sensorId, $t, $h, $c, $f, $secondsAgo);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Insert failed: ' . $stmt->error]);
            exit();
        }
        $readingId = $conn->insert_id;
        if ($alerts) $alertModel->evaluateReading($sensorId, $t, $h, $c, $f, $readingId);

        $inserted++;
        $last = ['id' => $readingId, 'temperature' => $t, 'humidity' => $h, 'co2' => $c, 'food_level' => $f];
    }

    echo json_encode([
        'success'  => true,
        'inserted' => $inserted,
        'last'     => $last,
        'state'    => ['temp' => $temp, 'hum' => $hum, 'co2' => $co2, 'food' => $food],
    ]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>HiveSense Simulator</title>
<style>
    body { font-family: sans-serif; max-width: 560px; margin: 24px auto; padding: 0 16px; color: #1A3320; }
    h2 { color: #2D7A3A; }
    label { display: block; font-size: .8rem; margin-top: 12px; font-weight: 600; }
    input, select { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 6px; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    button { margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 6px; background: #2D7A3A; color: #fff; font-weight: 600; cursor: pointer; }
    button.stop { background: #b91c1c; }
    button.alt { background: #6b7280; }
    #log { margin-top: 16px; background: #F4FAF5; border: 1px solid #ccc; border-radius: 6px; padding: 10px; height: 240px; overflow-y: auto; font: .78rem monospace; white-space: pre-wrap; }
    .warn { font-size: .78rem; color: #b91c1c; }
</style>
</head>
<body>
<h2>HiveSense Simulator</h2>
<p class="warn">Testing only. Delete this file from the server when you're done.</p>

<label>Secret key</label>
<input type="password" id="key" placeholder="SIM_KEY from the file">

<div class="row">
    <div>
        <label>Sensor ID (blank = unassigned)</label>
        <input type="number" id="sensor" value="1">
    </div>
    <div>
        <label>Mode</label>
        <select id="mode">
            <option>normal</option><option>hot</option><option>cold</option>
            <option>humid</option><option>dry</option><option>co2</option>
        </select>
    </div>
</div>

<div class="row">
    <div>
        <label>Exact CO₂ (ppm, blank = auto)</label>
        <input type="number" id="co2" step="any" placeholder="e.g. 1200">
    </div>
    <div>
        <label>Exact food (g, blank = auto)</label>
        <input type="number" id="food" step="any" placeholder="e.g. 250">
    </div>
</div>

<label>Live interval (seconds)</label>
<input type="number" id="interval" value="10" min="2">

<button id="toggle" onclick="toggleLive()">Start live</button>

<label style="margin-top:24px;">Simulate past data</label>
<div class="row">
    <div><label>From</label><input type="datetime-local" id="fromDt"></div>
    <div><label>To</label><input type="datetime-local" id="toDt"></div>
</div>
<div class="row">
    <div><label>Seconds between readings</label><input type="number" id="bstep" value="60" min="30"></div>
    <div></div>
</div>

<button class="alt" id="bfBtn" onclick="backfill()">Simulate full period (no alerts)</button>

<div id="log"></div>

<script>
let timer = null, state = null;
const $ = id => document.getElementById(id);

function log(msg) {
    const l = $('log');
    l.textContent += new Date().toLocaleTimeString() + '  ' + msg + '\n';
    l.scrollTop = l.scrollHeight;
}

async function call(extra) {
    const res = await fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            key: $('key').value, sensor: $('sensor').value,
            mode: $('mode').value,
            co2: $('co2').value, food: $('food').value,
            state, ...extra
        })
    });
    const data = await res.json();
    if (!data.success) throw new Error(data.message || 'Request failed');
    state = data.state;
    return data;
}

async function tick() {
    try {
        const d = await call({ action: 'live', count: 1 });
        const r = d.last;
        log(`#${r.id}  ${r.temperature}°C  ${r.humidity}%  ${r.co2}ppm  food ${r.food_level} g`);
    } catch (e) { log('Error: ' + e.message); stopLive(); }
}

function toggleLive() { timer ? stopLive() : startLive(); }

function startLive() {
    tick();
    timer = setInterval(tick, Math.max(2, +$('interval').value) * 1000);
    $('toggle').textContent = 'Stop live';
    $('toggle').classList.add('stop');
}

function stopLive() {
    clearInterval(timer); timer = null;
    $('toggle').textContent = 'Start live';
    $('toggle').classList.remove('stop');
}

async function backfill() {
    const step = +$('bstep').value;
    const from = new Date($('fromDt').value);
    const to   = new Date($('toDt').value);
    if (isNaN(from) || isNaN(to) || to <= from) { log('Pick a valid From/To range.'); return; }

    const now = Date.now();
    const fromAgo = Math.max(0, Math.round((now - from.getTime()) / 1000)); // oldest
    const toAgo   = Math.max(0, Math.round((now - to.getTime()) / 1000));   // newest
    const total = Math.floor((fromAgo - toAgo) / step) + 1;
    const chunk = 200;
    let done = 0;
    state = null;
    $('bfBtn').disabled = true;
    log(`Simulating ${total} readings from ${$('fromDt').value} to ${$('toDt').value}...`);
    try {
        while (done < total) {
            const n = Math.min(chunk, total - done);
            const startAgo = fromAgo - done * step;   // oldest reading of this group
            await call({ action: 'backfill', count: n, step, start_ago: startAgo,
                         gap_from: 0, gap_len: 0 });
            done += n;
            log(`${done}/${total} sent`);
        }
        log('Done.');
    } catch (e) { log('Error: ' + e.message + ` (stopped at ${done}/${total})`); }
    $('bfBtn').disabled = false;
}
</script>
</body>
</html>