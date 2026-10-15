<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method string|null getTitle()
 * @method void setTitle(string|null $title)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method string|null getIdemKey()
 * @method void setIdemKey(string|null $idemKey)
 * @method \DateTime|null getStartedAt()
 * @method void setStartedAt(\DateTime|null $startedAt)
 * @method \DateTime|null getClosedAt()
 * @method void setClosedAt(\DateTime|null $closedAt)
 */
class GroupSession extends Entity
{
	protected $actorRef;
	protected $title;
	protected $status;
	protected $idemKey;
	protected $startedAt;
	protected $closedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('title', 'string');
		$this->addType('status', 'string');
		$this->addType('idemKey', 'string');
		$this->addType('startedAt', 'datetime');
		$this->addType('closedAt', 'datetime');
	}
}
