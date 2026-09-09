<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolLiveBlockEnrichmentBridge.php';

$failures=array();
function reconciliationAssert($condition,$message){global $failures;if(!$condition)$failures[]=$message;}
function reconciliationDifferent($left,$right,$message){reconciliationAssert(!BadpoolLiveBlockEnrichmentBridge::blockStatesEqual($left,$right),$message);}

$package=array('id'=>27320,'coin_id'=>1267,'blockhash'=>'c0f51c427e68f99fe1d256ce9ce2893186b2fa3c8652c507d2dd2a090cab00e1','txhash'=>'ed633625de5868c03169faa58e289f54d16389beeb1609ff64117dd29a1f4810','amount'=>'2165.269074420000','confirmations'=>1722,'price'=>'0.000000000000','category'=>'generate');
$database=array('id'=>'27320','coin_id'=>'1267','blockhash'=>$package['blockhash'],'txhash'=>$package['txhash'],'amount'=>'2165.26907442','confirmations'=>'1722','price'=>'0.00000000','category'=>'generate');
reconciliationAssert(BadpoolLiveBlockEnrichmentBridge::blockStatesEqual($package,$database),'MariaDB strings and package values normalize equally');
reconciliationAssert(BadpoolLiveBlockEnrichmentBridge::blockStatesEqual(array('confirmations'=>1722),array('confirmations'=>'001722')),'integer and numeric-string confirmations normalize equally');
reconciliationAssert(BadpoolLiveBlockEnrichmentBridge::blockStatesEqual(array('amount'=>'2165.269074420000'),array('amount'=>'2165.26907442')),'amount scale variants normalize equally');
reconciliationAssert(BadpoolLiveBlockEnrichmentBridge::blockStatesEqual(array('price'=>'0.000000000000'),array('price'=>'0.00')),'zero price scale variants normalize equally');
foreach(array(0,'0','0.000000000000') as $zero)reconciliationAssert(BadpoolLiveBlockEnrichmentBridge::blockStatesEqual(array('price'=>'0.000000000000'),array('price'=>$zero)),'integer and textual zero prices normalize equally');
reconciliationDifferent(array('amount'=>null),array('amount'=>''),'NULL remains distinct from empty string');
reconciliationDifferent(array('amount'=>null),array('amount'=>'0'),'NULL remains distinct from zero');
foreach(array('txhash'=>'other','amount'=>'2165.26907443','confirmations'=>1723,'price'=>'0.1','category'=>'immature') as $field=>$changed){$other=$package;$other[$field]=$changed;reconciliationDifferent($package,$other,'changed '.$field.' is rejected');}
foreach(array('id'=>27321,'coin_id'=>1268,'blockhash'=>'other-block') as $field=>$changed){$other=$package;$other[$field]=$changed;reconciliationDifferent($package,$other,'identity drift in '.$field.' is rejected');}

class ReconciliationTransaction
{
	public $active=true;private $db;private $before;
	public function __construct($db){$this->db=$db;$this->before=$db->rows;}
	public function commit(){$this->active=false;$this->db->commits++;}
	public function rollback(){$this->db->rows=$this->before;$this->active=false;$this->db->rollbacks++;}
}
class ReconciliationCommand
{
	private $db;private $sql;
	public function __construct($db,$sql){$this->db=$db;$this->sql=$sql;if($sql!==null)$db->queries[]=$sql;}
	private function castDoubleAsMariaDbChar($value)
	{
		if($value===null)return null;
		// MariaDB's CAST(DOUBLE AS CHAR) emits a textual, non-padded value. These
		// fixtures model the exact values from the production DOUBLE columns.
		if($value===2165.26907442)return '2165.26907442';
		if($value===2165.26907443)return '2165.26907443';
		if($value===0.0)return '0';
		if($value===0.1)return '0.1';
		throw new RuntimeException('unmodelled fake DOUBLE value');
	}
	public function queryRow($fetchAssociative,$params){$row=$this->db->rows[intval($params[':id'])];$fields=strpos($this->sql,'SELECT id,')===0?array('id','coin_id','blockhash','txhash','amount','confirmations','price','category'):array('txhash','amount','confirmations','price','category');$out=array();foreach($fields as $field){$value=$row[$field];$cast=($field==='amount'&&strpos($this->sql,'CAST(amount AS CHAR) AS amount')!==false)||($field==='price'&&strpos($this->sql,'CAST(price AS CHAR) AS price')!==false);$out[$field]=$cast?$this->castDoubleAsMariaDbChar($value):$value;}if($this->db->corruptField!==null&&count($fields)===5)$out[$this->db->corruptField]=$this->db->corruptValue;return $out;}
	public function update($table,$values,$where,$params){$id=intval($params[':id']);$this->db->updateFields=array_keys($values);foreach($values as $field=>$value)$this->db->rows[$id][$field]=($field==='amount'||$field==='price')?floatval($value):$value;return 1;}
}
class ReconciliationDatabase
{
	public $rows;public $queries=array();public $commits=0;public $rollbacks=0;public $updateFields=array();public $corruptField=null;public $corruptValue=null;
	public $protectedState=array('earnings'=>0,'accounts'=>0,'payouts'=>0,'wallet'=>0,'services'=>0,'backend_loop'=>0,'shares'=>0,'financial'=>0);
	public function __construct($row){$this->rows=array(intval($row['id'])=>$row);}
	public function beginTransaction(){return new ReconciliationTransaction($this);}
	public function createCommand($sql=null){return new ReconciliationCommand($this,$sql);}
}

