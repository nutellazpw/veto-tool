<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function is_admin(?string $t): bool { return ADMIN_TOKEN !== '' && $t !== null && hash_equals(ADMIN_TOKEN, $t); }

/** Admins get seeds, team DB and settings; everyone else gets the public view. */
function payload_for(bool $admin, ?array $s = null): array {
    $s = $s ?? load_state();
    return $admin ? admin_payload($s) : public_payload($s);
}

function save_logo(array $f, string $prefix): string {
    if ($f['error'] !== UPLOAD_ERR_OK) throw new InvalidArgumentException('Logo upload failed.');
    if ($f['size'] > MAX_LOGO_BYTES) throw new InvalidArgumentException('Logo is too large (max 5 MB).');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new InvalidArgumentException('Logo must be PNG, JPG, WEBP or GIF.');
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0775, true);
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) throw new RuntimeException('Cannot save the logo (is uploads/ writable?).');
    return 'uploads/' . $name;
}

function delete_logo(?string $path): void {
    if ($path && preg_match('~^uploads/team_[\w.-]+$~', $path) && is_file(__DIR__ . '/' . $path)) @unlink(__DIR__ . '/' . $path);
}

/** Keep the current match in sync when a DB team is edited or deleted. */
function sync_match_team(string $id, ?array $team): void {
    $s = load_state();
    $hit = false;
    foreach (['a', 'b'] as $t) if ($s['teams'][$t]['id'] === $id) $hit = true;
    if (!$hit) return;
    mutate_state(function (&$s) use ($id, $team) {
        foreach (['a', 'b'] as $t) {
            if ($s['teams'][$t]['id'] !== $id) continue;
            $s['teams'][$t] = $team ? team_slot($team) : blank_team($t);
        }
    });
}

