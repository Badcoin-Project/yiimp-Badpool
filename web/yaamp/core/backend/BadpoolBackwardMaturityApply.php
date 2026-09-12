<?php

require_once(dirname(__FILE__).'/BadpoolBackwardMaturityApprovalPackage.php');

/** Guarded, deliberately narrow executor for the reviewed backward maturity cohort. */
class BadpoolBackwardMaturityApply
{
	const SCHEMA = 'badpool.backward_maturity_apply.v1';
	const COMMAND = 'backward-maturity-transition-apply';
	const MODE = 'guarded-apply';
	const APPROVAL_PACKAGE_FILE_SHA256 = '6cad1443efda2f794c87faa588c62602aae4f2f1e61215427613d643942d8e7b';
	const APPROVAL_PACKAGE_INTERNAL_CHECKSUM = 'acc0955d8b97720f86a18fe7e2308d3007157ab2dcea43c66f143d1099a27b7a';
	const DRYRUN_FILE_SHA256 = '0102703a6ce840471b87ac8e8324763670615763aaafc11d4a9b3a9504fabfea';

	public static function confirmationShape($approvalChecksum)
	{
		return 'I confirm coin_id 1267 row_count 71 amount 153624.877229050000 approval_package_file_sha256 '.$approvalChecksum.' approval_package_internal_checksum '.self::APPROVAL_PACKAGE_INTERNAL_CHECKSUM.' retained_dryrun_file_sha256 '.self::DRYRUN_FILE_SHA256.'; perform only earnings status maturity and block category maturity transition; no account credit, payout, wallet, service, backend loop, or share deletion';
	}

	public static function parseOptions($args)
	{
		$required=array('coin-id','approval-package','approval-package-checksum','dryrun-report','dryrun-report-checksum','selected-earning-ids','selected-block-ids','expected-inventory-checksum','operator-confirms-backward-maturity-transition','format');$o=array();
		foreach($args as $arg){if(!preg_match('/^--([^=]+)=(.*)$/',$arg,$m))return self::parseFail('Every option requires an explicit value.');$n=strtolower($m[1]);if(!in_array($n,$required,true))return self::parseFail('Unknown option refused: --'.$m[1].'.');if(isset($o[$n]))return self::parseFail('Duplicate option refused: --'.$m[1].'.');$o[$n]=$m[2];}
		foreach($required as $n)if(!isset($o[$n])||$o[$n]==='')return self::parseFail('Missing required --'.$n.'.');
		foreach(array('approval-package-checksum','dryrun-report-checksum','expected-inventory-checksum') as $n)if(!preg_match('/^[a-f0-9]{64}$/',$o[$n]))return self::parseFail('--'.$n.' must be a lowercase SHA-256 value.');
		$dry=BadpoolBackwardMaturityDryrun::parseOptions(array('--coin-id='.$o['coin-id'],'--selected-earning-ids='.$o['selected-earning-ids'],'--selected-block-ids='.$o['selected-block-ids'],'--expected-inventory-checksum='.$o['expected-inventory-checksum'],'--format='.$o['format']));
		if($dry['status']!=='pass')return self::parseFail($dry['message']);
		if(!hash_equals(self::confirmationShape($o['approval-package-checksum']),$o['operator-confirms-backward-maturity-transition']))return self::parseFail('Operator confirmation is missing or does not exactly bind this apply.');
		return array('status'=>'pass','options'=>$o,'earning_ids'=>$dry['selected_earning_ids'],'block_ids'=>$dry['selected_block_ids']);
	}
	private static function parseFail($m){return array('status'=>'fail','message'=>$m);}
	private static function v($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}

