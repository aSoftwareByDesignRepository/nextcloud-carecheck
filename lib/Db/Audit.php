<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getAction()
 * @method void setAction(string|null $action)
 * @method string|null getDetail()
 * @method void setDetail(string|null $detail)
 * @method \DateTime getTs()
 * @method void setTs(\DateTime|null $ts)
 */
class Audit extends Entity
{
	protected $actorRef;
	protected $clientId;
	protected $action;
	protected $detail;
	protected $ts;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('clientId', 'integer');
		$this->addType('action', 'string');
		$this->addType('detail', 'string');
		$this->addType('ts', 'datetime');
	}
}