<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class ClientDoc extends Entity
{
	protected $clientId;
	protected $label;
	protected $fileId;
	protected $expiresOn;
	protected $remindedOn;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('label', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('expiresOn', 'date');
		$this->addType('remindedOn', 'date');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
