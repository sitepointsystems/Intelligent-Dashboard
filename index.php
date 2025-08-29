<?php
declare(strict_types=1);

$isFallback = false;

/* ==========================
   CONFIG FOR PROPERTIES SOURCE
   ========================== */
//const PROP_WEBHOOK_URL = 'https://virtuaix.com/webhook/77466bf0-3eb9-4076-ac31-0026a50de0f2';
// Replace with:
const PROP_FILE = __DIR__ . '/ga_properties.json'; // default
if (getenv('PROP_FILE')) {
  define('PROP_FILE', getenv('PROP_FILE'));
} else {
  define('PROP_FILE', __DIR__ . '/ga_properties.json');
}
const PROP_TIMEOUT     = 30;

/* ---------- Utilities ---------- */
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function json_safe_decode(?string $txt) {
  if (!is_string($txt) || trim($txt)==='') return null;
  $d = json_decode($txt, true);
  return is_array($d) ? $d : null;
}
function curl_get(string $url, int $timeout = PROP_TIMEOUT): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => ['Accept: application/json, */*;q=0.1']
  ]);
  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  return [$info['http_code'] ?? 0, $body, $err, $info];
}

/**
 * Normalize properties JSON into a flat array:
 * [
 *   ['accountId'=>'123','propertyId'=>'properties/999','displayName'=>'Site A'],
 *   ...
 * ]
 * Accepts shapes:
 *  - { accounts_and_properties: [ {accountId,propertyId,displayName}, ... ] }
 *  - { accounts: [ { accountId, properties: [ {propertyId/displayName or name/displayName}, ... ] } ] }
 *  - raw array of items with keys above
 */
function normalize_properties(array $raw): array {
  $out = [];
  if (isset($raw['accounts_and_properties']) && is_array($raw['accounts_and_properties'])) {
    foreach ($raw['accounts_and_properties'] as $p) {
      if (!is_array($p)) continue;
      $out[] = [
        'accountId'   => (string)($p['accountId'] ?? ''),
        'propertyId'  => (string)($p['propertyId'] ?? $p['name'] ?? ''),
        'displayName' => (string)($p['displayName'] ?? '')
      ];
    }
    return $out;
  }
  if (isset($raw['accounts']) && is_array($raw['accounts'])) {
    foreach ($raw['accounts'] as $acc) {
      $accId = (string)($acc['accountId'] ?? '');
      $props = $acc['properties'] ?? [];
      if (!is_array($props)) continue;
      foreach ($props as $p) {
        $out[] = [
          'accountId'   => $accId,
          'propertyId'  => (string)($p['propertyId'] ?? $p['name'] ?? ''),
          'displayName' => (string)($p['displayName'] ?? '')
        ];
      }
    }
    return $out;
  }
  if (array_is_list($raw)) {
    foreach ($raw as $p) {
      if (!is_array($p)) continue;
      $out[] = [
        'accountId'   => (string)($p['accountId'] ?? ''),
        'propertyId'  => (string)($p['propertyId'] ?? $p['name'] ?? ''),
        'displayName' => (string)($p['displayName'] ?? '')
      ];
    }
    return $out;
  }
  return $out;
}

