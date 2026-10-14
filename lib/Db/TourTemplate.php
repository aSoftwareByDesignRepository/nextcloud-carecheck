<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class TourTemplate extends Entity
{
	protected $name;
	protected $weekdaySet;
	protected $leadRef;
	protected $status;
	protected $revision;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('name', 'string');
		$this->addType('weekdaySet', 'string');
		$this->addType('leadRef', 'string');
		$this->addType('status', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}
}