<?php
require_once __DIR__ . '/config.php';

const MAP_CATALOG = [
    'ancient'  => 'Ancient',
    'anubis'   => 'Anubis',
    'cache'    => 'Cache',
    'dust2'    => 'Dust II',
    'inferno'  => 'Inferno',
    'mirage'   => 'Mirage',
    'nuke'     => 'Nuke',
    'overpass' => 'Overpass',
    'train'    => 'Train',
    'vertigo'  => 'Vertigo',
];

const DEFAULT_POOL = ['ancient', 'anubis', 'cache', 'dust2', 'inferno', 'mirage', 'nuke'];
const MAX_SEED = 8;

// ===================================================================== JSON storage

function json_path(string $name): string { return DATA_DIR . '/' . $name . '.json'; }

function read_json(string $name, array $default): array {
    $f = json_path($name);
    if (!is_file($f)) return $default;
    $fh = fopen($f, 'r');
    if (!$fh) return $default;
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $d = json_decode($raw ?: '', true);
    return is_array($d) ? $d : $default;
}

/** Atomic read-modify-write under an exclusive lock. $fn gets data by reference and may throw. */
function write_json(string $name, array $default, callable $fn): array {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fh = fopen(json_path($name), 'c+');
    if (!$fh) throw new RuntimeException("Cannot open data/$name.json (folder permissions?)");
    flock($fh, LOCK_EX);
    try {
        $d = json_decode(stream_get_contents($fh) ?: '', true);
        $d = is_array($d) ? $d : $default;
        $fn($d);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $d;
}

// ===================================================================== veto state

function blank_team(string $t): array {
    return ['id' => '', 'name' => 'Team ' . strtoupper($t), 'logo' => '', 'seed' => null];
}

function default_state(): array {
    return [
        'version'     => 1,
        'updatedAt'   => time(),
        'title'       => 'RELOG MEDIA',
        'format'      => 'bo3',
        'sidePick'    => true,
        'deciderSide' => 'knife',      // knife | a | b
        'pool'        => DEFAULT_POOL,
        'teams'       => ['a' => blank_team('a'), 'b' => blank_team('b')],
        'actions'     => [],
        'webhook'     => null,         // result of the last Discord post for this veto
        'coin'        => null,         // coin toss: ['id', 'winner' => a|b, 'choice' => null|first|second, 't']
    ];
}

function normalize_state(array $s): array {
    $s = array_replace(default_state(), $s);
    foreach (['a', 'b'] as $t) $s['teams'][$t] = array_replace(blank_team($t), (array)($s['teams'][$t] ?? []));
    return $s;
}

function load_state(): array { return normalize_state(read_json('state', default_state())); }

/** Mutate the veto state; bumps version and runs the auto-decider. */
function mutate_state(callable $fn): array {
    $s = write_json('state', default_state(), function (&$s) use ($fn) {
        $s = normalize_state($s);
        $fn($s);
        auto_advance($s);
        if (count($s['actions']) < count(build_steps($s))) $s['webhook'] = null; // veto re-opened → may post again
        $s['version'] = ($s['version'] ?? 0) + 1;
        $s['updatedAt'] = time();
    });
    return normalize_state($s);
}

function other(string $t): string { return $t === 'a' ? 'b' : 'a'; }

/** Veto sequence. Team 'a' always starts (use Swap to change who starts). */
function build_steps(array $s): array {
    $seq = [
        'bo1' => [['ban','a'],['ban','b'],['ban','a'],['ban','b'],['ban','a'],['ban','b']],
        'bo3' => [['ban','a'],['ban','b'],['pick','a'],['pick','b'],['ban','a'],['ban','b']],
        'bo5' => [['ban','a'],['ban','b'],['pick','a'],['pick','b'],['pick','a'],['pick','b']],
    ][$s['format']] ?? [];

    $steps = [];
    foreach ($seq as [$type, $team]) {
        $steps[] = ['type' => $type, 'team' => $team];
        if ($type === 'pick' && $s['sidePick']) {
            $steps[] = ['type' => 'side', 'team' => other($team)];
        }
    }
    $steps[] = ['type' => 'decider', 'team' => null];
    if ($s['sidePick'] && in_array($s['deciderSide'], ['a', 'b'], true)) {
        $steps[] = ['type' => 'side', 'team' => $s['deciderSide']];
    }
    return $steps;
}

function is_complete(array $s): bool { return count($s['actions']) >= count(build_steps($s)); }

function remaining_maps(array $s): array {
    // side actions repeat a map, so look only at ban/pick/decider
    $taken = [];
    foreach ($s['actions'] as $a) {
        if (in_array($a['type'], ['ban', 'pick', 'decider'], true)) $taken[] = $a['map'];
    }
    return array_values(array_diff($s['pool'], $taken));
}

function last_played_map(array $s): ?string {
    for ($i = count($s['actions']) - 1; $i >= 0; $i--) {
        $a = $s['actions'][$i];
        if (in_array($a['type'], ['pick', 'decider'], true)) return $a['map'];
    }
    return null;
}

/** Decider step resolves itself as soon as it becomes current. */
function auto_advance(array &$s): void {
    $steps = build_steps($s);
    $i = count($s['actions']);
    if (isset($steps[$i]) && $steps[$i]['type'] === 'decider') {
        $left = remaining_maps($s);
        if (count($left) === 1) {
            $s['actions'][] = ['type' => 'decider', 'team' => null, 'map' => $left[0], 'side' => null, 't' => time()];
        }
    }
}

// ===================================================================== coin toss

/** Flip a fair coin between the two teams in the match. */
function coin_toss(array &$s): void {
    $s['coin'] = [
        'id'     => (int)($s['coin']['id'] ?? 0) + 1,
        'winner' => random_int(0, 1) ? 'a' : 'b',
        'choice' => null,
        't'      => time(),
    ];
    $s['actions'] = [];
}

/**
 * The toss winner decides to ban first or second. Team A always bans first,
 * so the teams are swapped when needed and the veto starts clean.
 */
function coin_decide(array &$s, string $choice): void {
    $c = $s['coin'];
    if (!$c) throw new InvalidArgumentException('Do the coin toss first.');
    if (!in_array($choice, ['first', 'second'], true)) throw new InvalidArgumentException('Choose first or second.');
    if ($c['choice'] !== null) throw new InvalidArgumentException('The toss winner has already chosen.');
    $want = $choice === 'first' ? 'a' : 'b';
    if ($c['winner'] !== $want) {
        $s['teams'] = ['a' => $s['teams']['b'], 'b' => $s['teams']['a']];
        $c['winner'] = $want;
    }
    $c['choice'] = $choice;
    $s['coin'] = $c;
    $s['actions'] = [];
}

/** Apply a tablet/admin choice. $expectStep guards against double taps. */
function apply_choice(array &$s, ?int $expectStep, ?string $map, ?string $side): void {
    if (!empty($s['coin']) && $s['coin']['choice'] === null) throw new InvalidArgumentException('Waiting for the coin toss winner to choose.');
    $steps = build_steps($s);
    $i = count($s['actions']);
    if (!isset($steps[$i])) throw new InvalidArgumentException('The veto is already complete.');
    if ($expectStep !== null && $expectStep !== $i) throw new InvalidArgumentException('That step was already played — refreshed.');
    $step = $steps[$i];

    if ($step['type'] === 'ban' || $step['type'] === 'pick') {
        if (!$map || !in_array($map, remaining_maps($s), true)) throw new InvalidArgumentException('That map is not available.');
        $s['actions'][] = ['type' => $step['type'], 'team' => $step['team'], 'map' => $map, 'side' => null, 't' => time()];
    } elseif ($step['type'] === 'side') {
        if (!in_array($side, ['ct', 't'], true)) throw new InvalidArgumentException('Choose CT or T.');
        $s['actions'][] = ['type' => 'side', 'team' => $step['team'], 'map' => last_played_map($s), 'side' => $side, 't' => time()];
    } else {
        throw new InvalidArgumentException('Unexpected step.');
    }
}

function map_images(): array {
    $out = [];
    foreach (glob(__DIR__ . '/assets/maps/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        $out[pathinfo($f, PATHINFO_FILENAME)] = 'assets/maps/' . basename($f) . '?v=' . filemtime($f);
    }
    return $out;
}

/** What everyone (tablet, overlay) may see. Seeds stay admin-only. */
function public_payload(array $s): array {
    $pub = $s;
    foreach (['a', 'b'] as $t) unset($pub['teams'][$t]['seed'], $pub['teams'][$t]['id']);
    unset($pub['webhook']);
    return [
        'state'     => $pub,
        'steps'     => build_steps($s),
        'catalog'   => MAP_CATALOG,
        'mapImages' => map_images(),
    ];
}

function admin_payload(array $s): array {
    $p = public_payload($s);
    $p['state'] = $s;
    $p['db'] = load_teams();
    $settings = load_settings();
    $p['settings'] = [
        'webhook'       => $settings['webhook'],
        'webhookActive' => effective_webhook() !== '',
    ];
    return $p;
}

// ===================================================================== team database

function load_teams(): array {
    $teams = read_json('teams', [])['teams'] ?? [];
    usort($teams, fn($x, $y) => [($x['seed'] ?? 99), mb_strtolower($x['name'])] <=> [($y['seed'] ?? 99), mb_strtolower($y['name'])]);
    return $teams;
}

function find_team(string $id): ?array {
    foreach (load_teams() as $t) if ($t['id'] === $id) return $t;
    return null;
}

/** Copy a DB team into the match slot shape. */
function team_slot(array $t): array {
    return ['id' => $t['id'], 'name' => $t['name'], 'logo' => $t['logo'] ?? '', 'seed' => $t['seed'] ?? null];
}

// ===================================================================== settings + Discord

function load_settings(): array { return array_replace(['webhook' => ''], read_json('settings', [])); }

function effective_webhook(): string {
    $w = trim(load_settings()['webhook']);
    return $w !== '' ? $w : DISCORD_WEBHOOK_DEFAULT;
}

function valid_webhook(string $url): bool {
    return (bool)preg_match('~^https://(?:www\.|ptb\.|canary\.)?(?:discord|discordapp)\.com/api/webhooks/\d+/[\w-]+$~', $url);
}

function side_name(string $x): string { return $x === 'ct' ? 'CT' : 'T'; }

/** Map names as written in the Discord result (Dust2, not Dust II). */
const DISCORD_MAP_NAMES = ['dust2' => 'Dust2'];

/**
 * Build the Discord message for a finished veto, e.g.
 *   1. YNG Sharks banned Dust2
 *   3. YNG Sharks picked Cache (paiN Gaming CT)
 *   7. System picked Inferno
 */
function discord_lines(array $s): array {
    $tn = fn($t) => $t ? $s['teams'][$t]['name'] : '';
    $mn = fn($m) => DISCORD_MAP_NAMES[$m] ?? (MAP_CATALOG[$m] ?? $m);

    $lines = [];    // one entry per ban / pick / decider
    $index = [];    // map id → position in $lines, to attach the side choice
    foreach ($s['actions'] as $a) {
        if ($a['type'] === 'ban' || $a['type'] === 'pick') {
            $index[$a['map']] = count($lines);
            $lines[] = ['text' => sprintf('%s %s %s', $tn($a['team']), $a['type'] === 'ban' ? 'banned' : 'picked', $mn($a['map'])), 'side' => ''];
        } elseif ($a['type'] === 'decider') {
            $index[$a['map']] = count($lines);
            $lines[] = ['text' => 'System picked ' . $mn($a['map']), 'side' => ''];
        } elseif ($a['type'] === 'side' && isset($index[$a['map']])) {
            $lines[$index[$a['map']]]['side'] = sprintf(' (%s %s)', $tn($a['team']), side_name($a['side']));
        }
    }
    $out = [];
    foreach ($lines as $i => $l) $out[] = ($i + 1) . '. ' . $l['text'] . $l['side'];
    return $out;
}

function discord_message(array $s): array {
    return [
        'username' => 'Relog Veto',
        'content' => mb_substr(implode("\n", discord_lines($s)), 0, 2000),
        'allowed_mentions' => ['parse' => []],
    ];
}

/** POST JSON to a Discord webhook. Returns [ok, http code, error text]. */
function post_discord(string $url, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $resp === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $body, 'timeout' => 8, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m) ? (int)$m[1] : 0;
        $err = $resp === false ? 'request failed' : '';
    }
    $ok = $code >= 200 && $code < 300;
    if (!$ok && !$err) $err = 'HTTP ' . $code . ($resp ? ': ' . mb_substr((string)$resp, 0, 200) : '');
    return [$ok, $code, $err];
}

/**
 * Post the finished veto to Discord once. Called after every state change;
 * claims the send under the state lock so two requests can never double-post.
 */
function maybe_send_webhook(bool $force = false): ?array {
    $url = effective_webhook();
    if ($url === '') return null;

    $claimed = false;
    $s = write_json('state', default_state(), function (&$s) use (&$claimed, $force) {
        $s = normalize_state($s);
        if (!is_complete($s)) return;
        if (!$force && $s['webhook'] !== null) return;
        $s['webhook'] = ['status' => 'sending', 't' => time()];
        $claimed = true;
    });
    if (!$claimed) return null;

    $s = normalize_state($s);
    [$ok, $code, $err] = post_discord($url, discord_message($s));
    $result = ['status' => $ok ? 'sent' : 'failed', 'code' => $code, 'error' => $err, 't' => time()];
    write_json('state', default_state(), function (&$st) use ($result) {
        $st['webhook'] = $result;
        $st['version'] = ($st['version'] ?? 0) + 1; // let the admin panel pick up the status
    });
    return $result;
}
