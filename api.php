<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_admin(?string $t): bool { return ADMIN_TOKEN !== '' && $t !== null && hash_equals(ADMIN_TOKEN, $t); }
function is_tablet(?string $k): bool { return TABLET_KEY === '' || ($k !== null && hash_equals(TABLET_KEY, $k)); }

// ---------- GET: state (polling) ----------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = load_state();
    $since = isset($_GET['since']) ? (int)$_GET['since'] : -1;
    if ($since === (int)$s['version']) out(['same' => true, 'version' => $s['version']]);
    out(public_payload($s));
}

// ---------- POST: logo upload (multipart) ----------
if (isset($_POST['action']) && $_POST['action'] === 'logo') {
    if (!is_admin($_POST['token'] ?? null)) out(['error' => 'Pogrešna admin lozinka.'], 403);
    $team = $_POST['team'] ?? '';
    if (!in_array($team, ['a', 'b'], true)) out(['error' => 'Nepoznat tim.'], 400);

    if (!empty($_POST['clear'])) {
        $s = mutate_state(function (&$s) use ($team) { $s['teams'][$team]['logo'] = ''; });
        out(public_payload($s));
    }

    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) out(['error' => 'Upload nije uspeo.'], 400);
    if ($f['size'] > MAX_LOGO_BYTES) out(['error' => 'Fajl je prevelik (max 5 MB).'], 400);
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if (!$ext) out(['error' => 'Dozvoljeni su PNG, JPG, WEBP i GIF.'], 400);

    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    $name = 'team_' . $team . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) out(['error' => 'Ne mogu da sačuvam fajl (dozvole za uploads/?).'], 500);

    $s = mutate_state(function (&$s) use ($team, $name) { $s['teams'][$team]['logo'] = 'uploads/' . $name; });
    out(public_payload($s));
}

// ---------- POST: JSON actions ----------
$in = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? '';
$admin = is_admin($in['token'] ?? null);

try {
    switch ($action) {
        case 'auth':
            if (ADMIN_TOKEN === '') out(['ok' => false, 'error' => 'Admin nije podešen — napravi config.local.php na serveru.'], 403);
            if (!$admin) out(['ok' => false, 'error' => 'Pogrešna lozinka.'], 403);
            out(['ok' => true, 'tabletKey' => TABLET_KEY]);

        case 'key':
            out(['ok' => is_tablet($in['key'] ?? null)]);

        case 'choose': // tablet or admin
            if (!$admin && !is_tablet($in['key'] ?? null)) out(['error' => 'Tablet ključ nije ispravan.'], 403);
            $s = mutate_state(function (&$s) use ($in) {
                apply_choice($s, isset($in['step']) ? (int)$in['step'] : null, $in['map'] ?? null, $in['side'] ?? null);
            });
            out(public_payload($s));

        case 'undo':
            if (!$admin) out(['error' => 'Samo admin.'], 403);
            $s = mutate_state(function (&$s) {
                // pop the last real choice; an auto decider goes with the ban before it
                do { $last = array_pop($s['actions']); } while ($last && $last['type'] === 'decider' && $s['actions']);
            });
            out(public_payload($s));

        case 'restart':
            if (!$admin) out(['error' => 'Samo admin.'], 403);
            $s = mutate_state(function (&$s) { $s['actions'] = []; });
            out(public_payload($s));

        case 'swap':
            if (!$admin) out(['error' => 'Samo admin.'], 403);
            $s = mutate_state(function (&$s) {
                $s['teams'] = ['a' => $s['teams']['b'], 'b' => $s['teams']['a']];
                $s['actions'] = [];
            });
            out(public_payload($s));

        case 'config':
            if (!$admin) out(['error' => 'Samo admin.'], 403);
            $s = mutate_state(function (&$s) use ($in) {
                $resetNeeded = false;
                if (isset($in['title'])) $s['title'] = mb_substr(trim((string)$in['title']), 0, 60);
                foreach (['a', 'b'] as $t) {
                    if (isset($in['teams'][$t]['name'])) $s['teams'][$t]['name'] = mb_substr(trim((string)$in['teams'][$t]['name']), 0, 32) ?: strtoupper("Team $t");
                }
                if (isset($in['format']) && in_array($in['format'], ['bo1', 'bo3', 'bo5'], true) && $in['format'] !== $s['format']) {
                    $s['format'] = $in['format'];
                    // sensible BO1 default: team that did not ban last picks side
                    $s['deciderSide'] = $in['format'] === 'bo1' ? 'a' : 'knife';
                    $resetNeeded = true;
                }
                if (isset($in['sidePick']) && (bool)$in['sidePick'] !== $s['sidePick']) { $s['sidePick'] = (bool)$in['sidePick']; $resetNeeded = true; }
                if (isset($in['deciderSide']) && in_array($in['deciderSide'], ['knife', 'a', 'b'], true) && $in['deciderSide'] !== $s['deciderSide']) {
                    $s['deciderSide'] = $in['deciderSide']; $resetNeeded = true;
                }
                if (isset($in['pool'])) {
                    $pool = array_values(array_unique(array_intersect((array)$in['pool'], array_keys(MAP_CATALOG))));
                    if (count($pool) !== 7) throw new InvalidArgumentException('Map pool mora imati tačno 7 mapa.');
                    if ($pool !== $s['pool']) { $s['pool'] = $pool; $resetNeeded = true; }
                }
                if ($resetNeeded) $s['actions'] = [];
            });
            out(public_payload($s));

        default:
            out(['error' => 'Nepoznata akcija.'], 400);
    }
} catch (InvalidArgumentException $e) {
    out(['error' => $e->getMessage(), 'payload' => public_payload(load_state())], 409);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}
