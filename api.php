<?php
// ============================================================
//  O Impostor em Os Maias — API Backend
//  Ficheiro: api.php
//  Requisitos: PHP 7.4+, permissão de escrita em ./rooms/
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Diretório de salas ──────────────────────────────────────
define('ROOMS_DIR', __DIR__ . '/rooms');
if (!is_dir(ROOMS_DIR)) {
    if (!mkdir(ROOMS_DIR, 0755, true)) {
        resp(false, 'Não foi possível criar o diretório de salas. Verifica as permissões.');
    }
}

// ── Dados do jogo ───────────────────────────────────────────
const CHARACTERS = [
    ['name' => 'Caetano da Maia',  'desc' => 'Antepassado da família, fundador da linhagem Maia. Homem austero e tradicional.',          'clue' => 'Fundador de uma grande família lisboeta. Homem do passado, rígido nos costumes.'],
    ['name' => 'Afonso da Maia',   'desc' => 'Patriarca da família Maia, homem de valores, cultura e princípios sólidos.',               'clue' => 'Ancião respeitado, avô do protagonista. Representa os valores e a dignidade da família.'],
    ['name' => 'Pedro da Maia',    'desc' => 'Pai de Carlos, homem fraco, melancólico e dominado pela paixão. Acaba tragicamente.',       'clue' => 'Pai do protagonista. A sua vida termina tragicamente por causa de uma paixão avassaladora.'],
    ['name' => 'Carlos da Maia',   'desc' => 'Médico elegante e culto, neto de Afonso. Protagonista da obra, herói falhado.',             'clue' => 'Personagem central. Médico e elegante, neto do patriarca — mas incapaz de cumprir o seu destino.'],
    ['name' => 'João da Ega',      'desc' => 'Melhor amigo de Carlos, escritor boémio, espirituoso e irreverente.',                      'clue' => 'Melhor amigo do protagonista. Escritor cheio de ironia que nunca concretiza as suas obras.'],
    ['name' => 'Tomás de Alencar', 'desc' => 'Poeta romântico ultrapassado e ridículo, figura caricata do salão lisboeta.',              'clue' => 'Poeta preso no passado romântico. Figura algo ridícula nos salões da alta sociedade.'],
];

const TASKS = [
    ['num' => 1, 'text' => 'Explica um acontecimento importante do livro Os Maias.'],
    ['num' => 2, 'text' => 'Descreve a relação entre duas personagens da obra.'],
    ['num' => 3, 'text' => 'Fala sobre um tema da obra (ex: crítica social, decadência, amor).'],
    ['num' => 4, 'text' => 'Fala como se fosses a tua personagem durante 30 segundos.'],
    ['num' => 5, 'text' => 'Diz uma característica marcante da tua personagem.'],
    ['num' => 6, 'text' => 'Conta um momento marcante envolvendo Carlos da Maia.'],
];

