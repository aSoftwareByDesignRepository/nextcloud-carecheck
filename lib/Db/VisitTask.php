<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class VisitTask extends Entity
{
	protected $visitId;
	protected $taskCode;
	protected $status;
	protected $reasonCode;
	protected $valueEnc;
	protected $commentEnc;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('visitId', 'integer');
		$this->addType('taskCode', 'string');
		$this->addType('status', 'string');
		$this->addType('reasonCode', 'string');
		$this->addType('valueEnc', 'string');
		$this->addType('commentEnc', 'string');
	}
}