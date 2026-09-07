<?php

require_once(dirname(__FILE__).'/BadpoolGuardReport.php');

class BadpoolGuardContext
{
	const SCHEMA = 'badpool.guardrail.preview.v1';
	const MODE = 'read-only-preview';

	private $command;
	private $format = 'json';
	private $scope = array(
		'all_coins_preview' => false,
		'coin_id' => null,
		'coin' => null,
	);
	private $warnings = array();
	private $errors = array();
	private $options = array();

	private $allowedOptions = array(
		'coin-id',
		'all-coins-preview',
		'format',
		'limit',
		'selected-payout-ids',
		'algo',
		'mode',
		'scope',
		'only',
		'batch-size',
		'stop-before-wallet-send',
		'resume-batch-id',
		'batch-id',
		'payment-delay-override-package',
		'payment-delay-override-package-checksum',
		'operator-confirms-payment-delay-override',
		'selector',
		'first-block-id',
		'last-block-id',
		'max-rows',
		'selected-block-ids',
		'approval-package',
		'approval-package-checksum',
		'selected-scope-checksum',
		'source-live-candidate-checksum',
		'attribution-checksum',
		'projected-earnings-checksum',
		'operator-confirms-live-capture-earnings',
		'candidate-inventory-checksum',
		'block-inventory-checksum',
		'rpc-result-checksum',
		'operator-confirms-live-capture-block-enrichment',
	);

	private $dangerousOptions = array(
		'execute',
		'apply',
		'send',
		'delete',
		'retry',
		'wallet',
		'wallet-read',
		'wallet-send',
		'mutate',
		'write',
		'start-service',
		'run-backend',
		'share-delete',
		'payout-retry',
		'payout-delete',
		'create-payouts',
	);

	public static function fromArgs($command, $args)
	{
		$context = new self($command);
		$context->parse($args);
		return $context;
	}

	public static function fromBackwardMaturityApplyArgs($command, $args)
	{
		$context = new self($command);
		$context->preloadFormat($args);
		$parsed = BadpoolBackwardMaturityApply::parseOptions($args);
		if (!is_array($parsed) || !isset($parsed['status']) || $parsed['status'] !== 'pass') {
			$message = is_array($parsed) && isset($parsed['message']) ? $parsed['message'] : 'Backward maturity apply options are invalid.';
			$context->addError($message);
			return $context;
		}

		$options = $parsed['options'];
		$context->parse(array('--coin-id='.$options['coin-id'], '--format='.$options['format']));
		if ($context->isValid()) {
			$context->options = $options;
		}
		return $context;
	}

	public static function fromRetainedReportArgs($command, $args, $scope)
	{
		$context = new self($command);
		$context->preloadFormat($args);
		$context->scope = is_array($scope) ? $scope : $context->scope;
		return $context;
	}

	public function __construct($command)
	{
		$this->command = $command;
	}

	public function parse($args)
	{
		$this->preloadFormat($args);
		$options = $this->parseOptions($args);
		if (!$this->isValid()) {
			return;
		}

		if (isset($options['format'])) {
			$this->format = strtolower($options['format']);
			if (!in_array($this->format, array('json', 'text'), true)) {
				$this->addError('Unsupported --format value. Use --format=json or --format=text.');
				return;
			}
		}

		if ($this->command === 'completed-payout-batch-closeout') {
			if (!isset($options['batch-id'])) {
				$this->addError('completed-payout-batch-closeout requires --batch-id=<id>.');
				return;
			}
			if (!is_string($options['batch-id']) || !preg_match('/^[A-Za-z0-9._-]+$/', $options['batch-id'])) {
				$this->addError('Invalid --batch-id. Expected a nonempty batch identifier.');
				return;
			}
		}

		$this->options = $options;
		$this->resolveScope($options);
	}

	public function isValid()
	{
		return empty($this->errors);
	}

	public function getFormat()
	{
		return $this->format;
	}

	public function getScope()
	{
		return $this->scope;
	}

	public function getOption($name, $default=null)
	{
		return array_key_exists($name, $this->options) ? $this->options[$name] : $default;
	}

	public function getCoin()
	{
		return $this->scope['coin'];
	}

	public function isAllCoinsPreview()
	{
		return $this->scope['all_coins_preview'];
	}

	public function addWarning($message)
	{
		$this->warnings[] = $message;
	}

