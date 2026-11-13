<?php
require_once 'init.php';
require_login();
require_once 'weather_engine.php';

// Weather & Alerts page: live conditions and a 3-day forecast for South Caloocan (Open-Meteo, the same source the
// alert engine uses), the engine's current threat level, and the AI restocking alerts.
// The top of the page is an animated "sky" that follows the real weather (sun, clouds, rain, lightning, night stars).
$is_admin_view = is_role_in(['head_admin', 'admin', 'official']);
$user_id = (int)$_SESSION['user_id'];

// WMO weather code => description
function wx_describe($code){
    $map = [
        0 => 'Clear skies', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Cloudy',
        45 => 'Fog', 48 => 'Fog', 51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle', 56 => 'Freezing drizzle', 57 => 'Freezing drizzle',
        61 => 'Light rain', 63 => 'Moderate rain', 65 => 'Heavy rain', 66 => 'Freezing rain', 67 => 'Freezing rain',
        80 => 'Light rain showers', 81 => 'Rain showers', 82 => 'Violent rain showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with hail', 99 => 'Thunderstorm with hail',
    ];
    return $map[(int)$code] ?? 'Unsettled weather';
}

// WMO code => visual kind used by the icons and the animated sky.
function wx_kind($code){
    $c = (int)$code;
    if ($c === 0) return 'clear';
    if ($c === 1 || $c === 2) return 'partly';
    if ($c === 3) return 'cloudy';
    if ($c === 45 || $c === 48) return 'fog';
    if (in_array($c, [51, 53, 55, 56, 57], true)) return 'drizzle';
    if (in_array($c, [65, 67, 82], true)) return 'heavy';
    if ($c >= 95) return 'thunder';
    if ($c >= 61) return 'rain';
    return 'cloudy';
}

// Animated SVG weather icon (gradients come from the shared <defs> block on the page).
function wx_icon($kind, $is_day = true, $size = 48, $static = false){
    $sun = '<g class="wxi-sun"><circle cx="32" cy="32" r="11" fill="url(#wxgSun)"/><g class="wxi-rays" stroke="#FDB813" stroke-width="3" stroke-linecap="round">'
        . implode('', array_map(function ($a) { $r = deg2rad($a); return '<line x1="' . round(32 + 16 * cos($r), 1) . '" y1="' . round(32 + 16 * sin($r), 1) . '" x2="' . round(32 + 22 * cos($r), 1) . '" y2="' . round(32 + 22 * sin($r), 1) . '"/>'; }, range(0, 315, 45)))
        . '</g></g>';
    $moon = '<path class="wxi-moon" d="M38 10a18 18 0 1 0 16 28A15 15 0 0 1 38 10z" fill="url(#wxgMoon)"/>';
    $cloud = function ($dark = false, $dy = 0) {
        return '<path class="wxi-cloud" transform="translate(0 ' . $dy . ')" d="M19 50h27a10 10 0 0 0 .8-19.97A14 14 0 0 0 19.6 27.2 11.5 11.5 0 0 0 19 50z" fill="url(#' . ($dark ? 'wxgCloudDark' : 'wxgCloud') . ')"/>';
    };
    $drops = function ($n, $heavy = false) {
        $out = '<g stroke="#74c0fc" stroke-width="' . ($heavy ? 3 : 2.4) . '" stroke-linecap="round">';
        $xs = $n === 2 ? [26, 38] : [22, 32, 42];
        foreach ($xs as $i => $x) $out .= '<line class="wxi-drop" style="animation-delay:' . ($i * 0.33) . 's" x1="' . $x . '" y1="49" x2="' . ($x - 3) . '" y2="' . ($heavy ? 58 : 55) . '"/>';
        return $out . '</g>';
    };
    switch ($kind) {
        case 'clear': $body = $is_day ? $sun : $moon; break;
        case 'partly': $body = '<g transform="translate(-8 -9) scale(.85)">' . ($is_day ? $sun : $moon) . '</g>' . $cloud(false, 4); break;
        case 'cloudy': $body = '<g class="wxi-back" transform="translate(-9 -8) scale(.8)" opacity=".7">' . $cloud(false) . '</g>' . $cloud(false, 3); break;
        case 'fog': $body = $cloud(false, -4) . '<g class="wxi-fog" stroke="#e9eef5" stroke-width="3" stroke-linecap="round"><line x1="14" y1="52" x2="50" y2="52"/><line x1="20" y1="58" x2="44" y2="58"/></g>'; break;
        case 'drizzle': $body = ($is_day ? '<g transform="translate(-8 -12) scale(.75)">' . $sun . '</g>' : '') . $cloud(false, -5) . $drops(2); break;
        case 'rain': $body = $cloud(true, -6) . $drops(3); break;
        case 'heavy': $body = $cloud(true, -7) . $drops(3, true); break;
        case 'thunder': $body = $cloud(true, -8) . $drops(2) . '<path class="wxi-bolt" d="M35 40l-9 12h7l-4 11 13-15h-7l5-8z" fill="#FFD43B"/>'; break;
        default: $body = $cloud(false);
    }
    return '<svg class="wxi' . ($static ? ' wxi-static' : '') . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" aria-hidden="true">' . $body . '</svg>';
}

function wx_compass($deg){
    $dirs = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    return $dirs[(int)round(((int)$deg % 360) / 45) % 8];
}

// Display forecast, cached for 10 minutes so the page is fast and the free service isn't called on every view.
function wx_display_forecast(){
    $cache = sys_get_temp_dir() . '/drrmc_weather_display_v2.json';
    if (is_file($cache) && filemtime($cache) > time() - 300) {
        $cached = json_decode((string)file_get_contents($cache), true);
        if (is_array($cached)) return $cached + ['_checked' => filemtime($cache)];
    }
    $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => WEATHER_LAT, 'longitude' => WEATHER_LNG,
        'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
        'hourly' => 'temperature_2m,relative_humidity_2m,precipitation,precipitation_probability,weather_code,wind_speed_10m,wind_direction_10m,is_day',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max,sunrise,sunset,uv_index_max',
        'forecast_days' => WEATHER_FORECAST_DAYS, 'timezone' => 'Asia/Manila',
    ]);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5]);
        $raw = curl_exec($ch);
        $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        $data = $ok ? json_decode((string)$raw, true) : null;
        if (is_array($data) && !empty($data['hourly'])) {
            @file_put_contents($cache, $raw);
            return $data + ['_checked' => time()];
        }
    }
    // Service unreachable: fall back to the last cached copy, however old.
    $stale = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    return is_array($stale) ? $stale + ['_checked' => filemtime($cache)] : null;
}

$fc = wx_display_forecast();

// Engine state: last reading and the open (or most recent) weather event.
$last_reading = $conn->query('SELECT * FROM weather_readings ORDER BY id DESC LIMIT 1')->fetch_assoc();
$event = $conn->query('SELECT * FROM weather_events ORDER BY (ended_at IS NULL) DESC, id DESC LIMIT 1')->fetch_assoc();
$event_open = $event && $event['ended_at'] === null;
$level = $last_reading ? (int)$last_reading['threat_level'] : 0;
$level_names = [0 => 'No threat', 1 => 'Advisory', 2 => 'Watch', 3 => 'Warning', 4 => 'Severe warning', 5 => 'Extreme'];
$level_colors = ['#12b76a', '#eab308', '#f79009', '#e8590c', '#d92d20', '#9b1c1c'];

