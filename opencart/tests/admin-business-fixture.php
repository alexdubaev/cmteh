<?php
declare(strict_types=1);
// Disposable loopback runtime fixture. Never used by the extension or release operations.
require '/var/www/html/config.php';
require_once __DIR__.'/../extension/cmteh/system/Lead.php';
require_once __DIR__.'/../extension/cmteh/system/Quote.php';
use Cmteh\{Database,Lead,LeadInput,Quote};
if (getenv('CMTEH_LOCAL_BUSINESS_FIXTURE') !== '1' || DB_HOSTNAME !== 'db') throw new RuntimeException('Local fixture only.');
$pdo=Database::connectFromOpenCart(); $t=DB_PREFIX.'cmteh_lead'; $q=DB_PREFIX.'cmteh_quote';
$marker='Synthetic business acceptance';
$probe='"><svg data-probe=cmteh onload=1>';
if (($argv[1]??'')==='create') {
    $lead=new Lead($pdo,DB_PREFIX); $quote=new Quote($pdo,DB_PREFIX);
    for($i=0;$i<51;$i++) {
        $p=['request_key'=>LeadInput::uuid(),'name'=>$marker,'email'=>'business@example.test','company'=>$probe,'page_url'=>$i%2?'https://example.test/catalog':'','request_items'=>[['article'=>'001A','quantity'=>2]]];
        $r=$lead->submit(json_encode($p),'192.0.2.'.($i+1),['content-type'=>'application/json']);
        if($r['status']!==200)throw new RuntimeException('Fixture lead refused.');
    }
    // A pre-existing caller-declared URL may be hostile; render it as text, never as a link.
    $pdo->prepare("UPDATE {$t} SET page_url=? WHERE name=? ORDER BY lead_id LIMIT 1")->execute(['https://example.test/'.$probe,$marker]);
    $p=['request_key'=>LeadInput::uuid(),'name'=>$marker,'email'=>'business@example.test','input_kind'=>'file','file'=>['filename'=>'original.txt','data_base64'=>base64_encode('001A;5')]];
    $r=$quote->submit(json_encode($p),'198.51.100.60',['content-type'=>'application/json']);
    if($r['status']!==200)throw new RuntimeException('Fixture quote refused.');
    echo json_encode(['quote_id'=>$r['body']['id'],'rows'=>52]);
} elseif (($argv[1]??'')==='delete') {
    $pdo->beginTransaction();
    foreach(['_item','_outbox'] as $s)$pdo->exec("DELETE d FROM {$t}{$s} d JOIN {$t} p ON p.lead_id=d.lead_id WHERE p.name=".$pdo->quote($marker));
    $pdo->exec("DELETE FROM {$t} WHERE name=".$pdo->quote($marker));
    foreach(['_attachment','_outbox'] as $s)$pdo->exec("DELETE d FROM {$q}{$s} d JOIN {$q} p ON p.quote_id=d.quote_id WHERE p.name=".$pdo->quote($marker));
    $bytes=(int)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM {$q} WHERE name=".$pdo->quote($marker))->fetchColumn();
    $pdo->prepare("UPDATE {$q}_quota SET used_bytes=used_bytes-? WHERE id=1")->execute([$bytes]);
    $pdo->exec("DELETE FROM {$q} WHERE name=".$pdo->quote($marker)); $pdo->commit();
    echo 'fixture removed';
} elseif (($argv[1]??'')==='hash') {
    $hash=[];
    foreach(['cmteh_lead','cmteh_lead_item','cmteh_lead_outbox','cmteh_quote','cmteh_quote_attachment','cmteh_quote_outbox','customer','order','customer_affiliate','marketing'] as $table)
        $hash[$table]=hash('sha256',serialize($pdo->query('SELECT * FROM '.DB_PREFIX.$table)->fetchAll(PDO::FETCH_ASSOC)));
    echo json_encode($hash);
} else throw new RuntimeException('Unknown local action.');
