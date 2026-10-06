<?php
declare(strict_types=1);
require __DIR__ . '/synth.php';
session_start();

const MAX_BATCH = 25;
const MAX_COMBOS = 200;
const LOAD_LIMIT = 2.5;

$bank = new Bank();
is_dir('hits') ? $bank->load('hits') : $bank->save('hits');
$names = array_keys($bank->hits);
$selected = $_POST['hit'] ?? $_GET['hit'] ?? $names[0];
$hit = $bank->resolve($selected);
$notice = '';
$files = [];

function machineLoad(): float {
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    return $load ? (float)$load[0] : 0.0;
}
function backoff(): void {
    $load = machineLoad();
    if ($load > LOAD_LIMIT) sleep((int)min(5, 1 + ($load - LOAD_LIMIT) * 2));
}
function combos(Hit $hit, array $keys): array {
    $out = [[]];
    foreach ($keys as $key) {
        $next = [];
        foreach ($out as $row) foreach ($hit->params[$key]->points as $point) $next[] = $row + [$key => $point];
        $out = $next;
    }
    return $out;
}
function bundle(array $paths, string $dest, string $kind): void {
    if (is_file($dest)) unlink($dest);
    if ($kind === 'tar') {
        $tar = new PharData($dest);
        foreach ($paths as $p) if (is_file($p)) $tar->addFile($p, basename($p));
        return;
    }
    $zip = new ZipArchive();
    $zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($paths as $p) if (is_file($p)) $zip->addFile($p, basename($p));
    $zip->close();
}
function send(string $tmp): void {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($tmp) . '"');
    readfile($tmp);
    if (str_starts_with($tmp, sys_get_temp_dir())) unlink($tmp);
    exit;
}

if (isset($_GET['dl'])) {
    $stem = preg_replace('/\.(wav|png)$/', '', basename($_GET['dl']));
    $kind = $_GET['as'] ?? 'wav';
    $wav = "exports/$stem.wav";
    $png = "exports/$stem.png";
    if ($kind === 'wav' || $kind === 'png') send($kind === 'wav' ? $wav : $png);
    $tmp = sys_get_temp_dir() . "/$stem.$kind";
    bundle([$wav, $png], $tmp, $kind);
    send($tmp);
}
if (isset($_GET['group'])) {
    $group = basename($_GET['group']);
    $kind = $_GET['as'] ?? 'zip';
    $wavs = isset($_GET['stems'])
        ? array_map(fn($s) => 'exports/' . basename($s) . '.wav', explode(',', $_GET['stems']))
        : (glob('exports/' . $group . '*') ?: []);
    $paths = [];
    foreach ($wavs as $wav) {
        $paths[] = $wav;
        $paths[] = preg_replace('/\.wav$/', '.png', $wav);
    }
    $tmp = sys_get_temp_dir() . "/$group.$kind";
    bundle($paths, $tmp, $kind);
    send($tmp);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'ranges') {
            foreach ($hit->params as $key => $param) {
                $raw = trim($_POST['range'][$key] ?? '');
                if ($raw === '') continue;
                $points = array_map('floatval', preg_split('/\s*,\s*/', $raw));
                if (count($points) > 1 && $points[0] > $points[count($points) - 1]) throw new RuntimeException("$key must run from low to high.");
                $hit->params[$key] = new Param($points, $param->curve, $points[intdiv(count($points), 2)]);
            }
            $bank->save('hits');
            $notice = 'Ranges saved.';
        }
        if (($_POST['action'] ?? '') === 'sow') {
            if (!is_dir('exports')) mkdir('exports', 0777, true);
            $path = 'exports/' . $hit->name . '.wav';
            $hit->saveWav($path);
            $files = [$path, preg_replace('/\.wav$/', '.png', $path)];
            $notice = 'Sound and picture saved.';
        }
        if (($_POST['action'] ?? '') === 'permute') {
            $keys = array_values(array_filter($_POST['use'] ?? []));
            if (!$keys) throw new RuntimeException('Choose at least one setting.');
            $all = combos($hit, $keys);
            if (count($all) > MAX_COMBOS) throw new RuntimeException(count($all) . ' versions is over the limit of ' . MAX_COMBOS . '.');
            $done = (int)($_SESSION['done'][$hit->name] ?? 0);
            $slice = array_slice($all, $done, MAX_BATCH);
            if (!$slice) $notice = 'All versions are already made.';
            else {
                if (!is_dir('exports')) mkdir('exports', 0777, true);
                foreach ($slice as $combo) {
                    backoff();
                    $copy = unserialize(serialize($hit));
                    foreach ($combo as $key => $point) $copy->params[$key] = new Param([$point], $copy->params[$key]->curve, $point);
                    $file = $hit->name . '-' . implode('-', array_map(fn($k, $v) => "$k$v", array_keys($combo), $combo)) . '.wav';
                    $copy->saveWav("exports/$file");
                    $files[] = "exports/$file";
                }
                $_SESSION['done'][$hit->name] = $done + count($slice);
                $notice = 'Made ' . count($slice) . ' of ' . count($all) . '. Next batch starts at ' . $_SESSION['done'][$hit->name] . '.';
            }
        }
        if (($_POST['action'] ?? '') === 'compose') {
    $picked = array_values(array_filter($_POST['pick'] ?? []));
    if (!$picked) throw new RuntimeException('Choose at least one sound.');
    $hits = array_map(fn($name) => $bank->resolve($name), $picked);
    $bpm = max(40, min(220, (float)$_POST['bpm']));
    $beats = max(1, min(32, (int)$_POST['beats']));
    $root = max(20, min(880, (float)$_POST['root']));
    $piece = new Composition($hits, $bpm, $beats, $root, harmonicRatios($beats));
    $files = $piece->save('piece-' . $bpm . '-' . $root);
    $notice = 'Composition saved.';
}
if (($_POST['action'] ?? '') === 'compose-permute') {
    $picked = array_values(array_filter($_POST['pick'] ?? []));
    $bpms = array_map('floatval', preg_split('/\s*,\s*/', $_POST['bpms'] ?? '80,100'));
    $roots = array_map('floatval', preg_split('/\s*,\s*/', $_POST['roots'] ?? '55,82.5'));
    $beats = max(1, min(16, (int)$_POST['beats']));
    $all = [];
    foreach ($bpms as $bpm) foreach ($roots as $root) $all[] = [$bpm, $root];
    if (count($all) > MAX_COMBOS) throw new RuntimeException('Too many compositions.');
    $done = (int)($_SESSION['compose_done'] ?? 0);
    foreach (array_slice($all, $done, MAX_BATCH) as [$bpm, $root]) {
        backoff();
        $hits = array_map(fn($name) => $bank->resolve($name), $picked);
        $files = array_merge($files, (new Composition($hits, $bpm, $beats, $root, harmonicRatios($beats)))->save("piece-$bpm-$root"));
    }
    $_SESSION['compose_done'] = $done + min(MAX_BATCH, count($all) - $done);
    $notice = 'Composition batch saved through ' . $_SESSION['compose_done'] . ' of ' . count($all) . '.';
}
    } catch (Throwable $e) { $notice = $e->getMessage(); }
}