// ── Utilitários ─────────────────────────────────────────────
function resp($ok, $error = null, $data = []) {
    $out = array_merge(['ok' => $ok], $ok ? $data : ['error' => $error]);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function roomFile(string $id): string {
    $safe = preg_replace('/[^A-Z0-9]/', '', strtoupper($id));
    return ROOMS_DIR . '/' . $safe . '.json';
}

function loadRoom(string $id): ?array {
    $f = roomFile($id);
    if (!file_exists($f)) return null;
    $data = file_get_contents($f);
    return $data ? json_decode($data, true) : null;
}

function saveRoom(array $room): void {
    $room['updated_at'] = time();
    file_put_contents(roomFile($room['room_id']), json_encode($room, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function generateId(int $len = 4): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

function generateToken(): string { return bin2hex(random_bytes(10)); }

function playersList(array $room, string $my_pid): array {
    $out = [];
    foreach ($room['player_order'] as $pid) {
        if (!isset($room['players'][$pid])) continue;
        $p = $room['players'][$pid];
        $out[] = [
            'id'           => $pid,
            'name'         => $p['name'],
            'has_revealed' => $p['has_revealed'],
            'voted'        => $p['vote'] !== null,
            'is_me'        => ($pid === $my_pid),
            'is_host'      => ($pid === $room['host_id']),
        ];
    }
    return $out;
}

function cleanOldRooms(): void {
    // Apaga salas com mais de 4 horas
    foreach (glob(ROOMS_DIR . '/*.json') as $f) {
        if (filemtime($f) < time() - 14400) @unlink($f);
    }
}

// ── Input ────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// ── Rotas ────────────────────────────────────────────────────
switch ($action) {

    // ── Criar sala ───────────────────────────────────────────
    case 'create':
        cleanOldRooms();
        $name = trim($body['name'] ?? 'Anfitrião');
        if ($name === '') resp(false, 'Indica o teu nome.');

        $room_id = generateId(4);
        $attempts = 0;
        while (file_exists(roomFile($room_id)) && $attempts++ < 20) $room_id = generateId(4);

        $pid = generateToken();
        $room = [
            'room_id'        => $room_id,
            'host_id'        => $pid,
            'phase'          => 'lobby',
            'num_impostors'  => 1,
            'shared_character' => null,
            'current_task'   => 0,
            'tiebreak_round' => 0,
            'players'        => [
                $pid => ['name' => $name, 'is_impostor' => false, 'has_revealed' => false, 'vote' => null]
            ],
            'player_order'   => [$pid],
            'created_at'     => time(),
            'updated_at'     => time(),
        ];
        saveRoom($room);
        resp(true, null, ['room_id' => $room_id, 'player_id' => $pid, 'name' => $name]);

    // ── Entrar na sala ───────────────────────────────────────
    case 'join':
        $room_id = strtoupper(trim($body['room_id'] ?? ''));
        $name    = trim($body['name'] ?? '');
        if ($name === '') resp(false, 'Indica o teu nome.');

        $room = loadRoom($room_id);
        if (!$room)                                   resp(false, 'Sala não encontrada. Verifica o código.');
        if ($room['phase'] !== 'lobby')               resp(false, 'O jogo já começou. Aguarda a próxima ronda.');
        if (count($room['players']) >= 30)            resp(false, 'Sala cheia (máximo 30 jogadores).');

        // Verificar nome duplicado
        foreach ($room['players'] as $p) {
            if (strtolower($p['name']) === strtolower($name)) resp(false, 'Esse nome já está em uso.');
        }

        $pid = generateToken();
        $room['players'][$pid] = ['name' => $name, 'is_impostor' => false, 'has_revealed' => false, 'vote' => null];
        $room['player_order'][] = $pid;
        saveRoom($room);
        resp(true, null, ['room_id' => $room_id, 'player_id' => $pid, 'name' => $name]);

    // ── Iniciar jogo (anfitrião) ─────────────────────────────
    case 'start':
        $room_id      = $body['room_id']      ?? '';
        $pid          = $body['player_id']    ?? '';
        $num_impostors = intval($body['num_impostors'] ?? 1);

        $room = loadRoom($room_id);
        if (!$room)                          resp(false, 'Sala não encontrada.');
        if ($room['host_id'] !== $pid)       resp(false, 'Só o anfitrião pode iniciar o jogo.');
        if (count($room['players']) < 2)     resp(false, 'São necessários pelo menos 2 jogadores.');

        // Sorteia personagem única
        $chars = CHARACTERS;
        $char  = $chars[array_rand($chars)];
        $room['shared_character'] = $char;
        $room['num_impostors']    = max(1, min($num_impostors, count($room['players']) - 1));
        $room['phase']            = 'reveal';
        $room['current_task']     = 0;

        // Atribui impostores aleatoriamente
        $order = $room['player_order'];
        shuffle($order);
        $impostors = array_slice($order, 0, $room['num_impostors']);

        foreach ($room['players'] as $id => &$p) {
            $p['is_impostor']  = in_array($id, $impostors);
            $p['has_revealed'] = false;
            $p['vote']         = null;
        }
        unset($p);
        saveRoom($room);
        resp(true);

    // ── Marcar carta como vista ──────────────────────────────
    case 'reveal':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room || !isset($room['players'][$pid])) resp(false, 'Sessão inválida.');

        $room['players'][$pid]['has_revealed'] = true;

        // Se todos revelaram, avança automaticamente para tasks
        $all = true;
        foreach ($room['players'] as $p) { if (!$p['has_revealed']) { $all = false; break; } }
        if ($all) $room['phase'] = 'tasks';

        saveRoom($room);
        resp(true, null, ['all_revealed' => $all]);

    // ── Avançar tarefa (anfitrião) ───────────────────────────
    case 'next_task':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');
        if ($room['current_task'] < count(TASKS) - 1) $room['current_task']++;
        saveRoom($room);
        resp(true);

    case 'prev_task':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');
        if ($room['current_task'] > 0) $room['current_task']--;
        saveRoom($room);
        resp(true);

    // ── Mudar fase (anfitrião) ───────────────────────────────
    case 'set_phase':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $phase   = $body['phase']     ?? '';
        $room    = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');
        $allowed = ['tasks', 'discussion', 'voting', 'results'];
        if (!in_array($phase, $allowed, true)) resp(false, 'Fase inválida.');
        $room['phase'] = $phase;
        saveRoom($room);
        resp(true);

    // ── Votar ────────────────────────────────────────────────
    case 'vote':
        $room_id   = $body['room_id']   ?? '';
        $pid       = $body['player_id'] ?? '';
        $voted_for = $body['voted_for'] ?? '';
        $room      = loadRoom($room_id);
        if (!$room || !isset($room['players'][$pid]))    resp(false, 'Sessão inválida.');
        if ($room['phase'] !== 'voting')                  resp(false, 'Não é a fase de votação.');
        if ($voted_for === $pid)                          resp(false, 'Não podes votar em ti próprio.');
        if (!isset($room['players'][$voted_for]))         resp(false, 'Jogador inválido.');
        if ($room['players'][$pid]['vote'] !== null)      resp(false, 'Já votaste.');

        $room['players'][$pid]['vote'] = $voted_for;

        // Verifica se todos votaram
        $all_voted = true;
        foreach ($room['players'] as $p) {
            if ($p['vote'] === null) { $all_voted = false; break; }
        }

        if ($all_voted) {
            // Verificar empate
            $counts = [];
            foreach ($room['player_order'] as $id) $counts[$id] = 0;
            foreach ($room['players'] as $p) {
                if ($p['vote'] && isset($counts[$p['vote']])) $counts[$p['vote']]++;
            }
            $max = max($counts);
            $leaders = array_keys(array_filter($counts, fn($v) => $v === $max));

            if (count($leaders) > 1) {
                // EMPATE — nova ronda de tarefas
                $room['phase'] = 'tiebreak';
                $room['tiebreak_round'] = ($room['tiebreak_round'] ?? 0) + 1;
                // Reset votos
                foreach ($room['players'] as &$p) { $p['vote'] = null; }
                unset($p);
                $room['current_task'] = 0;
            } else {
                $room['phase'] = 'results';
            }
        }

        saveRoom($room);
        resp(true, null, ['all_voted' => $all_voted]);

    // ── Expulsar jogador (anfitrião) ─────────────────────────
    case 'kick':
        $room_id   = $body['room_id']   ?? '';
        $pid       = $body['player_id'] ?? '';
        $kick_id   = $body['kick_id']   ?? '';
        $room      = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');
        if ($kick_id === $pid) resp(false, 'Não podes expulsar-te a ti próprio.');
        if (!isset($room['players'][$kick_id])) resp(false, 'Jogador não encontrado.');
        
        unset($room['players'][$kick_id]);
        $room['player_order'] = array_values(array_filter($room['player_order'], fn($id) => $id !== $kick_id));
        saveRoom($room);
        resp(true);

    // ── Sair da sala ─────────────────────────────────────────
    case 'leave':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room) resp(false, 'Sala não encontrada.');
        
        // Se for o anfitrião e houver mais jogadores, transfere para o próximo
        if ($room['host_id'] === $pid && count($room['players']) > 1) {
            $remaining = array_filter($room['player_order'], fn($id) => $id !== $pid);
            $room['host_id'] = reset($remaining);
        }
        
        unset($room['players'][$pid]);
        $room['player_order'] = array_values(array_filter($room['player_order'], fn($id) => $id !== $pid));
        
        // Se não sobrar ninguém, apaga a sala
        if (count($room['players']) === 0) {
            @unlink(roomFile($room_id));
            resp(true, null, ['deleted' => true]);
        }
        
        saveRoom($room);
        resp(true);

    // ── Eliminar sala (anfitrião) ────────────────────────────
    case 'delete':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');
        
        @unlink(roomFile($room_id));
        resp(true);

    // ── Reiniciar sala (anfitrião) ───────────────────────────
    case 'restart':
        $room_id = $body['room_id']   ?? '';
        $pid     = $body['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room || $room['host_id'] !== $pid) resp(false, 'Sem permissão.');

        $room['phase']             = 'lobby';
        $room['shared_character']  = null;
        $room['current_task']      = 0;
        foreach ($room['players'] as &$p) {
            $p['is_impostor']  = false;
            $p['has_revealed'] = false;
            $p['vote']         = null;
        }
        unset($p);
        saveRoom($room);
        resp(true);

    // ── Estado atual ─────────────────────────────────────────
    case 'state':
        $room_id = $_GET['room_id']   ?? '';
        $pid     = $_GET['player_id'] ?? '';
        $room    = loadRoom($room_id);
        if (!$room) resp(false, 'Sala não encontrada.');

        $me   = $room['players'][$pid] ?? null;
        $char = $room['shared_character'];

        // Carta privada do jogador
        $my_card = null;
        if ($me && $char) {
            $my_card = $me['is_impostor']
                ? ['type' => 'impostor', 'clue' => $char['clue']]
                : ['type' => 'innocent', 'name' => $char['name'], 'desc' => $char['desc']];
        }

        // Tiebreak info
        $tiebreak_info = null;
        if ($room['phase'] === 'tiebreak' && ($room['tiebreak_round'] ?? 0) > 0) {
            // Calcular quem ficou empatado
            $counts = [];
            foreach ($room['player_order'] as $id) $counts[$id] = 0;
            foreach ($room['players'] as $p) {
                if ($p['vote'] && isset($counts[$p['vote']])) $counts[$p['vote']]++;
            }
            $max = max($counts);
            $tied_ids = array_keys(array_filter($counts, fn($v) => $v === $max));
            $tied_names = array_map(fn($id) => $room['players'][$id]['name'], $tied_ids);
            $tiebreak_info = [
                'round' => $room['tiebreak_round'],
                'tied_players' => $tied_names,
            ];
        }

        // Resultados (só na fase results)
        $results = null;
        if ($room['phase'] === 'results' && $char) {
            $counts = [];
            foreach ($room['player_order'] as $id) $counts[$id] = 0;
            foreach ($room['players'] as $p) {
                if ($p['vote'] && isset($counts[$p['vote']])) $counts[$p['vote']]++;
            }
            arsort($counts);
            $max          = (int) reset($counts);
            $elim_ids     = array_keys(array_filter($counts, fn($v) => $v === $max));
            $imp_elim     = false;
            foreach ($elim_ids as $eid) {
                if ($room['players'][$eid]['is_impostor']) { $imp_elim = true; break; }
            }

            $tally = [];
            foreach ($room['player_order'] as $id) {
                $tally[] = ['id' => $id, 'name' => $room['players'][$id]['name'], 'count' => $counts[$id] ?? 0];
            }
            usort($tally, fn($a, $b) => $b['count'] - $a['count']);

            $results = [
                'character'          => $char,
                'impostors'          => array_values(array_map(
                    fn($id) => $room['players'][$id]['name'],
                    array_keys(array_filter($room['players'], fn($p) => $p['is_impostor']))
                )),
                'eliminated'         => array_map(fn($id) => $room['players'][$id]['name'], $elim_ids),
                'impostor_eliminated' => $imp_elim,
                'tally'              => $tally,
            ];
        }

        $tasks = TASKS;
        resp(true, null, [
            'phase'        => $room['phase'],
            'room_id'      => $room['room_id'],
            'is_host'      => ($pid === $room['host_id']),
            'players'      => playersList($room, $pid),
            'my_card'      => $my_card,
            'has_revealed' => $me ? $me['has_revealed'] : false,
            'my_vote'      => $me ? $me['vote'] : null,
            'current_task' => $room['current_task'],
            'task'         => $tasks[$room['current_task']] ?? null,
            'num_tasks'    => count($tasks),
            'results'      => $results,
            'updated_at'   => $room['updated_at'],
            'num_impostors' => $room['num_impostors'],
            'tiebreak_round' => $room['tiebreak_round'] ?? 0,
            'tiebreak_info' => $tiebreak_info,
        ]);

    default:
        resp(false, 'Ação desconhecida.');
}