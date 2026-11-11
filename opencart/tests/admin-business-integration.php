<?php
declare(strict_types=1);
require '/var/www/html/config.php';
require_once __DIR__.'/../extension/cmteh/system/Lead.php';
require_once __DIR__.'/../extension/cmteh/system/Quote.php';
require_once __DIR__.'/../extension/cmteh/system/AdminBusiness.php';
use Cmteh\{Database,Lead,LeadInput,Quote,AdminBusiness};
function check(bool $ok,string $label):void { if(!$ok)throw new RuntimeException($label); echo "PASS: $label\n"; }
$pdo=Database::connectFromOpenCart(); $prefix='ab_'.bin2hex(random_bytes(6)).'_';
$lead=new Lead($pdo,$prefix); $quote=new Quote($pdo,$prefix);
function image(PDO $pdo,string $prefix):array {
    $r=[]; foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
        if(str_starts_with($t,$prefix)||in_array($t,[DB_PREFIX.'customer',DB_PREFIX.'order',DB_PREFIX.'customer_affiliate']))
            $r[$t]=hash('sha256',serialize($pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC)));
    } return $r;
}
try {
    $b=new AdminBusiness($pdo,$prefix);
    check($b->dashboard()['unavailable']===['lead','quote'] && $b->contacts()['total']===0,'Missing sources are unavailable, not fabricated; no migration on read');
    $lead->migrate(); $quote->migrate(); $b=new AdminBusiness($pdo,$prefix);
    check($b->dashboard()['total']===0 && $b->sources()['total']===0,'Existing empty sources report zero');
    $p=['request_key'=>LeadInput::uuid(),'name'=>'Synthetic contact','phone'=>'+70000000000','email'=>'synthetic@example.test','page_url'=>'https://example.test/catalog','request_items'=>[['article'=>'001A','quantity'=>7]]];
    $l=$lead->submit(json_encode($p),'192.0.2.40',['content-type'=>'application/json']);
    $qp=['request_key'=>LeadInput::uuid(),'name'=>'Synthetic contact','email'=>'synthetic@example.test','input_kind'=>'file','file'=>['filename'=>'original.txt','data_base64'=>base64_encode('001A;5')]];
    $q=$quote->submit(json_encode($qp),'192.0.2.41',['content-type'=>'application/json']);
    check($l['status']===200 && $q['status']===200,'Real submissions accepted');
    $pdo->exec("UPDATE {$prefix}cmteh_lead SET created_at='2026-11-01 12:00:00'");
    $pdo->exec("UPDATE {$prefix}cmteh_quote SET created_at='2026-11-02 12:00:00'");
    $before=image($pdo,$prefix); $d=$b->dashboard();
    check($d['total']===2 && $d['lead']===1 && $d['quote']===1 && $d['items']===1 && $d['quantity']===7,'Dashboard counts real types and only structured requested quantities');
    $c=$b->contacts([],1,1); $c2=$b->contacts([],2,1);
    check($c['total']===2 && $c['pages']===2 && count($c['rows'])===1 && $c['rows'][0]['type']==='quote' && $c2['rows'][0]['public_id']===$l['body']['id'],'Server pagination preserves separate source identities for equal contacts');
    check($b->contacts(['from'=>'2026-11-01','to'=>'2026-11-01'])['total']===1 && $b->contacts(['search'=>"%' OR 1=1 --"])['total']===0,'Date range inclusive and search parameterized');
    check($b->contacts(['type'=>'quote'])['total']===1 && $b->contacts(['status'=>'sent'])['total']===0,'Type and delivery-state filters apply');
    $s=$b->sources(); check($s['total']===2 && array_sum(array_column($s['rows'],'requests'))===2 && in_array('',array_column($s['rows'],'source'),true),'Declared URL and unknown source both retained');
    $r=$b->reports(); check($r['total']===2 && array_sum(array_column($r['rows'],'requests'))===2,'Report groups agree with registry totals');
    try{$b->contacts(['from'=>'2026-02-30']);throw new RuntimeException('Invalid date accepted');}catch(InvalidArgumentException){}
    check(image($pdo,$prefix)===$before,'All business reads leave snapshots, outboxes, originals, native customers/orders unchanged');
    check($lead->submit(json_encode($p),'192.0.2.40',['content-type'=>'application/json'])['body']['replayed']===true && $quote->attachment($q['body']['id'])['bytes']==='001A;5','Read model preserves replay and private original bytes');
    $pdo->exec("DROP TABLE {$prefix}cmteh_quote_outbox"); $b=new AdminBusiness($pdo,$prefix);
    check($b->dashboard()['total']===1 && $b->dashboard()['unavailable']===['quote'],'Partially missing source does not hide available requests or invent zero');
}finally {
    foreach(['cmteh_quote_attachment','cmteh_quote_outbox','cmteh_quote_quota','cmteh_quote_rate','cmteh_quote','cmteh_lead_item','cmteh_lead_outbox','cmteh_lead_rate','cmteh_lead','cmteh_lead_schema'] as $t)$pdo->exec("DROP TABLE IF EXISTS {$prefix}{$t}");
}
