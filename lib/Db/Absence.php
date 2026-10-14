<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Absence extends Entity
{
	protected $actorRef;
	protected $fromDate;
	protected $untilDate;
	protected $kind;
	protected $source;
	protected $markedBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('actorRef', 'string');
		$this->addType('fromDate', 'datetime');
		$this->addType('untilDate', 'datetime');
		$this->addType('kind', 'string');
		$this->addType('source', 'string');
		$this->addType('markedBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}