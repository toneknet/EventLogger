<?php
declare(strict_types=1);

const SERVICES = ['sync', 'update', 'updates', 'backup', 'reboot', 'unlocked', 'done'];

function loadEnvironment(): void {
    static $loaded=false;if($loaded)return;$loaded=true;$file=__DIR__.'/.env';if(!is_file($file))return;
    foreach(file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){$line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;[$key,$value]=array_map('trim',explode('=',$line,2));$value=trim($value,"\"'");if(getenv($key)===false)putenv("$key=$value");}
}

function dbDriver(): string { return strtolower(getenv('DB_DRIVER') ?: 'sqlite'); }

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    loadEnvironment();
    $driver=dbDriver();
    if ($driver==='mysql') {
        $host=getenv('DB_HOST') ?: '127.0.0.1'; $port=getenv('DB_PORT') ?: '3306'; $name=getenv('DB_DATABASE') ?: 'eventlogger';
        $pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",getenv('DB_USERNAME') ?: 'root',getenv('DB_PASSWORD') ?: '');
    } elseif ($driver==='sqlite') {
        $dir=__DIR__.'/data'; if(!is_dir($dir))mkdir($dir,0775,true);
        $path=getenv('DB_DATABASE') ?: $dir.'/serverlogg.sqlite';
        $pdo=new PDO('sqlite:'.$path);
    } else throw new RuntimeException('DB_DRIVER måste vara sqlite eller mysql.');
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if($driver==='sqlite')$pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL;');
    createSchema($pdo,$driver); migrateSchema($pdo,$driver); seedDatabase($pdo); seedAdminUser($pdo);
    return $pdo;
}

function createSchema(PDO $db,string $driver): void {
    $id=$driver==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int=$driver==='mysql'?'TINYINT(1)':'INTEGER'; $engine=$driver==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    $statements=[
        "CREATE TABLE IF NOT EXISTS companies (id $id,name VARCHAR(255) NOT NULL,info TEXT NOT NULL,active $int NOT NULL DEFAULT 1)$engine",
        "CREATE TABLE IF NOT EXISTS users (id $id,username VARCHAR(100) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at VARCHAR(32) NOT NULL)$engine",
        "CREATE TABLE IF NOT EXISTS servers (id $id,company_id ".($driver==='mysql'?'BIGINT UNSIGNED':'INTEGER')." NOT NULL,name VARCHAR(255) NOT NULL,active $int NOT NULL DEFAULT 1,enabled_fields TEXT NOT NULL,sort_order INTEGER NOT NULL DEFAULT 0,is_separator $int NOT NULL DEFAULT 0,CONSTRAINT fk_servers_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE)$engine",
        "CREATE TABLE IF NOT EXISTS logs (id $id,company_id ".($driver==='mysql'?'BIGINT UNSIGNED':'INTEGER')." NOT NULL,server_id ".($driver==='mysql'?'BIGINT UNSIGNED':'INTEGER')." NOT NULL,log_date VARCHAR(10) NOT NULL,values_json TEXT NOT NULL,comment TEXT NOT NULL,updated_at VARCHAR(32) NOT NULL,start_time VARCHAR(5) NOT NULL DEFAULT '',end_time VARCHAR(5) NOT NULL DEFAULT '',follow_up $int NOT NULL DEFAULT 0,followup_resolved $int NOT NULL DEFAULT 0,resolution_comment TEXT NOT NULL,resolved_at VARCHAR(32) NOT NULL DEFAULT '',CONSTRAINT fk_logs_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,CONSTRAINT fk_logs_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE,UNIQUE(server_id,log_date))$engine",
        "CREATE TABLE IF NOT EXISTS daily_logs (id $id,company_id ".($driver==='mysql'?'BIGINT UNSIGNED':'INTEGER')." NOT NULL,log_date VARCHAR(10) NOT NULL,start_time VARCHAR(5) NOT NULL DEFAULT '',end_time VARCHAR(5) NOT NULL DEFAULT '',updated_at VARCHAR(32) NOT NULL,CONSTRAINT fk_daily_company FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,UNIQUE(company_id,log_date))$engine"
    ];
    foreach($statements as $sql)$db->exec($sql);
}

function hasColumn(PDO $db,string $driver,string $table,string $column): bool {
    if($driver==='sqlite'){ $rows=$db->query("PRAGMA table_info($table)")->fetchAll();return in_array($column,array_column($rows,'name'),true); }
    $s=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');$s->execute([$table,$column]);return (int)$s->fetchColumn()>0;
}

function migrateSchema(PDO $db,string $driver): void {
    $text=$driver==='mysql'?'VARCHAR(32)':'TEXT'; $bool=$driver==='mysql'?'TINYINT(1)':'INTEGER';
    $columns=[
        ['servers','sort_order','INTEGER NOT NULL DEFAULT 0'],['servers','is_separator',"$bool NOT NULL DEFAULT 0"],
        ['logs','start_time',"$text NOT NULL DEFAULT ''"],['logs','end_time',"$text NOT NULL DEFAULT ''"],
        ['logs','follow_up',"$bool NOT NULL DEFAULT 0"],['logs','followup_resolved',"$bool NOT NULL DEFAULT 0"],
        ['logs','resolution_comment',"TEXT NOT NULL"],['logs','resolved_at',"$text NOT NULL DEFAULT ''"]
    ];
    foreach($columns as [$table,$column,$definition])if(!hasColumn($db,$driver,$table,$column))$db->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    $db->exec('UPDATE servers SET sort_order=id WHERE sort_order=0');
}

function seedDatabase(PDO $db): void {
    if((int)$db->query('SELECT COUNT(*) FROM companies')->fetchColumn()!==0)return;
    $c=$db->prepare('INSERT INTO companies(name,info,active) VALUES (?,?,1)');
    $c->execute(['Exempelbolaget AB','Backup körs efter 22:00. Kontakta IT-ansvarig före omstart.']);$c1=(int)$db->lastInsertId();
    $c->execute(['Nordic Demo AB','Testkund – fritt att prova.']);$c2=(int)$db->lastInsertId();
    $s=$db->prepare('INSERT INTO servers(company_id,name,enabled_fields,sort_order) VALUES (?,?,?,?)');
    $s->execute([$c1,'SRV-APP-01',json_encode(SERVICES),10]);$s->execute([$c1,'SRV-BACKUP-01',json_encode(['backup','reboot','unlocked','done']),20]);$s->execute([$c2,'DEMO-DC-01',json_encode(['sync','update','updates','reboot','done']),10]);
}

function seedAdminUser(PDO $db): void {
    if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0)return;
    $s=$db->prepare('INSERT INTO users(username,password_hash,created_at) VALUES (?,?,?)');
    $s->execute(['admin',password_hash('password',PASSWORD_DEFAULT),(new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
}

function insertIfMissing(PDO $db,string $table,array $data): void {
    $columns=array_keys($data);$sql='INSERT INTO '.$table.'('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';
    try{$db->prepare($sql)->execute(array_values($data));}catch(PDOException $e){if(!in_array((string)$e->getCode(),['23000','19'],true))throw $e;}
}

function jsonInput(): array { return json_decode(file_get_contents('php://input'),true) ?: []; }
function reply(mixed $data,int $status=200): never { http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode($data,JSON_UNESCAPED_UNICODE);exit; }
