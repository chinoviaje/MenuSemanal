<?php
// Menú de la Casa: guarda la base de la app en el servidor.
// GET  api.php?ping=1          -> {"app":"menu-de-la-casa"}  (sin clave: la app lo usa para saber que hay servidor)
// GET  api.php                 -> {"rev":N,"savedAt":T,"state":{...}}   (header X-Menu-Key)
// GET  api.php?since=N         -> {"rev":N,"same":true} si no cambió
// PUT  api.php {state,baseRev} -> {"rev":N+1}  |  409 con la versión actual si otro dispositivo guardó antes
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('serialize_precision', '-1');

function out(int $code, $data): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (isset($_GET['ping'])) out(200, ['app' => 'menu-de-la-casa', 'ok' => true]);

$cfg = require __DIR__ . '/config.php';
$clave = (string)($cfg['clave'] ?? '');
if ($clave === '' || $clave === 'CAMBIAR-ESTA-CLAVE') out(500, ['error' => 'Falta configurar la clave en config.php']);
$key = (string)($_SERVER['HTTP_X_MENU_KEY'] ?? '');
if (!hash_equals($clave, $key)) { usleep(500000); out(401, ['error' => 'Clave incorrecta']); }

$dir = rtrim((string)$cfg['carpeta_datos'], '/');
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) out(500, ['error' => 'No puedo crear la carpeta de datos']);
if (!is_writable($dir)) out(500, ['error' => 'La carpeta de datos no tiene permiso de escritura']);
$file = $dir . '/base.json';

function readBase(string $file) {
  if (!is_file($file)) return null;
  $b = json_decode((string)file_get_contents($file)); // objetos (no arrays): conserva {} vacíos
  return is_object($b) ? $b : null;
}

$m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($m === 'GET') {
  $b = readBase($file);
  if (!$b) out(200, ['rev' => 0, 'state' => null]);
  if (isset($_GET['since']) && (int)$_GET['since'] === (int)$b->rev) out(200, ['rev' => (int)$b->rev, 'same' => true]);
  out(200, $b);
}
if ($m === 'PUT' || $m === 'POST') {
  $raw = (string)file_get_contents('php://input');
  if (strlen($raw) > (int)($cfg['max_bytes'] ?? 10485760)) out(413, ['error' => 'La base es demasiado grande']);
  $in = json_decode($raw);
  if (!is_object($in) || !isset($in->state) || !is_object($in->state)) out(400, ['error' => 'Formato inválido']);
  $lock = fopen($dir . '/.lock', 'c');
  flock($lock, LOCK_EX);
  $cur = readBase($file);
  $rev = $cur ? (int)$cur->rev : 0;
  if (property_exists($in, 'baseRev') && $in->baseRev !== null && (int)$in->baseRev !== $rev) { flock($lock, LOCK_UN); out(409, $cur); }
  if ($cur) { // una copia por día; se guardan las últimas N
    $bd = $dir . '/copias';
    if (!is_dir($bd)) @mkdir($bd, 0700, true);
    $bf = $bd . '/base-' . date('Y-m-d') . '.json';
    if (!is_file($bf)) {
      @copy($file, $bf);
      $all = glob($bd . '/base-*.json') ?: []; sort($all);
      while (count($all) > (int)($cfg['copias'] ?? 30)) @unlink(array_shift($all));
    }
  }
  $new = ['rev' => $rev + 1, 'savedAt' => time(), 'state' => $in->state];
  $tmp = $file . '.tmp';
  if (file_put_contents($tmp, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $file)) { flock($lock, LOCK_UN); out(500, ['error' => 'No pude guardar']); }
  flock($lock, LOCK_UN);
  out(200, ['rev' => $new['rev'], 'savedAt' => $new['savedAt']]);
}
out(405, ['error' => 'Método no permitido']);
