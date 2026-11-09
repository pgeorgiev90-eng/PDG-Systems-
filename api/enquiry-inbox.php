<?php
declare(strict_types=1);
require __DIR__.'/admin-common.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) pdg_answer(405, ['error'=>'method_not_allowed']);
    [$db] = pdg_admin_session();
    $db->exec('CREATE TABLE IF NOT EXISTS pdg_enquiry_reads (
        enquiry_id BIGINT UNSIGNED PRIMARY KEY,
        read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    // Separate workflow metadata preserves existing enquiries and labour statuses.
    $db->exec("CREATE TABLE IF NOT EXISTS pdg_enquiry_workflow (
        enquiry_id BIGINT UNSIGNED PRIMARY KEY,
        stage VARCHAR(20) NOT NULL DEFAULT 'new',
        follow_up DATE NULL,
        decline_reason VARCHAR(20) NOT NULL DEFAULT '',
        note VARCHAR(2000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $join = "FROM pdg_electric_enquiries e LEFT JOIN pdg_enquiry_reads r ON r.enquiry_id=e.id LEFT JOIN pdg_enquiry_workflow w ON w.enquiry_id=e.id";
    $unread = "r.enquiry_id IS NULL AND e.status <> 'reviewed'";
    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input', false, null, 0, 12000), true);
        if (!is_array($data)) pdg_answer(422, ['error'=>'invalid_input']);
        $ref = $data['reference'] ?? null;
        if (!is_string($ref) || !preg_match('/^[a-f0-9]{24}$/D', $ref)) pdg_answer(422, ['error'=>'invalid_reference']);
        $q = $db->prepare('SELECT id FROM pdg_electric_enquiries WHERE public_ref=?');
        $q->execute([$ref]);
        $id = $q->fetchColumn();
        if (!$id) pdg_answer(404, ['error'=>'not_found']);
        $action = $data['action'] ?? 'read';
        if ($action === 'workflow') {
            $stage = $data['stage'] ?? null;
            $date = $data['follow_up'] ?? '';
            $reason = $data['decline_reason'] ?? '';
            $note = $data['note'] ?? '';
            if (!in_array($stage, ['new','processing','quoted','completed'], true)
                || !is_string($date) || !is_string($note) || mb_strlen($note, 'UTF-8') > 2000
                || !in_array($reason, ['', 'price', 'delivery', 'equipment', 'other'], true)) {
                pdg_answer(422, ['error'=>'invalid_workflow']);
            }
            if ($date !== '') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) pdg_answer(422, ['error'=>'invalid_follow_up']);
            }
            $q = $db->prepare('INSERT INTO pdg_enquiry_workflow (enquiry_id,stage,follow_up,decline_reason,note)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE stage=VALUES(stage),follow_up=VALUES(follow_up),decline_reason=VALUES(decline_reason),note=VALUES(note)');
            $q->execute([$id, $stage, $date === '' ? null : $date, $reason, trim($note)]);
            pdg_answer(200, ['ok'=>true]);
        }
        if ($action !== 'read') pdg_answer(422, ['error'=>'invalid_action']);
        $q = $db->prepare('INSERT INTO pdg_enquiry_reads (enquiry_id) VALUES (?) ON DUPLICATE KEY UPDATE enquiry_id=enquiry_id');
        $q->execute([$id]);
        pdg_answer(200, ['unread'=>(int)$db->query("SELECT COUNT(*) $join WHERE $unread")->fetchColumn()]);
    }
    $rows = $db->query("SELECT e.public_ref,e.customer_name,e.email,e.phone,e.project_name,e.material_json,e.created_at,
        COALESCE(w.stage, 'new') AS stage,w.follow_up,COALESCE(w.decline_reason, '') AS decline_reason,COALESCE(w.note, '') AS note,
        ($unread) AS unread $join ORDER BY unread DESC,e.id DESC LIMIT 50")->fetchAll();
    foreach ($rows as &$row) {
        $details = json_decode($row['material_json'], true);
        unset($row['material_json']);
        $row['source'] = ($details['source'] ?? '') === 'website_contact' ? 'site' : 'electric';
        $row['message'] = $row['source'] === 'site' ? ($details['message'] ?? '') : '';
        $row['unread'] = (bool)$row['unread'];
    }
    unset($row);
    pdg_answer(200, ['enquiries'=>$rows, 'csrf'=>$_SESSION['csrf'],
        'unread'=>(int)$db->query("SELECT COUNT(*) $join WHERE $unread")->fetchColumn()]);
} catch (Throwable $e) {
    error_log('PDG enquiry inbox: '.get_class($e));
    pdg_answer(503, ['error'=>'service_unavailable']);
}