/** Fetch and save properties file. Returns [success(bool), data(array), msg(string)] */
function fetch_and_save_properties(string $url, string $file): array {
  [$code, $body, $err] = curl_get($url);
  if ($code >= 400 || $body === false) {
    return [false, [], "Failed to fetch properties ($code): $err"];
  }
  $parsed = json_safe_decode((string)$body);
  if (!$parsed) {
    return [false, [], "Webhook did not return JSON."];
  }
  $flat = normalize_properties($parsed);
  if (!$flat) {
    return [false, [], "No properties found in response."];
  }
  $ok = @file_put_contents($file, json_encode(['accounts_and_properties'=>$flat], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  if ($ok === false) {
    return [false, $flat, "Could not write to ".basename($file).". Check permissions."];
  }
  return [true, $flat, "OK"];
}

/* ----- Handle property selection via POST (remove URL param) ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['property'])) {
    // Accept "properties%2F250097593" or "properties/250097593"
    $rawSel = rawurldecode((string)$_POST['property']);
    setcookie('ga_property', $rawSel, time() + 60*60*24*365, '/');

    // Clean redirect (no URL params)
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    header("Location: $base", true, 303); // prevent resubmission
    exit;
} else {
    require_once('auth.php');
}


/**
 * Input sources priority:
 * 1) ?file=dashboard.json (read from same directory)
 * 2) multipart upload 'file' or 'jsonfile'
 * 3) POST/GET 'json' parameter
 * 4) raw body
 * 5) example.json fallback (if present)
 * 6) built-in sample
 */
function get_json_input(): string {
    global $isFallback;
    // 1) Local file by query (useful for uploaded dashboard.json)
    if (!empty($_GET['file'])) {
        $fname = basename((string)$_GET['file']);
        $path = __DIR__ . '/' . $fname;
        if (is_readable($path)) return (string)file_get_contents($path);
    }
    // 2) Multipart upload
    foreach (['file','jsonfile'] as $fld) {
        if (!empty($_FILES[$fld]['tmp_name']) && is_uploaded_file($_FILES[$fld]['tmp_name'])) {
            $txt = file_get_contents($_FILES[$fld]['tmp_name']);
            if ($txt !== false && trim((string)$txt) !== '') return (string)$txt;
        }
    }
    // 3) json param
    $raw = $_POST['json'] ?? $_GET['json'] ?? null;
    if ($raw && trim((string)$raw) !== '') return (string)$raw;
    // 4) raw body
    $raw = file_get_contents('php://input');
    if ($raw && trim((string)$raw) !== '') return (string)$raw;
    // 5) example.json fallback
    $ex = __DIR__ . '/example.json';
    if (is_readable($ex)) return (string)file_get_contents($ex);
    // 6) built-in sample (schema-compliant)
    $isFallback = true;
    return '{
      "version":"1.0",
      "user_question":"Show me last 14 days GA + Ads, compare to previous period, highlight what\'s driving ROAS.",
      "theme":{"mode":"dark","accent":"#27E1FF","brand":"Intelligent Dashboard"},
      "period":{"start":"2025-08-09","end":"2025-08-22","compare":{"type":"previous_period"}},
      "filters":[{"field":"country","operator":"in","value":["DK"]},{"field":"device","operator":"=","value":"mobile"}],
      "layout":{"columns":12,"cards_order":["kpi_roas","kpi_conv","kpi_spend","ts_perf","tbl_campaigns","insights","note"]},
      "cards":[
        {"id":"kpi_roas","type":"metric","title":"Acquisition • ROAS","subtitle":"Revenue / Ad Spend",
         "metric":{"value":4.2,"unit":"x","format":"number:1","delta":{"value":0.35,"direction":"up","vs":"previous_period"},"annotation":"Above target (3.5x)."},
         "layout":{"colSpan":3},"agent_summary":""},
        {"id":"kpi_conv","type":"metric","title":"Acquisition • Conversions","subtitle":"Purchases (GA4)",
         "metric":{"value":1256,"unit":"","format":"number","delta":{"value":0.12,"direction":"up","vs":"previous_period"}},
         "layout":{"colSpan":3},"agent_summary":""},
        {"id":"kpi_spend","type":"metric","title":"Ads • Spend","subtitle":"Google Ads",
         "metric":{"value":6240.50,"unit":"DKK","format":"currency","delta":{"value":-0.05,"direction":"down","vs":"previous_period"}},
         "layout":{"colSpan":3},"agent_summary":""},
        {"id":"ts_perf","type":"chart","title":"Engagement • Sessions vs Conversions","subtitle":"Daily trend","viz":"area",
         "series":[
           {"label":"Sessions","axis":"left","data":[["2025-08-09",2450],["2025-08-10",2590],["2025-08-11",2700]]},
           {"label":"Conversions","axis":"right","data":[["2025-08-09",110],["2025-08-10",124],["2025-08-11",133]]}
         ],
         "compare_series":[{"label":"Sessions (prev)","axis":"left","style":"dashed","data":[["2025-07-26",2300],["2025-07-27",2400],["2025-07-28",2550]]}],
         "layout":{"colSpan":12},"agent_summary":""},
        {"id":"tbl_campaigns","type":"table","title":"Ads • Top Campaigns",
         "columns":["campaign","clicks","impressions","ctr","cpc","cost","conversions","cpa","roas"],
         "rows":[
            ["Brand - DK",1200,120000,"1.0%","DKK 5.20","DKK 6,240",140,"DKK 44.57","6.1x"],
            ["Shopping - DK",980,101500,"0.97%","DKK 4.80","DKK 4,704",132,"DKK 35.64","7.7x"],
            ["Prospecting - UK",650,140300,"0.46%","DKK 8.10","DKK 5,265",31,"DKK 169.84","1.2x"]
         ],
         "layout":{"colSpan":12},"agent_summary":""},
        {"id":"insights","type":"insight","title":"Acquisition • What’s happening?","items":[
            {"emoji":"💡","text":"DK Shopping improved CVR by 6% while CPC fell 18%."},
            {"emoji":"⚠️","text":"Prospecting - UK CPA is 2.3x target; consider pausing."}
         ],"layout":{"colSpan":12},"agent_summary":""},
        {"id":"note","type":"callout","title":"Methodology","variant":"info",
         "body":"Revenue: GA4 purchase revenue. Spend: Google Ads cost. Period: last 14 days vs previous.",
         "layout":{"colSpan":12},"agent_summary":""}
      ],
      "agent_summary":"ROAS improved by 0.35 vs previous period. CPC down, CVR up on Shopping."
    }';
}

/** Decode with tolerance for BOM/whitespace */
$raw = trim((string)get_json_input());
$input = json_safe_decode($raw);

/* ---------- Unwrap helper ---------- */
function unwrap_dashboard_with_answer($d): array {
    $orig = $d;
    if (is_array($d) && array_is_list($d) && isset($d[0]) && is_array($d[0])) {
        $candidate = $d[0];
    } else {
        $candidate = is_array($d) ? $d : [];
    }

    $answer = '';
    $whatsnext = '';
    if (isset($candidate['output']['answer']) && is_string($candidate['output']['answer'])) {
        $answer = (string)$candidate['output']['answer'];
    } elseif (isset($candidate['answer']) && is_string($candidate['answer'])) {
        $answer = (string)$candidate['answer'];
    }
    if (isset($candidate['output']['whatsnext']) && is_string($candidate['output']['whatsnext'])) {
        $whatsnext = (string)$candidate['output']['whatsnext'];
    } elseif (isset($candidate['whatsnext']) && is_string($candidate['whatsnext'])) {
        $whatsnext = (string)$candidate['whatsnext'];
    }

    $dash = $candidate;
    $unwrap = function($x) {
        if (!is_array($x)) return $x;
        if (isset($x['version']) && isset($x['cards']) && is_array($x['cards'])) return $x;

        if (array_is_list($x) && isset($x[0])) {
            $first = $x[0];
            if (isset($first['json']) && is_array($first['json'])) return $first['json'];
            if (isset($first['dashboard']) && is_array($first['dashboard'])) return $first['dashboard'];
            return $first;
        }
        foreach (['dashboard','json','data','body'] as $k) {
            if (isset($x[$k]) && is_array($x[$k])) return $x[$k];
        }
        return $x;
    };

    $dash = $unwrap($dash);
    if (is_array($dash) && !isset($dash['version']) && isset($dash['json']) && is_array($dash['json'])) {
        $dash = $unwrap($dash);
    }

    return [$dash, $answer, $whatsnext, $orig, $candidate];
}

[$dash, $answer_from_wrapper, $whatsnext_from_wrapper, $raw_arr, $best_container] = unwrap_dashboard_with_answer($input ?: []);

/* ---- Section order + explanations from wrapper (if provided) ---- */
$wrapperOutput   = is_array($best_container['output'] ?? null) ? $best_container['output'] : [];
$sectionOrderRaw = is_array($wrapperOutput['order'] ?? null) ? $wrapperOutput['order'] : [];
$sectionExplain  = is_array($wrapperOutput['explanations'] ?? null) ? $wrapperOutput['explanations'] : [];
$normExplain = [];
foreach ($sectionExplain as $k => $v) { $normExplain[strtolower(trim((string)$k))] = (string)$v; }
$sectionOrder = [];
foreach ($sectionOrderRaw as $k => $v) { $sectionOrder[strtolower(trim((string)$k))] = (int)$v; }

/* Helper: derive a "section key" from card title */
function card_section_key(array $card): string {
    $title = (string)($card['title'] ?? '');
    if ($title === '') return 'other';
    if (preg_match('/^\s*([^•:\-]+)\s*[•:\-]/u', $title, $m)) {
        return strtolower(trim($m[1]));
    }
    return strtolower(trim($title)) ?: 'other';
}

/* ---------- Validate minimal schema ---------- */
if (!is_array($dash) || !isset($dash['version']) || !isset($dash['cards']) || !is_array($dash['cards'])) {
    http_response_code(400);
    echo "Dashboard JSON missing required fields ('version', 'cards').";
    exit;
}

$theme   = $dash['theme'] ?? [];
$accent  = $theme['accent'] ?? '#27E1FF';
$mode    = strtolower($theme['mode'] ?? 'dark');
$brand   = $theme['brand'] ?? '';

$period  = $dash['period'] ?? [];
$filters = $dash['filters'] ?? [];
$cards   = $dash['cards'] ?? [];
$columns = (int)($dash['layout']['columns'] ?? 12);
$dashOrder = $dash['layout']['cards_order'] ?? array_map(fn($c)=>$c['id'] ?? uniqid('card_'), $cards);

/* ---------- Build render model (KPI first, hero chart, sections) ---------- */
$kpiCards = [];
$nonKpi   = [];
foreach ($cards as $c) {
    if (strtolower($c['type'] ?? '') === 'metric') $kpiCards[] = $c; else $nonKpi[] = $c;
}
if (!empty($dashOrder)) {
    usort($kpiCards, function($a,$b) use($dashOrder) {
        $pa = array_search($a['id'] ?? null, $dashOrder, true);
        $pb = array_search($b['id'] ?? null, $dashOrder, true);
        $pa = ($pa===false) ? PHP_INT_MAX : $pa;
        $pb = ($pb===false) ? PHP_INT_MAX : $pb;
        return $pa <=> $pb;
    });
}
$firstChart = null;
foreach ($dashOrder as $idInOrder) {
    foreach ($nonKpi as $c) {
        if (($c['id'] ?? null) === $idInOrder && strtolower($c['type'] ?? '') === 'chart') {
            $firstChart = $c;
            break 2;
        }
    }
}
$firstChartId = $firstChart['id'] ?? null;

$cardsBySection = [];
$idsBySection   = [];
foreach ($nonKpi as $c) {
    if (($c['id'] ?? null) === $firstChartId) continue;
    $sec = card_section_key($c);
    $cardsBySection[$sec] = $cardsBySection[$sec] ?? [];
    $idsBySection[$sec]   = $idsBySection[$sec]   ?? [];
    $cardsBySection[$sec][] = $c;
    if (!empty($c['id'])) $idsBySection[$sec][$c['id']] = true;
}
foreach ($cardsBySection as $sec => $list) {
    $ordered = [];
    $seen    = [];
    foreach ($dashOrder as $id) {
        if (isset($idsBySection[$sec][$id])) {
            foreach ($list as $card) if (($card['id'] ?? null) === $id) { $ordered[] = $card; $seen[$id]=true; break; }
        }
    }
    foreach ($list as $card) { $cid = $card['id'] ?? null; if (!$cid || empty($seen[$cid])) $ordered[] = $card; }
    $cardsBySection[$sec] = $ordered;
}

$allSections = array_keys($cardsBySection);
usort($allSections, function($a, $b) use ($sectionOrder) {
    $oa = $sectionOrder[$a] ?? 9999;
    $ob = $sectionOrder[$b] ?? 9999;
    if ($oa === $ob) return strcmp($a, $b);
    return $oa <=> $ob;
});
$printedExpl = [];

/* ---------- Properties: load or refresh ---------- */
$needRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
$propertiesData = [];
$propertiesMsg  = '';
$selectedProperty = '';

if ($needRefresh || !is_file(PROP_FILE)) {
  [$ok, $props, $msg] = fetch_and_save_properties(PROP_WEBHOOK_URL, PROP_FILE);
  $propertiesData = $props;
  $propertiesMsg  = $msg;
} else {
  $stored = json_safe_decode(@file_get_contents(PROP_FILE) ?: '');
  $propertiesData = normalize_properties(is_array($stored) ? $stored : []);
  if (!$propertiesData) {
    $propertiesMsg = 'Property file exists but is empty or invalid.';
  }
}

/* Selected property: cookie first (set by POST), else first available */
if (!empty($_COOKIE['ga_property'])) {
  $selectedProperty = (string)$_COOKIE['ga_property']; // e.g. "properties/250097593"
} elseif (!empty($propertiesData)) {
  $selectedProperty = (string)($propertiesData[0]['propertyId'] ?? '');
}

/* Prefer selection forwarded from proxy/n8n wrapper (if present) */
$selFromWrapper = '';
if (is_array($best_container ?? null)) {
  $selFromWrapper = (string)($best_container['selectedProperty']
    ?? $best_container['propertyFull']
    ?? $best_container['propertyId']
    ?? '');
}
if ($selFromWrapper !== '') {
  if (preg_match('/^\d+$/', $selFromWrapper)) {
    $selFromWrapper = 'properties/'.$selFromWrapper; // normalize numeric -> full path
  }
  $selectedProperty = $selFromWrapper;
  // refresh cookie for next normal page load
  setcookie('ga_property', $selectedProperty, time()+60*60*24*365, '/');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title><?= e($brand ? "$brand – Analytics & Ads" : "Intelligent Dashboard"); ?></title>

<!-- Fonts -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=Orbitron:wght@500;700&display=swap" rel="stylesheet">

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>

<style>
:root {
  --accent: <?= e($accent) ?>;
  --bg: <?= $mode === 'light' ? '#0b1220' : '#0b1220' ?>;
  --card: rgba(255,255,255,0.06);
  --muted: rgba(255,255,255,0.65);
  --text: #e6f3ff;
  --border: rgba(255,255,255,0.15);
  --glow: 0 0 20px rgba(39, 225, 255, 0.35), inset 0 0 20px rgba(39, 225, 255, 0.08);
}

* { box-sizing: border-box; }
html, body { height: 100%; }
body {
  margin: 0;
  background:
    radial-gradient(1200px 800px at 10% -10%, rgba(39,225,255,0.12), transparent),
    radial-gradient(1000px 700px at 90% 110%, rgba(39,225,255,0.10), transparent),
    var(--bg);
  color: var(--text);
  font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji","Segoe UI Emoji", "Segoe UI Symbol", sans-serif;
}

/* Header */
.header {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;
  padding: 24px clamp(14px, 3vw, 32px);
}
.header-top {
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
}
.brand {
  font-family: Orbitron, Inter, sans-serif;
  letter-spacing: 0.04em;
  font-weight: 700; font-size: 22px; color: var(--text);
  display: flex; align-items: center; gap: 12px;
}
.brand .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); box-shadow: var(--glow); }