try {
    // ---------- GET: state (polling) ----------
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $admin = is_admin($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? null);
        $s = load_state();
        $since = isset($_GET['since']) ? (int)$_GET['since'] : -1;
        if ($since === (int)$s['version']) out(['same' => true, 'version' => $s['version']]);
        out(payload_for($admin, $s));
    }

    // ---------- POST multipart: save team in database (with optional logo) ----------
    if (($_POST['action'] ?? '') === 'team_save') {
        if (!is_admin($_POST['token'] ?? null)) out(['error' => 'Admin only.'], 403);
        $admin = true;
        $id = preg_replace('~[^a-f0-9]~', '', (string)($_POST['id'] ?? ''));
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 32);
        $seedRaw = trim((string)($_POST['seed'] ?? ''));
        $seed = $seedRaw === '' ? null : (int)$seedRaw;
        if ($name === '') throw new InvalidArgumentException('Team name is required.');
        if ($seed !== null && ($seed < 1 || $seed > MAX_SEED)) throw new InvalidArgumentException('Seed must be 1–' . MAX_SEED . '.');

        $newLogo = !empty($_FILES['file']['name']) ? save_logo($_FILES['file'], 'team_db') : null;
        $old = null;
        $saved = null;
        try {
        write_json('teams', ['teams' => []], function (&$d) use ($id, $name, $seed, $newLogo, &$old, &$saved) {
            $d['teams'] = $d['teams'] ?? [];
            foreach ($d['teams'] as $t) {
                if ($t['id'] === $id) continue;
                if (mb_strtolower($t['name']) === mb_strtolower($name)) throw new InvalidArgumentException("A team named \"$name\" already exists.");
                if ($seed !== null && ($t['seed'] ?? null) === $seed) throw new InvalidArgumentException("Seed #$seed is already taken by {$t['name']}.");
            }
            $idx = null;
            foreach ($d['teams'] as $i => $t) if ($id !== '' && $t['id'] === $id) $idx = $i;
            if ($idx === null) {
                $saved = ['id' => bin2hex(random_bytes(6)), 'name' => $name, 'seed' => $seed, 'logo' => $newLogo ?? ''];
                $d['teams'][] = $saved;
            } else {
                $old = $d['teams'][$idx];
                $saved = array_replace($old, ['name' => $name, 'seed' => $seed]);
                if ($newLogo) $saved['logo'] = $newLogo;
                if (!empty($_POST['clearLogo']) && !$newLogo) $saved['logo'] = '';
                $d['teams'][$idx] = $saved;
            }
        });
        } catch (Throwable $e) { delete_logo($newLogo); throw $e; }
        if ($old && ($old['logo'] ?? '') !== ($saved['logo'] ?? '')) delete_logo($old['logo'] ?? '');
        sync_match_team($saved['id'], $saved);
        out(payload_for(true));
    }

    // ---------- POST JSON ----------
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';
    $admin = is_admin($in['token'] ?? null);

    if ($action === 'auth') {
        if (ADMIN_TOKEN === '') out(['ok' => false, 'error' => 'Admin is not configured — create config.local.php on the server.'], 403);
        if (!$admin) out(['ok' => false, 'error' => 'Wrong password.'], 403);
        out(['ok' => true]);
    }

    if ($action === 'choose') { // tablet (open to everyone) or admin
        $s = mutate_state(function (&$s) use ($in) {
            apply_choice($s, isset($in['step']) ? (int)$in['step'] : null, $in['map'] ?? null, $in['side'] ?? null);
        });
        $p = payload_for($admin, $s);
        // respond first, then talk to Discord so the tablet never waits on it
        if (is_complete($s) && effective_webhook() !== '') {
            echo json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            else { @ob_end_flush(); @flush(); }
            ignore_user_abort(true);
            maybe_send_webhook();
            exit;
        }
        out($p);
    }

    if (!$admin) out(['error' => 'Admin only.'], 403);

    switch ($action) {
        case 'undo':
            $s = mutate_state(function (&$s) {
                // pop the last real choice; an auto decider goes with the ban before it
                do { $last = array_pop($s['actions']); } while ($last && $last['type'] === 'decider' && $s['actions']);
            });
            out(payload_for(true, $s));

        case 'restart':
            out(payload_for(true, mutate_state(function (&$s) { $s['actions'] = []; })));

        case 'swap':
            out(payload_for(true, mutate_state(function (&$s) {
                $s['teams'] = ['a' => $s['teams']['b'], 'b' => $s['teams']['a']];
                $s['actions'] = [];
            })));

        case 'match_teams': // pick both teams from the database
            $a = find_team((string)($in['a'] ?? ''));
            $b = find_team((string)($in['b'] ?? ''));
            if (!$a || !$b) throw new InvalidArgumentException('Choose both teams.');
            if ($a['id'] === $b['id']) throw new InvalidArgumentException('Team A and Team B must be different teams.');
            out(payload_for(true, mutate_state(function (&$s) use ($a, $b) {
                $s['teams'] = ['a' => team_slot($a), 'b' => team_slot($b)];
                $s['actions'] = [];
            })));

        case 'team_delete':
            $id = (string)($in['id'] ?? '');
            $gone = null;
            write_json('teams', ['teams' => []], function (&$d) use ($id, &$gone) {
                $d['teams'] = array_values(array_filter($d['teams'] ?? [], function ($t) use ($id, &$gone) {
                    if ($t['id'] === $id) { $gone = $t; return false; }
                    return true;
                }));
            });
            if ($gone) { delete_logo($gone['logo'] ?? ''); sync_match_team($id, null); }
            out(payload_for(true));

        case 'settings':
            $url = trim((string)($in['webhook'] ?? ''));
            if ($url !== '' && !valid_webhook($url)) throw new InvalidArgumentException('That is not a Discord webhook URL (https://discord.com/api/webhooks/…).');
            write_json('settings', ['webhook' => ''], function (&$d) use ($url) { $d['webhook'] = $url; });
            out(payload_for(true));

        case 'webhook_test':
            $url = effective_webhook();
            if ($url === '') throw new InvalidArgumentException('No webhook set.');
            [$ok, $code, $err] = post_discord($url, ['username' => 'Relog Veto', 'content' => '✅ Relog veto tool is connected. Finished vetoes will be posted here.', 'allowed_mentions' => ['parse' => []]]);
            if (!$ok) throw new InvalidArgumentException('Discord rejected the test: ' . $err);
            out(['ok' => true] + payload_for(true));

        case 'webhook_resend':
            if (!is_complete(load_state())) throw new InvalidArgumentException('The veto is not finished yet.');
            $r = maybe_send_webhook(true);
            if (!$r) throw new InvalidArgumentException('No webhook set.');
            if ($r['status'] !== 'sent') throw new InvalidArgumentException('Discord error: ' . $r['error']);
            out(payload_for(true));

        case 'config':
            $s = mutate_state(function (&$s) use ($in) {
                $resetNeeded = false;
                if (isset($in['title'])) $s['title'] = mb_substr(trim((string)$in['title']), 0, 60);
                if (isset($in['format']) && in_array($in['format'], ['bo1', 'bo3', 'bo5'], true) && $in['format'] !== $s['format']) {
                    $s['format'] = $in['format'];
                    // sensible BO1 default: the team that did not ban last picks side
                    $s['deciderSide'] = $in['format'] === 'bo1' ? 'a' : 'knife';
                    $resetNeeded = true;
                }
                if (isset($in['sidePick']) && (bool)$in['sidePick'] !== $s['sidePick']) { $s['sidePick'] = (bool)$in['sidePick']; $resetNeeded = true; }
                if (isset($in['deciderSide']) && in_array($in['deciderSide'], ['knife', 'a', 'b'], true) && $in['deciderSide'] !== $s['deciderSide']) {
                    $s['deciderSide'] = $in['deciderSide']; $resetNeeded = true;
                }
                if (isset($in['pool'])) {
                    $pool = array_values(array_unique(array_intersect((array)$in['pool'], array_keys(MAP_CATALOG))));
                    if (count($pool) !== 7) throw new InvalidArgumentException('The map pool needs exactly 7 maps.');
                    if ($pool !== $s['pool']) { $s['pool'] = $pool; $resetNeeded = true; }
                }
                if ($resetNeeded) $s['actions'] = [];
            });
            out(payload_for(true, $s));

        default:
            out(['error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $e) {
    $admin = $admin ?? false;
    out(['error' => $e->getMessage(), 'payload' => payload_for($admin)], 409);
} catch (Throwable $e) {
    out(['error' => $e->getMessage()], 500);
}
