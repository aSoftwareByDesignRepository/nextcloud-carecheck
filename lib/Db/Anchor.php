<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Anchor extends Entity
{
	protected $date;
	protected $headsDigest;
	protected $anchorToken;
	protected $status;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('date', 'datetime');
		$this->addType('headsDigest', 'string');
		$this->addType('anchorToken', 'string');
		$this->addType('status', 'string');
		$this->addType('createdAt', 'datetime');
	}
}