$preview = combos($hit, array_keys($hit->params));
$saved = glob('exports/*.wav') ?: [];
$byName = [];
$byShape = [];
foreach ($saved as $wav) {
    $stem = basename($wav, '.wav');
    $byName[explode('-', $stem)[0]][] = $stem;
    $byShape[substr(md5(implode(',', array_slice(explode('-', $stem), 1, 2))), 0, 6)][] = $stem;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sound Maker</title>
<style>
:root { --bg:#f4f1ea; --ink:#1c1915; --card:#fffdf8; --line:#d7d0c4; --btn:#1f4b3a; --btntext:#fff; }
body { margin:0; font:20px/1.45 Georgia, serif; background:var(--bg); color:var(--ink); }
body.lg { font-size:24px; } body.xl { font-size:28px; }
body.contrast { --bg:#000; --ink:#fff; --card:#111; --line:#fff; --btn:#ffd84a; --btntext:#000; }
main { max-width:960px; margin:auto; padding:24px 16px 64px; }
button, select, input { font:inherit; min-height:48px; }
button { background:var(--btn); color:var(--btntext); border:2px solid var(--btn); border-radius:8px; padding:8px 16px; }
select, input[type=text], input:not([type]) { width:100%; background:var(--card); color:var(--ink); border:2px solid var(--line); border-radius:8px; padding:8px; }
.card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin:16px 0; }
table { width:100%; border-collapse:collapse; }
th, td { text-align:left; padding:8px; border-bottom:1px solid var(--line); vertical-align:top; }
.note { padding:12px; background:#e7f0ea; border-radius:8px; }
a { color:inherit; }
img { max-width:100%; height:auto; }
</style>
</head>
<body>
<main>
<h1>Sound Maker</h1>
<div>
<button type="button" onclick="document.body.classList.toggle('lg')">Larger text</button>
<button type="button" onclick="document.body.classList.toggle('xl')">Largest text</button>
<button type="button" onclick="document.body.classList.toggle('contrast')">High contrast</button>
</div>
<?php if ($notice): ?><p class="note"><?=htmlspecialchars($notice)?></p><?php endif; ?>

<form method="get" class="card">
<label for="hit">Sound</label>
<select id="hit" name="hit" onchange="this.form.submit()">
<?php foreach ($names as $name): ?><option <?= $name === $hit->name ? 'selected' : '' ?>><?=htmlspecialchars($name)?></option><?php endforeach; ?>
</select>
</form>

<form method="post" class="card">
<input type="hidden" name="hit" value="<?=htmlspecialchars($hit->name)?>">
<h2>Ranges for <?=htmlspecialchars($hit->name)?></h2>
<p>Three numbers mean low, current, high. One number stays fixed. Separate numbers with commas.</p>
<table>
<tr><th>Use</th><th>Setting</th><th>Low, current, high</th></tr>
<?php foreach ($hit->params as $key => $param): ?>
<tr>
<td><input type="checkbox" name="use[]" value="<?=htmlspecialchars($key)?>" checked></td>
<td><?=htmlspecialchars($key)?></td>
<td><input name="range[<?=htmlspecialchars($key)?>]" value="<?=htmlspecialchars(implode(', ', $param->points))?>"></td>
</tr>
<?php endforeach; ?>
</table>
<button name="action" value="ranges">Save ranges</button>
<button name="action" value="sow">Make this sound</button>
<button name="action" value="permute">Make checked versions</button>
</form>

<form method="post" class="card">
<h2>Composition</h2>
<p>Check the sounds to place on the beats. They repeat in the order checked. Each beat is tuned by a harmonic of the root.</p>
<?php foreach ($names as $name): ?>
<label><input type="checkbox" name="pick[]" value="<?=htmlspecialchars($name)?>"> <?=htmlspecialchars($name)?></label>
<?php endforeach; ?>
<label for="bpm">Beats per minute</label>
<input id="bpm" name="bpm" value="90">
<label for="beats">Beats in the piece</label>
<input id="beats" name="beats" value="8">
<label for="root">Root note, Hz</label>
<input id="root" name="root" value="55">
<label for="bpms">Beats-per-minute versions</label>
<input id="bpms" name="bpms" value="80, 100, 120">
<label for="roots">Root versions, Hz</label>
<input id="roots" name="roots" value="55, 82.5, 110">
<button name="action" value="compose">Make one piece</button>
<button name="action" value="compose-permute">Make version pieces</button>
</form>

<section class="card">
<h2><?=count($preview)?> versions from the saved ranges</h2>
<p>A batch writes at most <?=MAX_BATCH?> of these, and waits if the computer is busy. The full list cannot pass <?=MAX_COMBOS?>.</p>
<ol>
<?php foreach (array_slice($preview, 0, 50) as $row): ?>
<li><?=htmlspecialchars(implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($row), $row)))?></li>
<?php endforeach; ?>
</ol>
<?php if (count($preview) > 50): ?><p>Showing 50 of <?=count($preview)?>.</p><?php endif; ?>
</section>

<section class="card">
<h2>Saved sounds</h2>
<?php foreach ($saved as $wav): $stem = basename($wav, '.wav'); ?>
<article>
<h3><?=htmlspecialchars($stem)?></h3>
<?php if (is_file("exports/$stem.png")): ?><img src="exports/<?=htmlspecialchars($stem)?>.png" alt=""><?php endif; ?>
<p>
<a href="?dl=<?=urlencode($stem)?>.wav&as=wav">WAV</a>
<a href="?dl=<?=urlencode($stem)?>.png&as=png">PNG</a>
<a href="?dl=<?=urlencode($stem)?>.wav&as=zip">WAV and PNG, ZIP</a>
<a href="?dl=<?=urlencode($stem)?>.wav&as=tar">WAV and PNG, TAR</a>
</p>
</article>
<?php endforeach; ?>

<h2>Groups by name</h2>
<?php foreach ($byName as $name => $stems): ?>
<p><?=htmlspecialchars($name)?> (<?=count($stems)?>)
<a href="?group=<?=urlencode($name)?>&as=zip">ZIP</a>
<a href="?group=<?=urlencode($name)?>&as=tar">TAR</a></p>
<?php endforeach; ?>

<h2>Groups by similar settings</h2>
<?php foreach ($byShape as $key => $stems): if (count($stems) < 2) continue; ?>
<p>Similar <?=htmlspecialchars($key)?> (<?=count($stems)?>)
<a href="?group=<?=urlencode($key)?>&as=zip&stems=<?=urlencode(implode(',', $stems))?>">ZIP</a>
<a href="?group=<?=urlencode($key)?>&as=tar&stems=<?=urlencode(implode(',', $stems))?>">TAR</a></p>
<?php endforeach; ?>
</section>
</main>
</body>
</html>