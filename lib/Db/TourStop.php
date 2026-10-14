<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class TourStop extends Entity
{
	protected $tourId;
	protected $clientId;
	protected $orderHint;
	protected $winStart;
	protected $winEnd;
	protected $kind;
	protected $executorRef;
	protected $status;
	protected $skipReasonCode;
	protected $timeKind;
	protected $toleranceMin;
	protected $locText;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('tourId', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('orderHint', 'integer');
		$this->addType('winStart', 'integer');
		$this->addType('winEnd', 'integer');
		$this->addType('kind', 'string');
		$this->addType('executorRef', 'string');
		$this->addType('status', 'string');
		$this->addType('skipReasonCode', 'string');
		$this->addType('locText', 'string');
		$this->addType('toleranceMin', 'integer');
		$this->addType('timeKind', 'string');
	}
}