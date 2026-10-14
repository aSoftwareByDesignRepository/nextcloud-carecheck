<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Tour extends Entity
{
	protected $templateId;
	protected $date;
	protected $executorRef;
	protected $status;
	protected $revision;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('templateId', 'integer');
		$this->addType('date', 'datetime');
		$this->addType('executorRef', 'string');
		$this->addType('status', 'string');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}