	public function addError($message)
	{
		$this->errors[] = $message;
	}

	public function baseReport($status='ok')
	{
		return array(
			'schema' => self::SCHEMA,
			'generated_at' => gmdate('c'),
			'command' => $this->command,
			'mode' => self::MODE,
			'status' => $status,
			'read_only' => true,
			'wallet_reads' => false,
			'wallet_sends' => false,
			'db_mutations' => false,
			'share_deletion' => false,
			'payout_retry_delete' => false,
			'service_actions' => false,
			'scope' => $this->scope,
			'summary' => array(),
			'items' => array(),
			'warnings' => $this->warnings,
			'errors' => $this->errors,
			'blocked_actions' => array(
				'wallet_reads',
				'wallet_sends',
				'database_mutations',
				'share_deletion',
				'payout_row_creation',
				'withdraw_creation',
				'account_balance_mutation',
				'earnings_mutation',
				'block_mutation',
				'coin_mutation',
				'payout_retry_delete',
				'service_or_cron_changes',
			),
		);
	}

	public function refusalReport()
	{
		return $this->baseReport('refused');
	}

	public function finalizeReport($report)
	{
		$report['warnings'] = $this->warnings;
		$report['errors'] = $this->errors;
		return BadpoolGuardReport::finalize($report);
	}

	public function emit($report)
	{
		BadpoolGuardReport::render($report, $this->format);
	}

	public function selectAll($sql, $params=array())
	{
		$this->assertSelectOnly($sql);
		$command = app()->db->createCommand($sql);
		foreach ($params as $name => $value) {
			$command->bindValue($name, $value);
		}
		return $command->queryAll();
	}

	public function selectRow($sql, $params=array())
	{
		$this->assertSelectOnly($sql);
		$command = app()->db->createCommand($sql);
		foreach ($params as $name => $value) {
			$command->bindValue($name, $value);
		}
		return $command->queryRow();
	}

	public function tableExists($table)
	{
		return app()->db->schema->getTable($table, true) !== null;
	}

	public function columnExists($table, $column)
	{
		$schema = app()->db->schema->getTable($table, true);
		return $schema !== null && isset($schema->columns[$column]);
	}

	public function selectColumns($table, $columns)
	{
		$selected = array();
		foreach ($columns as $column) {
			if ($this->columnExists($table, $column)) {
				$selected[] = $this->qcol($column);
			}
		}

		if (empty($selected)) {
			return '1 AS no_safe_columns_available';
		}

		return implode(', ', $selected);
	}

	public function coinWhere($tableOrAlias, $column)
	{
		if ($this->scope['all_coins_preview']) {
			return array('sql' => '1=1', 'params' => array(), 'filter' => 'all_coins_preview');
		}

		$prefix = preg_match('/^[A-Z]$/', $tableOrAlias) ? $tableOrAlias.'.' : '';
		return array(
			'sql' => $prefix.$this->qcol($column).'=:coin_id',
			'params' => array(':coin_id' => $this->scope['coin_id']),
			'filter' => 'coin_id',
		);
	}

	public function qtable($table)
	{
		return '`'.str_replace('`', '``', $table).'`';
	}

	public function qcol($column)
	{
		return '`'.str_replace('`', '``', $column).'`';
	}

	public function missingTable($table)
	{
		$this->addWarning("Table '$table' is not available in this database/schema.");
		return array('error' => "missing table: $table");
	}