// Alerts: barangay staff see their own barangay's latest one; the DRRM side sees every barangay for the latest event.
$my_alert = null;
$event_alerts = [];
if ($is_admin_view) {
    if ($event) {
        $stmt = $conn->prepare("SELECT a.* FROM weather_alerts a INNER JOIN (SELECT barangay, MAX(id) AS id FROM weather_alerts WHERE event_id = ? GROUP BY barangay) latest ON latest.id = a.id ORDER BY a.barangay");
        $stmt->bind_param('i', $event['id']);
        $stmt->execute();
        $event_alerts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $me = $conn->prepare('SELECT barangay FROM users WHERE id = ?');
    $me->bind_param('i', $user_id);
    $me->execute();
    $my_barangay = (string)($me->get_result()->fetch_assoc()['barangay'] ?? '');
    $me->close();
    if (ctype_digit($my_barangay)) {
        $b = (int)$my_barangay;
        $stmt = $conn->prepare("SELECT * FROM weather_alerts WHERE barangay = ? AND status = 'ready' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $b);
        $stmt->execute();
        $my_alert = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
$events_history = $conn->query('SELECT * FROM weather_events ORDER BY id DESC LIMIT 8')->fetch_all(MYSQLI_ASSOC);

// Current conditions + the sky scene. Admins can preview other skies (e.g. for a presentation); it's labeled as a preview.
$previews = ['clear' => 'Sunny', 'partly' => 'Partly cloudy', 'rain' => 'Rain', 'heavy' => 'Heavy rain', 'thunder' => 'Thunderstorm', 'night' => 'Night'];
$preview = $is_admin_view && isset($previews[$_GET['preview'] ?? '']) ? $_GET['preview'] : null;
$cur = $fc['current'] ?? null;
$d = $fc['daily'] ?? null;
$is_day = $cur ? !empty($cur['is_day']) : true;
$kind = $cur ? wx_kind($cur['weather_code']) : 'cloudy';
$desc = $cur ? wx_describe($cur['weather_code']) : '';
if ($preview) {
    if ($preview === 'night') { $is_day = false; $kind = 'clear'; $desc = 'Clear night'; }
    else { $is_day = true; $kind = $preview; $desc = $previews[$preview]; }
}
// Sky settings per kind: rain drops, lightning, cloud count. Night swaps the palette and adds stars.
$scene = [
    'clear' => ['rain' => 0, 'clouds' => 2], 'partly' => ['rain' => 0, 'clouds' => 5], 'cloudy' => ['rain' => 0, 'clouds' => 8],
    'fog' => ['rain' => 0, 'clouds' => 7], 'drizzle' => ['rain' => 70, 'clouds' => 7], 'rain' => ['rain' => 200, 'clouds' => 8],
    'heavy' => ['rain' => 380, 'clouds' => 9], 'thunder' => ['rain' => 300, 'clouds' => 9],
][$kind];
$scene_class = 'sky-' . $kind . ($is_day ? ' is-day' : ' is-night');

// Hour by hour (next 24 h) and the 3-hourly chart series.
$hourly = [];
$chart = ['labels' => [], 'kinds' => [], 'days' => [], 'temp' => [], 'rain' => [], 'humidity' => [], 'wind' => [], 'wind_dir' => [], 'pop' => []];
if ($fc) {
    $h = $fc['hourly'];
    $now_hour = substr((string)$cur['time'], 0, 13);
    $start = 0;
    foreach ($h['time'] as $i => $t) { if (substr($t, 0, 13) >= $now_hour) { $start = $i; break; } }
    for ($i = $start; $i < min(count($h['time']), $start + 25); $i++) {
        $hourly[] = ['label' => $i === $start ? 'Now' : date('g A', strtotime($h['time'][$i])), 'kind' => wx_kind($h['weather_code'][$i]), 'day' => !empty($h['is_day'][$i]),
            'temp' => round($h['temperature_2m'][$i]), 'pop' => (int)($h['precipitation_probability'][$i] ?? 0)];
    }
    for ($i = $start; $i < count($h['time']); $i += 3) {
        $ts = strtotime($h['time'][$i]);
        $chart['labels'][] = [date('D', $ts), date('ga', $ts)];
        $chart['kinds'][] = wx_kind($h['weather_code'][$i]);
        $chart['days'][] = !empty($h['is_day'][$i]);
        $chart['temp'][] = round((float)$h['temperature_2m'][$i], 1);
        $chart['humidity'][] = (int)$h['relative_humidity_2m'][$i];
        $chart['rain'][] = round(array_sum(array_slice(array_map('floatval', $h['precipitation']), $i, 3)), 1);
        $chart['pop'][] = (int)($h['precipitation_probability'][$i] ?? 0);
        $chart['wind'][] = round((float)$h['wind_speed_10m'][$i]);
        $chart['wind_dir'][] = (int)$h['wind_direction_10m'][$i];
    }
}

// Deterministic "random" layout for clouds, stars' seed and the skyline.
mt_srand(20260928);
$clouds = [];
for ($i = 0; $i < $scene['clouds']; $i++) {
    $clouds[] = ['top' => mt_rand(0, 30), 'w' => mt_rand(110, 240), 'dur' => mt_rand(70, 150), 'delay' => -mt_rand(0, 150), 'op' => mt_rand(25, 55) / 100];
}
$skyline = '';
$windows = '';
for ($x = 0; $x < 1600;) {
    $w = mt_rand(28, 70); $hgt = mt_rand(26, 110);
    $skyline .= '<rect x="' . $x . '" y="' . (120 - $hgt) . '" width="' . $w . '" height="' . $hgt . '"/>';
    if ($hgt > 50 && mt_rand(0, 1)) $skyline .= '<rect x="' . ($x + $w / 2 - 2) . '" y="' . (120 - $hgt - 14) . '" width="4" height="14"/>';
    for ($wy = 120 - $hgt + 8; $wy < 112; $wy += 12) for ($wx = $x + 6; $wx < $x + $w - 6; $wx += 10) if (mt_rand(0, 9) < 3) $windows .= '<rect x="' . $wx . '" y="' . $wy . '" width="4" height="5"/>';
    $x += $w + mt_rand(0, 6);
}

// Tile values
if ($fc) {
    $uv = (float)($d['uv_index_max'][0] ?? 0);
    $uv_label = $uv < 3 ? 'Low' : ($uv < 6 ? 'Moderate' : ($uv < 8 ? 'High' : ($uv < 11 ? 'Very high' : 'Extreme')));
    $hum = (int)$cur['relative_humidity_2m'];
    $hum_label = $hum >= 80 ? 'Very humid' : ($hum >= 60 ? 'Humid' : 'Comfortable');
    $sunrise = strtotime($d['sunrise'][0]); $sunset = strtotime($d['sunset'][0]); $now_ts = strtotime($cur['time']);
    $sun_frac = max(0, min(1, ($now_ts - $sunrise) / max(1, $sunset - $sunrise)));
    $sun_x = round(70 - 56 * cos(M_PI * $sun_frac), 1); $sun_y = round(66 - 56 * sin(M_PI * $sun_frac), 1);
    $tmin = min(array_map('floatval', $d['temperature_2m_min'])); $tmax = max(array_map('floatval', $d['temperature_2m_max']));
    $tspan = max(1, $tmax - $tmin);
}
$active_alert = $event_open && $event;
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Weather &amp; Alerts</title>
  <?php app_favicon(); ?>
  <link rel="stylesheet" href="<?= e(asset_v('assets/style.css')) ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .page-scroll-shell > main.wx-main { padding:20px; overflow-x:hidden; }
    .wx-page { max-width:1280px; margin:0 auto; display:grid; grid-template-columns:minmax(0, 1fr); gap:18px; min-width:0; }
    /* Grid children must be allowed to shrink, or the long hourly strip / chart would stretch the page off-screen. */
    .wx-page > *, .wx-content > *, .wx-tiles > * { min-width:0; }

    /* ================= The sky ================= */
    /* The sky color is a see-through layer (::before) so the system's orange/green theme shows through it.
       --sky-op: lighter weather is more transparent; storms and night stay darker so white text stays readable. */
    .wx-scene { position:relative; isolation:isolate; overflow:hidden; border-radius:28px; color:#fff; padding:0 0 22px;
      background:transparent; border:1px solid rgba(255,255,255,.35); box-shadow:0 18px 40px rgba(120,60,0,.18); --sky-op:.4; }
    .wx-scene::before { content:''; position:absolute; inset:0; z-index:-1; pointer-events:none; background-image:var(--sky); opacity:var(--sky-op);
      background-size:100% 200%; animation:wxSkyShift 24s ease-in-out infinite alternate; }
    @keyframes wxSkyShift { from { background-position:0 0; } to { background-position:0 100%; } }
    .sky-clear.is-day   { --sky:linear-gradient(180deg,#1766c9 0%,#3a8ae8 30%,#79b8ff 60%,#b9dcff 100%); --sky-op:.3; }
    .sky-partly.is-day  { --sky:linear-gradient(180deg,#2d6fb8 0%,#4f8fd6 35%,#8fb9e6 70%,#c7dcf0 100%); --sky-op:.3; }
    .sky-cloudy.is-day, .sky-fog.is-day { --sky:linear-gradient(180deg,#4f6a86 0%,#6f8aa6 40%,#9db2c6 80%,#c2cfdb 100%); --sky-op:.3; }
    .sky-drizzle.is-day { --sky:linear-gradient(180deg,#44586f 0%,#627a93 45%,#8aa0b6 100%); --sky-op:.3; }
    .sky-rain.is-day    { --sky:linear-gradient(180deg,#2f4155 0%,#4a5f76 45%,#6f8499 100%); --sky-op:.42; }
    .sky-heavy.is-day   { --sky:linear-gradient(180deg,#1f2d3d 0%,#34475c 45%,#56697e 100%); --sky-op:.52; }
    .sky-thunder        { --sky:linear-gradient(180deg,#0e131d 0%,#1f2838 40%,#3a465c 100%); --sky-op:.72; }
    .is-night           { --sky:linear-gradient(180deg,#060b1f 0%,#0f1d45 40%,#243e78 80%,#35508a 100%); --sky-op:.72; }
    .is-night.sky-cloudy, .is-night.sky-fog, .is-night.sky-drizzle, .is-night.sky-rain, .is-night.sky-heavy { --sky:linear-gradient(180deg,#0b1020 0%,#1a2336 45%,#2f3b52 100%); --sky-op:.72; }

    .wx-layer { position:absolute; inset:0; pointer-events:none; }
    .wx-canvas { position:absolute; inset:0; width:100%; height:100%; pointer-events:none; z-index:1; }
    .wx-sunglow { position:absolute; top:-120px; right:-80px; width:460px; height:460px; border-radius:50%; z-index:0;
      background:radial-gradient(circle, rgba(255,241,168,.95) 0 14%, rgba(255,214,102,.55) 22%, rgba(255,214,102,.15) 45%, transparent 68%); animation:wxPulse 6s ease-in-out infinite; }
    .wx-sunrays { position:absolute; top:-260px; right:-220px; width:740px; height:740px; z-index:0; opacity:.35;
      background:repeating-conic-gradient(from 0deg, rgba(255,255,255,.55) 0 4deg, transparent 4deg 18deg);
      -webkit-mask:radial-gradient(circle, #000 18%, transparent 62%); mask:radial-gradient(circle, #000 18%, transparent 62%); animation:wxSpin 90s linear infinite; }
    .wx-moonglow { position:absolute; top:40px; right:90px; width:90px; height:90px; border-radius:50%; z-index:0;
      background:radial-gradient(circle at 35% 35%, #fffbe6 0 45%, #fde9a8 70%, #f7d774 100%); box-shadow:0 0 60px 20px rgba(255,244,196,.35), 0 0 140px 60px rgba(160,190,255,.18); animation:wxFloat 8s ease-in-out infinite; }
    .wx-moonglow::after { content:''; position:absolute; top:22px; left:48px; width:16px; height:16px; border-radius:50%; background:rgba(214,190,120,.35); box-shadow:-26px 20px 0 -3px rgba(214,190,120,.3), -8px 38px 0 -5px rgba(214,190,120,.28); }
    @keyframes wxSpin { to { transform:rotate(360deg); } }
    @keyframes wxPulse { 50% { transform:scale(1.06); opacity:.85; } }
    @keyframes wxFloat { 50% { transform:translateY(-8px); } }

    .wx-cloud-bg { position:absolute; left:0; z-index:0; filter:blur(3px); animation:wxDrift linear infinite; }
    .wx-cloud-bg svg { width:100%; height:auto; display:block; }
    @keyframes wxDrift { from { transform:translateX(-40vw); } to { transform:translateX(110vw); } }
    .is-night .wx-cloud-bg, .sky-rain .wx-cloud-bg { filter:blur(3px) brightness(.55); }
    .sky-heavy .wx-cloud-bg, .sky-thunder .wx-cloud-bg { filter:blur(3px) brightness(.32); }
    .wx-fogband { position:absolute; left:-20%; width:140%; height:120px; z-index:1; background:linear-gradient(90deg, transparent, rgba(255,255,255,.35), transparent); filter:blur(18px); animation:wxFog 26s ease-in-out infinite alternate; }
    @keyframes wxFog { to { transform:translateX(12%); } }
    .wx-flash { position:absolute; inset:0; z-index:2; background:rgba(235,240,255,.85); opacity:0; pointer-events:none; }
    .wx-flash.go { animation:wxFlash .9s ease-out; }
    @keyframes wxFlash { 0% { opacity:0; } 8% { opacity:.9; } 16% { opacity:.1; } 24% { opacity:.7; } 100% { opacity:0; } }
    .wx-bigbolt { position:absolute; z-index:2; width:90px; opacity:0; filter:drop-shadow(0 0 18px rgba(255,236,150,.9)); pointer-events:none; }
    .wx-bigbolt.go { animation:wxBolt .7s ease-out; }
    @keyframes wxBolt { 0%, 100% { opacity:0; } 10%, 40% { opacity:1; } 25% { opacity:.3; } }

    .wx-hero { position:relative; }
    .wx-skyline { position:absolute; left:-24px; right:-24px; bottom:-6px; height:80px; z-index:-1; pointer-events:none; }
    .wx-skyline svg { width:100%; height:100%; display:block; }
    .wx-skyline .bld { fill:rgba(8,18,38,.38); }
    .is-night .wx-skyline .bld { fill:rgba(3,7,18,.75); }
    .wx-skyline .win { fill:rgba(255,214,120,0); }
    .is-night .wx-skyline .win { fill:rgba(255,214,120,.85); animation:wxWin 7s ease-in-out infinite; }
    @keyframes wxWin { 50% { opacity:.55; } }

    /* ================= Content on the sky ================= */
    .wx-content { position:relative; z-index:3; padding:22px 24px 0; display:grid; grid-template-columns:minmax(0, 1fr); gap:16px; }
    .wx-topbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; }
    .wx-loc { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:999px; background:rgba(0,0,0,.22); backdrop-filter:blur(8px); font-weight:700; font-size:15px; }
    .wx-live { display:inline-flex; align-items:center; gap:7px; font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.9; }
    .wx-live i { width:8px; height:8px; border-radius:50%; background:#4ade80; box-shadow:0 0 0 0 rgba(74,222,128,.7); animation:wxLive 1.8s infinite; }
    @keyframes wxLive { 70% { box-shadow:0 0 0 10px rgba(74,222,128,0); } 100% { box-shadow:0 0 0 0 rgba(74,222,128,0); } }
    .wx-previews { display:flex; gap:6px; flex-wrap:wrap; align-items:center; font-size:12px; }
    .wx-previews a { color:#fff; text-decoration:none; padding:5px 10px; border-radius:999px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.22); }
    .wx-previews a.on, .wx-previews a:hover { background:rgba(255,255,255,.34); }
    .wx-preview-note { padding:8px 14px; border-radius:12px; background:rgba(245,158,11,.9); color:#1f1300; font-weight:700; font-size:13px; }

    .wx-hero { display:grid; justify-items:center; text-align:center; padding:0 0 62px; text-shadow:0 2px 16px rgba(0,0,0,.45), 0 0 2px rgba(0,0,0,.25); }
    .wx-hero .wxi { filter:drop-shadow(0 10px 18px rgba(0,0,0,.25)); }
    .wx-temp { font-size:clamp(60px, 8vw, 88px); font-weight:300; line-height:.95; letter-spacing:-2px; margin:0; }
    .wx-temp sup { font-size:.45em; font-weight:300; vertical-align:top; position:relative; top:.25em; letter-spacing:0; }
    .wx-desc { font-size:19px; font-weight:600; margin-top:4px; }
    .wx-hl { font-size:16px; opacity:.92; margin-top:4px; }
    .wx-feels { font-size:13px; opacity:.8; margin-top:2px; }

    .wx-alertbar { display:flex; align-items:center; gap:12px; padding:12px 16px; border-radius:16px; background:linear-gradient(90deg, rgba(217,45,32,.92), rgba(247,144,9,.9)); box-shadow:0 0 0 0 rgba(217,45,32,.6); animation:wxAlarm 2s infinite; font-weight:700; }
    .wx-alertbar i { font-size:20px; }
    @keyframes wxAlarm { 70% { box-shadow:0 0 0 14px rgba(217,45,32,0); } 100% { box-shadow:0 0 0 0 rgba(217,45,32,0); } }

    /* Background clouds only drift through the sky at the top, fading out before the cards. */
    .wx-cloudzone { z-index:0; bottom:auto; height:420px; -webkit-mask:linear-gradient(180deg,#000 70%,transparent); mask:linear-gradient(180deg,#000 70%,transparent); }
    /* Section tabs (one section shown at a time) */
    .wx-tabs { display:flex; gap:6px; flex-wrap:wrap; padding:6px; border-radius:18px; background:rgba(12,20,32,.18); border:1px solid rgba(255,255,255,.22); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); }
    .wx-tab { display:inline-flex; align-items:center; gap:7px; border:0; border-radius:12px; padding:9px 14px; background:transparent; color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; opacity:.85; transition:background .2s, opacity .2s; }
    .wx-tab:hover { background:rgba(255,255,255,.14); opacity:1; }
    .wx-tab[aria-selected="true"] { background:rgba(255,255,255,.95); color:#7a3e00; opacity:1; box-shadow:0 4px 12px rgba(0,0,0,.15); }
    .wx-tab:focus-visible { outline:2px solid #fff; outline-offset:2px; }
    .wx-tab-badge { display:inline-grid; place-items:center; min-width:20px; height:20px; padding:0 6px; border-radius:999px; color:#fff; font-size:11px; font-weight:800; }
    .wx-tab-badge.alert { background:#d92d20; }
    .wx-tab-temp { font-weight:800; opacity:.9; }
    .wx-hero[data-wx-section] { padding-top:14px; }
    [data-wx-section].wx-hidden { display:none !important; }
    [data-wx-section].wx-show { animation:wxSectionIn .35s ease; }
    @keyframes wxSectionIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:none; } }
    .wx-alerts-hint { display:none; text-align:center; font-size:13px; opacity:.9; padding:6px 0 2px; }
    .wx-alerts-hint.on { display:block; }
    @media (max-width:700px) { .wx-tab { padding:8px 10px; font-size:12px; } }
    .glass { background:rgba(70,38,8,.16); border:1px solid rgba(255,255,255,.22); border-radius:22px; backdrop-filter:blur(16px) saturate(140%); -webkit-backdrop-filter:blur(16px) saturate(140%); box-shadow:0 8px 24px rgba(0,0,0,.12); }
    .is-night .glass, .sky-rain .glass, .sky-heavy .glass, .sky-thunder .glass { background:rgba(255,255,255,.09); }
    .wx-threat h3, .layout .wx-threat h3 { display:block; text-align:left; }
    .wx-moonglow { top:96px; right:70px; }
    .glass-title { display:flex; align-items:center; gap:8px; font-size:11px; font-weight:700; letter-spacing:.09em; text-transform:uppercase; opacity:.78; padding:14px 18px 0; }
    .glass-title .right { margin-left:auto; text-transform:none; letter-spacing:0; font-weight:500; }

    /* Threat gauge */
    .wx-threat { display:grid; grid-template-columns:auto 1fr; gap:18px; align-items:center; padding:12px 20px 18px; }
    .wx-gauge { width:170px; }
    .wx-gauge .needle { transform-origin:85px 88px; transform:rotate(-90deg); animation:wxNeedle 1.6s cubic-bezier(.3,1.5,.5,1) .3s forwards; }
    @keyframes wxNeedle { to { transform:rotate(var(--angle)); } }
    .wx-threat h3, .layout .wx-threat h3 { margin:0; font-size:22px; color:#fff; background:none; padding:0; }
    .wx-threat p { margin:6px 0 0; font-size:14px; line-height:1.55; opacity:.92; }
    .wx-lvl-pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:800; color:#fff; margin-right:8px; vertical-align:middle; }

    /* Hourly strip */
    .wx-hours { display:flex; gap:4px; overflow-x:auto; padding:10px 12px 14px; scroll-snap-type:x mandatory; scrollbar-width:thin; scrollbar-color:rgba(255,255,255,.35) transparent; }
    .wx-hour { flex:0 0 68px; display:grid; justify-items:center; gap:4px; padding:10px 4px; border-radius:16px; scroll-snap-align:start; font-size:13px; }
    .wx-hour.now { background:rgba(255,255,255,.18); font-weight:700; }
    .wx-hour b { font-size:17px; font-weight:600; }
    .wx-hour small { color:#a5d8ff; font-weight:700; font-size:11px; min-height:14px; }

    /* Tiles */
    .wx-tiles { display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:14px; }
    .wx-tile { padding-bottom:16px; min-height:170px; display:flex; flex-direction:column; }
    .wx-tile .big { font-size:34px; font-weight:500; padding:8px 18px 0; }
    .wx-tile .big small { font-size:15px; opacity:.8; }
    .wx-tile .sub { padding:4px 18px 0; font-size:13px; opacity:.85; line-height:1.45; }
    .wx-tile .viz { display:flex; justify-content:center; padding-top:6px; }
    .wx-compass .needle { transform-origin:60px 60px; animation:wxWobble 3.5s ease-in-out infinite; }
    @keyframes wxWobble { 0%, 100% { transform:rotate(calc(var(--dir) - 6deg)); } 50% { transform:rotate(calc(var(--dir) + 6deg)); } }
    .wx-ring circle.val { transition:stroke-dashoffset 1.4s ease; }
    .wx-uvbar { position:relative; height:6px; margin:14px 18px 0; border-radius:3px; background:linear-gradient(90deg,#4ade80,#facc15,#fb923c,#ef4444,#a855f7); }
    .wx-uvbar i { position:absolute; top:50%; width:12px; height:12px; margin:-6px 0 0 -6px; border-radius:50%; background:#fff; box-shadow:0 0 0 3px rgba(0,0,0,.2); }
    .wx-sunarc .sundot { filter:drop-shadow(0 0 6px rgba(255,214,102,.95)); animation:wxPulse 3s ease-in-out infinite; transform-box:fill-box; transform-origin:center; }
    .wx-rainbar { height:6px; margin:14px 18px 0; border-radius:3px; background:rgba(255,255,255,.2); overflow:hidden; }
    .wx-rainbar i { display:block; height:100%; border-radius:3px; background:linear-gradient(90deg,#74c0fc,#4dabf7); }

    /* 3-day list */
    .wx-days { padding:6px 18px 12px; }
    .wx-dayrow { display:grid; grid-template-columns:96px 70px 44px 1fr 44px; align-items:center; gap:10px; padding:10px 0; border-top:1px solid rgba(255,255,255,.14); font-size:15px; }
    .wx-dayrow:first-child { border-top:0; }
    .wx-dayrow .pop { color:#a5d8ff; font-size:11px; font-weight:700; display:flex; align-items:center; gap:2px; }
    .wx-dayrow .lo { opacity:.7; text-align:right; }
    .wx-range { position:relative; height:6px; border-radius:3px; background:rgba(0,0,0,.2); }
    .wx-range i { position:absolute; top:0; bottom:0; border-radius:3px; background:linear-gradient(90deg,#74c0fc,#ffd43b,#ff922b); }
    .wx-range b { position:absolute; top:50%; width:10px; height:10px; margin:-5px 0 0 -5px; border-radius:50%; background:#fff; box-shadow:0 0 0 2px rgba(0,0,0,.25); }

    /* Chart */
    .wx-chart-wrap { padding:4px 10px 12px; overflow-x:auto; }
    .wx-chart-inner { min-width:880px; }
    .wx-icons, .wx-winds { display:grid; margin:0 58px 0 46px; justify-items:center; }
    .wx-winds span { font-size:11px; opacity:.85; line-height:1.2; text-align:center; }
    .wx-winds b { display:block; font-size:15px; }
    .wx-chart-box { position:relative; height:300px; }
    .wx-legend { display:flex; flex-wrap:wrap; gap:14px; padding:0 18px 14px; font-size:12px; opacity:.85; }
    .wx-legend i { display:inline-block; width:14px; height:4px; border-radius:2px; vertical-align:middle; margin-right:5px; }
    .wx-foot { font-size:11px; opacity:.75; text-align:center; padding:0 20px; }

    /* ================= Icons ================= */
    .wxi .wxi-rays { transform-box:fill-box; transform-origin:center; animation:wxSpin 14s linear infinite; }
    .wxi .wxi-cloud { animation:wxCloudBob 5s ease-in-out infinite; }
    .wxi .wxi-back { animation:wxCloudBob 6s ease-in-out -2s infinite reverse; }
    .wxi .wxi-drop { animation:wxDrop 1s linear infinite; }
    .wxi .wxi-bolt { animation:wxBoltIcon 2.4s steps(1) infinite; }
    .wxi .wxi-moon { transform-box:fill-box; transform-origin:center; animation:wxMoonTilt 7s ease-in-out infinite; }
    .wxi .wxi-fog { animation:wxFogIcon 3s ease-in-out infinite alternate; }
    @keyframes wxCloudBob { 50% { transform:translateX(2.5px); } }
    @keyframes wxDrop { 0% { transform:translateY(-5px); opacity:0; } 30% { opacity:1; } 100% { transform:translateY(6px); opacity:0; } }
    @keyframes wxBoltIcon { 0%, 60%, 100% { opacity:1; } 30%, 70% { opacity:.15; } }
    @keyframes wxMoonTilt { 50% { transform:rotate(-10deg); } }
    @keyframes wxFogIcon { to { transform:translateX(4px); } }
    .wxi-static * { animation:none !important; }

    /* ================= Alerts (below the sky, solid for readability) ================= */
    .wx-card { background:#fff; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 4px 14px rgba(16,24,40,.07); overflow:hidden; }
    .wx-card-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:16px 20px; border-bottom:1px solid #eef0f2; flex-wrap:wrap; }
    .wx-card-head h3, .layout .wx-card-head h3 { margin:0; font-size:17px; color:#1f2937; background:none; padding:0; }
    .wx-card-head small { color:#667085; font-size:12px; }
    .wx-alert-msg { padding:14px 20px 4px; color:#344054; font-size:14px; line-height:1.55; }
    .wx-table-wrap { overflow-x:auto; padding:6px 20px 18px; }
    .wx-table { width:100%; border-collapse:collapse; font-size:13px; min-width:640px; }
    .wx-table th { text-align:left; padding:10px; background:#f8fafb; color:#667085; font-size:11px; text-transform:uppercase; letter-spacing:.03em; }
    .wx-table td { padding:10px; border-top:1px solid #eef0f2; color:#344054; vertical-align:top; }
    .wx-pri { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:800; }
    .wx-pri.Critical { background:#fee4e2; color:#b42318; } .wx-pri.High { background:#ffead5; color:#b93815; }
    .wx-pri.Medium { background:#fef0c7; color:#93370d; } .wx-pri.Low { background:#f2f4f7; color:#475467; }
    .wx-empty { padding:22px 20px; color:#667085; font-size:14px; }
    .wx-tag { display:inline-block; padding:2px 8px; border-radius:6px; background:#eef4ff; color:#1d4ed8; font-size:11px; font-weight:700; }
    .wx-tag.rules { background:#f2f4f7; color:#475467; }
    details.wx-details summary { cursor:pointer; color:#c56a00; font-weight:700; }
    details.wx-details ul { margin:6px 0 0; padding-left:18px; }
    .wx-note { padding:0 20px 16px; color:#98a2b3; font-size:12px; }
    .wx-lvl-chip { display:inline-grid; place-items:center; width:28px; height:28px; border-radius:8px; color:#fff; font-weight:800; font-size:13px; }

    @media (max-width:700px) {
      .wx-content { padding:16px 14px 0; }
      .wx-threat { grid-template-columns:1fr; justify-items:center; text-align:center; }
      .wx-dayrow { grid-template-columns:70px 56px 36px 1fr 36px; font-size:14px; }
      .wx-skyline { left:-14px; right:-14px; }
    }
    @media (prefers-reduced-motion: reduce) {
      .wx-scene, .wx-scene::before, .wx-scene *, .wxi * { animation:none !important; }
      .wx-gauge .needle { transform:rotate(var(--angle)); }
    }
  </style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true"><defs>
  <linearGradient id="wxgSun" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFE066"/><stop offset="1" stop-color="#FF922B"/></linearGradient>
  <linearGradient id="wxgCloud" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffffff"/><stop offset="1" stop-color="#d3deea"/></linearGradient>
  <linearGradient id="wxgCloudDark" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#b8c4d6"/><stop offset="1" stop-color="#6f7f96"/></linearGradient>
  <linearGradient id="wxgMoon" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFF6CC"/><stop offset="1" stop-color="#FCC419"/></linearGradient>
</defs></svg>
<div class="layout">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="page-scroll-shell">
    <header style="background:#fff;border-bottom:1px solid #e0e0e0;padding:20px;">
      <h2 style="margin:0;font-size:24px;"><i class="fa-solid fa-cloud-sun-rain" style="color:var(--primary);margin-right:8px;"></i>Weather &amp; Alerts</h2>
      <p style="margin:4px 0 0;color:#667085;font-size:13px;">Live weather and 3-day forecast for South Caloocan, and the AI restocking alerts it triggers.</p>
    </header>
    <main class="main-content wx-main">
      <div class="wx-page">

      <?php if ($fc): ?>
      <section class="wx-scene <?= $scene_class ?>" id="wxScene" data-rain="<?= $scene['rain'] ?>" data-thunder="<?= $kind === 'thunder' ? 1 : 0 ?>" data-stars="<?= $is_day ? 0 : 1 ?>" data-wind="<?= (int)$cur['wind_speed_10m'] ?>">
        <?php if ($is_day && in_array($kind, ['clear', 'partly'], true)): ?><div class="wx-sunrays"></div><div class="wx-sunglow"></div><?php endif; ?>
        <?php if (!$is_day && in_array($kind, ['clear', 'partly'], true)): ?><div class="wx-moonglow"></div><?php endif; ?>
        <div class="wx-layer wx-cloudzone">
          <?php foreach ($clouds as $c): ?><div class="wx-cloud-bg" style="top:<?= $c['top'] ?>%;width:<?= $c['w'] ?>px;opacity:<?= $c['op'] ?>;animation-duration:<?= $c['dur'] ?>s;animation-delay:<?= $c['delay'] ?>s;"><svg viewBox="0 0 64 40"><path d="M14 38h36a12 12 0 0 0 1-23.9A17 17 0 0 0 18.4 10 13 13 0 0 0 14 38z" fill="#fff"/></svg></div><?php endforeach; ?>
        </div>
        <?php if ($kind === 'fog'): ?><div class="wx-fogband" style="top:180px;"></div><div class="wx-fogband" style="top:420px;animation-delay:-9s;"></div><?php endif; ?>
        <canvas class="wx-canvas" id="wxCanvas"></canvas>
        <div class="wx-flash" id="wxFlash"></div>
        <svg class="wx-bigbolt" id="wxBolt" viewBox="0 0 40 80" aria-hidden="true"><path d="M24 0L4 44h13L8 80l28-48H22L32 0z" fill="#fff6c2"/></svg>

        <div class="wx-content">
          <div class="wx-topbar">
            <span class="wx-loc"><i class="fa-solid fa-location-dot"></i> South Caloocan, Metro Manila</span>
            <span class="wx-live" title="Open-Meteo updates current conditions every 15 minutes"><i></i> Live · weather as of <?= e(date('g:i A', strtotime($cur['time']))) ?> · checked <?= e((new DateTime('@' . (int)$fc['_checked']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('g:i A')) ?></span>
          </div>
          <?php if ($is_admin_view): ?>
            <div class="wx-previews"><span style="opacity:.8;">Preview sky:</span><a href="weather.php" class="<?= $preview ? '' : 'on' ?>">Live</a><?php foreach ($previews as $k => $label): ?><a href="weather.php?preview=<?= $k ?>" class="<?= $preview === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach; ?></div>
          <?php endif; ?>
          <?php if ($preview): ?><div class="wx-preview-note"><i class="fa-solid fa-eye"></i> Preview only: this is not the real weather. The numbers below are still live. <a href="weather.php" style="color:#1f1300;">Back to live</a></div><?php endif; ?>
          <?php if ($active_alert): ?>
            <div class="wx-alertbar"><i class="fa-solid fa-triangle-exclamation"></i><span>ACTIVE WEATHER ALERT: <?= e($event['weather_condition']) ?><?= (int)$event['peak_signal'] ? ' · est. Signal No. ' . (int)$event['peak_signal'] : '' ?><?= $event['simulated'] ? ' (simulation)' : '' ?>. Restocking alerts have been sent to the barangays.</span></div>
          <?php endif; ?>

          <?php
            // Section tabs at the top: one section at a time, so the page stays short. Threat level opens first.
            $alert_count = $is_admin_view
                ? count(array_filter($event_alerts, function ($r) { return $r['status'] === 'ready'; }))
                : ($my_alert ? 1 : 0);
            $wx_tabs = [
                'threat' => ['fa-shield-halved', 'Threat level', '<span class="wx-tab-badge" style="background:' . $level_colors[$level] . '">' . $level . '</span>'],
                'now' => ['fa-cloud-sun', 'Now', '<span class="wx-tab-temp">' . round($cur['temperature_2m']) . '°</span>'],
                'hourly' => ['fa-regular fa-clock', 'Hourly', ''],
                'details' => ['fa-temperature-half', 'Details', ''],
                'forecast' => ['fa-regular fa-calendar', WEATHER_FORECAST_DAYS . '-day', ''],
                'chart' => ['fa-chart-line', 'Chart', ''],
                'alerts' => ['fa-boxes-stacked', $is_admin_view ? 'Barangay alerts' : 'My alert', $alert_count ? '<span class="wx-tab-badge alert">' . $alert_count . '</span>' : ''],
            ];
            if (!$chart['labels']) unset($wx_tabs['chart']);
          ?>
          <nav class="wx-tabs" role="tablist" aria-label="Weather sections">
            <?php foreach ($wx_tabs as $key => [$icon, $label, $badge]): ?>
              <button type="button" class="wx-tab" role="tab" data-wx-tab="<?= $key ?>" aria-controls="wx-<?= $key ?>" aria-selected="false"><i class="<?= strpos($icon, 'fa-regular') === 0 ? $icon : 'fa-solid ' . $icon ?>"></i> <?= e($label) ?><?= $badge ?></button>
            <?php endforeach; ?>
          </nav>

          <div class="wx-hero" data-wx-section="now" id="wx-now">
            <?= wx_icon($kind, $is_day, 88) ?>
            <p class="wx-temp"><?= round($cur['temperature_2m']) ?><sup>°C</sup></p>
            <div class="wx-desc"><?= e($desc) ?></div>
            <div class="wx-hl">H: <?= round($d['temperature_2m_max'][0]) ?>°&nbsp;&nbsp;L: <?= round($d['temperature_2m_min'][0]) ?>°</div>
            <div class="wx-feels">Feels like <?= round($cur['apparent_temperature']) ?>°</div>
            <div class="wx-skyline"><svg viewBox="0 0 1600 120" preserveAspectRatio="xMidYMax slice"><g class="bld"><?= $skyline ?></g><g class="win"><?= $windows ?></g></svg></div>
          </div>

          <div class="glass" data-wx-section="threat" id="wx-threat">
            <div class="glass-title"><i class="fa-solid fa-shield-halved"></i> Weather threat level<span class="right"><?= $last_reading ? 'Auto-checked every ' . WEATHER_FETCH_MINUTES . ' min · last ' . e(date('g:i A', strtotime($last_reading['fetched_at']))) : 'Waiting for the first check' ?></span></div>
            <div class="wx-threat">
              <svg class="wx-gauge" viewBox="0 0 170 116" aria-label="Threat level <?= $level ?> of 5">
                <?php for ($i = 0; $i < 6; $i++): $a0 = M_PI * (1 - $i / 6); $a1 = M_PI * (1 - ($i + 1) / 6); ?>
                  <path d="M<?= round(85 + 70 * cos($a0), 1) ?> <?= round(88 - 70 * sin($a0), 1) ?> A70 70 0 0 1 <?= round(85 + 70 * cos($a1), 1) ?> <?= round(88 - 70 * sin($a1), 1) ?>" stroke="<?= $level_colors[$i] ?>" stroke-width="14" fill="none" opacity="<?= $i <= $level ? 1 : .3 ?>"/>
                <?php endfor; ?>
                <g class="needle" style="--angle:<?= -75 + $level * 30 ?>deg"><line x1="85" y1="88" x2="85" y2="30" stroke="#fff" stroke-width="4" stroke-linecap="round"/><circle cx="85" cy="88" r="7" fill="#fff"/></g>
                <text x="85" y="112" text-anchor="middle" font-size="10" fill="rgba(255,255,255,.8)" font-weight="700">LEVEL <?= $level ?> / 5</text>
              </svg>
              <div>
                <h3><span class="wx-lvl-pill" style="background:<?= $level_colors[$level] ?>"><?= $level ?></span><?= e($level_names[$level]) ?><?= $last_reading && $level ? ' · ' . e($last_reading['weather_condition']) : '' ?><?= $last_reading && (int)$last_reading['signal_number'] ? ' · est. Signal No. ' . (int)$last_reading['signal_number'] : '' ?></h3>
                <p>
                  <?php if ($last_reading): ?>
                    <?php if (!empty($last_reading['expected_at'])): ?><b>Forecast, not happening now:</b> expected around <b><?= e(date('l, M j, g A', strtotime($last_reading['expected_at']))) ?></b>.<?php endif; ?>
                    Next <?= WEATHER_FORECAST_DAYS ?> days: winds up to <?= e($last_reading['wind_speed_kph']) ?> km/h, about <?= e($last_reading['rainfall_mm']) ?> mm of rain in total.
                    <?php if ($event_open): ?>Active weather event since <?= e(date('M j, g:i A', strtotime($event['started_at']))) ?>: restocking alerts were sent.
                    <?php elseif ($level > 0): ?>Advisory only: barangays get restocking alerts for an estimated wind signal (39 km/h and up) or heavy (orange/red) rain.
                    <?php else: ?>All clear. No restocking alerts are being sent.<?php endif; ?>
                  <?php else: ?>The engine has not checked the weather yet.<?php endif; ?>
                </p>
              </div>
            </div>
          </div>

          <div class="glass" data-wx-section="hourly" id="wx-hourly">
            <div class="glass-title"><i class="fa-regular fa-clock"></i> Hourly forecast</div>
            <div class="wx-hours">
              <?php foreach ($hourly as $i => $hr): ?>
                <div class="wx-hour<?= $i === 0 ? ' now' : '' ?>"><span><?= e($hr['label']) ?></span><?= wx_icon($hr['kind'], $hr['day'], 34, $i > 8) ?><small><?= $hr['pop'] >= 20 ? $hr['pop'] . '%' : '' ?></small><b><?= $hr['temp'] ?>°</b></div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="wx-tiles" data-wx-section="details" id="wx-details">
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-wind"></i> Wind</div>
              <div class="viz"><svg class="wx-compass" width="120" height="120" viewBox="0 0 120 120" aria-label="Wind from <?= wx_compass($cur['wind_direction_10m']) ?>">
                <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(255,255,255,.25)" stroke-width="2"/>
                <?php for ($t = 0; $t < 360; $t += 30): $r = deg2rad($t); ?><line x1="<?= round(60 + 46 * sin($r), 1) ?>" y1="<?= round(60 - 46 * cos($r), 1) ?>" x2="<?= round(60 + 52 * sin($r), 1) ?>" y2="<?= round(60 - 52 * cos($r), 1) ?>" stroke="rgba(255,255,255,.45)" stroke-width="2"/><?php endfor; ?>
                <g font-size="11" font-weight="700" fill="rgba(255,255,255,.85)" text-anchor="middle"><text x="60" y="26">N</text><text x="98" y="64">E</text><text x="60" y="102">S</text><text x="22" y="64">W</text></g>
                <g class="needle" style="--dir:<?= ((int)$cur['wind_direction_10m'] + 180) % 360 ?>deg"><path d="M60 22 L67 62 L60 56 L53 62 Z" fill="#fff"/><path d="M60 98 L64 62 L60 66 L56 62 Z" fill="rgba(255,255,255,.4)"/></g>
                <text x="60" y="64" text-anchor="middle" font-size="13" font-weight="800" fill="#fff" dy="18"></text>
              </svg></div>
              <div class="sub" style="text-align:center;"><b style="font-size:20px;"><?= round($cur['wind_speed_10m']) ?> km/h</b> from the <?= wx_compass($cur['wind_direction_10m']) ?><br>Gusts <?= round($cur['wind_gusts_10m'] ?? 0) ?> km/h</div>
            </div>
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-droplet"></i> Humidity</div>
              <div class="viz"><svg class="wx-ring" width="120" height="120" viewBox="0 0 120 120"><circle cx="60" cy="60" r="44" fill="none" stroke="rgba(255,255,255,.2)" stroke-width="10"/>
                <circle class="val" cx="60" cy="60" r="44" fill="none" stroke="#74c0fc" stroke-width="10" stroke-linecap="round" stroke-dasharray="276.5" stroke-dashoffset="276.5" data-offset="<?= round(276.5 * (1 - $hum / 100), 1) ?>" transform="rotate(-90 60 60)"/>
                <text x="60" y="68" text-anchor="middle" font-size="26" font-weight="600" fill="#fff"><?= $hum ?>%</text></svg></div>
              <div class="sub" style="text-align:center;"><?= e($hum_label) ?></div>
            </div>
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-temperature-half"></i> Feels like</div>
              <div class="big"><?= round($cur['apparent_temperature']) ?>°</div>
              <div class="sub"><?= $cur['apparent_temperature'] > $cur['temperature_2m'] + 1 ? 'Feels hotter than the actual ' . round($cur['temperature_2m']) . '° because of the humidity. Stay hydrated.' : 'Similar to the actual temperature.' ?></div>
            </div>
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-cloud-rain"></i> Rain today</div>
              <div class="big"><?= e($d['precipitation_sum'][0]) ?> <small>mm</small></div>
              <div class="sub"><?= (int)$d['precipitation_probability_max'][0] ?>% chance of rain<?= $cur['precipitation'] > 0 ? ' · ' . e($cur['precipitation']) . ' mm falling now' : '' ?></div>
              <div class="wx-rainbar"><i style="width:<?= (int)$d['precipitation_probability_max'][0] ?>%"></i></div>
            </div>
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-sun"></i> UV index</div>
              <div class="big"><?= round($uv) ?> <small><?= e($uv_label) ?></small></div>
              <div class="sub"><?= $uv >= 6 ? 'Use sun protection 10 AM to 4 PM.' : 'Low risk for most people.' ?></div>
              <div class="wx-uvbar"><i style="left:<?= round(min(1, $uv / 11) * 100) ?>%"></i></div>
            </div>
            <div class="glass wx-tile">
              <div class="glass-title"><i class="fa-solid fa-sun-plant-wilt"></i> Sunrise &amp; sunset</div>
              <div class="viz"><svg class="wx-sunarc" width="140" height="80" viewBox="0 0 140 80">
                <path d="M14 66 A56 56 0 0 1 126 66" fill="none" stroke="rgba(255,255,255,.3)" stroke-width="2" stroke-dasharray="4 5"/>
                <line x1="4" y1="66" x2="136" y2="66" stroke="rgba(255,255,255,.35)" stroke-width="1.5"/>
                <?php if ($now_ts >= $sunrise && $now_ts <= $sunset): ?><circle class="sundot" cx="<?= $sun_x ?>" cy="<?= $sun_y ?>" r="7" fill="#FFD43B"/><?php endif; ?>
              </svg></div>
              <div class="sub" style="display:flex;justify-content:space-between;"><span><i class="fa-solid fa-arrow-up"></i> <?= e(date('g:i A', $sunrise)) ?></span><span><i class="fa-solid fa-arrow-down"></i> <?= e(date('g:i A', $sunset)) ?></span></div>
            </div>
          </div>

          <div class="glass" data-wx-section="forecast" id="wx-forecast">
            <div class="glass-title"><i class="fa-regular fa-calendar"></i> <?= WEATHER_FORECAST_DAYS ?>-day forecast</div>
            <div class="wx-days">
              <?php foreach ($d['time'] as $i => $day): $lo = (float)$d['temperature_2m_min'][$i]; $hi = (float)$d['temperature_2m_max'][$i]; $pop = (int)$d['precipitation_probability_max'][$i]; ?>
                <div class="wx-dayrow">
                  <b><?= $i === 0 ? 'Today' : e(date('l', strtotime($day))) ?></b>
                  <span style="display:flex;align-items:center;gap:4px;"><?= wx_icon(wx_kind($d['weather_code'][$i]), true, 30, true) ?><span class="pop"><?= $pop >= 20 ? $pop . '%' : '' ?></span></span>
                  <span class="lo"><?= round($lo) ?>°</span>
                  <span class="wx-range" title="<?= e(wx_describe($d['weather_code'][$i])) ?> · <?= e($d['precipitation_sum'][$i]) ?> mm · wind up to <?= round($d['wind_speed_10m_max'][$i]) ?> km/h">
                    <i style="left:<?= round(($lo - $tmin) / $tspan * 100) ?>%;right:<?= round(($tmax - $hi) / $tspan * 100) ?>%"></i>
                    <?php if ($i === 0): ?><b style="left:<?= round(max(0, min(1, ((float)$cur['temperature_2m'] - $tmin) / $tspan)) * 100) ?>%"></b><?php endif; ?>
                  </span>
                  <span><?= round($hi) ?>°</span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <?php if ($chart['labels']): ?>
          <div class="glass" data-wx-section="chart" id="wx-chart">
            <div class="glass-title"><i class="fa-solid fa-chart-line"></i> Forecast chart<span class="right">Every 3 hours</span></div>
            <div class="wx-chart-wrap"><div class="wx-chart-inner">
              <div class="wx-icons" style="grid-template-columns:repeat(<?= count($chart['kinds']) ?>,1fr);"><?php foreach ($chart['kinds'] as $k => $kk): ?><?= wx_icon($kk, $chart['days'][$k], 28, true) ?><?php endforeach; ?></div>
              <div class="wx-chart-box"><canvas id="wxChart" aria-label="Temperature, rainfall and humidity forecast"></canvas></div>
              <div class="wx-winds" style="grid-template-columns:repeat(<?= count($chart['wind']) ?>,1fr);"><?php foreach ($chart['wind'] as $k => $w): ?><span title="Wind from <?= wx_compass($chart['wind_dir'][$k]) ?>"><b style="transform:rotate(<?= ($chart['wind_dir'][$k] + 180) % 360 ?>deg);display:inline-block;">↑</b><?= $w ?></span><?php endforeach; ?></div>
            </div></div>
            <div class="wx-legend"><span><i style="background:#ff8787"></i>Temperature (°C)</span><span><i style="background:#74c0fc;height:8px"></i>Rainfall per 3 h (mm)</span><span><i style="background:#8ce99a"></i>Humidity (%)</span><span>↑ Wind direction &amp; speed (km/h)</span></div>
          </div>
          <?php endif; ?>
          <p class="wx-alerts-hint" id="wxAlertsHint"><i class="fa-solid fa-arrow-down"></i> The restocking alerts are shown below.</p>
          <p class="wx-foot">Source: Open-Meteo forecast for South Caloocan. Signal numbers are estimated from forecast wind speed using PAGASA's wind bands; always follow official PAGASA bulletins.</p>
        </div>
      </section>
      <?php else: ?>
        <section class="wx-card"><div class="wx-empty"><i class="fa-solid fa-triangle-exclamation"></i> The weather service can't be reached right now. Please try again in a few minutes.</div></section>
      <?php endif; ?>

      <?php if (!$is_admin_view): $a = $my_alert ? json_decode($my_alert['alert_json'], true) : null; ?>
        <section class="wx-card" data-wx-section="alerts" id="wx-alerts">
          <div class="wx-card-head"><h3><i class="fa-solid fa-boxes-stacked" style="color:#ea7a00;"></i> Your restocking alert</h3>
            <?php if ($a): ?><small>Issued <?= e(date('M j, g:i A', strtotime($my_alert['processed_at']))) ?> · <span class="wx-tag <?= $my_alert['source'] === 'ai' ? '' : 'rules' ?>"><?= $my_alert['source'] === 'ai' ? 'AI recommendation' : 'Rule-based' ?></span> · confidence <?= round($a['confidence'] * 100) ?>%</small><?php endif; ?></div>
          <?php if ($a): ?>
            <div class="wx-alert-msg"><strong><?= e($a['popup_title']) ?></strong><br><?= e($a['popup_message']) ?> <span style="color:#667085;"><?= e($a['weather_summary']) ?></span></div>
            <div class="wx-table-wrap"><table class="wx-table">
              <thead><tr><th>Item</th><th>Category</th><th>You have</th><th>Stock up to</th><th>Priority</th><th>Why</th></tr></thead>
              <tbody><?php foreach ($a['items_to_stock'] as $it): ?><tr>
                <td><strong><?= e($it['item_name']) ?></strong></td><td><?= e($it['category']) ?></td>
                <td><?= (int)$it['current_stock'] ?> <?= e($it['unit_of_measure']) ?></td><td><b><?= (int)$it['recommended_stock'] ?> <?= e($it['unit_of_measure']) ?></b></td>
                <td><span class="wx-pri <?= e($it['priority']) ?>"><?= e($it['priority']) ?></span></td><td><?= e($it['reason']) ?></td>
              </tr><?php endforeach; ?></tbody>
            </table></div>
            <p class="wx-note">After restocking, <a href="user_report.php">update your equipment report</a> so the DRRM Office sees your new stock.</p>
          <?php else: ?>
            <div class="wx-empty">No restocking alert for your barangay right now. When a real weather threat is forecast, your recommended supplies will appear here and as a pop-up.</div>
          <?php endif; ?>
        </section>
      <?php else: ?>
        <section class="wx-card" data-wx-section="alerts" id="wx-alerts">
          <div class="wx-card-head"><h3><i class="fa-solid fa-boxes-stacked" style="color:#ea7a00;"></i> Barangay restocking alerts</h3>
            <small><?= $event ? 'Weather event: ' . e($event['weather_condition']) . ' · started ' . e(date('M j, g:i A', strtotime($event['started_at']))) . ($event_open ? ' · active' : ' · ended') : 'No weather events yet' ?></small></div>
          <?php if ($event_alerts): ?>
            <div class="wx-table-wrap"><table class="wx-table">
              <thead><tr><th>Barangay</th><th>Status</th><th>Items to restock</th><th>Source</th><th>Issued</th></tr></thead>
              <tbody><?php foreach ($event_alerts as $row): $a = $row['alert_json'] ? json_decode($row['alert_json'], true) : null; $items = $a['items_to_stock'] ?? []; $crit = count(array_filter($items, function ($x) { return $x['priority'] === 'Critical'; })); ?><tr>
                <td><strong><?= e(barangay_label($row['barangay'])) ?></strong></td>
                <td><?= $row['status'] === 'ready' ? '<span class="wx-pri High">Alert sent</span>' : ($row['status'] === 'queued' ? '<span class="wx-pri Low">Preparing…</span>' : '<span class="wx-pri Low">No restock needed</span>') ?></td>
                <td><?php if ($items): ?><details class="wx-details"><summary><?= count($items) ?> item(s)<?= $crit ? ' · ' . $crit . ' critical' : '' ?></summary><ul><?php foreach ($items as $it): ?><li><?= e($it['item_name']) ?>: <?= (int)$it['current_stock'] ?> → <b><?= (int)$it['recommended_stock'] ?></b> <?= e($it['unit_of_measure']) ?> <span class="wx-pri <?= e($it['priority']) ?>"><?= e($it['priority']) ?></span></li><?php endforeach; ?></ul></details><?php else: ?>-<?php endif; ?></td>
                <td><?= $row['source'] ? '<span class="wx-tag ' . ($row['source'] === 'ai' ? '' : 'rules') . '">' . ($row['source'] === 'ai' ? 'AI' : 'Rule-based') . '</span>' . ($a ? ' ' . round($a['confidence'] * 100) . '%' : '') : '-' ?></td>
                <td><?= $row['processed_at'] ? e(date('M j, g:i A', strtotime($row['processed_at']))) : '-' ?></td>
              </tr><?php endforeach; ?></tbody>
            </table></div>
          <?php else: ?>
            <div class="wx-empty">No restocking alerts yet. They are created automatically when a real weather threat is forecast.</div>
          <?php endif; ?>
          <?php if ($events_history): ?>
            <div class="wx-card-head" style="border-top:1px solid #eef0f2;"><h3 style="font-size:15px;">Recent weather events</h3></div>
            <div class="wx-table-wrap"><table class="wx-table">
              <thead><tr><th>Event</th><th>Peak level</th><th>Started</th><th>Ended</th></tr></thead>
              <tbody><?php foreach ($events_history as $ev): ?><tr>
                <td><?= e($ev['weather_condition']) ?><?= $ev['simulated'] ? ' <span class="wx-tag rules">simulation</span>' : '' ?></td>
                <td><span class="wx-lvl-chip" style="background:<?= $level_colors[(int)$ev['peak_level']] ?>"><?= (int)$ev['peak_level'] ?></span><?= (int)$ev['peak_signal'] ? ' est. Signal ' . (int)$ev['peak_signal'] : '' ?></td>
                <td><?= e(date('M j, g:i A', strtotime($ev['started_at']))) ?></td>
                <td><?= $ev['ended_at'] ? e(date('M j, g:i A', strtotime($ev['ended_at']))) : '<b>Active</b>' ?></td>
              </tr><?php endforeach; ?></tbody>
            </table></div>
          <?php endif; ?>
        </section>

      <?php endif; ?>
      </div>
    </main>
  </div>
</div>
<?php if ($fc): ?>
<script>
// The living sky: falling rain, twinkling stars and lightning, drawn on a canvas over the scene.
(function () {
  var scene = document.getElementById('wxScene'), canvas = document.getElementById('wxCanvas');
  if (!scene || !canvas) return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Humidity ring fills in
  var ring = scene.querySelector('.wx-ring .val');
  if (ring) setTimeout(function () { ring.style.strokeDashoffset = ring.getAttribute('data-offset'); }, 250);
  if (reduce) { if (ring) ring.style.strokeDashoffset = ring.getAttribute('data-offset'); return; }

  var ctx = canvas.getContext('2d');
  var rainCount = +scene.dataset.rain, stars = scene.dataset.stars === '1', thunder = scene.dataset.thunder === '1';
  var slant = Math.min(0.35, (+scene.dataset.wind || 0) / 100) + 0.08;
  var W, H, drops = [], starList = [];
  function size() {
    W = canvas.width = scene.clientWidth;
    H = canvas.height = scene.clientHeight;
    drops = []; starList = [];
    var density = W / 1200;
    for (var i = 0; i < Math.round(rainCount * density); i++) drops.push(newDrop(true));
    if (stars) for (var s = 0; s < Math.round(140 * density); s++) starList.push({ x: Math.random() * W, y: Math.random() * Math.min(H, 520), r: Math.random() * 1.4 + .3, p: Math.random() * Math.PI * 2, sp: .01 + Math.random() * .03 });
  }
  function newDrop(anywhere) {
    return { x: Math.random() * (W + 200) - 100, y: anywhere ? Math.random() * H : -20 - Math.random() * 200, len: 12 + Math.random() * 16, v: 11 + Math.random() * 9, a: .18 + Math.random() * .32 };
  }
  var splashes = [];
  function frame() {
    ctx.clearRect(0, 0, W, H);
    if (starList.length) {
      for (var s = 0; s < starList.length; s++) {
        var st = starList[s]; st.p += st.sp;
        ctx.globalAlpha = .35 + .65 * Math.abs(Math.sin(st.p));
        ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(st.x, st.y, st.r, 0, 6.283); ctx.fill();
      }
      ctx.globalAlpha = 1;
    }
    if (drops.length) {
      ctx.lineCap = 'round'; ctx.lineWidth = 1.3;
      for (var i = 0; i < drops.length; i++) {
        var d = drops[i];
        ctx.strokeStyle = 'rgba(200,225,255,' + d.a + ')';
        ctx.beginPath(); ctx.moveTo(d.x, d.y); ctx.lineTo(d.x - d.len * slant, d.y + d.len); ctx.stroke();
        d.y += d.v; d.x -= d.v * slant;
        if (d.y > H) { if (Math.random() < .25) splashes.push({ x: d.x, y: H - 4, r: 1, a: .5 }); drops[i] = newDrop(false); }
      }
      for (var k = splashes.length - 1; k >= 0; k--) {
        var sp = splashes[k]; sp.r += .6; sp.a -= .04;
        if (sp.a <= 0) { splashes.splice(k, 1); continue; }
        ctx.strokeStyle = 'rgba(200,225,255,' + sp.a + ')'; ctx.beginPath(); ctx.ellipse(sp.x, sp.y, sp.r * 2, sp.r * .6, 0, 0, 6.283); ctx.stroke();
      }
    }
    requestAnimationFrame(frame);
  }
  size();
  window.addEventListener('resize', size);
  if (drops.length || starList.length) requestAnimationFrame(frame);

  // Lightning: a flash of the whole sky, sometimes with a visible bolt, every few seconds.
  if (thunder) {
    var flash = document.getElementById('wxFlash'), bolt = document.getElementById('wxBolt');
    (function strike() {
      setTimeout(function () {
        flash.classList.remove('go'); void flash.offsetWidth; flash.classList.add('go');
        if (Math.random() < .7) {
          bolt.style.left = (10 + Math.random() * 75) + '%'; bolt.style.top = (Math.random() * 60) + 'px';
          bolt.style.transform = 'scale(' + (0.8 + Math.random() * 1.2) + ') rotate(' + (Math.random() * 20 - 10) + 'deg)';
          bolt.classList.remove('go'); void bolt.offsetWidth; bolt.classList.add('go');
        }
        strike();
      }, 2500 + Math.random() * 6000);
    })();
  }
})();
</script>
<?php if ($chart['labels']): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
// Drawn the first time the Chart tab is opened (a chart drawn while hidden would get the wrong size).
window.wxInitChart = function () {
  if (!window.Chart || window.wxChartDone) return;
  window.wxChartDone = true;
  var D =<?= json_encode(['labels' => $chart['labels'], 'temp' => $chart['temp'], 'rain' => $chart['rain'], 'humidity' => $chart['humidity'], 'wind' => $chart['wind'], 'pop' => $chart['pop']], JSON_UNESCAPED_UNICODE) ?>;
  var white = 'rgba(255,255,255,.85)', grid = 'rgba(255,255,255,.12)';
  // Prints each rainfall value above its bar, like the PAGASA chart.
  var barValues = { id: 'barValues', afterDatasetsDraw: function (chart) {
    var ctx = chart.ctx, meta = chart.getDatasetMeta(1);
    ctx.save(); ctx.font = '700 11px sans-serif'; ctx.fillStyle = '#fff'; ctx.textAlign = 'center';
    meta.data.forEach(function (bar, i) { var v = D.rain[i]; if (v > 0) ctx.fillText(v, bar.x, bar.y - 4); });
    ctx.restore();
  } };
  new Chart(document.getElementById('wxChart'), {
    data: {
      labels: D.labels,
      datasets: [
        { type: 'line', label: 'Temperature (°C)', data: D.temp, borderColor: '#ff8787', backgroundColor: 'rgba(255,135,135,.18)', fill: true, borderWidth: 2.5, pointRadius: 2.5, pointBackgroundColor: '#ff8787', tension: .35, yAxisID: 'y' },
        { type: 'bar', label: 'Rainfall (mm / 3 h)', data: D.rain, backgroundColor: 'rgba(116,192,252,.85)', borderRadius: 4, yAxisID: 'yRain', barPercentage: .9, categoryPercentage: .9 },
        { type: 'line', label: 'Humidity (%)', data: D.humidity, borderColor: '#8ce99a', borderWidth: 2, borderDash: [5, 4], pointRadius: 0, tension: .35, yAxisID: 'yHum' }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      animation: { duration: 1400, easing: 'easeOutQuart' },
      plugins: {
        legend: { display: false },
        tooltip: { backgroundColor: 'rgba(15,23,42,.9)', callbacks: {
          title: function (items) { var l = D.labels[items[0].dataIndex]; return l[0] + ' ' + l[1]; },
          afterBody: function (items) { var i = items[0].dataIndex; return ['Rain chance: ' + D.pop[i] + '%', 'Wind: ' + D.wind[i] + ' km/h']; }
        } }
      },
      scales: {
        x: { grid: { color: grid }, ticks: { font: { size: 11 }, color: white, maxRotation: 0, autoSkip: false } },
        y: { position: 'left', suggestedMin: 20, suggestedMax: 40, title: { display: true, text: 'Temperature (°C)', color: '#ffa8a8' }, ticks: { color: '#ffa8a8' }, grid: { color: grid } },
        yRain: { position: 'left', display: false, beginAtZero: true, suggestedMax: 10, grace: '25%' },
        yHum: { position: 'right', min: 40, max: 100, title: { display: true, text: 'Humidity (%)', color: '#b2f2bb' }, ticks: { color: '#b2f2bb' }, grid: { display: false } }
      }
    },
    plugins: [barValues]
  });
};
</script>
<?php endif; ?>
<script>
// Section tabs: shows one section at a time. Opens on Threat level (or the tab in the address, e.g. weather.php#hourly).
// The alerts card sits below the sky, so that tab scrolls to it.
(function () {
  var tabs = [].slice.call(document.querySelectorAll('[data-wx-tab]'));
  var sections = [].slice.call(document.querySelectorAll('[data-wx-section]'));
  if (!tabs.length) return;
  var names = tabs.map(function (t) { return t.getAttribute('data-wx-tab'); });
  var hint = document.getElementById('wxAlertsHint');
  function show(name, scroll) {
    if (names.indexOf(name) === -1) name = 'threat';
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-wx-tab') === name;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
    });
    sections.forEach(function (s) {
      var on = s.getAttribute('data-wx-section') === name;
      s.classList.toggle('wx-hidden', !on);
      s.classList.remove('wx-show');
      if (on) { void s.offsetWidth; s.classList.add('wx-show'); }
    });
    if (hint) hint.classList.toggle('on', name === 'alerts');
    if (name === 'chart' && window.wxInitChart) window.wxInitChart();
    if (name === 'alerts' && scroll) { var card = document.getElementById('wx-alerts'); if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    if (history.replaceState) history.replaceState(null, '', '#' + name);
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.getAttribute('data-wx-tab'), true); });
    t.addEventListener('keydown', function (e) {
      var d = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
      if (!d) return;
      var next = tabs[(i + d + tabs.length) % tabs.length];
      next.focus(); show(next.getAttribute('data-wx-tab'), true);
    });
  });
  var start = (location.hash || '').replace('#', '');
  show(names.indexOf(start) === -1 ? 'threat' : start, false);
})();
</script>
<?php endif; ?>
</body>
</html>
