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

function default_state(): array {
    return [
        'version'     => 1,
        'updatedAt'   => time(),
        'title'       => 'RELOG MEDIA',
        'format'      => 'bo3',
        'sidePick'    => true,
        'deciderSide' => 'knife',      // knife | a | b
        'pool'        => DEFAULT_POOL,
        'teams'       => [
            'a' => ['name' => 'Team A', 'logo' => ''],
            'b' => ['name' => 'Team B', 'logo' => ''],
        ],
        'actions'     => [],
    ];
}

function state_file(): string { return DATA_DIR . '/state.json'; }

/** Read state (shared lock). */
function load_state(): array {
    $f = state_file();
    if (!is_file($f)) return default_state();
    $fh = fopen($f, 'r');
    if (!$fh) return default_state();
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $s = json_decode($raw, true);
    return is_array($s) ? array_replace(default_state(), $s) : default_state();
}

/** Atomic read-modify-write under exclusive lock. $fn receives state by ref and may throw. */
function mutate_state(callable $fn): array {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fh = fopen(state_file(), 'c+');
    if (!$fh) throw new RuntimeException('Ne mogu da otvorim data/state.json (dozvole foldera?)');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $s = json_decode($raw ?: '', true);
    $s = is_array($s) ? array_replace(default_state(), $s) : default_state();
    try {
        $fn($s);
        auto_advance($s);
        $s['version'] = ($s['version'] ?? 0) + 1;
        $s['updatedAt'] = time();
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $s;
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

/** Apply a tablet/admin choice. $expectStep guards against double taps. */
function apply_choice(array &$s, ?int $expectStep, ?string $map, ?string $side): void {
    $steps = build_steps($s);
    $i = count($s['actions']);
    if (!isset($steps[$i])) throw new InvalidArgumentException('Veto je završen.');
    if ($expectStep !== null && $expectStep !== $i) throw new InvalidArgumentException('Korak je već odigran — osveženo.');
    $step = $steps[$i];

    if ($step['type'] === 'ban' || $step['type'] === 'pick') {
        if (!$map || !in_array($map, remaining_maps($s), true)) throw new InvalidArgumentException('Mapa nije dostupna.');
        $s['actions'][] = ['type' => $step['type'], 'team' => $step['team'], 'map' => $map, 'side' => null, 't' => time()];
    } elseif ($step['type'] === 'side') {
        if (!in_array($side, ['ct', 't'], true)) throw new InvalidArgumentException('Izaberi CT ili T.');
        $s['actions'][] = ['type' => 'side', 'team' => $step['team'], 'map' => last_played_map($s), 'side' => $side, 't' => time()];
    } else {
        throw new InvalidArgumentException('Neočekivan korak.');
    }
}

function map_images(): array {
    $out = [];
    foreach (glob(__DIR__ . '/assets/maps/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $f) {
        $out[pathinfo($f, PATHINFO_FILENAME)] = 'assets/maps/' . basename($f) . '?v=' . filemtime($f);
    }
    return $out;
}

function public_payload(array $s): array {
    return [
        'state'     => $s,
        'steps'     => build_steps($s),
        'catalog'   => MAP_CATALOG,
        'mapImages' => map_images(),
        'tabletKeyRequired' => TABLET_KEY !== '',
    ];
}
