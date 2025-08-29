<?php
declare(strict_types=1);

/**
 * Proxy: n8n -> renderer.
 * - Loads .env via auth.php (env-only, no UI)
 * - Forwards propertyId/propertyFull to n8n
 * - Sends X-Render-Key to renderer to bypass login
 * - Forwards ga_property cookie to renderer so selection persists
 * - Health check: GET ?ping=1
 */

//////////////////////////////
// Bootstrap env-only auth  //
//////////////////////////////
define('AUTH_ENV_ONLY', true);
require_once __DIR__ . '/auth.php'; // defines N8N_WEBHOOK, RENDERER_URL, (optional) RENDER_KEY

// ====== CONFIG ======
const DEBUG_BROWSER = true;
const TIMEOUT_SEC   = 180;
const SSL_VERIFY    = true;
// ====================

header('Cache-Control: no-store');

// Ensure required env present
if (!defined('N8N_WEBHOOK') || !defined('RENDERER_URL') || N8N_WEBHOOK === '' || RENDERER_URL === '') {
  http_response_code(503);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Missing required keys in .env (N8N_WEBHOOK and/or RENDERER_URL). Open index.php in a browser to run setup.";
  exit;
}

/////////////////////////////
// Logging + fatal capture //
/////////////////////////////
function resolve_log_path(): string {
  $candidates = [];
  $envTmp = getenv('TMPDIR');
  if ($envTmp) $candidates[] = rtrim($envTmp, '/').'/proxy.log';
  $candidates[] = rtrim(sys_get_temp_dir() ?: '/tmp', '/').'/proxy.log';
  $candidates[] = '/tmp/proxy.log';
  foreach ($candidates as $p) {
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    if ((file_exists($p) && is_writable($p)) || (is_dir($dir) && is_writable($dir))) return $p;
  }
  return '/tmp/proxy.log';
}
define('LOG_PATH', resolve_log_path());

function log_line(string $label, $data=null): void {
  $ts = date('Y-m-d H:i:s');
  $line = "[$ts] $label";
  if ($data !== null) {
    $line .= ' ' . (is_scalar($data) ? (string)$data : json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PARTIAL_OUTPUT_ON_ERROR));
  }
  $line .= "\n";
  $ok = @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
  if ($ok === false) error_log("PROXY ".$line);
}

set_error_handler(function($severity, $message, $file, $line) {
  log_line('PHP_ERROR', ['severity'=>$severity,'message'=>$message,'file'=>$file,'line'=>$line]);
  if (error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function($ex){
  log_line('UNCAUGHT_EXCEPTION', ['type'=>get_class($ex), 'msg'=>$ex->getMessage(), 'file'=>$ex->getFile(), 'line'=>$ex->getLine()]);
  respond_error(502, 'Internal proxy exception: '.$ex->getMessage());
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && in_array($e['type'] ?? 0, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    log_line('FATAL_SHUTDOWN', $e);
    if (!headers_sent()) { http_response_code(502); header('Content-Type: text/plain; charset=UTF-8'); }
    echo DEBUG_BROWSER
      ? "Proxy fatal error (logged). See: ".LOG_PATH."\n".$e['message']." in ".$e['file'].":".$e['line']."\n"
      : "Proxy fatal error.";
  }
});

// Health check
if (($_GET['ping'] ?? '') !== '') {
  log_line('HEALTHCHECK', ['ip'=>$_SERVER['REMOTE_ADDR'] ?? '']);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "OK ".date('c')." (log: ".LOG_PATH.")";
  exit;
}

////////////////////////
// HTTP helper (POST) //
////////////////////////
function http_post_json(string $url, $json, array $headers = [], int $timeout = TIMEOUT_SEC): array {
  $ch  = curl_init($url);
  $payload = is_string($json) ? $json : json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

  $hdr = array_merge([
    'Content-Type: application/json',
    'Accept: application/json, text/html;q=0.9, */*;q=0.1',
    'User-Agent: DashProxy/1.1',
    'Expect:' // avoid 100-continue issues with some CDNs
  ], $headers);

  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $hdr,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_SSL_VERIFYPEER => SSL_VERIFY,
    CURLOPT_SSL_VERIFYHOST => SSL_VERIFY ? 2 : 0,
  ]);

  $body = curl_exec($ch);
  $err  = curl_error($ch);
  $info = curl_getinfo($ch);
  $code = (int)($info['http_code'] ?? 0);
  curl_close($ch);

  return [$code, $body, $err, $info];
}

function respond_error(int $code, string $msg, array $dbg=[]): void {
  log_line("RESPOND_ERROR $code: $msg", $dbg);
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  if (DEBUG_BROWSER) {
    echo $msg;
    if ($dbg) {
      echo "\n\n--- debug (short) ---\n";
      foreach ($dbg as $k=>$v) {
        echo $k.": ".(is_string($v)?$v:json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))."\n";
      }
      echo "\nlog: ".LOG_PATH."\n";
    }
  } else {
    echo $msg;
  }
  exit;
}

///////////////////////////////
// GA property id normalizer //
///////////////////////////////
function extract_property_id(?string $raw): string {
  $raw = (string)$raw;
  if ($raw === '') return '';
  $decoded = urldecode($raw);           // "properties%2F123" -> "properties/123"
  $parts = explode('/', $decoded);
  $last  = trim(end($parts));
  $numeric = preg_replace('/\D+/', '', $last); // GA4 ids are numeric
  return $numeric ?: $last;
}

