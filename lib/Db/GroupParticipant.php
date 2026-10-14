<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class GroupParticipant extends Entity
{
	protected $sessionId;
	protected $clientId;
	protected $attended;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('sessionId', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('attended', 'integer');
	}
}
