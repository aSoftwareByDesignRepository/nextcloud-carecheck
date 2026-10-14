<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class Member extends Entity
{
	protected $displayName;
	protected $qualification;
	protected $active;
	protected $teamId;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('displayName', 'string');
		$this->addType('qualification', 'string');
		$this->addType('active', 'integer');
		$this->addType('teamId', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}