<?php
require_once dirname(__FILE__).'/../web/yaamp/core/backend/BadpoolGuardContext.php';

$failures=array();
function optionScopeExpect($condition,$message){global $failures;if(!$condition)$failures[]=$message;}

function parseLiveOptions($command,$args)
{
	$context=new BadpoolGuardContext($command);
	$method=new ReflectionMethod('BadpoolGuardContext','parseOptions');
	$method->setAccessible(true);
	$result=$method->invoke($context,$args);
	return array($context,$result);
}

$sha=str_repeat('a',64);
$enrichmentOnly=array(
	'--candidate-inventory-checksum='.$sha,
	'--block-inventory-checksum='.$sha,
	'--rpc-result-checksum='.$sha,
	'--operator-confirms-live-capture-block-enrichment=confirmed',
);
$earningsOnly=array(
	'--source-live-candidate-checksum='.$sha,
	'--attribution-checksum='.$sha,
	'--projected-earnings-checksum='.$sha,
	'--operator-confirms-live-capture-earnings=confirmed',
);

foreach(array('live-capture-earnings-dryrun','live-capture-earnings-approval-package') as $command) {
	foreach($enrichmentOnly as $option) {
		list($context)=parseLiveOptions($command,array($option));
		optionScopeExpect(!$context->isValid(),$command.' accepted enrichment-only option '.$option);
	}
}
foreach(array('live-capture-block-enrichment-dryrun','live-capture-block-enrichment-approval-package') as $command) {
	foreach($earningsOnly as $option) {
		list($context)=parseLiveOptions($command,array($option));
		optionScopeExpect(!$context->isValid(),$command.' accepted earnings-only option '.$option);
	}
}

list($earningsContext,$earningsParsed)=parseLiveOptions('live-capture-earnings-approval-package',array_merge(array('--approval-package=/tmp/earnings.json'),$earningsOnly));
optionScopeExpect($earningsContext->isValid()&&count($earningsParsed)===5,'earnings command rejected its valid option family');
list($enrichmentContext,$enrichmentParsed)=parseLiveOptions('live-capture-block-enrichment-approval-package',array_merge(array('--approval-package=/tmp/enrichment.json'),$enrichmentOnly));
optionScopeExpect($enrichmentContext->isValid()&&count($enrichmentParsed)===5,'enrichment command rejected its valid option family');

list($malformedContext)=parseLiveOptions('live-capture-earnings-dryrun',array('--attribution-checksum'));
optionScopeExpect(!$malformedContext->isValid(),'malformed option was accepted');
list($duplicateContext)=parseLiveOptions('live-capture-block-enrichment-dryrun',array('--rpc-result-checksum='.$sha,'--rpc-result-checksum='.$sha));
optionScopeExpect(!$duplicateContext->isValid(),'duplicate option was accepted');
list($unknownContext)=parseLiveOptions('live-capture-earnings-dryrun',array('--not-a-live-option=value'));
optionScopeExpect(!$unknownContext->isValid(),'unknown option was accepted');

// Calling parseOptions directly proves these parser-only cases cannot resolve a
// database scope or reach daemon, wallet, or backend callback code.
optionScopeExpect(!function_exists('app'),'parser harness unexpectedly bootstrapped a database application');
optionScopeExpect(!class_exists('WalletRPC',false),'parser harness unexpectedly loaded wallet RPC');
foreach(array('BackendBlockNew','BackendBlocksUpdate','BackendBlockFind1','BackendBlockFind2') as $callback)
	optionScopeExpect(!function_exists($callback),'parser harness unexpectedly loaded '.$callback);

if($failures){echo "Live capture option scope harness FAILED\n";foreach($failures as $failure)echo " - $failure\n";exit(1);}
echo "Live capture option scope harness passed\n";
