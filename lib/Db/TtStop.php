<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getTemplateId()
 * @method void setTemplateId(int|null $templateId)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getWinStart()
 * @method void setWinStart(int|null $winStart)
 * @method int|null getWinEnd()
 * @method void setWinEnd(int|null $winEnd)
 * @method int|null getOrderHint()
 * @method void setOrderHint(int|null $orderHint)
 * @method string|null getExecutorRef()
 * @method void setExecutorRef(string|null $executorRef)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getTimeKind()
 * @method void setTimeKind(string|null $timeKind)
 * @method int|null getToleranceMin()
 * @method void setToleranceMin(int|null $toleranceMin)
 * @method string|null getLocText()
 * @method void setLocText(string|null $locText)
 */
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