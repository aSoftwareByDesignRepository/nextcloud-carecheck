<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

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