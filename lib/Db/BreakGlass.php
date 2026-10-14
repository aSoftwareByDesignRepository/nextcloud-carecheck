<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class BreakGlass extends Entity
{
	protected $actorRef;
	protected $clientId;
	protected $reasonCode;
	protected $grantedAt;
	protected $expiresAt;
	protected $state;
	protected $qualMismatch;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('clientId', 'integer');
		$this->addType('reasonCode', 'string');
		$this->addType('grantedAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('state', 'string');
		$this->addType('qualMismatch', 'integer');
	}
}