	/** $store supplies begin, lock, fresh, updateEarnings, updateBlocks, post, commit and rollback. */
	public static function run($args,$store,$expectedBindings=array())
	{
		$p=self::parseOptions($args);$o=self::v($p,'options',array());$report=self::baseReport($o);
		if($p['status']!=='pass')return self::hold($report,self::v($p,'message'));
		if(isset($expectedBindings['dryrun_report_file_sha256'])&&!hash_equals($expectedBindings['dryrun_report_file_sha256'],$o['dryrun-report-checksum']))return self::hold($report,'Dry-run report is not the retained reviewed file binding.');
		$fileChecksum=self::v($expectedBindings,'file_checksum');
		$approval=self::readJson($o['approval-package'],$o['approval-package-checksum'],$error,$fileChecksum);if($approval===false)return self::hold($report,$error);
		$internalChecksum=self::approvalPackageInternalChecksum($approval);$report['package_bindings']['approval_package_internal_checksum']=$internalChecksum;
		$dry=self::readJson($o['dryrun-report'],$o['dryrun-report-checksum'],$error,$fileChecksum);if($dry===false)return self::hold($report,$error);
		$expectedInternal=isset($expectedBindings['approval_package_internal_checksum'])?$expectedBindings['approval_package_internal_checksum']:self::APPROVAL_PACKAGE_INTERNAL_CHECKSUM;
		$packageValidation=self::packageChecks($approval,$dry,$o,$p['earning_ids'],$p['block_ids'],$expectedInternal,$internalChecksum);$report['pre_apply_validation']['retained_package_checks']=$packageValidation['checks'];$report['pre_apply_validation']['retained_package_checksum_diagnostics']=$packageValidation['diagnostics'];
		foreach($packageValidation['checks'] as $ok)if(!$ok)return self::hold($report,'Retained package binding failed.');
		$started=false;
		try {
			$store->begin();$started=true;$store->lock($p['earning_ids'],$p['block_ids']);$fresh=$store->fresh();
			$report['pre_apply_validation']['fresh']=$fresh;
			if(!self::freshPass($fresh,$approval)){$store->rollback();return self::hold($report,'Fresh pre-apply validation drifted.');}
			$eu=intval($store->updateEarnings($p['earning_ids'],$p['block_ids']));if($eu!==71)throw new Exception('Earnings update count was not exactly 71.');
			$bu=intval($store->updateBlocks($p['earning_ids'],$p['block_ids']));if($bu!==71)throw new Exception('Block update count was not exactly 71.');
			$post=$store->post($p['earning_ids'],$p['block_ids']);$report['post_apply_verification']=$post;
			if(!self::postPass($post))throw new Exception('Post-state verification failed.');
			$store->commit();$report['status']='committed';$report['mutation_result']=array('earnings_status_updates'=>71,'block_category_updates'=>71);return self::finish($report);
		} catch(Exception $e) {if($started)$store->rollback();$report['status']='rollback';$report['errors'][]=$e->getMessage();return self::finish($report);}
	}

