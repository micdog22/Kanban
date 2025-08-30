<?php
declare(strict_types=1);
session_start();

/**
 * MicDog Kanban - API (Premium UI)
 */

header_remove('X-Powered-By');

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function method(): string { return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'); }

function route_path(): string {
    $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $path = '/' . ltrim(substr($uri, strlen($base)), '/');
    $path = preg_replace('#/index\.php#', '', $path, 1);
    return $path === '' ? '/' : $path;
}

function query(string $k, ?string $default = null): ?string {
    return isset($_GET[$k]) && $_GET[$k] !== '' ? (string)$_GET[$k] : $default;
}

function parse_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : [];
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'kanban.sqlite';
    $needInit = !file_exists($dbPath);
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($needInit) init_db($pdo);
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        PRAGMA journal_mode = WAL;
        CREATE TABLE IF NOT EXISTS boards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS columns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY(board_id) REFERENCES boards(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS cards (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            board_id INTEGER NOT NULL,
            column_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            labels TEXT,
            due_date TEXT,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT,
            FOREIGN KEY(board_id) REFERENCES boards(id) ON DELETE CASCADE,
            FOREIGN KEY(column_id) REFERENCES columns(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_columns_board_pos ON columns(board_id, position);
        CREATE INDEX IF NOT EXISTS idx_cards_col_pos ON cards(column_id, position);
    ");

    // Seed: default board + 4 columns + demo cards
    $pdo->exec("INSERT INTO boards (name) VALUES ('MicDog Board')");
    $boardId = (int)$pdo->lastInsertId();

    $stc = $pdo->prepare("INSERT INTO columns (board_id, name, position) VALUES (?, ?, ?)");
    $stc->execute([$boardId, 'Backlog', 0]);
    $colBack = (int)$pdo->lastInsertId();
    $stc->execute([$boardId, 'Em Progresso', 1]);
    $colDoing = (int)$pdo->lastInsertId();
    $stc->execute([$boardId, 'Revisão', 2]);
    $colRev = (int)$pdo->lastInsertId();
    $stc->execute([$boardId, 'Concluído', 3]);
    $colDone = (int)$pdo->lastInsertId();

    $st = $pdo->prepare("INSERT INTO cards (board_id, column_id, title, description, labels, due_date, position) VALUES (?,?,?,?,?,?,?)");
    $st->execute([$boardId, $colBack, 'Página inicial', 'Layout hero + CTA', 'design,frontend', date('Y-m-d', strtotime('+3 days')), 0]);
    $st->execute([$boardId, $colBack, 'API de comentários', 'Endpoints /comments', 'backend,api', null, 1]);
    $st->execute([$boardId, $colDoing, 'Autenticação', 'Sessão + CSRF', 'backend,security', date('Y-m-d', strtotime('+1 day')), 0]);
    $st->execute([$boardId, $colRev, 'Landing de produto', 'Ajustes de copy', 'marketing,frontend', null, 0]);
    $st->execute([$boardId, $colDone, 'Setup do projeto', 'Estrutura com PHP + SQLite', 'chore', null, 0]);
}

// CSRF
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function require_csrf(): void {
    if (method() === 'GET') return;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdr || !hash_equals($_SESSION['csrf'], $hdr)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

$path = route_path();
$method = method();

try {
    if ($path === '/' || $path === '') {
        json_response(['ok' => true, 'service' => 'MicDog Kanban API']);
    }

    if ($path === '/csrf' && $method === 'GET') {
        json_response(['token' => $_SESSION['csrf']]);
    }

    // Boards
    if ($path === '/boards') {
        $pdo = get_db();
        if ($method === 'GET') {
            $rows = $pdo->query("SELECT id, name, created_at FROM boards ORDER BY id ASC")->fetchAll();
            json_response(['items' => $rows]);
        }
        if ($method === 'POST') {
            require_csrf();
            $d = parse_json();
            $name = trim((string)($d['name'] ?? ''));
            if ($name === '') json_response(['errors' => ['name' => 'Required']], 422);
            $st = $pdo->prepare("INSERT INTO boards (name) VALUES (?)");
            $st->execute([$name]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query("SELECT id, name, created_at FROM boards WHERE id = $id")->fetch();
            json_response(['item' => $row], 201);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/boards/(\d+)$#', $path, $m)) {
        $pdo = get_db();
        $id = (int)$m[1];
        if ($method === 'GET') {
            $row = $pdo->query("SELECT id, name, created_at FROM boards WHERE id = $id")->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }
        if ($method === 'DELETE') {
            require_csrf();
            $st = $pdo->prepare("DELETE FROM boards WHERE id = ?");
            $st->execute([$id]);
            json_response(['deleted' => $id]);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    // Columns
    if ($path === '/columns') {
        $pdo = get_db();
        if ($method === 'GET') {
            $boardId = (int)(query('board_id') ?? 0);
            $st = $pdo->prepare("SELECT id, board_id, name, position FROM columns WHERE board_id = ? ORDER BY position ASC, id ASC");
            $st->execute([$boardId]);
            json_response(['items' => $st->fetchAll()]);
        }
        if ($method === 'POST') {
            require_csrf();
            $d = parse_json();
            $boardId = (int)($d['board_id'] ?? 0);
            $name = trim((string)($d['name'] ?? ''));
            if ($boardId <= 0 || $name === '') json_response(['errors' => ['board_id' => 'Required', 'name' => 'Required']], 422);
            $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), -1) AS m FROM columns WHERE board_id = ?");
            $stmt->execute([$boardId]);
            $pos = (int)$stmt->fetchColumn() + 1;
            $st = $pdo->prepare("INSERT INTO columns (board_id, name, position) VALUES (?, ?, ?)");
            $st->execute([$boardId, $name, $pos]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query("SELECT id, board_id, name, position FROM columns WHERE id = $id")->fetch();
            json_response(['item' => $row], 201);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/columns/(\d+)$#', $path, $m)) {
        $pdo = get_db();
        $id = (int)$m[1];
        if ($method === 'PUT') {
            require_csrf();
            $d = parse_json();
            $fields = [];
            $params = [':id' => $id];
            if (isset($d['name'])) { $fields[] = 'name = :name'; $params[':name'] = trim((string)$d['name']); }
            if (!$fields) json_response(['error' => 'Nothing to update'], 400);
            $st = $pdo->prepare("UPDATE columns SET ".implode(',',$fields)." WHERE id = :id");
            $st->execute($params);
            $row = $pdo->query("SELECT id, board_id, name, position FROM columns WHERE id = $id")->fetch();
            json_response(['item' => $row]);
        }
        if ($method === 'DELETE') {
            require_csrf();
            $st = $pdo->prepare("DELETE FROM columns WHERE id = ?");
            $st->execute([$id]);
            json_response(['deleted' => $id]);
        }
        if ($method === 'GET') {
            $row = $pdo->query("SELECT id, board_id, name, position FROM columns WHERE id = $id")->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/columns/(\d+)/move$#', $path, $m) && $method === 'POST') {
        require_csrf();
        $pdo = get_db();
        $id = (int)$m[1];
        $d = parse_json();
        $toPos = (int)($d['to_position'] ?? 0);

        $col = $pdo->query("SELECT id, board_id, position FROM columns WHERE id = $id")->fetch();
        if (!$col) json_response(['error' => 'Not found'], 404);

        $pdo->beginTransaction();
        $boardId = (int)$col['board_id'];
        $curr = (int)$col['position'];

        if ($toPos < 0) $toPos = 0;
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position),0) FROM columns WHERE board_id = ?");
        $stmt->execute([$boardId]);
        $max = (int)$stmt->fetchColumn();
        if ($toPos > $max) $toPos = $max;

        if ($toPos == $curr) { $pdo->commit(); json_response(['item' => $col]); }

        if ($toPos > $curr) {
            $st = $pdo->prepare("UPDATE columns SET position = position - 1 WHERE board_id = ? AND position > ? AND position <= ?");
            $st->execute([$boardId, $curr, $toPos]);
        } else {
            $st = $pdo->prepare("UPDATE columns SET position = position + 1 WHERE board_id = ? AND position >= ? AND position < ?");
            $st->execute([$boardId, $toPos, $curr]);
        }
        $st = $pdo->prepare("UPDATE columns SET position = ? WHERE id = ?");
        $st->execute([$toPos, $id]);

        $pdo->commit();
        $row = $pdo->query("SELECT id, board_id, name, position FROM columns WHERE id = $id")->fetch();
        json_response(['item' => $row]);
    }

    // Cards
    if ($path === '/cards') {
        $pdo = get_db();
        if ($method === 'GET') {
            $boardId = query('board_id'); $colId = query('column_id'); $q = query('q');
            if ($boardId) {
                $sql = "SELECT * FROM cards WHERE board_id = ?";
                $params = [(int)$boardId];
                if ($q) { $sql .= " AND (title LIKE ? OR labels LIKE ?)"; $like = '%'.$q.'%'; array_push($params, $like, $like); }
                $sql .= " ORDER BY column_id ASC, position ASC, id ASC";
                $st = $pdo->prepare($sql); $st->execute($params);
                json_response(['items' => $st->fetchAll()]);
            } elseif ($colId) {
                $st = $pdo->prepare("SELECT * FROM cards WHERE column_id = ? ORDER BY position ASC, id ASC");
                $st->execute([(int)$colId]);
                json_response(['items' => $st->fetchAll()]);
            } else {
                json_response(['items' => []]);
            }
        }
        if ($method === 'POST') {
            require_csrf();
            $d = parse_json();
            $boardId = (int)($d['board_id'] ?? 0);
            $colId = (int)($d['column_id'] ?? 0);
            $title = trim((string)($d['title'] ?? ''));
            $desc = (string)($d['description'] ?? null);
            $labels = (string)($d['labels'] ?? null);
            $due = (string)($d['due_date'] ?? null);
            if ($boardId <= 0 || $colId <= 0 || $title === '') json_response(['errors' => ['board_id'=>'Required','column_id'=>'Required','title'=>'Required']], 422);

            $st = $pdo->prepare("SELECT COALESCE(MAX(position), -1) FROM cards WHERE column_id = ?");
            $st->execute([$colId]);
            $pos = (int)$st->fetchColumn() + 1;

            $ins = $pdo->prepare("INSERT INTO cards (board_id, column_id, title, description, labels, due_date, position) VALUES (?,?,?,?,?,?,?)");
            $ins->execute([$boardId, $colId, $title, $desc ?: null, $labels ?: null, $due ?: null, $pos]);
            $id = (int)$pdo->lastInsertId();
            $row = $pdo->query("SELECT * FROM cards WHERE id = $id")->fetch();
            json_response(['item' => $row], 201);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/cards/(\d+)$#', $path, $m)) {
        $pdo = get_db();
        $id = (int)$m[1];
        if ($method === 'PUT') {
            require_csrf();
            $d = parse_json();
            $fields = [];
            $params = [':id' => $id];
            foreach (['title','description','labels','due_date'] as $f) {
                if (array_key_exists($f, $d)) {
                    $fields[] = "$f = :$f";
                    $params[":$f"] = $d[$f] !== '' ? $d[$f] : null;
                }
            }
            if (!$fields) json_response(['error' => 'Nothing to update'], 400);
            $sql = "UPDATE cards SET ".implode(',', $fields).", updated_at = datetime('now') WHERE id = :id";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $pdo->query("SELECT * FROM cards WHERE id = $id")->fetch();
            json_response(['item' => $row]);
        }
        if ($method === 'DELETE') {
            require_csrf();
            $card = $pdo->query("SELECT column_id, position FROM cards WHERE id = $id")->fetch();
            if ($card) {
                $pdo->beginTransaction();
                $st = $pdo->prepare("DELETE FROM cards WHERE id = ?");
                $st->execute([$id]);
                $st = $pdo->prepare("UPDATE cards SET position = position - 1 WHERE column_id = ? AND position > ?");
                $st->execute([(int)$card['column_id'], (int)$card['position']]);
                $pdo->commit();
            }
            json_response(['deleted' => $id]);
        }
        if ($method === 'GET') {
            $row = $pdo->query("SELECT * FROM cards WHERE id = $id")->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }
        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/cards/(\d+)/move$#', $path, $m) && $method === 'POST') {
        require_csrf();
        $pdo = get_db();
        $id = (int)$m[1];
        $d = parse_json();
        $toCol = (int)($d['to_column_id'] ?? 0);
        $toPos = (int)($d['to_position'] ?? 0);
        $card = $pdo->query("SELECT id, board_id, column_id, position FROM cards WHERE id = $id")->fetch();
        if (!$card) json_response(['error' => 'Not found'], 404);

        $fromCol = (int)$card['column_id'];
        $currPos = (int)$card['position'];
        if ($toCol <= 0) $toCol = $fromCol;

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(position),-1) FROM cards WHERE column_id = ?");
        $stmt->execute([$toCol]);
        $max = (int)$stmt->fetchColumn();
        if ($toPos < 0) $toPos = 0;
        if ($toPos > $max + ($toCol === $fromCol ? 0 : 1)) $toPos = $max + ($toCol === $fromCol ? 0 : 1);

        if ($toCol === $fromCol) {
            if ($toPos > $currPos) {
                $st = $pdo->prepare("UPDATE cards SET position = position - 1 WHERE column_id = ? AND position > ? AND position <= ?");
                $st->execute([$fromCol, $currPos, $toPos]);
            } else {
                $st = $pdo->prepare("UPDATE cards SET position = position + 1 WHERE column_id = ? AND position >= ? AND position < ?");
                $st->execute([$fromCol, $toPos, $currPos]);
            }
            $st = $pdo->prepare("UPDATE cards SET position = ? WHERE id = ?");
            $st->execute([$toPos, $id]);
        } else {
            $st = $pdo->prepare("UPDATE cards SET position = position - 1 WHERE column_id = ? AND position > ?");
            $st->execute([$fromCol, $currPos]);
            $st = $pdo->prepare("UPDATE cards SET position = position + 1 WHERE column_id = ? AND position >= ?");
            $st->execute([$toCol, $toPos]);
            $st = $pdo->prepare("UPDATE cards SET column_id = ?, position = ?, board_id = (SELECT board_id FROM columns WHERE id = ?) WHERE id = ?");
            $st->execute([$toCol, $toPos, $toCol, $id]);
        }

        $pdo->commit();
        $row = $pdo->query("SELECT * FROM cards WHERE id = $id")->fetch();
        json_response(['item' => $row]);
    }

    json_response(['error' => 'Not found', 'path' => $path], 404);
} catch (Throwable $e) {
    json_response(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}
