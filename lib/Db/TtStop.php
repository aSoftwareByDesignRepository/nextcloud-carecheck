<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class TtStop extends Entity
{
	protected $templateId;
	protected $clientId;
	protected $winStart;
	protected $winEnd;
	protected $orderHint;
	protected $executorRef;
	protected $kind;
	protected $timeKind;
	protected $toleranceMin;
	protected $locText;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('templateId', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('winStart', 'integer');
		$this->addType('winEnd', 'integer');
		$this->addType('orderHint', 'integer');
		$this->addType('executorRef', 'string');
		$this->addType('kind', 'string');
		$this->addType('timeKind', 'string');
		$this->addType('toleranceMin', 'integer');
		$this->addType('locText', 'string');
	}
}