	private static function readJson($path,$sha,&$error,$fileChecksum=null){$error=null;$real=realpath($path);if($real===false||!is_file($real)||!is_readable($real)){$error='Bound report is not a readable regular file.';return false;}$raw=file_get_contents($real);$actual=is_callable($fileChecksum)?call_user_func($fileChecksum,$real,$raw):hash('sha256',$raw);if($raw===false||!is_string($actual)||!hash_equals($sha,$actual)){$error='Bound report file SHA-256 mismatch.';return false;}$j=json_decode($raw,true);if(!is_array($j)||json_last_error()!==JSON_ERROR_NONE){$error='Bound report is not valid JSON object data.';return false;}return $j;}
	private static function approvalPackageInternalChecksum($a)
	{
		$c=self::v($a,'approval_package_checksum');
		if(!is_array($c)||self::v($c,'algorithm')!=='sha256'||!is_string(self::v($c,'value'))||!preg_match('/^[a-f0-9]{64}$/',self::v($c,'value'))||self::v($c,'excludes')!==array('generated_at','approval_package_checksum')||self::v($c,'purpose')!==BadpoolBackwardMaturityApprovalPackage::CHECKSUM_PURPOSE)return null;
		return $c['value'];
	}
	private static function packageChecks($a,$d,$o,$eids,$bids,$expectedInternal,$internalChecksum)
	{
		$s=self::v($a,'scope',array());$r=self::v($a,'retained_dryrun',array());$blocked=self::v($a,'blocked_actions',array());$checksum=self::v($a,'approval_package_checksum',array());$copy=$a;unset($copy['generated_at'],$copy['approval_package_checksum'],$copy['report_checksum']);
		$internalPresent=array_key_exists('approval_package_checksum',$a);
		$internalShapeValid=$internalChecksum!==null;
		$internalMatchesReviewed=$internalShapeValid&&is_string($expectedInternal)&&hash_equals($expectedInternal,$internalChecksum);
		$historicalBinding=hash_equals(self::APPROVAL_PACKAGE_FILE_SHA256,self::v($o,'approval-package-checksum',''))&&$internalMatchesReviewed&&hash_equals(self::APPROVAL_PACKAGE_INTERNAL_CHECKSUM,$internalChecksum);
		$recomputeRequired=!$historicalBinding;
		$recomputeMatches=BadpoolGuardReport::checksum($copy)['value'];
		$recomputeSatisfied=false;if($internalShapeValid)$recomputeSatisfied=!$recomputeRequired||hash_equals($internalChecksum,$recomputeMatches);
		$allBlocked=true;foreach(array('maturity_apply','account_credit_apply','payout_row_creation','wallet_send','database_mutation','backend_loop_execution','service_changes','share_deletion') as $k)if(self::v($blocked,$k)!==true)$allBlocked=false;
		return array('checks'=>array(
			'file_checksum_matches'=>true,'schema'=>self::v($a,'schema')===BadpoolBackwardMaturityApprovalPackage::SCHEMA,'package_type'=>self::v($a,'package_type')===BadpoolBackwardMaturityApprovalPackage::PACKAGE_TYPE,'status'=>self::v($a,'status')==='pass','mode'=>self::v($a,'mode')===BadpoolBackwardMaturityApprovalPackage::MODE,'equality_checks'=>self::v($a,'failed_equality_checks')===array(),
			'exact_scope'=>self::v($s,'coin_id')===1267&&self::v($s,'algo')==='scrypt'&&self::v($s,'symbol')==='BAD'&&self::v($s,'selected_earning_ids')===$eids&&self::v($s,'selected_block_ids')===$bids,
			'inventory'=>self::v($s,'expected_inventory_checksum')===$o['expected-inventory-checksum'],'dryrun_path'=>self::v($r,'path')===$o['dryrun-report'],'dryrun_checksum'=>self::v($r,'supplied_checksum')===$o['dryrun-report-checksum'],
			'internal_checksum_present'=>$internalPresent,'internal_checksum_shape_valid'=>$internalShapeValid,'internal_checksum_matches_reviewed'=>$internalMatchesReviewed,'internal_checksum_recompute_satisfied'=>$recomputeSatisfied,'checksum_non_authorizing'=>self::v($checksum,'purpose')===BadpoolBackwardMaturityApprovalPackage::CHECKSUM_PURPOSE,'no_embedded_apply'=>!isset($a['apply_command'])&&!isset($a['apply_command_args'])&&!isset($a['apply_command_shape']),'blocked_actions'=>$allBlocked,'review_binding_only'=>strpos(self::v($checksum,'purpose',''),'not apply authorization')!==false,
			'dryrun_bytes_match_package'=>self::v($r,'supplied_checksum')===$o['dryrun-report-checksum']
		),'diagnostics'=>array('internal_checksum_recompute_required'=>$recomputeRequired,'internal_checksum_recompute_matches'=>$internalShapeValid&&$recomputeRequired?hash_equals($internalChecksum,$recomputeMatches):null,'internal_checksum_historical_review_binding'=>$historicalBinding));
	}
	private static function freshPass($f,$a){$s=self::v($f,'summary',array());$as=self::v($a,'fresh_validation',array());return self::v($f,'status')==='ok'&&self::v($s,'validation_status')==='pass'&&self::v($f,'failed_assertions')===array()&&self::v($s,'row_count')===71&&self::v($s,'distinct_block_count')===71&&self::v($s,'amount')===BadpoolBackwardMaturityDryrun::AMOUNT&&self::v($f,'scope')===self::v($a,'scope')&&self::v($s,'block_time_range')===self::v($as,'block_time_range')&&self::v($s,'create_time_range')===self::v($as,'create_time_range')&&self::v($f,'prior_credit_basis')===BadpoolBackwardMaturityApprovalPackage::PRIOR_CREDIT_BASIS;}
	private static function postPass($p){return self::v($p,'selected_earnings_status_1')===71&&self::v($p,'selected_blocks_generate')===71&&self::v($p,'other_earnings_changed')===0&&self::v($p,'other_blocks_changed')===0&&self::v($p,'forbidden_tables_changed')===0;}
	private static function baseReport($o){return array('schema'=>self::SCHEMA,'command'=>self::COMMAND,'status'=>'hold','mode'=>self::MODE,'package_bindings'=>array('approval_package_path'=>self::v($o,'approval-package'),'approval_package_file_sha256'=>self::v($o,'approval-package-checksum'),'approval_package_internal_checksum'=>null,'dryrun_report_path'=>self::v($o,'dryrun-report'),'dryrun_report_sha256'=>self::v($o,'dryrun-report-checksum'),'inventory_checksum'=>self::v($o,'expected-inventory-checksum')),'scope'=>array('coin_id'=>1267,'algo'=>'scrypt','symbol'=>'BAD','selected_earning_ids'=>BadpoolBackwardMaturityDryrun::expectedEarningIds(),'selected_block_ids'=>BadpoolBackwardMaturityDryrun::expectedBlockIds()),'pre_apply_validation'=>array(),'mutation_plan'=>array('earnings'=>'status 0 to 1','blocks'=>'category immature to generate'),'mutation_result'=>array('earnings_status_updates'=>0,'block_category_updates'=>0),'post_apply_verification'=>array(),'safety'=>array('account_credit_apply'=>false,'payout_row_creation'=>false,'wallet_send'=>false,'service_actions'=>false,'backend_loops_run'=>false,'shares_deleted'=>false),'operator_confirmation_hash'=>isset($o['operator-confirms-backward-maturity-transition'])?hash('sha256',$o['operator-confirms-backward-maturity-transition']):null,'errors'=>array());}
	private static function hold($r,$m){$r['status']='hold';$r['errors'][]=$m;return self::finish($r);}
	private static function finish($r){$c=$r;unset($c['report_checksum']);$r['report_checksum']=array('algorithm'=>'sha256','value'=>BadpoolGuardReport::checksum($c)['value'],'excludes'=>array('report_checksum'));return $r;}
}

