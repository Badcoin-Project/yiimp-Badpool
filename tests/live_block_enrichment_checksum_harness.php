<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLiveBlockEnrichmentBridge.php';

$failures=array();
function checksumAssert($condition,$message){global $failures;if(!$condition)$failures[]=$message;}

class ChecksumHarnessStore implements BadpoolLiveBlockEnrichmentStore
{
	public $applyCalls=0;
	public $protectedState=array('database'=>0,'wallet'=>0,'payouts'=>0,'earnings'=>0,'services'=>0,'shares'=>0,'financial'=>0);
	public function candidates($coinId,$algo,$selectedBlockIds,$limit)
	{
		return array(array(
			'block_id'=>41,'candidate_coin_id'=>1267,'candidate_algo'=>'scrypt','candidate_blockhash'=>'block-41',
			'block_exists'=>true,'block_coin_id'=>1267,'block_algo'=>'scrypt','block_blockhash'=>'block-41',
			'block_txhash'=>null,'block_amount'=>null,'block_confirmations'=>null,'block_price'=>null,
			'block_category'=>'new','coin_price'=>'0.125','coin'=>(object)array('id'=>1267),
		));
	}
	public function applyEnrichments($updates)
	{
		$this->applyCalls++;
		return array('updated_count'=>count($updates),'reconciled_count'=>count($updates));
	}
}
class ChecksumHarnessRpc
{
	public function getblock($hash){return array('hash'=>$hash,'tx'=>array('tx-41'),'confirmations'=>7);}
	public function gettransaction($hash){return array('confirmations'=>7,'details'=>array(array('category'=>'generate','amount'=>'3.5')));}
}

$store=new ChecksumHarnessStore();
$protectedBefore=$store->protectedState;
$bridge=new BadpoolLiveBlockEnrichmentBridge($store,function($coin){return new ChecksumHarnessRpc();});
$dryrun=$bridge->dryrun(1267,'scrypt',array(41),1);
$package=$bridge->approvalPackage(1267,'scrypt',array(41),1);
checksumAssert(!isset($dryrun['approval_package_checksum'])&&$dryrun['action']!==$package['action'],'package and dry-run modes remain separate');
checksumAssert(isset($package['approval_package_checksum'])&&preg_match('/^[a-f0-9]{64}$/',$package['approval_package_checksum']),'approval package emits a checksum');

// A command finalizer adds report envelope fields after bridge generation and before JSON output.
$package['warnings']=array();
$package['errors']=array();
$package['status']='pass';
$package['report_checksum']=array('algorithm'=>'sha256','value'=>'report-only');
$package['approval_package_checksum']=BadpoolLiveBlockEnrichmentBridge::approvalPackageChecksum($package);
$json=json_encode($package,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
$decoded=json_decode($json,true);
checksumAssert(is_array($decoded),'approval package JSON round decoded');
checksumAssert(BadpoolLiveBlockEnrichmentBridge::approvalPackageChecksum($decoded)===$decoded['approval_package_checksum'],'JSON round round-trip preserves embedded checksum contract');
$reportChanged=$decoded;$reportChanged['report_checksum']['value']='computed-after-approval-checksum';
checksumAssert(BadpoolLiveBlockEnrichmentBridge::approvalPackageChecksum($reportChanged)===$decoded['approval_package_checksum'],'later report checksum does not create a self-reference');

$mixed=array(
	'associative'=>array('10'=>'numeric-string-value','empty'=>array(),'z'=>null,'a'=>true),
	'list'=>array(null,false,0,1.25,'1'),
);
$mixedRoundTrip=json_decode(json_encode($mixed,JSON_UNESCAPED_SLASHES),true);
checksumAssert(BadpoolLiveBlockEnrichmentBridge::checksum($mixed)===BadpoolLiveBlockEnrichmentBridge::checksum($mixedRoundTrip),'lists, maps, null, boolean, numeric, and string values are deterministic');

$provided=array();
foreach(array('candidate_inventory_checksum','block_inventory_checksum','rpc_result_checksum','selected_scope_checksum') as $field)$provided[$field]=$decoded[$field];
$result=$bridge->applyPackage($decoded,$provided,BadpoolLiveBlockEnrichmentBridge::CONFIRMATION);
checksumAssert($result['updated_count']===1&&$store->applyCalls===1,'valid JSON fixture reaches only the fake store');

$payloadFields=array('schema','version','action','coin_id','algo','selected_block_ids','selected_count','blocked_count','approved_count','inventory','candidate_inventory_checksum','block_inventory_checksum','rpc_result_checksum','selected_scope_checksum','read_only','apply_command_shape','approval_ready','warnings','errors','status');
foreach($payloadFields as $field){
	$tampered=$decoded;
	$tampered[$field]=array('tampered'=>$field);
	try{$bridge->applyPackage($tampered,$provided,BadpoolLiveBlockEnrichmentBridge::CONFIRMATION);checksumAssert(false,'tampered payload field accepted: '.$field);}catch(RuntimeException $e){checksumAssert($e->getMessage()==='approval package checksum mismatch','tampered payload rejected at checksum: '.$field);}
}
$tamperedChecksum=$decoded;$tamperedChecksum['approval_package_checksum']=str_repeat('0',64);
try{$bridge->applyPackage($tamperedChecksum,$provided,BadpoolLiveBlockEnrichmentBridge::CONFIRMATION);checksumAssert(false,'tampered embedded checksum accepted');}catch(RuntimeException $e){checksumAssert($e->getMessage()==='approval package checksum mismatch','tampered embedded checksum rejected');}

checksumAssert($store->applyCalls===1,'rejected packages never reach the store');
checksumAssert($store->protectedState===$protectedBefore,'database, wallet, payout, earnings, service, share, and financial state untouched');

if($failures)throw new RuntimeException('FAIL: '.implode('; ',$failures));
echo "PASS live block enrichment checksum harness\n";
