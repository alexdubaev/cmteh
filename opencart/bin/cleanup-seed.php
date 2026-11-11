<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
umask(0077);
$options=getopt('', ['config:','task:','manifest-sha:','dry-run','apply','confirm:','backup:',
    'snapshot:','snapshot-sha:','recover:','receipt-sha:'], $first);
try {
    if ($first!==$argc || !is_string($options['config'] ?? null) || !is_readable($options['config'])) throw new RuntimeException('Usage requires a readable --config');
    require $options['config'];
    $system=rtrim(DIR_EXTENSION,'/\\').'/cmteh/system/';
    require_once $system.'Database.php'; require_once $system.'SeedCleanup.php';
    if (($options['task'] ?? '')!==Cmteh\SeedCleanup::TASK || ($options['manifest-sha'] ?? '')!==Cmteh\SeedCleanup::MANIFEST_SHA256) throw new RuntimeException('Explicit task/manifest binding required');
    $modes=(int)isset($options['dry-run'])+(int)isset($options['apply'])+(int)isset($options['recover']);
    if ($modes!==1) throw new RuntimeException('Select exactly one --dry-run, --apply or --recover');
    $root=realpath(DIR_OPENCART);
    if ($root===false) throw new RuntimeException('Document root unavailable');
    $privateFile=static function(string $path,bool $exists) use($root): string {
        $parent=realpath(dirname($path));
        if ($parent===false || is_link($path) || basename($path)==='.' || basename($path)==='..') throw new RuntimeException('Private file path invalid');
        $resolved=$parent.DIRECTORY_SEPARATOR.basename($path);
        $normal=static fn($s)=>strtolower(str_replace('\\','/',rtrim($s,'/\\').'/'));
        if (str_starts_with($normal($parent),$normal($root))) throw new RuntimeException('Output must be outside webroot');
        if ($exists && (!is_file($resolved) || !is_readable($resolved))) throw new RuntimeException('Closed private file missing');
        if (!$exists && file_exists($resolved)) throw new RuntimeException('Refusing to overwrite a private file');
        if (PHP_OS_FAMILY!=='Windows' && ((fileperms($parent)&0077)!==0 || ($exists && (fileperms($resolved)&0077)!==0))) throw new RuntimeException('Private directory/file must deny group and public access');
        return $resolved;
    };
    if (!in_array(parse_url(Cmteh\Database::siteUrl(),PHP_URL_HOST),['test.cmteh.ru','localhost','127.0.0.1','[::1]'],true)) throw new RuntimeException('Only test.cmteh.ru or an isolated loopback fixture is supported');
    $pdo=Cmteh\Database::connectFromOpenCart();
    $tool=new Cmteh\SeedCleanup($pdo,DB_PREFIX);
    if (isset($options['dry-run'])) {
        $result=$tool->preview();
    } elseif (isset($options['apply'])) {
        $snapshot=$privateFile((string)($options['snapshot'] ?? ''),true);
        $snapshotHash=hash_file('sha256',$snapshot);
        if (!hash_equals($snapshotHash,(string)($options['snapshot-sha'] ?? ''))) throw new RuntimeException('Closed snapshot checksum mismatch');
        $backup=$privateFile((string)($options['backup'] ?? ''),false);
        $result=$tool->apply((string)($options['confirm'] ?? ''),static function(array $receipt) use($backup,$snapshotHash): void {
            $receipt['closed_snapshot_sha256']=$snapshotHash;
            $bytes=json_encode($receipt,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
            $file=fopen($backup,'x+b');
            if ($file===false) throw new RuntimeException('Cannot create private preimage');
            try {
                if (!chmod($backup,0600) || fwrite($file,$bytes)!==strlen($bytes) || !fflush($file) || !fsync($file)) throw new RuntimeException('Cannot close durable preimage');
            } finally { fclose($file); }
            if (!hash_equals(hash('sha256',$bytes),hash_file('sha256',$backup))) throw new RuntimeException('Preimage readback failed');
        });
        if (is_file($backup)) $result['receipt_sha256']=hash_file('sha256',$backup);
    } else {
        $file=$privateFile((string)$options['recover'],true);
        if (!hash_equals(hash_file('sha256',$file),(string)($options['receipt-sha'] ?? ''))) throw new RuntimeException('Recovery receipt checksum mismatch');
        if (filesize($file)>4000000) throw new RuntimeException('Recovery receipt too large');
        $receipt=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        // Official legacy seed contains zero dates. Permit those only on this CLI connection,
        // retaining strict transactional/error modes and restoring the original session mode.
        $mode=(string)$pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $restoreMode=implode(',',array_diff(explode(',',$mode),['NO_ZERO_DATE','NO_ZERO_IN_DATE']));
        $pdo->exec('SET SESSION sql_mode='.$pdo->quote($restoreMode));
        try { $tool->recover($receipt); }
        finally { $pdo->exec('SET SESSION sql_mode='.$pdo->quote($mode)); }
        $result=['task'=>Cmteh\SeedCleanup::TASK,'manifest'=>Cmteh\SeedCleanup::MANIFEST_SHA256,'state'=>'recovered'];
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // PDO/config errors can contain private connection data; only our bounded guard messages are public.
    $message=$error instanceof RuntimeException && !($error instanceof PDOException) ? $error->getMessage() : 'Private database/configuration failure';
    fwrite(STDERR,'Cleanup stopped: '.$message.PHP_EOL); exit(1);
}
