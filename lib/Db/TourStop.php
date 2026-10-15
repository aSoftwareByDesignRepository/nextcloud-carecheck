<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getTourId()
 * @method void setTourId(int|null $tourId)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getOrderHint()
 * @method void setOrderHint(int|null $orderHint)
 * @method int|null getWinStart()
 * @method void setWinStart(int|null $winStart)
 * @method int|null getWinEnd()
 * @method void setWinEnd(int|null $winEnd)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getExecutorRef()
 * @method void setExecutorRef(string|null $executorRef)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method string|null getSkipReasonCode()
 * @method void setSkipReasonCode(string|null $skipReasonCode)
 * @method string|null getTimeKind()
 * @method void setTimeKind(string|null $timeKind)
 * @method int|null getToleranceMin()
 * @method void setToleranceMin(int|null $toleranceMin)
 * @method string|null getLocText()
 * @method void setLocText(string|null $locText)
 */
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