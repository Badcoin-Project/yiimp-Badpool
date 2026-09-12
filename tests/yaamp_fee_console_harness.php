<?php
define('YAAMP_FEES_MINING', 0.5);
$configFixedPoolFees = array();
class FeeHarnessCache {
	public $values = array();
	public function get($key) { return isset($this->values[$key]) ? $this->values[$key] : false; }
	public function set($key, $value) { $this->values[$key] = $value; }
}
$feeHarnessCache = new FeeHarnessCache;
function cache() { global $feeHarnessCache; return $feeHarnessCache; }
function controller() { throw new RuntimeException('console fee calculation must not request controller()->memcache'); }
require_once dirname(__FILE__).'/../web/yaamp/core/functions/yaamp.php';
if (yaamp_fee('scrypt') !== 0.5 || take_yaamp_fee(100, 'scrypt') !== 99.5)
	throw new RuntimeException('FAIL console fee calculation');
echo "PASS yaamp fee console harness\n";
