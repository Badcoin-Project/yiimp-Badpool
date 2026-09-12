<?php

$root = dirname(__DIR__);
$commandPath = $root.'/web/yaamp/commands/BadpoolGuardCommand.php';
$failures = array();

function badpool_expect_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) === false) {
		$failures[] = "$label: missing expected text: $needle";
	}
}

function badpool_expect_not_contains($label, $haystack, $needle, &$failures)
{
	if (strpos($haystack, $needle) !== false) {
		$failures[] = "$label: found forbidden text: $needle";
	}
}

$command = is_file($commandPath) ? file_get_contents($commandPath) : '';
if ($command === '') {
	$failures[] = "Unable to read required file: $commandPath";
}

foreach (array(
	'earnings-maturity-transition-dryrun',
	'earnings-maturity-transition-approval-package',
	'earnings-maturity-transition-apply',
	'account-credit-clear-dryrun',
	'account-credit-clear-approval-package',
	'account-credit-clear-apply',
) as $action) {
	badpool_expect_contains("action registered: $action", $command, "'$action'", $failures);
	badpool_expect_contains("help documents: $action", $command, "badpoolguard $action", $failures);
}

badpool_expect_contains('maturity proof status emitted', $command, 'maturity_proof_status', $failures);
badpool_expect_contains('maturity proof reason emitted', $command, 'maturity_proof_reason', $failures);
badpool_expect_contains('maturity proof unavailable blocker emitted', $command, 'conservative_maturity_proof_unavailable', $failures);
badpool_expect_contains('maturity missing mature blocks excluded', $command, 'missing_mature_blocks', $failures);
badpool_expect_contains('maturity below mature blocks excluded', $command, 'confirmations_below_mature_blocks', $failures);
badpool_expect_contains('maturity dryrun selects status0 rows with explicit eligible block categories', $command, "E.status=0 AND B.coin_id=:coin_id AND E.coinid=:coin_id AND B.category IN ('immature','generate')", $failures);
badpool_expect_contains('maturity apply mutates only immature blocks', $command, "WHERE id=:id AND coin_id=:coin_id AND height=:height AND category='immature'", $failures);
badpool_expect_contains('maturity apply mutates only selected earnings by identity scope', $command, "WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=0", $failures);
badpool_expect_not_contains('maturity apply must not require exact amount equality', $command, "WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND amount".'=:amt'." AND status=0", $failures);
badpool_expect_contains('maturity confirmation required', $command, 'operator-confirms-maturity-transition', $failures);
badpool_expect_contains('maturity checksum required', $command, 'projected-block-mutation-checksum', $failures);
badpool_expect_not_contains('maturity block aggregate must not reset per selected earning', $command, '$items[] = $item; $blocks[$blockId] = array(', $failures);
badpool_expect_not_contains('maturity block aggregate must not stage linked earning IDs separately', $command, '$blockEarningIds', $failures);
badpool_expect_contains('maturity block aggregate initializes each block once', $command, 'if (!isset($blocks[$blockId])) $blocks[$blockId] = array(', $failures);
badpool_expect_contains('maturity block aggregate appends every selected earning ID', $command, '$blocks[$blockId][\'linked_earning_ids\'][] = intval($r[\'earning_id\']);', $failures);
badpool_expect_contains('maturity block aggregate uses canonical decimal accumulation', $command, '$blocks[$blockId][\'total_amount\'] = BadpoolStage1Manifest::addAmounts($blocks[$blockId][\'total_amount\'], $r[\'amount\']);', $failures);
badpool_expect_contains('maturity user aggregate uses canonical decimal accumulation', $command, '$totalsByUser[$u][\'amount_total\']=BadpoolStage1Manifest::addAmounts($totalsByUser[$u][\'amount_total\'],$r[\'amount\']);', $failures);

badpool_expect_contains('account credit dryrun selects clearable status1 rows', $command, 'E.status=1 AND E.mature_time<:delay AND E.coinid=:coin_id', $failures);
badpool_expect_contains('account credit uses BackendClearEarnings conversion helper', $command, 'yaamp_convert_amount_user($coin,$r[\'amount\'],$user)', $failures);
badpool_expect_contains('account credit apply guards selected earnings by identity scope', $command, 'WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND status=1 AND mature_time=:mt', $failures);
badpool_expect_not_contains('account credit apply must not require exact amount equality', $command, 'WHERE id=:id AND userid=:uid AND coinid=:cid AND blockid=:bid AND amount'.'=:amt'.' AND status=1 AND mature_time=:mt', $failures);
badpool_expect_contains('account credit apply guards selected accounts', $command, 'WHERE id=:id AND coinid=:coinid', $failures);
badpool_expect_contains('account credit confirmation required', $command, 'operator-confirms-account-credit', $failures);
badpool_expect_contains('account credit checksum required', $command, 'projected-account-credit-checksum', $failures);

badpool_expect_contains('apply scope uses selected earning ids', $command, 'selected-earning-ids', $failures);
badpool_expect_contains('apply scope exact row IDs required', $command, 'selected_row_ids_required', $failures);
badpool_expect_contains('apply scope exact row IDs message', $command, 'Apply scope must be exact selected row IDs.', $failures);
badpool_expect_contains('approval filtered to selected row IDs', $command, 'filterRowsByIds', $failures);
badpool_expect_contains('unselected drift excluded from authorization', $command, 'unselected drift is informational only and not part of authorization', $failures);
badpool_expect_contains('unselected candidate delta reported', $command, 'unselected_candidate_count_delta', $failures);
badpool_expect_contains('apply parser refuses unknown options', $command, 'Unknown option refused: --', $failures);
badpool_expect_contains('apply requires json format', $command, 'json_format_required', $failures);
badpool_expect_contains('apply requires checksums', $command, 'missing_required_checksum', $failures);
badpool_expect_contains('apply detects checksum mismatch', $command, 'checksum_mismatch', $failures);
badpool_expect_contains('apply transaction wrapped', $command, 'app()->db->beginTransaction()', $failures);
badpool_expect_contains('apply rolls back on mutation failure', $command, '$tx->rollback()', $failures);
badpool_expect_contains('apply refuses all-coin scope', $command, 'refuses all-coin scope', $failures);
badpool_expect_contains('maturity apply no account credit', $command, "'no_account_credit'=>true", $failures);
badpool_expect_contains('apply reports no wallet sends', $command, "'wallet_sends'=>false", $failures);
badpool_expect_contains('apply reports no backend loops', $command, "'backend_loops_run'=>false", $failures);
badpool_expect_contains('apply reports no share deletion', $command, "'shares_deleted'=>false", $failures);
badpool_expect_contains('apply reports informational unselected drift', $command, 'unselected drift is informational only and not part of authorization', $failures);

$applyParserStart = strpos($command, 'private function parseGuardedApplyOptions');
$applyParserEnd = strpos($command, 'private function guardedApplyBaseReport', $applyParserStart);
$applyParser = ($applyParserStart === false || $applyParserEnd === false) ? '' : substr($command, $applyParserStart, $applyParserEnd - $applyParserStart);
badpool_expect_not_contains('new apply parser must not accept limit', $applyParser, "'limit'", $failures);

if (!empty($failures)) {
	echo "Badpool account-credit guard harness FAILED\n";
	foreach ($failures as $failure) {
		echo " - $failure\n";
	}
	exit(1);
}

echo "Badpool account-credit guard harness passed\n";