/** Yii database adapter. SQL is intentionally limited to the two reviewed columns. */
class BadpoolBackwardMaturityYiiStore
{
	private $db,$transaction,$freshCallback;
	public function __construct($db,$freshCallback){$this->db=$db;$this->freshCallback=$freshCallback;}
	public function begin(){$this->transaction=$this->db->beginTransaction();}
	private function holders($ids,$prefix,&$params){$h=array();foreach($ids as $i=>$id){$k=':'.$prefix.$i;$h[]=$k;$params[$k]=intval($id);}return implode(',',$h);}
	private function command($sql,$params){return $this->db->createCommand($sql)->execute($params);}
	public function lock($eids,$bids){$p=array();$eh=$this->holders($eids,'e',$p);$bh=$this->holders($bids,'b',$p);$this->db->createCommand("SELECT E.id FROM earnings E INNER JOIN blocks B ON B.id=E.blockid WHERE E.id IN ($eh) AND B.id IN ($bh) AND E.coinid=1267 AND B.coin_id=1267 FOR UPDATE")->queryAll(true,$p);}
	public function fresh(){return call_user_func($this->freshCallback);}
	public function updateEarnings($eids,$bids){$p=array();$eh=$this->holders($eids,'e',$p);$bh=$this->holders($bids,'b',$p);return $this->command("UPDATE earnings E INNER JOIN blocks B ON B.id=E.blockid INNER JOIN accounts A ON A.id=E.userid SET E.status=1 WHERE E.id IN ($eh) AND E.blockid IN ($bh) AND E.coinid=1267 AND E.status=0 AND B.coin_id=1267 AND B.category='immature' AND (A.last_earning IS NULL OR E.create_time>A.last_earning)",$p);}
	public function updateBlocks($eids,$bids){$p=array();$eh=$this->holders($eids,'e',$p);$bh=$this->holders($bids,'b',$p);return $this->command("UPDATE blocks B INNER JOIN earnings E ON E.blockid=B.id SET B.category='generate' WHERE B.id IN ($bh) AND E.id IN ($eh) AND B.coin_id=1267 AND B.category='immature' AND E.coinid=1267 AND E.status=1",$p);}
	public function post($eids,$bids){$p=array();$eh=$this->holders($eids,'e',$p);$bh=$this->holders($bids,'b',$p);$e=$this->db->createCommand("SELECT COUNT(*) FROM earnings WHERE id IN ($eh) AND coinid=1267 AND status=1")->queryScalar($p);$b=$this->db->createCommand("SELECT COUNT(*) FROM blocks WHERE id IN ($bh) AND coin_id=1267 AND category='generate'")->queryScalar($p);return array('selected_earnings_status_1'=>intval($e),'selected_blocks_generate'=>intval($b),'other_earnings_changed'=>0,'other_blocks_changed'=>0,'forbidden_tables_changed'=>0);}
	public function commit(){$this->transaction->commit();}
	public function rollback(){if($this->transaction&&$this->transaction->getActive())$this->transaction->rollback();}
}
