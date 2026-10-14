<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

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
