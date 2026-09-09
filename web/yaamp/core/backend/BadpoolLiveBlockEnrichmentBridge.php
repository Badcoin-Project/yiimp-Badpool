<?php

interface BadpoolLiveBlockEnrichmentStore
{
	public function candidates($coinId, $algo, $selectedBlockIds, $limit);
	public function applyEnrichments($updates);
}

class BadpoolLiveBlockEnrichmentBridge
{
	const SCHEMA = 'badpool.live-capture-block-enrichment.v1';
	const CONFIRMATION = 'apply_approved_live_candidate_block_enrichment_only';
	private $store;
	private $rpcFactory;

	public function __construct(BadpoolLiveBlockEnrichmentStore $store, $rpcFactory)
	{
		$this->store=$store; $this->rpcFactory=$rpcFactory;
	}

	public static function parseIds($csv)
	{
		if ($csv===null || $csv==='') return array();
		$out=array();
		foreach(explode(',',$csv) as $raw) {
			if(!preg_match('/^[1-9][0-9]*$/',$raw)) throw new InvalidArgumentException('selected block IDs must be positive comma-separated integers');
			$id=intval($raw); if(isset($out[$id])) throw new InvalidArgumentException('duplicate selected block ID refused: '.$id); $out[$id]=$id;
		}
		ksort($out,SORT_NUMERIC); return array_values($out);
	}

	public function dryrun($coinId,$algo,$ids=array(),$limit=25)
	{
		$coinId=intval($coinId); $limit=intval($limit);
		if($coinId<=0 || !is_string($algo) || !preg_match('/^[A-Za-z0-9_-]+$/',$algo) || $limit<1 || $limit>100) throw new InvalidArgumentException('valid coin-id, algo, and limit from 1 through 100 are required');
		$rows=$this->store->candidates($coinId,$algo,$ids,$limit); if(!is_array($rows)) throw new RuntimeException('invalid live candidate inventory');
		usort($rows,function($a,$b){return intval($a['block_id'])-intval($b['block_id']);});
		$found=array();$items=array();
		foreach($rows as $row){$id=intval($row['block_id']);$found[$id]=true;$reasons=array();
			if(intval($row['candidate_coin_id'])!==$coinId)$reasons[]='candidate_coin_mismatch';
			if((string)$row['candidate_algo']!==$algo)$reasons[]='candidate_algo_mismatch';
			if(empty($row['block_exists']))$reasons[]='missing_block';
			if(!empty($row['block_exists'])&&intval($row['block_coin_id'])!==$coinId)$reasons[]='block_coin_mismatch';
			if(!empty($row['block_exists'])&&(string)$row['block_algo']!==$algo)$reasons[]='block_algo_mismatch';
			if(!empty($row['block_exists'])&&(string)$row['block_blockhash']!==(string)$row['candidate_blockhash'])$reasons[]='blockhash_mismatch';
			$expected=$this->expectedState($row);$enriched=null;
			if(!$reasons)$enriched=$this->enrich($row,$reasons);
			sort($reasons,SORT_STRING);$items[]=array('block_id'=>$id,'state'=>$reasons?'blocked':'approved','resulting_category'=>$reasons?$this->blockedCategory($reasons):$enriched['category'],'blocked_reasons'=>array_values(array_unique($reasons)),'candidate'=>$this->candidateState($row),'expected_block'=>$expected,'enrichment'=>$enriched);
		}
		foreach($ids as $id)if(!isset($found[$id]))$items[]=array('block_id'=>$id,'state'=>'blocked','resulting_category'=>'blocked_invalid_rpc_result','blocked_reasons'=>array('unknown_or_non_live_block_id'),'candidate'=>null,'expected_block'=>null,'enrichment'=>null);
		usort($items,function($a,$b){return $a['block_id']-$b['block_id'];});$selected=array();$blocked=0;foreach($items as $i){$selected[]=$i['block_id'];if($i['state']==='blocked')$blocked++;}
		return array('schema'=>self::SCHEMA,'version'=>1,'action'=>'live-capture-block-enrichment-dryrun','coin_id'=>$coinId,'algo'=>$algo,'selected_block_ids'=>$selected,'selected_count'=>count($items),'blocked_count'=>$blocked,'approved_count'=>count($items)-$blocked,'inventory'=>$items,'candidate_inventory_checksum'=>self::checksum(array_map(function($i){return $i['candidate'];},$items)),'block_inventory_checksum'=>self::checksum(array_map(function($i){return $i['expected_block'];},$items)),'rpc_result_checksum'=>self::checksum(array_map(function($i){return $i['enrichment'];},$items)),'selected_scope_checksum'=>self::checksum(array($coinId,$algo,$selected)),'read_only'=>true,'apply_command_shape'=>self::applyCommandShape());
	}

