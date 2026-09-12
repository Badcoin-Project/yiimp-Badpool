<?php
class CConsoleCommand {}
function arraySafeVal($a,$k,$d=null){return is_array($a)&&array_key_exists($k,$a)?$a[$k]:$d;}
if(!defined('YAAMP_ALLOW_EXCHANGE'))define('YAAMP_ALLOW_EXCHANGE',false);
if(!defined('YAAMP_PAYMENTS_FREQ'))define('YAAMP_PAYMENTS_FREQ',3600);

class GenerateCommand {
	private $db; private $sql;
	public function __construct($db,$sql){$this->db=$db;$this->sql=$sql;}
	public function queryRow($fetch,$p){
		$id=intval($p[':id']);
		if(strpos($this->sql,'SELECT id,userid')===0){if(!isset($this->db->earnings[$id]))return false;return array('id'=>$id,'amount'=>'1.000000000000','mature_time'=>0)+$this->db->earnings[$id];}
		if(!isset($this->db->blocks[$id]))return false;
		$b=$this->db->blocks[$id];
		if(strpos($this->sql,'SELECT category FROM')===0)return ($b['coin_id']==$p[':coin_id']&&$b['height']==$p[':height'])?array('category'=>$b['category']):false;
		return $b+array('id'=>$id,'mature_blocks'=>100);
	}
	public function execute($p){
		if(strpos($this->sql,'UPDATE blocks')===0){$id=intval($p[':id']);$b=&$this->db->blocks[$id];if(!$b||$b['coin_id']!=$p[':coin_id']||$b['height']!=$p[':height']||$b['category']!=='immature')return 0;$b['category']='generate';$this->db->blockUpdates++;return 1;}
		$id=intval($p[':id']);$e=&$this->db->earnings[$id];if(!$e||$e['userid']!=$p[':uid']||$e['coinid']!=$p[':cid']||$e['blockid']!=$p[':bid']||$e['status']!==0)return 0;$e['status']=1;$e['mature_time']=$p[':mt'];return 1;
	}
}
class GenerateDb {public $blocks=array(),$earnings=array(),$blockUpdates=0;public function createCommand($sql){return new GenerateCommand($this,$sql);}}
class GenerateApp {public $db;public function __construct(){$this->db=new GenerateDb;}}
$generateApp=new GenerateApp; function app(){global $generateApp;return $generateApp;}
require_once dirname(__DIR__).'/web/yaamp/commands/BadpoolGuardCommand.php';
function gm($name){$m=new ReflectionMethod('BadpoolGuardCommand',$name);$m->setAccessible(true);return $m;}
function ok($condition,$message){global $failures;if(!$condition)$failures[]=$message;}
function blockItem($id,$category,$model,$confirmations=120){return array('block_id'=>$id,'height'=>500000+$id,'coin_id'=>1267,'from_category'=>$category,'to_category'=>'generate','transition_model'=>$model,'confirmations'=>$confirmations,'mature_blocks'=>100);}
function earningItem($id,$block){return array('earning_id'=>$id,'userid'=>7,'coinid'=>1267,'linked_block_id'=>$block,'amount'=>'1.000000000000','current_earning_status'=>0,'current_earning_mature_time'=>0,'transition_model'=>$block===1?'immature_to_generate':'already_generate_earnings_only');}

$failures=array();$command=new BadpoolGuardCommand;$proof=gm('maturityProof');
foreach(array('immature','generate') as $category)ok($proof->invoke($command,array('block_category'=>$category,'confirmations'=>100,'mature_blocks'=>100))['status']==='pass',$category.' mature proof rejected');
ok($proof->invoke($command,array('block_category'=>'generate','confirmations'=>99,'mature_blocks'=>100))['reason']==='confirmations_below_mature_blocks','below-threshold generate accepted');
ok($proof->invoke($command,array('block_category'=>'generate','confirmations'=>null,'mature_blocks'=>100))['reason']==='missing_confirmations','generate without confirmations accepted');
foreach(array('new',null,'orphan') as $category)ok($proof->invoke($command,array('block_category'=>$category,'confirmations'=>120,'mature_blocks'=>100))['status']==='blocked','unsupported category accepted');

$blocks=array(blockItem(1,'immature','immature_to_generate'),blockItem(2,'generate','already_generate_earnings_only'));
$items=array(earningItem(11,1),earningItem(12,2));
$scopeA=BadpoolGuardReport::checksum(array('earnings'=>$items,'blocks'=>$blocks));$drift=$blocks;$drift[1]['transition_model']='immature_to_generate';$scopeB=BadpoolGuardReport::checksum(array('earnings'=>$items,'blocks'=>$drift));ok($scopeA['value']!==$scopeB['value'],'transition model is not checksum-bound');
$generateApp->db->blocks=array(1=>array('height'=>500001,'coin_id'=>1267,'category'=>'immature','confirmations'=>120),2=>array('height'=>500002,'coin_id'=>1267,'category'=>'generate','confirmations'=>120));
$generateApp->db->earnings=array(11=>array('userid'=>7,'coinid'=>1267,'blockid'=>1,'status'=>0),12=>array('userid'=>7,'coinid'=>1267,'blockid'=>2,'status'=>0));
$approval=array('items'=>array('linked_blocks'=>$blocks,'selected_earnings'=>$items));$applied=gm('applyMaturityTransitionRows')->invoke($command,$approval);
ok($generateApp->db->blocks[1]['category']==='generate'&&$generateApp->db->blocks[2]['category']==='generate','successful apply did not reconcile generate post-state');
ok($generateApp->db->blockUpdates===1,'already-generate model manufactured a block affected-row count');
ok($applied['updated_block_count']===1&&$applied['reconciled_generate_block_count']===1,'exact block update/reconciliation counts incorrect');
ok($generateApp->db->earnings[11]['status']===1&&$generateApp->db->earnings[12]['status']===1&&$generateApp->db->earnings[12]['mature_time']>0,'earnings maturity mutation failed');
ok($applied['no_account_credit']===true&&$applied['no_payout_rows']===true&&$applied['wallet_sends']===false&&$applied['backend_loops_run']===false&&$applied['shares_deleted']===false,'hard safety boundary changed');

$generateApp->db->blocks[2]['confirmations']=99;$generateApp->db->earnings[12]['status']=0;
try{gm('applyMaturityTransitionRows')->invoke($command,array('items'=>array('linked_blocks'=>array($blocks[1]),'selected_earnings'=>array($items[1]))));ok(false,'apply accepted failed fresh maturity proof');}catch(Exception $e){ok($generateApp->db->earnings[12]['status']===0,'earning changed after block reconciliation failure');}

if($failures){echo "Badpool already-generate maturity harness FAILED\n";foreach($failures as $failure)echo " - $failure\n";exit(1);}echo "Badpool already-generate maturity harness passed\n";
