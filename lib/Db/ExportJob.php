<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class ExportJob extends Entity
{
	protected $kind;
	protected $scopeJson;
	protected $actorRef;
	protected $hash;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('kind', 'string');
		$this->addType('scopeJson', 'string');
		$this->addType('actorRef', 'string');
		$this->addType('hash', 'string');
		$this->addType('createdAt', 'datetime');
	}
}