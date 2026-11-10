<?php
declare(strict_types=1);
require getenv('CMTEH_TEST_CONFIG') ?: '/var/www/html/config.php';
require_once __DIR__ . '/../extension/cmteh/system/QuoteOutbox.php';
require_once __DIR__ . '/../extension/cmteh/system/QuoteMail.php';
require_once __DIR__ . '/../extension/cmteh/system/Lead.php';
require_once __DIR__ . '/../extension/cmteh/system/LeadOutbox.php';
use Cmteh\Database;
use Cmteh\Quote;
use Cmteh\QuoteInput;
use Cmteh\QuoteOutbox;
use Cmteh\QuoteMail;
use Cmteh\LeadInput;

function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS: $label\n"; }
function payload(): array { return ['request_key'=>LeadInput::uuid(),'name'=>'<Quote> Example','email'=>'quote@example.test',
    'message'=>"Первая строка\nВторая строка",'page_url'=>'http://localhost/parts-list','input_kind'=>'text','list_text'=>"001A;2\r\n001A;5\n002;8"]; }
function submit(Quote $q, array $p): array { static $ip=0; return $q->submit(json_encode($p,JSON_THROW_ON_ERROR),'192.0.2.'.(++$ip % 250),['content-type'=>'application/json']); }

final class CommitFaultPDO extends PDO
{
    public int $commitsUntilFailure = 0;
    public bool $loseAcknowledgement = false;
    public function commit(): bool
    {
        if ($this->commitsUntilFailure > 0 && --$this->commitsUntilFailure === 0) {
            if ($this->loseAcknowledgement) { parent::commit(); }
            throw new RuntimeException('Synthetic commit acknowledgement failure.');
        }
        return parent::commit();
    }
}