	private function enrich($row,&$reasons)
	{
		try{$rpc=call_user_func($this->rpcFactory,$row['coin']);$block=$rpc->getblock($row['candidate_blockhash']);}catch(Exception $e){$reasons[]='blocked_pending_rpc';return null;}
		if($block===false||$block===null){$reasons[]='blocked_pending_rpc';return null;}
		if(!is_array($block)){ $reasons[]='blocked_invalid_rpc_result';return null; }
		$conf=isset($block['confirmations'])&&is_numeric($block['confirmations'])?intval($block['confirmations']):null;
		if($conf!==null&&$conf<0){return array('txhash'=>null,'amount'=>'0.000000000000','confirmations'=>$conf,'price'=>$this->decimal($row['coin_price']),'category'=>'orphan');}
		if(!isset($block['hash']) || (string)$block['hash']!==(string)$row['candidate_blockhash']){$reasons[]='blocked_invalid_rpc_result';return null;}
		if(!isset($block['tx'])||!is_array($block['tx'])||!isset($block['tx'][0])||!is_string($block['tx'][0])||$block['tx'][0]===''){$reasons[]='blocked_invalid_rpc_result';return null;}
		$txhash=$block['tx'][0];try{$tx=$rpc->gettransaction($txhash);}catch(Exception $e){$reasons[]='blocked_pending_rpc';return null;}
		if($tx===false||$tx===null){$reasons[]='blocked_pending_rpc';return null;}
		if(!is_array($tx)||!isset($tx['details'])||!is_array($tx['details'])||!isset($tx['details'][0])||!is_array($tx['details'][0])){$reasons[]='blocked_invalid_rpc_result';return null;}
		$d=$tx['details'][0];$category=isset($d['category'])?(string)$d['category']:'';$txconf=isset($tx['confirmations'])&&is_numeric($tx['confirmations'])?intval($tx['confirmations']):$conf;
		if($category==='orphan'||($txconf!==null&&$txconf<0))return array('txhash'=>$txhash,'amount'=>'0.000000000000','confirmations'=>$txconf===null?-1:$txconf,'price'=>$this->decimal($row['coin_price']),'category'=>'orphan');
		if(!array_key_exists('amount',$d)||$d['amount']===null||!is_numeric($d['amount'])||floatval($d['amount'])<=0){$reasons[]='blocked_pending_block_amount';return null;}
		if($txconf===null){$reasons[]='blocked_invalid_rpc_result';return null;}
		$resultCategory=$category==='generate'?'generate':'immature';
		return array('txhash'=>$txhash,'amount'=>$this->decimal($d['amount']),'confirmations'=>$txconf,'price'=>$this->decimal($row['coin_price']),'category'=>$resultCategory);
	}

