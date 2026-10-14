<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Share extends Entity
{
	protected $clientId;
	protected $tokenHash;
	protected $label;
	protected $expiresAt;
	protected $revoked;
	protected $createdBy;
	protected $createdAt;
	protected $lastAccessAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('tokenHash', 'string');
		$this->addType('label', 'string');
		$this->addType('expiresAt', 'datetime');
		$this->addType('revoked', 'integer');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('lastAccessAt', 'datetime');
	}
}
