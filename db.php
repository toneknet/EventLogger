<?php
declare(strict_types=1);

const SERVICES = ['sync', 'update', 'updates', 'backup', 'reboot', 'unlocked', 'done'];

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $pdo = new PDO('sqlite:' . $dir . '/serverlogg.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, info TEXT NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE IF NOT EXISTS servers (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, name TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
        enabled_fields TEXT NOT NULL DEFAULT '[]', FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
    );
    CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL, server_id INTEGER NOT NULL, log_date TEXT NOT NULL,
        values_json TEXT NOT NULL DEFAULT '{}', comment TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL,
        FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE, FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE,
        UNIQUE(server_id, log_date)
    );");
    $serverColumns = $pdo->query('PRAGMA table_info(servers)')->fetchAll();
    if (!in_array('sort_order', array_column($serverColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE servers ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('UPDATE servers SET sort_order = id WHERE sort_order = 0');
    }
    if (!in_array('is_separator', array_column($serverColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE servers ADD COLUMN is_separator INTEGER NOT NULL DEFAULT 0');
    }
    $logColumns = $pdo->query('PRAGMA table_info(logs)')->fetchAll();
    if (!in_array('start_time', array_column($logColumns, 'name'), true)) $pdo->exec("ALTER TABLE logs ADD COLUMN start_time TEXT NOT NULL DEFAULT ''");
    if (!in_array('end_time', array_column($logColumns, 'name'), true)) $pdo->exec("ALTER TABLE logs ADD COLUMN end_time TEXT NOT NULL DEFAULT ''");
    if ((int)$pdo->query('SELECT COUNT(*) FROM companies')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO companies(name,info) VALUES ('Exempelbolaget AB','Backup körs efter 22:00. Kontakta IT-ansvarig före omstart.'),('Nordic Demo AB','Testkund – fritt att prova.');");
        $c1=(int)$pdo->lastInsertId()-1; $c2=$c1+1;
        $s=$pdo->prepare('INSERT INTO servers(company_id,name,enabled_fields,sort_order) VALUES (?,?,?,?)');
        $s->execute([$c1,'SRV-APP-01',json_encode(SERVICES),10]);
        $s->execute([$c1,'SRV-BACKUP-01',json_encode(['backup','reboot','unlocked','done']),20]);
        $s->execute([$c2,'DEMO-DC-01',json_encode(['sync','update','updates','reboot','done']),10]);
    }
    return $pdo;
}

function jsonInput(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }
function reply(mixed $data, int $status=200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE); exit;
}