	public function approvalPackage($coin,$algo,$ids=array(),$limit=25){$r=$this->dryrun($coin,$algo,$ids,$limit);$r['action']='live-capture-block-enrichment-approval-package';$r['approval_ready']=$r['blocked_count']===0&&$r['approved_count']>0;$r['approval_package_checksum']=self::approvalPackageChecksum($r);return $r;}
	public function applyPackage($p,$provided,$confirmation)
	{
		if(!is_array($p)||!isset($p['approval_package_checksum'])||!hash_equals((string)$p['approval_package_checksum'],self::approvalPackageChecksum($p)))throw new RuntimeException('approval package checksum mismatch');
		foreach(array('candidate_inventory_checksum','block_inventory_checksum','rpc_result_checksum','selected_scope_checksum') as $k)if(!isset($provided[$k])||!hash_equals((string)$p[$k],(string)$provided[$k]))throw new RuntimeException($k.' mismatch');
		if($confirmation!==self::CONFIRMATION)throw new RuntimeException('incorrect operator confirmation');if(empty($p['approval_ready'])||intval($p['blocked_count'])!==0)throw new RuntimeException('package contains blocked candidates');
		$f=$this->dryrun($p['coin_id'],$p['algo'],$p['selected_block_ids'],count($p['selected_block_ids']));foreach(array('candidate_inventory_checksum','block_inventory_checksum','rpc_result_checksum','selected_scope_checksum') as $k)if(!hash_equals($p[$k],$f[$k]))throw new RuntimeException(str_replace('_checksum','',$k).' drift');
		$updates=array();foreach($p['inventory'] as $i)$updates[]=array('block_id'=>$i['block_id'],'expected_block'=>$i['expected_block'],'enrichment'=>$i['enrichment']);$result=$this->store->applyEnrichments($updates);if(!is_array($result)||intval($result['updated_count'])!==count($updates)||intval($result['reconciled_count'])!==count($updates))throw new RuntimeException('post-apply reconciliation failed');
		return array('schema'=>self::SCHEMA,'action'=>'live-capture-block-enrichment-apply','status'=>'applied','updated_count'=>count($updates),'earnings_mutated'=>false,'financial_mutations'=>false,'backend_callbacks_run'=>false);
	}
	public static function applyCommandShape(){return 'php yaamp/yiic.php badpoolguard live-capture-block-enrichment-apply --coin-id=<id> --algo=<algo> --approval-package=<path> --approval-package-checksum=<file-sha256> --candidate-inventory-checksum=<sha256> --block-inventory-checksum=<sha256> --rpc-result-checksum=<sha256> --selected-scope-checksum=<sha256> --operator-confirms-live-capture-block-enrichment='.self::CONFIRMATION.' --format=json';}
	public static function checksum($v){return hash('sha256',json_encode(self::canonical($v),JSON_UNESCAPED_SLASHES));}
	public static function normalizeBlockState($state)
	{
		if(!is_array($state))throw new InvalidArgumentException('block state must be an array');
		$fields=array('id','coin_id','blockhash','txhash','amount','confirmations','price','category');$allowed=array_flip($fields);
		foreach($state as $field=>$value)if(!isset($allowed[$field]))throw new InvalidArgumentException('unexpected block state field: '.$field);
		$out=array();
		foreach($fields as $field){
			if(!array_key_exists($field,$state))continue;
			$value=$state[$field];
			if($value===null){$out[$field]=null;continue;}
			if($field==='id'||$field==='coin_id'||$field==='confirmations')$out[$field]=self::normalizeInteger($value);
			elseif($field==='amount'||$field==='price')$out[$field]=self::normalizeDecimal($value);
			else $out[$field]=(string)$value;
		}
		return $out;
	}
	public static function blockStatesEqual($left,$right){return self::normalizeBlockState($left)===self::normalizeBlockState($right);}
	private static function normalizeInteger($value)
	{
		if(!(is_int($value)||is_string($value)))throw new InvalidArgumentException('invalid integer block state value');
		if(!preg_match('/^[+-]?[0-9]+$/D',(string)$value))return (string)$value;
		$value=(string)$value;$negative=isset($value[0])&&$value[0]==='-';
		if(isset($value[0])&&($value[0]==='-'||$value[0]==='+'))$value=substr($value,1);
		$value=ltrim($value,'0');if($value==='')return '0';return $negative?'-'.$value:$value;
	}
	private static function normalizeDecimal($value)
	{
		// Deliberately operate on fixed-point text: binary floating point must not
		// participate in a monetary reconciliation decision.
		if(!(is_int($value)||is_string($value)))throw new InvalidArgumentException('invalid decimal block state value');
		if(!preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D',(string)$value))return (string)$value;
		$value=(string)$value;$negative=isset($value[0])&&$value[0]==='-';
		if(isset($value[0])&&($value[0]==='-'||$value[0]==='+'))$value=substr($value,1);
		$parts=explode('.',$value,2);$whole=ltrim($parts[0],'0');if($whole==='')$whole='0';
		$fraction=isset($parts[1])?rtrim($parts[1],'0'):'';$value=$fraction===''?$whole:$whole.'.'.$fraction;
		return $negative&&$value!=='0'?'-'.$value:$value;
	}
	public static function approvalPackageChecksum($p){return self::checksum(self::approvalPackagePayload($p));}
	private static function approvalPackagePayload($p)
	{
		$out=$p;
		unset($out['approval_package_checksum'],$out['report_checksum']);
		// Normalize through the same JSON object/array boundary used by the file executor.
		return json_decode(json_encode($out,JSON_UNESCAPED_SLASHES),true);
	}
	private static function canonical($v){if(!is_array($v))return $v;$isList=count($v)===0||array_keys($v)===range(0,count($v)-1);if(!$isList)ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::canonical($x);return $v;}
	private function blockedCategory($reasons){if(in_array('blocked_pending_rpc',$reasons,true))return 'blocked_pending_rpc';if(in_array('blocked_pending_block_amount',$reasons,true))return 'blocked_pending_block_amount';if(in_array('blocked_orphan',$reasons,true))return 'blocked_orphan';return 'blocked_invalid_rpc_result';}
	private function candidateState($r){return array('block_id'=>intval($r['block_id']),'coin_id'=>intval($r['candidate_coin_id']),'algo'=>(string)$r['candidate_algo'],'blockhash'=>(string)$r['candidate_blockhash']);}
	private function expectedState($r){if(empty($r['block_exists']))return null;$o=array('id'=>intval($r['block_id']));foreach(array('coin_id','blockhash','txhash','amount','confirmations','price','category') as $k)$o[$k]=isset($r['block_'.$k])?$r['block_'.$k]:null;return $o;}
	private function decimal($v){return number_format(floatval($v),12,'.','');}
}

class BadpoolYiiLiveBlockEnrichmentStore implements BadpoolLiveBlockEnrichmentStore
{
	private $db; public function __construct($db){$this->db=$db;}
	public function candidates($coin,$algo,$ids,$limit){$p=array(':coin'=>$coin,':algo'=>$algo);$where='C.coin_id=:coin AND C.algo=:algo';if($ids){$q=array();foreach($ids as $n=>$id){$k=':id'.$n;$q[]=$k;$p[$k]=$id;}$where.=' AND C.block_id IN ('.implode(',',$q).')';}$sql="SELECT C.block_id,C.coin_id candidate_coin_id,C.algo candidate_algo,C.blockhash candidate_blockhash,B.id joined_block_id,B.coin_id block_coin_id,B.blockhash block_blockhash,B.txhash block_txhash,B.amount block_amount,B.confirmations block_confirmations,B.price block_price,B.category block_category,CO.algo block_algo,CO.price coin_price FROM live_block_candidates C LEFT JOIN blocks B ON B.id=C.block_id LEFT JOIN coins CO ON CO.id=B.coin_id WHERE $where ORDER BY C.block_id LIMIT ".intval($limit);$rows=$this->db->createCommand($sql)->queryAll(true,$p);foreach($rows as &$r){$r['block_exists']=$r['joined_block_id']!==null;$r['coin']=$r['block_exists']?getdbo('db_coins',$r['block_coin_id']):null;}return $rows;}
	public function applyEnrichments($updates){$tx=$this->db->beginTransaction();try{$done=0;$reconciled=0;foreach($updates as $u){$e=$u['expected_block'];$row=$this->db->createCommand('SELECT id,coin_id,blockhash,txhash,amount,confirmations,price,category FROM blocks WHERE id=:id FOR UPDATE')->queryRow(true,array(':id'=>$u['block_id']));if(!BadpoolLiveBlockEnrichmentBridge::blockStatesEqual($row,$e))throw new RuntimeException('locked block drift: '.$u['block_id']);$n=$this->db->createCommand()->update('blocks',$u['enrichment'],'id=:id',array(':id'=>$u['block_id']));if(intval($n)!==1)throw new RuntimeException('block update failed: '.$u['block_id']);$check=$this->db->createCommand('SELECT txhash,amount,confirmations,price,category FROM blocks WHERE id=:id')->queryRow(true,array(':id'=>$u['block_id']));if(!BadpoolLiveBlockEnrichmentBridge::blockStatesEqual($check,$u['enrichment']))throw new RuntimeException('block reconciliation failed: '.$u['block_id']);$done++;$reconciled++;}$tx->commit();return array('updated_count'=>$done,'reconciled_count'=>$reconciled);}catch(Exception $e){if($tx->active)$tx->rollback();throw $e;}}
}