.meta { display: flex; gap: 10px; flex-wrap: wrap; }
.badge {
  padding: 6px 10px; border: 1px solid var(--border); border-radius: 999px; color: var(--muted);
  background: linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.03));
}

/* Controls row */
.controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.select {
  display:flex; gap:8px; align-items:center;
  background: rgba(255,255,255,0.06);
  border:1px solid var(--border); border-radius:12px; padding:6px 10px;
}
.select select{
  background: transparent; color: var(--text); border:none; outline:none; padding:6px 4px; min-width:260px;
}
.button {
  padding:10px 12px; border-radius:12px; border:1px solid var(--border);
  background: linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.03));
  color: var(--text); cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px;
}
.button[disabled]{ opacity:.6; cursor:not-allowed; }

/* Ask Bar */
.askbar { display:flex; gap:10px; align-items:center; width:100%; }
.askbar input[type="text"]{
  flex:1; padding:12px 14px; border-radius:12px; border:1px solid var(--border);
  background: rgba(255,255,255,0.06); color: var(--text); outline:none;
}
.askbar button{
  padding:12px 14px; border-radius:12px; border:1px solid var(--border);
  background: linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.03));
  color: var(--text); cursor:pointer;
}
.askbar button[disabled]{ opacity:.6; cursor:not-allowed; }
.loader { width:18px; height:18px; border:3px solid rgba(255,255,255,0.25);
  border-top-color: var(--accent); border-radius:50%; animation: spin 0.9s linear infinite; display:none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Containers */
.container { padding: 0 clamp(14px, 3vw, 32px) 40px; }

/* KPI Strip (responsive, always first) */
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.kpi-card { background: var(--card); border:1px solid var(--border); border-radius:16px; padding:16px; box-shadow: var(--glow); }

.kpi-card h3 { margin:0 0 6px 0; font-size:14px; font-weight:700; letter-spacing:0.01em; }
.kpi .value { font-size: clamp(28px, 5vw, 42px); font-weight: 800; letter-spacing: -0.02em; }
.kpi .unit { opacity:.9; font-weight:600; margin-left:6px; }
.kpi .delta { display:inline-flex; align-items:center; gap:6px; font-size:12px; margin-top:6px; color: var(--muted); }
.kpi .delta.up { color:#43ff95; } .kpi .delta.down { color:#ff5b5b; }
.kpi .anno { margin-top:6px; color:var(--muted); font-size:12px; }

/* Section + cards grid */
.grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 16px;
}
.card {
  position: relative; background: var(--card); border: 1px solid var(--border);
  border-radius: 16px; padding: 18px 18px 16px; box-shadow: var(--glow); backdrop-filter: blur(10px);
  grid-column: span var(--span, 12);
  margin-bottom:15px;
}
.card h3 { margin: 0 0 4px 0; font-weight: 700; font-size: 18px; letter-spacing: 0.01em; }
.card .sub { color: var(--muted); font-size: 12px; margin-bottom: 12px; }

/* Section explainer */
.section-expl{
  margin: 8px 0 12px;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 12px;
  background: rgba(255,255,255,0.04);
}

/* Chart */
.chart-holder { height: 300px; }

/* Tables */
.table-wrap { overflow: auto; }
table.data { width: 100%; border-collapse: collapse; font-size: 13px; }
table.data th, table.data td { text-align: left; padding: 10px; border-bottom: 1px solid var(--border); }
table.data th { color: var(--muted); font-weight: 600; }

/* Pagination */
.pager { display:flex; gap:8px; align-items:center; justify-content:flex-end; padding-top:10px; font-size:12px; color:var(--muted); }
.pager button { border:1px solid var(--border); background: rgba(255,255,255,0.05); color: var(--text);
  padding:6px 10px; border-radius:8px; cursor:pointer; }
.pager button[disabled]{ opacity:.5; cursor:not-allowed; }
.pager .info { margin-right:auto; }

/* Agent summary (per card) */
.agent-summary{ margin:10px 0 12px; padding:8px 10px; border:1px dashed var(--border);
  border-radius:10px; background: rgba(255,255,255,0.03); color: var(--muted); font-size: 12px; line-height:1.35; }
.agent-summary .tag{ font-weight:600; margin-right:8px; color:var(--text); opacity:.85; }
.agent-summary.is-empty{ opacity:.6; }

/* Footer */
.footer { color: var(--muted); font-size: 12px; padding: 16px clamp(14px, 3vw, 32px) 40px; }
.footer a { color: var(--accent); text-decoration: none; }

/* Responsive grid spans */
@media (max-width: 1200px) { .grid { grid-template-columns: repeat(8, 1fr); } }
@media (max-width: 900px)  { .grid { grid-template-columns: repeat(6, 1fr); } .chart-holder{height:260px;} }
@media (max-width: 700px)  { .grid { grid-template-columns: repeat(4, 1fr); } .chart-holder{height:240px;} }
@media (max-width: 520px)  { .grid { grid-template-columns: repeat(1, 1fr); } .chart-holder{height:220px;} }

/* Fallback blur */
.fallback-blur { position: relative; }
.fallback-blur::after {
  content: "Ask your dashboard a question to see actual data";
  position: absolute; inset: 0;
  background: rgba(11,18,32,0.40);
  backdrop-filter: blur(6px);
  display: flex; justify-content: center; padding-top: 15%;
  font-size: 22px; font-weight: bold; color: #f98f00;
  border-radius: 16px; z-index: 5; pointer-events: none;
}

.dark option,optgroup {
    border-radius: 12px;
    border: 1px solid var(--border);
    background-color: var(--bg);
    color: var(--text);
    outline: none;
}

</style>
</head>
<body>

<header class="header">
  <div class="header-top">
    <div class="brand"><span class="dot"></span><?= e($brand ?: 'Intelligent Dashboard') ?></div>
    <div class="meta">
      <?php if (!empty($dash['user_question'])): ?>
        <span class="badge">Q: <?= e($dash['user_question']) ?></span>
      <?php endif; ?>
      <?php if (!empty($period['start']) && !empty($period['end'])): ?>
        <span class="badge">Period: <?= e($period['start']) ?> → <?= e($period['end']) ?></span>
      <?php endif; ?>
      <?php if (!empty($period['compare']['type'])): ?>
        <span class="badge">Compare: <?= e($period['compare']['type']) ?></span>
      <?php endif; ?>
      <?php foreach ($filters as $f): ?>
        <span class="badge">
          <?= e($f['field'] ?? '') . ' ' . e($f['operator'] ?? '') . ' ' . e(is_array($f['value'] ?? null) ? implode(',', $f['value']) : (string)($f['value'] ?? '')) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Controls: Property select (POST) + Refresh -->
  <div class="controls">
    <form method="POST" class="select" id="propForm">
      <label for="property" style="color:var(--muted); font-size:12px;">Property</label>
      <select name="property" id="property"
      <optgroup label="Properties" class="dark">
        onchange="try{localStorage.setItem('ga_property', this.value);}catch(e){}; document.getElementById('propForm').submit();">
        <?php if (!$propertiesData): ?>
          <option value="">No properties loaded</option>
        <?php else: ?>
          <?php foreach ($propertiesData as $p):
            $pid = (string)($p['propertyId'] ?? '');   // e.g. "properties/250097593"
            $label = trim((string)($p['displayName'] ?? '')) ?: $pid;
          ?>
            <option value="<?= e($pid) ?>" <?= $pid===$selectedProperty ? 'selected' : '' ?>>
              <?= e($label) ?> (<?= e($pid) ?>)
            </option>
          <?php endforeach; ?>
        <?php endif; ?>
        </optgroup>
      </select>
      <noscript><button class="button" type="submit">Apply</button></noscript>
    </form>

    <a class="button" href="?refresh=1">⟳ Refresh Properties</a>

    <?php if ($propertiesMsg && ($needRefresh || !is_file(PROP_FILE))): ?>
      <span class="badge"><?= e($propertiesMsg) ?></span>
    <?php endif; ?>
  </div>

  <!-- Ask Bar -->
  <form class="askbar" id="askbar" onsubmit="return false;">
    <input type="text" id="askInput" placeholder="Ask your Dashboard a question…" autocomplete="off" />
    <button id="askBtn" type="button">Ask</button>
    <div class="loader" id="askLoader" aria-hidden="true"></div>
  </form>
</header>

<div class="container<?= $isFallback ? ' fallback-blur' : '' ?>">

  <!-- Server-rendered Agent Answer (if present) -->
  <?php if (!empty($answer_from_wrapper)): ?>
    <div class="card" style="--span:12">
      <h3>Agent Answer</h3>
      <div class="sub"><?= e($answer_from_wrapper) ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($whatsnext_from_wrapper)): ?>
    <div class="card" style="--span:12">
      <h3>What to improve & how</h3>
      <div class="sub"><?= e($whatsnext_from_wrapper) ?></div>
    </div>
  <?php endif; ?>

  <?php if (!empty($dash['agent_summary'])): ?>
    <div class="card callout" style="--span:12">
      <h3>Summary</h3>
      <div class="sub"><?= e($dash['agent_summary']) ?></div>
    </div>
  <?php endif; ?>

  <!-- Nudge to fetch properties -->
  <?php if (!$propertiesData): ?>
    <div class="card" style="--span:12">
      <h3>Load Google Analytics properties</h3>
      <div class="sub">We don’t see a saved <code>ga_properties.json</code>. Click the button below to fetch your accounts & properties from Google (via your webhook) and create the file.</div>
      <p><a class="button" href="?refresh=1">⟳ Fetch Properties Now</a></p>
    </div>
  <?php endif; ?>

  <!-- KPI STRIP (always first) -->
  <?php if (!empty($kpiCards)): ?>
    <div class="kpi-strip">
      <?php foreach ($kpiCards as $kc): ?>
        <div class="kpi-card">
          <h3><?= e($kc['title'] ?? 'KPI') ?></h3>
          <?php
            $m = $kc['metric'] ?? [];
            $value = $m['value'] ?? null;
            $unit  = $m['unit'] ?? '';
            $format= $m['format'] ?? 'number';
            $delta = $m['delta']['value'] ?? null;
            $dir   = strtolower($m['delta']['direction'] ?? '');
            $anno  = $m['annotation'] ?? '';
            $fmt = function($v, $fmt) {
              if (!is_numeric($v)) return (string)$v;
              if (str_starts_with($fmt, 'number')) {
                  if (preg_match('/number:(\d+)/', $fmt, $mm)) return number_format((float)$v, (int)$mm[1], '.', ' ');
                  return number_format((float)$v, 0, '.', ' ');
              }
              if ($fmt === 'currency') return number_format((float)$v, 2, '.', ' ');
              if ($fmt === 'percent')  return number_format((float)$v * 100, 1) . '%';
              return (string)$v;
            };
          ?>
          <div class="kpi">
            <div class="value">
              <?= e($fmt($value, $format)) ?> <?php if ($unit): ?><span class="unit"><?= e($unit) ?></span><?php endif; ?>
            </div>
            <?php if ($delta !== null): ?>
              <?php
                $arrow = $dir === 'up' ? '▲' : ($dir === 'down' ? '▼' : '■');
                $deltaFmt = is_numeric($delta) ? number_format($delta*100,1).'%' : (string)$delta;
              ?>
              <div class="delta <?= e($dir) ?>"><?= $arrow ?> <?= e($deltaFmt) ?> vs <?= e($m['delta']['vs'] ?? 'previous') ?></div>
            <?php endif; ?>
            <?php if ($anno): ?><div class="anno"><?= e($anno) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- HERO CHART (first chart after KPIs) -->
  <?php if (!empty($firstChart)):
      $fcId = preg_replace('/[^a-zA-Z0-9_-]/','_', $firstChart['id'] ?? uniqid('chart_'));
      $cfg = [
        'id' => $fcId,
        'viz' => $firstChart['viz'] ?? 'line',
        'series' => $firstChart['series'] ?? [],
        'compare_series' => $firstChart['compare_series'] ?? []
      ];
      $secKeyForFirst = card_section_key($firstChart);
      if (!empty($normExplain[$secKeyForFirst]) && empty($printedExpl[$secKeyForFirst])):
  ?>
    <div class="card" style="--span:12">
      <h3><?= e(ucfirst($secKeyForFirst)) ?> — Overview</h3>
      <div class="section-expl">
        <strong style="opacity:.9">Explanation</strong>
        <div class="sub" style="margin-top:6px"><?= e($normExplain[$secKeyForFirst]) ?></div>
      </div>
    </div>
    <?php $printedExpl[$secKeyForFirst] = true; endif; ?>
    <div class="card chart" style="--span:12">
      <h3><?= e($firstChart['title'] ?? 'Trend') ?></h3>
      <?php if (!empty($firstChart['subtitle'])): ?><div class="sub"><?= e($firstChart['subtitle']) ?></div><?php endif; ?>
      <div class="chart-holder"><canvas id="canvas-<?= e($fcId) ?>"></canvas></div>
      <script type="application/json" id="cfg-<?= e($fcId) ?>"><?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    </div>
  <?php endif; ?>

  <!-- MAIN GRID: sections -->
  <div class="grid">
    <?php
      foreach ($allSections as $secKey):
        $cardsInSection = $cardsBySection[$secKey] ?? [];
        if (!$cardsInSection) continue;

        if (!empty($normExplain[$secKey]) && empty($printedExpl[$secKey])): ?>
          <section class="card" style="--span:12">
            <h3><?= e(ucfirst($secKey)) ?> — Overview</h3>
            <div class="section-expl">
              <strong style="opacity:.9)">Explanation</strong>
              <div class="sub" style="margin-top:6px"><?= e($normExplain[$secKey]) ?></div>
            </div>
          </section>
          <?php $printedExpl[$secKey] = true; ?>
        <?php endif;

        foreach ($cardsInSection as $c):
          $type = strtolower($c['type'] ?? 'unknown');
          $title = $c['title'] ?? ucfirst($type);
          $subt = $c['subtitle'] ?? '';
          $spanCols = max(1, min(12, (int)($c['layout']['colSpan'] ?? 12)));
          $id = preg_replace('/[^a-zA-Z0-9_-]/','_', $c['id'] ?? uniqid('card_'));
    ?>
      <section class="card <?= e($type) ?>" style="--span:<?= $spanCols ?>">
        <h3><?= e($title) ?></h3>
        <?php if ($subt): ?><div class="sub"><?= e($subt) ?></div><?php endif; ?>

        <?php
          $cardSummary = (string)($c['agent_summary'] ?? '');
          $isEmpty = trim($cardSummary) === '';
        ?>
        <div class="agent-summary<?= $isEmpty ? ' is-empty' : '' ?>">
          <span class="tag">Agent</span>
          <?= $isEmpty ? '&nbsp;' : e($cardSummary) ?>
        </div>

        <?php if ($type === 'chart'):
          $cfg = [
            'id' => $id,
            'viz' => $c['viz'] ?? 'line',
            'series' => $c['series'] ?? [],
            'compare_series' => $c['compare_series'] ?? []
          ];
        ?>
          <div class="chart-holder">
            <canvas id="canvas-<?= e($id) ?>"></canvas>
          </div>
          <script type="application/json" id="cfg-<?= e($id) ?>"><?= json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
        <?php elseif ($type === 'table'):
          $cols = $c['columns'] ?? [];
          $rows = $c['rows'] ?? [];
        ?>
          <div class="table-wrap">
            <table class="data">
              <thead><tr>
                <?php foreach ($cols as $col): ?><th><?= e((string)$col) ?></th><?php endforeach; ?>
              </tr></thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <?php
                      if (is_array($r) && array_is_list($r)) {
                        foreach ($r as $cell) echo '<td>'.e((string)$cell).'</td>';
                      } elseif (is_array($r)) {
                        foreach ($cols as $col) echo '<td>'.e((string)($r[$col] ?? '')).'</td>';
                      } else {
                        $colspan = max(1, count($cols));
                        echo '<td colspan="'.$colspan.'">'.e((string)$r).'</td>';
                      }
                    ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif ($type === 'insight'):
          $items = $c['items'] ?? [];
        ?>
          <ul class="ins-list">
            <?php foreach ($items as $it): ?>
              <li class="ins-item"><span><?= e((string)($it['emoji'] ?? '💡')) ?></span><span><?= e((string)($it['text'] ?? '')) ?></span></li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($type === 'callout'):
          $variant = strtolower($c['variant'] ?? 'info');
          $body = $c['body'] ?? '';
        ?>
          <div class="callout <?= e($variant) ?>">
            <?php if (!empty($c['title'])): ?><div class="sub" style="margin-bottom:8px; font-weight:600; color:var(--text)"><?= e($c['title']) ?></div><?php endif; ?>
            <div class="sub"><?= e($body) ?></div>
          </div>
        <?php else: ?>
          <div class="sub">Unknown card type: <code><?= e($type) ?></code></div>
        <?php endif; ?>
      </section>
    <?php endforeach; endforeach; ?>
  </div>
