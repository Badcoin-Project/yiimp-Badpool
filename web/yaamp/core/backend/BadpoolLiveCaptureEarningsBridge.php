<?php

interface BadpoolLiveCaptureEarningsStore
{
	public function inventory($coinId, $selectedBlockIds, $limit);
	public function applyEarnings($rows, $expected);
}

class BadpoolLiveCaptureEarningsBridge
{
	const SCHEMA = 'badpool.live-capture-earnings.v1';
	const CONFIRMATION = 'apply_approved_live_capture_earnings_only';
	private $store;
	private $fee;

	public function __construct(BadpoolLiveCaptureEarningsStore $store, $fee=null)
	{
		$this->store = $store;
		$this->fee = $fee ?: function($amount, $algo, $donation=null) {
			return $donation === null ? take_yaamp_fee($amount, $algo) : take_yaamp_fee($amount, $algo, $donation);
		};
	}

	public static function parseIds($csv)
	{
		if ($csv === null || $csv === '') return array();
		$out = array();
		foreach (explode(',', $csv) as $id) {
			if (!preg_match('/^[1-9][0-9]*$/', $id)) throw new InvalidArgumentException('selected block IDs must be positive comma-separated integers');
			$out[intval($id)] = intval($id);
		}
		ksort($out, SORT_NUMERIC);
		return array_values($out);
	}

	public function dryrun($coinId, $selectedBlockIds=array(), $limit=25)
	{
		$coinId = intval($coinId); $limit = intval($limit);
		if ($coinId <= 0 || $limit < 1 || $limit > 100) throw new InvalidArgumentException('coin-id and limit from 1 through 100 are required');
		$selectedBlockIds = array_values(array_unique(array_map('intval', $selectedBlockIds)));
		sort($selectedBlockIds, SORT_NUMERIC);
		$inventory = $this->store->inventory($coinId, $selectedBlockIds, $limit);
		if (!is_array($inventory)) throw new RuntimeException('invalid live candidate inventory');
		usort($inventory, function($a,$b) { return intval($a['block_id']) - intval($b['block_id']); });
		$found = array(); $blocks = array(); $projected = array();
		foreach ($inventory as $item) {
			$id = intval($item['block_id']); $found[$id] = true; $reasons = array();
			if (empty($item['block_exists'])) $reasons[] = 'missing_block';
			if (intval($item['candidate_coin_id']) !== $coinId) $reasons[] = 'candidate_coin_mismatch';
			if (!empty($item['block_exists']) && intval($item['block_coin_id']) !== intval($item['candidate_coin_id'])) $reasons[] = 'block_coin_mismatch';
			if (!empty($item['block_exists']) && (string)$item['block_algo'] !== (string)$item['candidate_algo']) $reasons[] = 'block_algo_mismatch';
			if (intval($item['earnings_count']) > 0) $reasons[] = 'already_has_earnings';
			$attrs = isset($item['attributions']) && is_array($item['attributions']) ? $item['attributions'] : array();
			usort($attrs, function($a,$b) { return intval($a['userid']) - intval($b['userid']); });
			if (!$attrs) $reasons[] = 'blocked_no_attribution';
			$total = 0.0;
			foreach ($attrs as $a) { $d=floatval($a['difficulty']); if ($d <= 0) $reasons[]='non_positive_attribution_difficulty'; else $total += $d; }
			if (!in_array(isset($item['block_category'])?(string)$item['block_category']:'',array('immature','generate'),true)) $reasons[] = 'blocked_pending_block_enrichment';
			if (!isset($item['block_amount']) || $item['block_amount'] === null || !is_numeric($item['block_amount']) || floatval($item['block_amount']) <= 0) $reasons[] = 'blocked_pending_block_amount';
			$reasons = array_values(array_unique($reasons)); sort($reasons, SORT_STRING);
			$state = empty($reasons) ? 'eligible' : 'blocked';
			$source = array('block_id'=>$id,'candidate_coin_id'=>intval($item['candidate_coin_id']),'candidate_algo'=>(string)$item['candidate_algo'],'blockhash'=>(string)$item['blockhash'],'found_time'=>intval($item['found_time']),'price'=>self::decimal($item['price']),'share_floor_id'=>intval($item['share_floor_id']),'share_ceiling_id'=>intval($item['share_ceiling_id']),'block_exists'=>(bool)$item['block_exists'],'block_coin_id'=>isset($item['block_coin_id'])?intval($item['block_coin_id']):null,'block_algo'=>isset($item['block_algo'])?(string)$item['block_algo']:null,'block_amount'=>isset($item['block_amount'])?self::decimal($item['block_amount']):null,'block_txhash'=>isset($item['block_txhash'])?(string)$item['block_txhash']:null,'block_confirmations'=>isset($item['block_confirmations'])?intval($item['block_confirmations']):null,'block_price'=>isset($item['block_price'])?self::decimal($item['block_price']):null,'block_category'=>isset($item['block_category'])?(string)$item['block_category']:null,'earnings_count'=>intval($item['earnings_count']));
			$blocks[] = array('block_id'=>$id,'state'=>$state,'refusal_reasons'=>$reasons,'source'=>$source,'attribution_count'=>count($attrs),'attribution_difficulty'=>self::decimal($total));
			if ($state === 'eligible') foreach ($attrs as $a) {
				$amount=floatval($item['block_amount'])*floatval($a['difficulty'])/$total;
				if (empty($a['no_fees'])) $amount=call_user_func($this->fee,$amount,$item['candidate_algo'],null);
				if (floatval($a['donation'])>0) $amount=call_user_func($this->fee,$amount,$item['candidate_algo'],floatval($a['donation']));
				if ($amount > 0) $projected[]=array('userid'=>intval($a['userid']),'coinid'=>$coinId,'blockid'=>$id,'create_time'=>intval($item['found_time']),'mature_time'=>null,'amount'=>self::decimal($amount),'price'=>self::decimal($item['price']),'status'=>0);
			}
		}
		if ($selectedBlockIds) foreach ($selectedBlockIds as $id) if (!isset($found[$id])) $blocks[]=array('block_id'=>$id,'state'=>'blocked','refusal_reasons'=>array('unknown_or_non_live_block_id'),'source'=>null,'attribution_count'=>0,'attribution_difficulty'=>self::decimal(0));
		usort($blocks,function($a,$b){return $a['block_id']-$b['block_id'];});
		usort($projected,function($a,$b){return $a['blockid']===$b['blockid']?$a['userid']-$b['userid']:$a['blockid']-$b['blockid'];});
		$ids=array();$blocked=0; foreach($blocks as $b){$ids[]=$b['block_id'];if($b['state']==='blocked')$blocked++;}
		$sources=array();foreach($blocks as $b)if($b['source']!==null)$sources[]=$b['source'];
		$attrsCanonical=array();foreach($inventory as $i){$as=$i['attributions'];usort($as,function($a,$b){return intval($a['userid'])-intval($b['userid']);});foreach($as as $a)$attrsCanonical[]=array('block_id'=>intval($i['block_id']),'userid'=>intval($a['userid']),'difficulty'=>self::decimal($a['difficulty']),'no_fees'=>intval($a['no_fees']),'donation'=>self::decimal($a['donation']));}
		return array('schema'=>self::SCHEMA,'version'=>1,'action'=>'live-capture-earnings-dryrun','coin_id'=>$coinId,'selected_block_ids'=>$ids,'selected_count'=>count($blocks),'blocked_count'=>$blocked,'eligible_count'=>count($blocks)-$blocked,'per_block_inventory'=>$blocks,'projected_earnings_rows'=>$projected,'source_live_candidate_checksum'=>self::checksum($sources),'attribution_checksum'=>self::checksum($attrsCanonical),'projected_earnings_checksum'=>self::checksum($projected),'selected_scope_checksum'=>self::checksum(array('coin_id'=>$coinId,'selected_block_ids'=>$ids)),'apply_command_shape'=>self::applyCommandShape(),'read_only'=>true);
	}

