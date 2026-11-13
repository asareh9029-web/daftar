<?php
// Vers purchase ledger - storage API (no database needed; data kept in ./data)
declare(strict_types=1);
const DATA_DIR = __DIR__ . '/data';
const COLLS = ['invoices', 'payments', 'bankBatches', 'settings'];
const GUARD = "<?php http_response_code(404); exit; ?>\n";

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

session_name('VERSSESS');
session_set_cookie_params(['lifetime' => 60 * 60 * 24 * 30, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
  'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
session_start();

function out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fpath(string $n): string { return DATA_DIR . "/$n.json.php"; }
function readj(string $n) {
  $p = fpath($n); if (!is_file($p)) return null;
  $s = (string)file_get_contents($p); $i = strpos($s, "\n");
  return json_decode($i === false ? '' : substr($s, $i + 1), true);
}
function writej(string $n, $v): void {
  $t = fpath($n) . '.' . bin2hex(random_bytes(4)) . '.tmp';
  if (file_put_contents($t, GUARD . json_encode($v, JSON_UNESCAPED_UNICODE)) === false) out(['error' => 'write_failed'], 500);
  if (!rename($t, fpath($n))) { @unlink($t); out(['error' => 'write_failed'], 500); }
}
function lockdb() { $h = fopen(DATA_DIR . '/.lock', 'c'); flock($h, LOCK_EX); return $h; }
function db(): array { $d = readj('db'); return is_array($d) ? $d : ['revs' => [], 'cols' => []]; }
function authed(): bool { return !empty($_SESSION['vers_ok']); }
function needAuth(): void { if (!authed()) out(['error' => 'auth'], 401); }
function coll(string $c): string { if (!in_array($c, COLLS, true)) out(['error' => 'bad_collection'], 400); return $c; }

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true)) out(['error' => 'no_data_dir'], 500);
if (!is_file(DATA_DIR . '/.htaccess')) @file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\nDeny from all\n");
if (!is_file(DATA_DIR . '/index.html')) @file_put_contents(DATA_DIR . '/index.html', '');

$op = $_GET['op'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'vers') out(['error' => 'bad_request'], 400);
$body = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

switch ($op) {
  case 'status':
    out(['setup' => readj('auth') === null, 'auth' => authed()]);

  case 'setup':
    if (!$post) out(['error' => 'bad_request'], 400);
    $h = lockdb();
    if (readj('auth') !== null) out(['error' => 'already_set'], 409);
    $pw = (string)($body['password'] ?? '');
    if ((function_exists('mb_strlen') ? mb_strlen($pw) : strlen($pw)) < 6) out(['error' => 'short_password'], 400);
    writej('auth', ['hash' => password_hash($pw, PASSWORD_DEFAULT)]);
    session_regenerate_id(true); $_SESSION['vers_ok'] = true;
    out(['ok' => true]);

  case 'login':
    if (!$post) out(['error' => 'bad_request'], 400);
    $a = readj('auth');
    if (!$a || !password_verify((string)($body['password'] ?? ''), $a['hash'] ?? '')) { sleep(2); out(['error' => 'wrong_password'], 403); }
    session_regenerate_id(true); $_SESSION['vers_ok'] = true;
    out(['ok' => true]);

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'revs':
    needAuth(); session_write_close();
    out(['revs' => (object)(db()['revs'] ?? [])]);

  case 'get':
    needAuth(); session_write_close();
    $c = coll((string)($_GET['c'] ?? '')); $d = db();
    out(['rev' => $d['revs'][$c] ?? 0, 'docs' => (object)($d['cols'][$c] ?? [])]);

  case 'set':
  case 'del':
    needAuth(); session_write_close();
    if (!$post) out(['error' => 'bad_request'], 400);
    $c = coll((string)($body['c'] ?? '')); $id = (string)($body['id'] ?? '');
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) out(['error' => 'bad_id'], 400);
    $h = lockdb(); $d = db();
    if ($op === 'set') {
      if (!is_array($body['doc'] ?? null)) out(['error' => 'bad_doc'], 400);
      $d['cols'][$c][$id] = $body['doc'];
    } else { unset($d['cols'][$c][$id]); }
    $d['revs'][$c] = ($d['revs'][$c] ?? 0) + 1;
    writej('db', $d);
    out(['ok' => true, 'rev' => $d['revs'][$c]]);

  case 'restore':
    needAuth(); session_write_close();
    if (!$post || !is_array($body['data'] ?? null)) out(['error' => 'bad_request'], 400);
    $h = lockdb(); $d = db();
    if (readj('db') !== null) writej('db-before-restore-' . date('Ymd-His'), $d);
    foreach (COLLS as $c) {
      $docs = $body['data'][$c] ?? [];
      $d['cols'][$c] = is_array($docs) ? $docs : [];
      $d['revs'][$c] = ($d['revs'][$c] ?? 0) + 1;
    }
    writej('db', $d);
    out(['ok' => true]);

  default:
    out(['error' => 'unknown_op'], 400);
}