$before=array('id'=>27320,'coin_id'=>1267,'blockhash'=>$package['blockhash'],'txhash'=>null,'amount'=>null,'confirmations'=>null,'price'=>null,'category'=>'new');
$enrichment=array('txhash'=>$package['txhash'],'amount'=>'2165.269074420000','confirmations'=>1722,'price'=>'0.000000000000','category'=>'generate');
$updates=array(array('block_id'=>27320,'expected_block'=>$before,'enrichment'=>$enrichment));
$db=new ReconciliationDatabase($before);$protectedBefore=$db->protectedState;$store=new BadpoolYiiLiveBlockEnrichmentStore($db);$result=$store->applyEnrichments($updates);
reconciliationAssert($result===array('updated_count'=>1,'reconciled_count'=>1),'successful fake-store reconciliation increments both counters');
reconciliationAssert($db->commits===1&&$db->rollbacks===0,'successful reconciliation commits');
reconciliationAssert($db->updateFields===array('txhash','amount','confirmations','price','category'),'update retains exact five-field mutation scope');
reconciliationAssert(strpos($db->queries[0],'CAST(amount AS CHAR) AS amount')!==false&&strpos($db->queries[0],'CAST(price AS CHAR) AS price')!==false&&substr($db->queries[0],-10)==='FOR UPDATE','locked query casts both DOUBLE fields and retains its lock');
reconciliationAssert(strpos($db->queries[1],'CAST(amount AS CHAR) AS amount')!==false&&strpos($db->queries[1],'CAST(price AS CHAR) AS price')!==false,'post-update query casts both DOUBLE fields');
reconciliationAssert(is_float($db->rows[27320]['amount'])&&is_float($db->rows[27320]['price']),'fake adapter stores DOUBLE columns as PHP floats before SQL casts them to text');
reconciliationAssert($db->protectedState===$protectedBefore,'protected markers remain untouched after success');

foreach(array('txhash'=>'other','amount'=>'2165.26907443','confirmations'=>1723,'price'=>'0.1','category'=>'immature') as $field=>$changed){
	$db=new ReconciliationDatabase($before);$protectedBefore=$db->protectedState;$db->corruptField=$field;$db->corruptValue=$changed;$store=new BadpoolYiiLiveBlockEnrichmentStore($db);
	try{$store->applyEnrichments($updates);reconciliationAssert(false,'changed post-update '.$field.' was accepted');}catch(RuntimeException $e){reconciliationAssert($e->getMessage()==='block reconciliation failed: 27320','failed '.$field.' reconciliation identifies the block');}
	reconciliationAssert($db->commits===0&&$db->rollbacks===1&&$db->rows[27320]===$before,'failed '.$field.' reconciliation rolls back the update');
	reconciliationAssert($db->protectedState===$protectedBefore,'protected markers remain untouched after '.$field.' rollback');
}

foreach(array('id'=>27321,'coin_id'=>1268,'blockhash'=>'drifted','txhash'=>'other','amount'=>2165.26907443,'confirmations'=>1,'price'=>0.1,'category'=>'immature') as $field=>$changed){
	$db=new ReconciliationDatabase($before);$db->rows[27320][$field]=$changed;$store=new BadpoolYiiLiveBlockEnrichmentStore($db);
	try{$store->applyEnrichments($updates);reconciliationAssert(false,'locked '.$field.' drift was accepted');}catch(RuntimeException $e){reconciliationAssert($e->getMessage()==='locked block drift: 27320','locked '.$field.' drift identifies the block');}
	reconciliationAssert($db->rollbacks===1&&$db->updateFields===array(),'locked '.$field.' drift rolls back before update');
}

if($failures)throw new RuntimeException('FAIL: '.implode('; ',$failures));
echo "PASS live block enrichment reconciliation harness\n";
