<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $status, array $value): never {
    http_response_code($status);
    echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $private = getenv('PDG_CHAT_PRIVATE_DIR') ?: dirname(__DIR__, 2) . '/.pdg-site-chat';
    umask(0077);
    if (!is_dir($private) && !@mkdir($private, 0700) && !is_dir($private)) {
        throw new RuntimeException('Private chat directory unavailable');
    }
    $saltFile = $private . '/rate-salt';
    $saltHandle = fopen($saltFile, 'c+b');
    if ($saltHandle === false || !flock($saltHandle, LOCK_EX)) {
        throw new RuntimeException('Chat rate-limit storage unavailable');
    }
    $rateSalt = stream_get_contents($saltHandle);
    if (strlen($rateSalt) !== 32) {
        $rateSalt = random_bytes(32);
        rewind($saltHandle);
        if (fwrite($saltHandle, $rateSalt) !== 32) {
            throw new RuntimeException('Chat rate-limit storage write failed');
        }
        fflush($saltHandle);
    }
    flock($saltHandle, LOCK_UN);
    fclose($saltHandle);
    $db = new PDO('sqlite:' . $private . '/queue.sqlite');
    $db->exec('PRAGMA busy_timeout = 3500');
    $db->exec('CREATE TABLE IF NOT EXISTS jobs (
        id TEXT PRIMARY KEY, client TEXT NOT NULL, question TEXT NOT NULL,
        created INTEGER NOT NULL, lease INTEGER NOT NULL DEFAULT 0,
        answer TEXT
    )');
    $now = time();
    $method = $_SERVER['REQUEST_METHOD'];
    $op = $_GET['op'] ?? '';

    if ($method === 'GET' && $op === 'status') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id)) {
            reply(400, ['error' => 'Невалидна заявка.']);
        }
        $q = $db->prepare('SELECT created, answer FROM jobs WHERE id=?');
        $q->execute([$id]);
        $job = $q->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['created'] < $now - 3600) {
            reply(404, ['error' => 'Заявката е изтекла.']);
        }
        reply(200, $job['answer'] === null ? ['status'=>'pending'] : ['status'=>'done','answer'=>$job['answer']]);
    }

    if ($method !== 'POST' || !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        reply(405, ['error'=>'Неподдържана заявка.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if ($raw === false || strlen($raw) > 16384) {
        reply(413, ['error'=>'Заявката е твърде голяма.']);
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        reply(400, ['error'=>'Невалидни данни.']);
    }

    if ($op === 'submit') {
        $question = $body['question'] ?? null;
        if (!is_string($question) || trim($question) === '' || strlen($question) > 1200) {
            reply(400, ['error'=>'Въведете кратък въпрос.']);
        }
        $client = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $rateSalt);
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('DELETE FROM jobs WHERE created < ' . ($now - 3600));
        $q = $db->prepare('SELECT count(*) FROM jobs WHERE client=? AND created>=?');
        $q->execute([$client,$now-60]);
        $rate = (int)$q->fetchColumn();
        $depth = (int)$db->query('SELECT count(*) FROM jobs WHERE answer IS NULL')->fetchColumn();
        if ($rate >= 3 || $depth >= 4) {
            $db->exec('ROLLBACK');
            reply(429, ['error'=>'В момента помощникът е зает. Опитайте отново след малко.']);
        }
        $id = bin2hex(random_bytes(24));
        $q = $db->prepare('INSERT INTO jobs(id,client,question,created) VALUES (?,?,?,?)');
        $q->execute([$id,$client,trim($question),$now]);
        $db->exec('COMMIT');
        reply(202, ['id'=>$id]);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authorization, 'Bearer ') || !hash_equals('655c4dc532105f2d68f655ad185199492d66fcda9a8e9a3b8ea8667149ef4317', hash('sha256', substr($authorization, 7)))) {
        reply(401, ['error'=>'Unauthorized']);
    }

    if ($op === 'claim') {
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('DELETE FROM jobs WHERE created < ' . ($now - 3600));
        $q = $db->prepare('SELECT id, question FROM jobs WHERE answer IS NULL AND lease<? AND created>=? ORDER BY created LIMIT 1');
        $q->execute([$now,$now-3600]);
        $job = $q->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $q = $db->prepare('UPDATE jobs SET lease=? WHERE id=?');
            $q->execute([$now+150,$job['id']]);
        }
        $db->exec('COMMIT');
        reply(200, ['job'=>$job ?: null]);
    }

    if ($op === 'finish') {
        $id = $body['id'] ?? null;
        $answer = $body['answer'] ?? null;
        if (!is_string($id) || !is_string($answer) || trim($answer) === '' || strlen($answer) > 6000) {
            reply(400, ['error'=>'Invalid result']);
        }
        $q = $db->prepare('UPDATE jobs SET answer=?, question=? WHERE id=? AND answer IS NULL');
        $q->execute([$answer,'',$id]);
        reply(200, ['ok'=>true]);
    }

    reply(404, ['error'=>'Unknown operation']);
} catch (Throwable $error) {
    error_log('PDG site chat: ' . $error->getMessage());
    reply(503, ['error'=>'Помощникът временно не е достъпен.']);
}