if (($argv[1] ?? '') === '--race') {
    $prefix=$argv[2] ?? ''; if (!preg_match('/^qt_[a-f0-9]{12}_$/D',$prefix) || $prefix===DB_PREFIX) { exit(2); }
    $q=new Quote(Database::connectFromOpenCart(),$prefix); $p=payload(); $p['request_key']=$argv[3];
    echo "ready\n"; fflush(STDOUT); if (fgets(STDIN)!=="go\n") { exit(2); }
    echo json_encode(submit($q,$p),JSON_THROW_ON_ERROR)."\n"; exit;
}
$pdo=Database::connectFromOpenCart(); $prefix='qt_'.bin2hex(random_bytes(6)).'_';
if ($prefix===DB_PREFIX) { throw new RuntimeException('Unsafe test prefix.'); }
$t=$prefix.'cmteh_quote'; $q=new Quote($pdo,$prefix); $capture=sys_get_temp_dir().'/'.$prefix.'capture';
$children=[];
try {
    $q->migrate(); $q->migrate(); $p=payload(); $accepted=submit($q,$p);
    check($accepted['status']===200 && $accepted['body']['replayed']===false && count($accepted['body'])===2,'Text accepted with private minimal acknowledgement');
    $id=$accepted['body']['id']; $saved=$q->find($id);
    check($saved['list_text']===$p['list_text'] && $saved['message']===$p['message'],'Original multiline text and contacts persist');
    $used=(int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn();
    $replay=submit($q,$p); $p['message']='Changed'; $conflict=submit($q,$p);
    check($replay['body']===['id'=>$id,'replayed'=>true] && $conflict['status']===409
        && (int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn()===$used,'Replay is stable and costs zero quota; changed request conflicts');
    $file=payload(); unset($file['list_text']); $file['input_kind']='file';
    $bytes="%PDF-1.4\n% Synthetic original\n1 0 obj\n<<>>\nendobj\n%%EOF\n";
    $file['file']=['filename'=>'Потребность № 01.pdf','data_base64'=>base64_encode($bytes)];
    $result=submit($q,$file); $fileId=$result['body']['id'] ?? ''; $attachment=$q->attachment($fileId);
    check($result['status']===200 && $attachment['bytes']===$bytes && $attachment['file_hash']===hash('sha256',$bytes)
        && $attachment['filename']===$file['file']['filename'] && $attachment['mime']==='application/pdf','Private file has unchanged original bytes, UTF-8 name and server MIME/hash');
    $image=$file; $image['request_key']=LeadInput::uuid();
    $image['file']=['filename'=>'photo.png','data_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO6sAAAAASUVORK5CYII='];
    check(submit($q,$image)['status']===200,'Photo accepted from real PNG bytes with matching extension');
    $zipFile=tmpfile(); $zip=new ZipArchive(); $zip->open(stream_get_meta_data($zipFile)['uri'],ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml','<Types><Override ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml" PartName="/xl/workbook.xml"/></Types>');
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>'); $zip->close();
    $xlsx=$file; $xlsx['request_key']=LeadInput::uuid(); $xlsx['file']=['filename'=>'original.xlsx','data_base64'=>base64_encode(file_get_contents(stream_get_meta_data($zipFile)['uri']))]; fclose($zipFile);
    check(submit($q,$xlsx)['status']===200,'XLSX package accepted without extracting it into public storage');
    $cp1251 = hex2bin('cfeef2f0e5e1edeef1f2fc3b32' . '0d0a' . 'c0f0f2e8eaf3eb3b303031');
    $csv = $file; $csv['request_key'] = LeadInput::uuid();
    $csv['file'] = ['filename'=>'Потребность.csv', 'data_base64'=>base64_encode($cp1251)];
    $csvResult = submit($q,$csv); $csvId = $csvResult['body']['id'] ?? '';
    check($csvResult['status']===200 && $q->attachment($csvId)['bytes']===$cp1251,
        'Windows-1251 Cyrillic CSV accepted and original private bytes preserved');
    $csvQuote=$q->find($csvId); $csvQuote['attachment']=$q->attachment($csvId);
    $csvMime=QuoteMail::message($csvQuote,['from'=>'info@cmteh.ru','to'=>'info@cmteh.ru','from_name'=>'СМТЕХНО']);
    $csvParts=explode("\r\n\r\n",$csvMime); $csvEncoded=explode("\r\n--cmteh-quote-",$csvParts[count($csvParts)-1],2)[0];
    check(base64_decode($csvEncoded,true)===$cp1251,'Windows-1251 original MIME attachment remains byte-identical without reencoding');
    $txt=$csv; $txt['request_key']=LeadInput::uuid(); $txt['file']['filename']='Потребность.txt';
    $txtResult=submit($q,$txt);
    check($txtResult['status']===200 && $q->attachment($txtResult['body']['id'])['bytes']===$cp1251,
        'Windows-1251 TXT uses the same safe original-byte acceptance');
    check(!$q->deleteAttachment($fileId) && !$q->retry($fileId),'Pending original cannot be deleted or retried manually');
    $count=(int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    foreach ([['filename'=>'bad.php','data_base64'=>base64_encode('<?php echo 1;')],
        ['filename'=>'fake.pdf','data_base64'=>base64_encode('just text')],
        ['filename'=>"evil\r\nBcc:x.pdf",'data_base64'=>base64_encode($bytes)],
        ['filename'=>'../file.pdf','data_base64'=>base64_encode($bytes)],
        ['filename'=>'image.svg','data_base64'=>base64_encode('<svg/>')],
        ['filename'=>'huge.txt','data_base64'=>base64_encode(str_repeat('A',5242881))],
        ['filename'=>'nul.csv','data_base64'=>base64_encode($cp1251."\0")],
        ['filename'=>'control.txt','data_base64'=>base64_encode($cp1251."\x01")],
        ['filename'=>'binary.csv','data_base64'=>base64_encode("PK\x03\x04\0\0\xFF\xFE")]] as $bad) {
        $invalid=$file; $invalid['request_key']=LeadInput::uuid(); $invalid['file']=$bad;
        check(submit($q,$invalid)['status']===400,'Invalid filename/type/content/decoded size rejected');
    }
    $invalid=payload(); $invalid['email']="a@example.test\r\nBcc:evil@example.test";
    check(submit($q,$invalid)['status']===400 && (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn()===$count,'Injection/invalid files leave no quote or payload');
    $large=payload(); $large['list_text']=str_repeat('X',1048576);
    check(submit($q,$large)['status']===200,'One MiB text accepted');
    $large['request_key']=LeadInput::uuid(); $large['list_text'].='X'; check(submit($q,$large)['status']===400,'Text over one MiB rejected');
    $large=$file; $large['request_key']=LeadInput::uuid(); $large['file']=['filename'=>'full.txt','data_base64'=>base64_encode(str_repeat('A',5242880))];
    $largeResult=submit($q,$large); check($largeResult['status']===200 && strlen($q->attachment($largeResult['body']['id'])['bytes'])===5242880,'Five MiB original survives real PDO BLOB');
    check($q->submit(str_repeat(' ',8388609),'192.0.2.1',['content-type'=>'application/json'])['status']===413,'Eight MiB encoded guard applies independently');

    $before=(int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn();
    $pdo->exec("UPDATE {$t}_quota SET used_bytes=268435456 WHERE id=1");
    check(submit($q,payload())['status']===503 && submit($q,$file)['body']===['id'=>$fileId,'replayed'=>true],'Full quota refuses new payload but still replays existing request');
    $pdo->exec("UPDATE {$t}_quota SET used_bytes=$before WHERE id=1");
    $pdo->exec("RENAME TABLE {$t}_outbox TO {$t}_held");
    $broken=$file; $broken['request_key']=LeadInput::uuid();
    check(submit($q,$broken)['status']===503 && (int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn()===$before,'Job failure rolls back quote, original and quota');
    $pdo->exec("RENAME TABLE {$t}_held TO {$t}_outbox");
    check(submit($q,$broken)['status']===200,'Same request recovers after transaction failure');
    $fault = new CommitFaultPDO('mysql:host='.DB_HOSTNAME.';port='.DB_PORT.';dbname='.DB_DATABASE.';charset=utf8mb4',
        DB_USERNAME,DB_PASSWORD,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $faultQuote = new Quote($fault,$prefix);
    $faultPayload=$file; $faultPayload['request_key']=LeadInput::uuid();
    $fault->commitsUntilFailure=2;
    $before=(int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn();
    check(submit($faultQuote,$faultPayload)['status']===503
        && (int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn()===$before,'Commit refusal rolls back original and quota without false success');
    $fault->commitsUntilFailure=2; $fault->loseAcknowledgement=true;
    check(submit($faultQuote,$faultPayload)['status']===503,'Lost commit acknowledgement returns honest unconfirmed result');
    $recover=submit($q,$faultPayload);
    check($recover['status']===200 && $recover['body']['replayed']===true
        && $q->attachment($recover['body']['id'])['bytes']===$bytes,'Retry recovers committed original after lost acknowledgement');

    $key=LeadInput::uuid();
    foreach ([1,2] as $n) {
        $process=proc_open([PHP_BINARY,__FILE__,'--race',$prefix,$key],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Race process unavailable.'); }
        $children[]=[$process,$pipes]; stream_set_timeout($pipes[1],10);
        check(fgets($pipes[1])==="ready\n",'Independent race connection ready');
    }
    // Both real connections block on the same quota row until the transaction releases it.
    $pdo->beginTransaction(); $pdo->query("SELECT used_bytes FROM {$t}_quota WHERE id=1 FOR UPDATE");
    foreach ($children as [,$pipes]) { fwrite($pipes[0],"go\n"); fflush($pipes[0]); }
    usleep(150000); $pdo->commit(); $results=[];
    foreach ($children as [$process,$pipes]) { $results[]=json_decode((string)fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR); }
    check($results[0]['status']===200 && $results[1]['status']===200 && $results[0]['body']['id']===$results[1]['body']['id']
        && $results[0]['body']['replayed']!==$results[1]['body']['replayed'],'Concurrent submissions yield one immutable quote/job');
    foreach ($children as [$process,$pipes]) { foreach($pipes as $pipe) { fclose($pipe); } proc_close($process); } $children=[];
    $used=(int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn();
    $remaining=strlen(payload()['list_text']); $nearFull=Quote::QUOTA_BYTES-$remaining;
    $pdo->exec("UPDATE {$t}_quota SET used_bytes=$nearFull");
    foreach ([1,2] as $n) {
        $process=proc_open([PHP_BINARY,__FILE__,'--race',$prefix,LeadInput::uuid()],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Quota race unavailable.'); }
        $children[]=[$process,$pipes]; stream_set_timeout($pipes[1],10); check(fgets($pipes[1])==="ready\n",'Quota race connection ready');
    }
    $pdo->beginTransaction(); $pdo->query("SELECT used_bytes FROM {$t}_quota WHERE id=1 FOR UPDATE");
    foreach ($children as [,$pipes]) { fwrite($pipes[0],"go\n"); fflush($pipes[0]); }
    usleep(150000); $pdo->commit(); $statuses=[];
    foreach ($children as [,$pipes]) { $statuses[]=json_decode((string)fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['status']; }
    sort($statuses);
    check($statuses===[200,503] && (int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn()===Quote::QUOTA_BYTES,'Concurrent quota boundary admits exactly one fitting original');
    $used+=$remaining; $pdo->exec("UPDATE {$t}_quota SET used_bytes=$used");

    $worker=new QuoteOutbox($pdo,$prefix); $other=new QuoteOutbox(Database::connectFromOpenCart(),$prefix);
    $failed=$worker->run(static function(array $quote) use ($other): void {
        check($other->run(static function(): void {})['busy'],'Own quote advisory lock excludes concurrent worker');
        throw new RuntimeException('synthetic MTA rejection');
    },1);
    check($failed['retry']===1 && $q->find($id)['status']==='retry','MTA refusal persists bounded retry with original intact');
    $pdo->exec("UPDATE {$t}_outbox SET available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE quote_id<>(SELECT quote_id FROM {$t} WHERE public_id='$id')");
    $pdo->exec("UPDATE {$t}_outbox SET available_at=UTC_TIMESTAMP() WHERE quote_id=(SELECT quote_id FROM {$t} WHERE public_id='$id')");
    $worker->run(static function(): void { throw new RuntimeException('synthetic MTA rejection'); },1);
    $pdo->exec("UPDATE {$t}_outbox SET available_at=UTC_TIMESTAMP() WHERE quote_id=(SELECT quote_id FROM {$t} WHERE public_id='$id')");
    $terminal=$worker->run(static function(): void { throw new RuntimeException('synthetic MTA rejection'); },1);
    check($terminal['failed']===1 && (int)$q->find($id)['attempts']===3 && $q->retry($id),'Three failed transport attempts stop and allow explicit native retry');
    $original=$q->find($fileId); $original['attachment']=$q->attachment($fileId);
    $mime=QuoteMail::message($original,['from'=>'info@cmteh.ru','to'=>'info@cmteh.ru','from_name'=>'СМ ТЕХНО']);
    $second=QuoteMail::message($original,['from'=>'info@cmteh.ru','to'=>'info@cmteh.ru','from_name'=>'СМ ТЕХНО']);
    check($mime===$second && str_contains($mime,"Reply-To: <quote@example.test>\r\n"),'Retries build stable MIME, Message-ID and validated Reply-To');
    $rejectedSettings=false;
    try { QuoteMail::hosting(['from'=>'info@cmteh.ru','to'=>'other@example.test','from_name'=>'СМТЕХНО']); }
    catch (InvalidArgumentException) { $rejectedSettings=true; }
    check($rejectedSettings,'Hosting adapter refuses any alternate recipient before touching MTA');
    $parts=explode("\r\n\r\n",$mime); $last=$parts[count($parts)-1]; $encoded=explode("\r\n--cmteh-quote-",$last,2)[0];
    check(base64_decode($encoded,true)===$bytes,'MIME attachment decodes to original file bytes');
    $captureSend=QuoteMail::capture($capture,'original.eml'); $captureSend($original);
    check(file_get_contents($capture.'/quote-test-capture/original.eml')===$mime,'Private capture writes complete MIME instead of generic notice');
    $pdo->exec("UPDATE {$t}_outbox SET available_at=UTC_TIMESTAMP()");
    $result=$worker->run(static function(array $quote) use ($capture): void { (QuoteMail::capture($capture,$quote['public_id'].'.eml'))($quote); },10);
    check($result['sent']>=5 && $q->find($id)['status']==='sent','Retry sends saved text/file through capture and records MTA acceptance');
    $pdo->exec("UPDATE {$t}_outbox SET status='sending',attempts=3,available_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE quote_id=(SELECT quote_id FROM {$t} WHERE public_id='$fileId')");
    $expired=$worker->run(static function(): void { throw new RuntimeException('must not send terminal lease'); });
    check($expired['failed']===1 && $q->find($fileId)['status']==='failed' && $q->retry($fileId),'Expired final claim becomes failed; explicit retry permitted');
    $worker->run($captureSend,1);
    $quotaBefore=(int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn();
    check($q->deleteAttachment($fileId) && !$q->deleteAttachment($fileId) && $q->attachment($fileId)===null
        && $q->find($fileId)['filename']===$file['file']['filename']
        && (int)$pdo->query("SELECT used_bytes FROM {$t}_quota")->fetchColumn()===$quotaBefore-strlen($bytes),'Explicit sent-file deletion releases quota, retains history, and is idempotent');
    check(submit($q,$file)['body']===['id'=>$fileId,'replayed'=>true] && $q->attachment($fileId)===null,'Replay after deletion cannot resurrect original or send another job');
    $pdo->exec("DELETE FROM {$t}_rate");
    $p=payload(); for($i=0;$i<12;$i++) { $r=$q->submit(json_encode($p),'198.51.100.1',['content-type'=>'application/json']); }
    check($r['status']===200 && $q->submit(json_encode($p),'198.51.100.1',['content-type'=>'application/json'])['status']===429,'Separate bounded rate counter rejects thirteenth same-client attempt');
    (new \Cmteh\Lead($pdo,$prefix))->migrate();
    $old=(new \Cmteh\LeadOutbox($pdo,$prefix))->run(static function(): void { throw new RuntimeException('Old worker consumed quote'); });
    check($old['sent']===0 && $old['retry']===0 && $old['failed']===0,'Old lead worker cannot consume standalone quote jobs');
    check($q->list(1)['total']>=6,'Native list exposes persisted structured quote history');
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    foreach ($children as [$process,$pipes]) { foreach($pipes as $pipe) { fclose($pipe); } if(proc_get_status($process)['running']) { proc_terminate($process); } proc_close($process); }
    foreach (['_outbox','_held','_attachment','_rate','_quota',''] as $suffix) { $pdo->exec("DROP TABLE IF EXISTS {$t}{$suffix}"); }
    foreach (['_item','_outbox','_rate','','_schema'] as $suffix) { $pdo->exec('DROP TABLE IF EXISTS '.$prefix.'cmteh_lead'.$suffix); }
    if (is_dir($capture.'/quote-test-capture')) { foreach(glob($capture.'/quote-test-capture/*.eml') as $path) { unlink($path); } rmdir($capture.'/quote-test-capture'); rmdir($capture); }
}
