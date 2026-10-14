<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Notice extends Entity
{
	protected $clientId;
	protected $actorRef;
	protected $kind;
	protected $noteEnc;
	protected $idemKey;
	protected $priority;
	protected $state;
	protected $ackedBy;
	protected $ackedAt;
	protected $createdAt;
	protected $escalated;
	protected $resolution;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('kind', 'string');
		$this->addType('noteEnc', 'string');
		$this->addType('idemKey', 'string');
		$this->addType('priority', 'string');
		$this->addType('state', 'string');
		$this->addType('ackedBy', 'string');
		$this->addType('ackedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('escalated', 'integer');
		$this->addType('resolution', 'string');
	}
}