	private function parseOptions($args)
	{
		$options = array();
		$batchOptions = array('mode', 'scope', 'only', 'batch-size', 'stop-before-wallet-send', 'resume-batch-id', 'payment-delay-override-package', 'payment-delay-override-package-checksum', 'operator-confirms-payment-delay-override');
		$liveBridgeOptions = array('approval-package','source-live-candidate-checksum','attribution-checksum','operator-confirms-live-capture-earnings','candidate-inventory-checksum','block-inventory-checksum','rpc-result-checksum','operator-confirms-live-capture-block-enrichment');
		foreach ($args as $arg) {

			if (!preg_match('/^--([^=]+)(=(.*))?$/', $arg, $matches)) {
				$this->addError("Unknown argument refused: $arg");
				return array();
			}

			$name = $matches[1];
			$value = isset($matches[3]) ? $matches[3] : true;
			$lower = strtolower($name);

			if (in_array($lower, $this->dangerousOptions, true)) {
				$this->addError("Dangerous option refused in read-only preview command: --$name");
				return array();
			}
			if (!in_array($lower, $this->allowedOptions, true)) {
				$this->addError("Unknown option refused: --$name");
				return array();
			}
			if (in_array($lower, $liveBridgeOptions, true) && strpos($this->command, 'live-capture-earnings-') !== 0 && strpos($this->command, 'live-capture-block-enrichment-') !== 0) {
				$this->addError("Option --$name is only available for live capture earnings bridge commands.");
				return array();
			}
			if ($this->command === 'completed-payout-batch-closeout' && !in_array($lower, array('batch-id', 'format'), true)) {
				$this->addError("Option --$name is not available for completed-payout-batch-closeout.");
				return array();
			}
			if (in_array($lower, $batchOptions, true) && !in_array($this->command, array('batch-run-preview','batch-run'), true)) {
				$this->addError("Option --$name is only available for payment batch commands.");
				return array();
			}
			if ($lower === 'batch-id' && $this->command !== 'completed-payout-batch-closeout') {
				$this->addError("Option --$name is only available for completed-payout-batch-closeout.");
				return array();
			}
			if (isset($options[$lower])) {
				$this->addError("Duplicate option refused: --$name");
				return array();
			}
			$options[$lower] = $value;
		}

		return $options;
	}

	private function preloadFormat($args)
	{
		foreach ($args as $arg) {
			if (preg_match('/^--format=(.*)$/', $arg, $matches)) {
				$format = strtolower($matches[1]);
				if (in_array($format, array('json', 'text'), true)) {
					$this->format = $format;
				}
			}
		}
	}

	private function resolveScope($options)
	{
		$hasCoinId = isset($options['coin-id']);
		$allCoins = isset($options['all-coins-preview']);

		if ($hasCoinId && $allCoins) {
			$this->addError('Use either --coin-id=<id> or --all-coins-preview, not both.');
			return;
		}
		if (!$hasCoinId && !$allCoins) {
			if ($this->command === 'status-runner' || $this->command === 'batch-run-preview' || $this->command === 'batch-run' || $this->command === 'completed-payout-batch-closeout') {
				$allCoins = true;
			} else {
				$this->addError('Refusing implicit all-coin preview. Pass --coin-id=<id> or --all-coins-preview.');
				return;
			}
		}

		if ($allCoins) {
			$allCoinsValue = array_key_exists('all-coins-preview', $options) ? $options['all-coins-preview'] : true;
			if ($allCoinsValue !== true && !in_array(strtolower($allCoinsValue), array('1', 'true', 'yes'), true)) {
				$this->addError('Use --all-coins-preview without a value, or with true/1/yes.');
				return;
			}

			$this->scope = array(
				'all_coins_preview' => true,
				'coin_id' => null,
				'coin' => null,
			);
			return;
		}

		$coinId = $options['coin-id'];
		if (!preg_match('/^[0-9]+$/', (string)$coinId) || intval($coinId) <= 0) {
			$this->addError('Invalid --coin-id. Expected a positive integer.');
			return;
		}
		if (!$this->tableExists('coins') || !$this->columnExists('coins', 'id')) {
			$this->addError('Cannot resolve --coin-id because coins.id is not available.');
			return;
		}

		$coin = $this->selectRow("SELECT ".$this->selectColumns('coins', array(
			'id', 'symbol', 'symbol2', 'name', 'algo', 'enable', 'installed', 'visible', 'auto_ready',
			'rpcencoding', 'txfee', 'payout_min', 'balance', 'available', 'cleared', 'immature',
			'mature_blocks', 'block_height', 'target_height', 'price', 'price2',
		))." FROM coins WHERE id=:coin_id", array(':coin_id' => intval($coinId)));

		if (!$coin) {
			$this->addError("No coin record found for --coin-id=$coinId.");
			return;
		}

		$this->scope = array(
			'all_coins_preview' => false,
			'coin_id' => intval($coinId),
			'coin' => $coin,
		);
	}

	private function assertSelectOnly($sql)
	{
		$trimmed = ltrim($sql);
		if (stripos($trimmed, 'SELECT ') !== 0) {
			throw new CException('Badpool guard refused non-SELECT SQL.');
		}
	}
}
