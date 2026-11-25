<?php
declare(strict_types=1);

function pdg_is_demo_account(string $email): bool {
    return preg_match('/^demo-cad-[a-f0-9]{10}@pdgsystems[.]bg$/iD', $email) === 1;
}

function pdg_retire_demo_accounts(PDO $db): void {
    $db->exec("UPDATE pdg_users SET is_active=0 WHERE is_active=1 AND role='customer' AND LOWER(email) REGEXP '^demo-cad-[a-f0-9]{10}@pdgsystems[.]bg$'");
}