	public function approvalPackage($coinId,$ids=array(),$limit=25)
	{
		$r=$this->dryrun($coinId,$ids,$limit);$r['action']='live-capture-earnings-approval-package';$r['approval_ready']=$r['blocked_count']===0&&$r['eligible_count']>0;$r['approval_package_checksum']=self::checksum(self::approvalPayload($r));return $r;
	}

	public function applyPackage($package,$fileChecksum,$provided,$confirmation)
	{
		if (!is_array($package) || !isset($package['approval_package_checksum'])) throw new InvalidArgumentException('missing approval package');
		$expected=$package['approval_package_checksum'];
		if (!hash_equals($expected,self::checksum(self::approvalPayload($package))) || !hash_equals($expected,(string)$fileChecksum)) throw new RuntimeException('approval package checksum mismatch');
		foreach(array('selected_scope_checksum','source_live_candidate_checksum','attribution_checksum','projected_earnings_checksum') as $key) if(!isset($provided[$key])||!hash_equals((string)$package[$key],(string)$provided[$key])) throw new RuntimeException($key.' mismatch');
		if($confirmation!==self::CONFIRMATION) throw new RuntimeException('incorrect operator confirmation');
		if(empty($package['approval_ready'])||intval($package['blocked_count'])!==0) throw new RuntimeException('package contains blocked candidates');
		$fresh=$this->dryrun($package['coin_id'],$package['selected_block_ids'],max(1,count($package['selected_block_ids'])));
		foreach(array('selected_scope_checksum','source_live_candidate_checksum','attribution_checksum','projected_earnings_checksum') as $key)if(!hash_equals((string)$package[$key],(string)$fresh[$key]))throw new RuntimeException('live scope drift: '.$key);
		$result=$this->store->applyEarnings($package['projected_earnings_rows'],$provided);
		if(!is_array($result)||intval($result['inserted_count'])!==count($package['projected_earnings_rows']))throw new RuntimeException('post-apply reconciliation failed');
		return array('schema'=>self::SCHEMA,'version'=>1,'action'=>'live-capture-earnings-apply','status'=>'applied','selected_block_ids'=>$package['selected_block_ids'],'inserted_count'=>intval($result['inserted_count']),'post_apply_reconciliation'=>$result,'payout_rows_created'=>false,'wallet_sends'=>false,'backend_loops_run'=>false);
	}