</div>

<footer class="footer">
  <div>Copyright Lennart Øster (SitePoint Ventures): <strong style="color:var(--accent)"><a href="https://lennartoester.com" target="_blank">https://lennartoester.com</a></strong></div>
</footer>

<!-- Embed full input (wrapper or pure dashboard) so the ask-bar can send context -->
<script type="application/json" id="dashboard-json"><?= json_encode($raw_arr ?: $dash, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script>
window.addEventListener('DOMContentLoaded', () => {
  // Re-apply saved property selection after proxy-rendered reloads, and set cookie client-side
  (function reapplySelection(){
    try {
      const sel = document.getElementById('property');
      const saved = localStorage.getItem('ga_property');
      if (sel && saved) {
        const has = Array.from(sel.options).some(o => o.value === saved);
        if (has && sel.value !== saved) {
          sel.value = saved;
        }
        document.cookie = 'ga_property=' + encodeURIComponent(saved) + '; path=/; max-age=' + (60*60*24*365);
      }
    } catch(e) {}
  })();

  // Initialize charts
  document.querySelectorAll('script[type="application/json"][id^="cfg-"]').forEach(script => {
    try {
      const cfg = JSON.parse(script.textContent || "{}");
      const canvas = document.getElementById('canvas-' + cfg.id);
      if (!canvas) return;

      const labelSet = new Set();
      const addLabels = (arr) => arr.forEach(s => (s.data || []).forEach(d => labelSet.add(d[0])));
      addLabels(cfg.series || []);
      addLabels(cfg.compare_series || []);
      const labels = Array.from(labelSet).sort();

      const mkDataset = (s, isCompare=false) => {
        const data = labels.map(l => {
          const found = (s.data || []).find(d => d[0] === l);
          return found ? found[1] : null;
        });
        const ds = {
          label: s.label || (isCompare ? 'Previous' : 'Series'),
          data,
          tension: 0.35,
          fill: (cfg.viz === 'area'),
          borderWidth: 2,
          yAxisID: (s.axis === 'right') ? 'y1' : 'y',
        };
        if (isCompare || s.style === 'dashed') {
          ds.borderDash = [6, 6];
          ds.pointRadius = 0;
        }
        return ds;
      };

      const datasets = []
        .concat((cfg.series || []).map(s => mkDataset(s, false)))
        .concat((cfg.compare_series || []).map(s => mkDataset(s, true)));

      const type = (cfg.viz === 'bar') ? 'bar' : 'line';
      new Chart(canvas.getContext('2d'), {
        type,
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text') || '#e6f3ff' } },
            tooltip: { mode: 'index', intersect: false }
          },
          scales: {
            x: { grid: { color: 'rgba(255,255,255,0.08)' }, ticks: { color: 'rgba(255,255,255,0.7)' } },
            y: { grid: { color: 'rgba(255,255,255,0.08)' }, ticks: { color: 'rgba(255,255,255,0.7)' } },
            y1:{ position:'right', grid:{ drawOnChartArea:false }, ticks:{ color:'rgba(255,255,255,0.7)' } }
          },
          elements: { point: { radius: 2 } }
        }
      });
    } catch (e) {
      console.error('Chart init error:', e);
    }
  });

  // Table Pagination (10 rows)
  const PAGE_SIZE = 10;
  function setupPagination(table){
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const total = rows.length;
    if (total <= PAGE_SIZE) return;

    let page = 1;
    const pages = Math.ceil(total / PAGE_SIZE);

    function render(){
      const start = (page-1) * PAGE_SIZE;
      const end = start + PAGE_SIZE;
      rows.forEach((tr, i) => { tr.style.display = (i>=start && i<end) ? '' : 'none'; });
      info.textContent = `Showing ${Math.min(start+1,total)}–${Math.min(end,total)} of ${total}`;
      prev.disabled = page===1;
      next.disabled = page===pages;
      pnum.textContent = `Page ${page}/${pages}`;
    }

    const pager = document.createElement('div'); pager.className = 'pager';
    const info = document.createElement('div'); info.className = 'info';
    const prev = document.createElement('button'); prev.type = 'button'; prev.textContent = 'Prev';
    const pnum = document.createElement('span'); pnum.style.minWidth='80px'; pnum.style.textAlign='center';
    const next = document.createElement('button'); next.type='button'; next.textContent = 'Next';
    prev.addEventListener('click', () => { if (page>1){ page--; render(); } });
    next.addEventListener('click', () => { if (page<pages){ page++; render(); } });
    pager.appendChild(info); pager.appendChild(prev); pager.appendChild(pnum); pager.appendChild(next);
    const wrap = table.closest('.table-wrap') || table.parentElement; wrap.appendChild(pager);
    render();
  }
  document.querySelectorAll('table.data').forEach(setupPagination);

  // Ask Agent: include selected GA property; handle HTML or JSON responses
  const askInput  = document.getElementById('askInput');
  const askBtn    = document.getElementById('askBtn');
  const askLoader = document.getElementById('askLoader');
  const propertySelect = document.getElementById('property');

  function setLoading(on){ askBtn.disabled = on; askLoader.style.display = on ? 'inline-block' : 'none'; }

  function extractPropertyId(raw){
    try { raw = decodeURIComponent(raw || ''); } catch(e) {}
    const parts = (raw || '').split('/');
    return parts.length ? parts[parts.length - 1] : raw; // "properties/123" -> "123"
  }

  async function askAgent(){
    const q = (askInput.value || '').trim();
    if (!q) return;

    const rawSelected = propertySelect ? propertySelect.value : '';
    const propertyId  = extractPropertyId(rawSelected); // e.g. "250097593"
    try { localStorage.setItem('ga_property', rawSelected); } catch(e) {}

    setLoading(true);
    try {
      const ctxScript = document.getElementById('dashboard-json');
      let context = {};
      try { context = JSON.parse(ctxScript.textContent || '{}'); } catch(e){}

      const res = await fetch('proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          question: q,
          propertyId: propertyId,         // numeric id only
          propertyFull: rawSelected,      // "properties/123" (optional, for renderer)
          selectedProperty: rawSelected,  // hint for renderer to mark selected
          dashboard: context
        })
      });

      const ct = (res.headers.get('content-type') || '').toLowerCase();

      if (ct.includes('text/html')) {
        const html = await res.text();
        document.open(); document.write(html); document.close();
        return;
      }

      const data = await res.json();
      const answer = (data && (data.answer || data.response || data.text)) || 'No answer returned.';
      const card = document.createElement('div');
      card.className = 'card'; card.style.setProperty('--span','12');
      card.innerHTML = `<h3>Agent Answer</h3><div class="sub"></div>`;
      card.querySelector('.sub').textContent = answer;
      document.querySelector('.container').prepend(card);

    } catch (err) {
      console.error(err);
      alert('Sorry, something went wrong asking the agent.');
    } finally {
      setLoading(false);
    }
  }

  askBtn.addEventListener('click', askAgent);
  askInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); askAgent(); } });
});
</script>

</body>
</html>