///////////////////////////
// Read incoming request //
///////////////////////////
$raw = file_get_contents('php://input') ?: '';
log_line('INCOMING', ['method'=>$_SERVER['REQUEST_METHOD'] ?? '','uri'=>($_SERVER['REQUEST_URI'] ?? ''),'raw_len'=>strlen($raw)]);
$in  = json_decode($raw, true);

$q         = is_array($in) ? (string)($in['question'] ?? '') : '';
$dashboard = is_array($in) && isset($in['dashboard']) ? $in['dashboard'] : null;

// Property context from frontend (optional)
$propertyFull = is_array($in) ? (string)($in['propertyFull'] ?? '') : '';
$propertyId   = is_array($in) ? (string)($in['propertyId']   ?? '') : '';
if ($propertyId === '' && $propertyFull !== '') {
  $propertyId = extract_property_id($propertyFull);
}
// Also try cookie ga_property -> send both to n8n and to renderer
$cookieGaProp = $_COOKIE['ga_property'] ?? '';
if ($propertyId === '' && $cookieGaProp !== '') {
  $propertyId = extract_property_id((string)$cookieGaProp);
}
log_line('PROPERTY_CTX', ['cookieGaProp'=>$cookieGaProp, 'propertyFull'=>$propertyFull, 'propertyId'=>$propertyId]);

if ($q === '') {
  respond_error(400, "Missing 'question' in JSON body.", ['received_head'=>substr($raw,0,300)]);
}

/////////////////////////////
// 1) Call n8n with JSON   //
/////////////////////////////
$payload = [
  'question'      => $q,
  'dashboard'     => $dashboard,
  'propertyId'    => $propertyId,     // numeric id (e.g. "250097593")
  'propertyFull'  => ($propertyFull ?: (string)$cookieGaProp), // "properties/250097593"
];

[$wcode, $wbody, $werr, $winfo] = http_post_json(N8N_WEBHOOK, $payload);
log_line('WEBHOOK_RESULT', ['code'=>$wcode,'err'=>$werr,'info'=>$winfo, 'body_head'=>substr((string)$wbody,0,300)]);

if ($wbody === false || $wcode >= 400) {
  respond_error(502, "Webhook error ($wcode).", ['curl_error'=>$werr,'curl_info'=>$winfo,'body_head'=>substr((string)$wbody,0,300)]);
}

/////////////////////////////
// 2) Parse webhook output //
/////////////////////////////
$wrapper = json_decode((string)$wbody, true);
$maybe   = null;

if (!is_array($wrapper)) {
  $maybe = json_decode((string)$wbody, true);
  if (is_array($maybe) && isset($maybe['body']) && is_string($maybe['body'])) {
    $wrapper = json_decode($maybe['body'], true);
  }
}
// n8n items shape
if (!is_array($wrapper) && is_array($maybe ?? null) && isset($maybe['items'][0]['json'])) {
  $wrapper = $maybe['items'][0]['json'];
}

if (!is_array($wrapper)) {
  respond_error(502, "Webhook returned non-JSON / invalid JSON.", ['raw_webhook_body_head'=>substr((string)$wbody,0,300)]);
}
if (!isset($wrapper['json'])) {
  respond_error(502, "Webhook JSON missing 'json' (dashboard).", ['parsed_keys'=>array_keys($wrapper)]);
}

/////////////////////////////////////
// 3) Call renderer (return HTML)  //
/////////////////////////////////////
$rendererHeaders = ['Accept: text/html'];

// Forward trusted render key (bypass login)
if (defined('RENDER_KEY') && RENDER_KEY !== '') {
  $rendererHeaders[] = 'X-Render-Key: '.RENDER_KEY;
}

// Forward the user's GA property cookie so dropdown stays selected
if ($cookieGaProp !== '') {
  // Only forward the single cookie we care about; avoids leaking user cookies to backend
  $rendererHeaders[] = 'Cookie: ga_property=' . $cookieGaProp;
}

[$rcode, $rhtml, $rerr, $rinfo] = http_post_json(RENDERER_URL, $wrapper, $rendererHeaders, TIMEOUT_SEC);
log_line('RENDER_RESULT', ['code'=>$rcode,'err'=>$rerr,'info'=>$rinfo,'html_head'=>substr((string)$rhtml,0,300)]);

if ($rhtml === false || $rcode >= 400) {
  respond_error(502, "Render error ($rcode).", [
    'curl_error'=>$rerr,
    'curl_info'=>$rinfo,
    'renderer_reply_head'=>substr((string)$rhtml,0,300)
  ]);
}

// Some stacks may wrap HTML in JSON { body: "<html...>" }
$hasHtmlTag = (stripos((string)$rhtml, '<html') !== false) || (stripos((string)$rhtml, '<!doctype') !== false);
if (!$hasHtmlTag) {
  $maybeHtml = json_decode((string)$rhtml, true);
  if (is_array($maybeHtml) && isset($maybeHtml['body']) && is_string($maybeHtml['body'])) {
    $rhtml = $maybeHtml['body'];
    $hasHtmlTag = (stripos((string)$rhtml, '<html') !== false) || (stripos((string)$rhtml, '<!doctype') !== false);
  }
}
if (!$hasHtmlTag) {
  respond_error(502, "Renderer did not return HTML.", ['renderer_body_head'=>substr((string)$rhtml,0,300)]);
}

header('Content-Type: text/html; charset=UTF-8');
echo $rhtml;
