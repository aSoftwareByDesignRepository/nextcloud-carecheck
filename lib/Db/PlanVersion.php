<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class PlanVersion extends Entity
{
	protected $clientId;
	protected $snapshotEnc;
	protected $status;
	protected $publishedBy;
	protected $publishedAt;
	protected $revision;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('snapshotEnc', 'string');
		$this->addType('status', 'string');
		$this->addType('publishedBy', 'string');
		$this->addType('publishedAt', 'datetime');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime');
	}
}