	public static function applyCommandShape(){return 'php yaamp/yiic.php badpoolguard live-capture-earnings-apply --coin-id=<id> --approval-package=<path> --approval-package-checksum=<sha256> --selected-scope-checksum=<sha256> --source-live-candidate-checksum=<sha256> --attribution-checksum=<sha256> --projected-earnings-checksum=<sha256> --operator-confirms-live-capture-earnings='.self::CONFIRMATION.' --format=json';}
	public static function checksum($value){return hash('sha256',json_encode(self::canonical($value),JSON_UNESCAPED_SLASHES));}
	private static function approvalPayload($p){$keys=array('schema','version','action','coin_id','selected_block_ids','selected_count','blocked_count','eligible_count','per_block_inventory','projected_earnings_rows','source_live_candidate_checksum','attribution_checksum','projected_earnings_checksum','selected_scope_checksum','apply_command_shape','approval_ready');$out=array();foreach($keys as $k)$out[$k]=isset($p[$k])?$p[$k]:null;return $out;}
	private static function canonical($v){if(!is_array($v))return $v;if(array_keys($v)!==range(0,count($v)-1)){ksort($v,SORT_STRING);}foreach($v as $k=>$x)$v[$k]=self::canonical($x);return $v;}
	private static function decimal($v){return number_format(floatval($v),12,'.','');}
}

class BadpoolYiiLiveCaptureEarningsStore implements BadpoolLiveCaptureEarningsStore
{
	private $db; public function __construct($db){$this->db=$db;}
	public function inventory($coinId,$ids,$limit)
	{
		$p=array(':coin'=>$coinId);$where='C.coin_id=:coin';
		if($ids){$in=array();foreach($ids as $n=>$id){$k=':id'.$n;$in[]=$k;$p[$k]=$id;}$where.=' AND C.block_id IN ('.implode(',',$in).')';}
		$sql="SELECT C.*,B.id AS joined_block_id,B.coin_id AS block_coin_id,CO.algo AS block_algo,B.amount AS block_amount,B.txhash AS block_txhash,B.confirmations AS block_confirmations,B.price AS block_price,B.category AS block_category,(SELECT COUNT(*) FROM earnings E WHERE E.blockid=C.block_id) AS earnings_count FROM live_block_candidates C LEFT JOIN blocks B ON B.id=C.block_id LEFT JOIN coins CO ON CO.id=B.coin_id WHERE $where ORDER BY C.block_id LIMIT ".intval($limit);
		$rows=$this->db->createCommand($sql)->queryAll(true,$p);foreach($rows as &$r){$r['block_exists']=$r['joined_block_id']!==null;$r['candidate_coin_id']=$r['coin_id'];$r['candidate_algo']=$r['algo'];$r['attributions']=$this->db->createCommand('SELECT userid,difficulty,no_fees,donation FROM live_block_attributions WHERE block_id=:id ORDER BY userid')->queryAll(true,array(':id'=>$r['block_id']));}return $rows;
	}
	public function applyEarnings($rows,$expected)
	{
		$tx=$this->db->beginTransaction();try{$blocks=array();foreach($rows as $r)$blocks[intval($r['blockid'])]=true;foreach(array_keys($blocks) as $id){$n=$this->db->createCommand('SELECT COUNT(*) FROM earnings WHERE blockid=:id FOR UPDATE')->queryScalar(array(':id'=>$id));if(intval($n)!==0)throw new RuntimeException('duplicate earnings for block '.$id);}foreach($rows as $r)$this->db->createCommand()->insert('earnings',array('userid'=>$r['userid'],'coinid'=>$r['coinid'],'blockid'=>$r['blockid'],'create_time'=>$r['create_time'],'amount'=>$r['amount'],'price'=>$r['price'],'status'=>0));$count=0;foreach(array_keys($blocks) as $id)$count+=intval($this->db->createCommand('SELECT COUNT(*) FROM earnings WHERE blockid=:id')->queryScalar(array(':id'=>$id)));$tx->commit();return array('inserted_count'=>$count,'block_count'=>count($blocks));}catch(Exception $e){if($tx->active)$tx->rollback();throw $e;}}
}
