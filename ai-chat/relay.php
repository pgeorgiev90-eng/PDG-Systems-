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

function feedbackSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS feedback_jobs (
        id TEXT PRIMARY KEY, topic TEXT NOT NULL, outcome TEXT NOT NULL, created INTEGER NOT NULL,
        voted INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS feedback_totals (
        topic TEXT NOT NULL, outcome TEXT NOT NULL, helpful INTEGER NOT NULL DEFAULT 0,
        unhelpful INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (topic,outcome)
    )');
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
    $db->exec('CREATE TABLE IF NOT EXISTS feedback_retries (
        parent_id TEXT PRIMARY KEY, revision_id TEXT NOT NULL UNIQUE, created INTEGER NOT NULL
    )');
    $now = time();
    $method = $_SERVER['REQUEST_METHOD'];
    $op = $_GET['op'] ?? '';

    if ($method === 'GET' && $op === 'status') {
        $id = $_GET['id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id)) {
            reply(400, ['error' => 'Невалидна заявка.']);
        }
        $q = $db->prepare('SELECT rowid, created, lease, answer FROM jobs WHERE id=?');
        $q->execute([$id]);
        $job = $q->fetch(PDO::FETCH_ASSOC);
        if (!$job || $job['created'] < $now - 3600) {
            reply(404, ['error' => 'Заявката е изтекла.']);
        }
        if ($job['answer'] !== null) {
            reply(200, ['status'=>'done','answer'=>$job['answer']]);
        }
        if ((int)$job['lease'] > $now) {
            reply(200, ['status'=>'pending','stage'=>'processing']);
        }
        $q = $db->prepare('SELECT count(*) FROM jobs WHERE answer IS NULL AND created>=?
            AND (created < ? OR (created = ? AND rowid < ?))');
        $q->execute([$now-3600,$job['created'],$job['created'],$job['rowid']]);
        reply(200, ['status'=>'pending','stage'=>'queued','ahead'=>(int)$q->fetchColumn()]);
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
        if ($rate >= 6) {
            $db->exec('ROLLBACK');
            header('Retry-After: 60');
            reply(429, ['error'=>'Изпратихте няколко въпроса поред. Изчакайте до минута и продължете разговора.']);
        }
        if ($depth >= 24) {
            $db->exec('ROLLBACK');
            header('Retry-After: 10');
            reply(429, ['error'=>'Опашката е пълна. Опитайте отново след малко.']);
        }
        $id = bin2hex(random_bytes(24));
        $q = $db->prepare('INSERT INTO jobs(id,client,question,created) VALUES (?,?,?,?)');
        $q->execute([$id,$client,trim($question),$now]);
        $db->exec('COMMIT');
        reply(202, ['id'=>$id]);
    }

    if ($op === 'feedback') {
        $id = $body['id'] ?? null;
        $helpful = $body['helpful'] ?? null;
        $question = $body['question'] ?? null;
        if ($question !== null && (!is_string($question) || trim($question) === '' || strlen($question) > 1200)) {
            reply(400, ['error'=>'Въведете кратък въпрос за новия опит.']);
        }
        if (!is_string($id) || !preg_match('/^[a-f0-9]{48}$/D', $id) || !is_bool($helpful)) {
            reply(400, ['error'=>'Невалидна оценка.']);
        }
        feedbackSchema($db);
        $client = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? '', $rateSalt);
        $db->exec('BEGIN IMMEDIATE');
        $q = $db->prepare('SELECT f.topic,f.outcome,f.voted FROM feedback_jobs f
            JOIN jobs j ON j.id=f.id WHERE j.id=? AND j.client=? AND f.created>=?');
        $q->execute([$id,$client,$now-3600]);
        $entry = $q->fetch(PDO::FETCH_ASSOC);
        if (!$entry) {
            $db->exec('ROLLBACK');
            reply(404, ['error'=>'Отговорът вече не може да бъде оценен.']);
        }
        $revisionId = null;
        if (!$helpful && $question !== null) {
            $q = $db->prepare('SELECT revision_id FROM feedback_retries WHERE parent_id=?');
            $q->execute([$id]);
            $revisionId = $q->fetchColumn() ?: null;
            if ($revisionId === null && !$entry['voted']) {
                $q = $db->prepare('SELECT count(*) FROM jobs WHERE client=? AND created>=?');
                $q->execute([$client,$now-60]);
                $rate = (int)$q->fetchColumn();
                $depth = (int)$db->query('SELECT count(*) FROM jobs WHERE answer IS NULL')->fetchColumn();
                if ($rate >= 6 || $depth >= 24) {
                    $db->exec('ROLLBACK');
                    header('Retry-After: 60');
                    reply(429, ['error'=>'Новият опит не може да започне веднага. Опитайте отново след малко.']);
                }
                $revisionId = bin2hex(random_bytes(24));
                $q = $db->prepare('INSERT INTO jobs(id,client,question,created) VALUES (?,?,?,?)');
                $q->execute([$revisionId,$client,trim($question),$now]);
                $q = $db->prepare('INSERT INTO feedback_retries(parent_id,revision_id,created) VALUES (?,?,?)');
                $q->execute([$id,$revisionId,$now]);
            }
        }
        if (!$entry['voted']) {
            $q = $db->prepare('UPDATE feedback_jobs SET voted=1 WHERE id=?');
            $q->execute([$id]);
            $q = $db->prepare('INSERT INTO feedback_totals(topic,outcome,helpful,unhelpful) VALUES (?,?,?,?)
                ON CONFLICT(topic,outcome) DO UPDATE SET helpful=helpful+excluded.helpful,
                unhelpful=unhelpful+excluded.unhelpful');
            $q->execute([$entry['topic'],$entry['outcome'],$helpful?1:0,$helpful?0:1]);
        }
        $db->exec('COMMIT');
        reply(200, ['ok'=>true,'revision_id'=>$revisionId]);
    }

    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authorization, 'Bearer ') || !hash_equals('655c4dc532105f2d68f655ad185199492d66fcda9a8e9a3b8ea8667149ef4317', hash('sha256', substr($authorization, 7)))) {
        reply(401, ['error'=>'Unauthorized']);
    }

    if ($op === 'claim') {
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('DELETE FROM jobs WHERE created < ' . ($now - 3600));
        $db->exec('DELETE FROM feedback_retries WHERE created < ' . ($now - 3600));
        $q = $db->prepare('SELECT j.id, j.question, prior.answer AS prior_answer
            FROM jobs j LEFT JOIN feedback_retries r ON r.revision_id=j.id
            LEFT JOIN jobs prior ON prior.id=r.parent_id AND prior.client=j.client
            WHERE j.answer IS NULL AND j.lease<=? AND j.created>=?
            ORDER BY j.created,j.rowid LIMIT 1');
        $q->execute([$now,$now-3600]);
        $job = $q->fetch(PDO::FETCH_ASSOC);
        if ($job) {
            $q = $db->prepare('UPDATE jobs SET lease=? WHERE id=?');
            $q->execute([$now+240,$job['id']]);
        }
        $db->exec('COMMIT');
        if ($job) {
            feedbackSchema($db);
            $job['feedback'] = $db->query('SELECT topic, outcome, helpful, unhelpful
                FROM feedback_totals ORDER BY helpful + unhelpful DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
        }
        reply(200, ['job'=>$job ?: null]);
    }

    if ($op === 'finish') {
        $id = $body['id'] ?? null;
        $answer = $body['answer'] ?? null;
        if (!is_string($id) || !is_string($answer) || trim($answer) === '' || strlen($answer) > 6000) {
            reply(400, ['error'=>'Invalid result']);
        }
        $topic = $body['topic'] ?? null;
        $outcome = $body['outcome'] ?? null;
        if (is_string($topic) && preg_match('/^[a-z_]{1,32}$/D', $topic)
            && in_array($outcome,['sourced','needs_evidence','clarification','catalog','general'],true)) {
            feedbackSchema($db);
            $db->exec('BEGIN IMMEDIATE');
            $db->exec('DELETE FROM feedback_jobs WHERE created < ' . ($now - 3600));
            $q = $db->prepare('UPDATE jobs SET answer=?, question=? WHERE id=? AND answer IS NULL');
            $q->execute([$answer,'',$id]);
            $accepted = $q->rowCount() === 1;
            if ($accepted) {
                $q = $db->prepare('INSERT INTO feedback_jobs(id,topic,outcome,created) VALUES (?,?,?,?)');
                $q->execute([$id,$topic,$outcome,$now]);
            }
            $db->exec('COMMIT');
            reply(200, ['ok'=>true,'accepted'=>$accepted]);
        }
        $q = $db->prepare('UPDATE jobs SET answer=?, question=? WHERE id=? AND answer IS NULL');
        $q->execute([$answer,'',$id]);
        reply(200, ['ok'=>true,'accepted'=>$q->rowCount() === 1]);
    }

    reply(404, ['error'=>'Unknown operation']);
} catch (Throwable $error) {
    error_log('PDG site chat: ' . $error->getMessage());
    reply(503, ['error'=>'Помощникът временно не е достъпен.']);
}
