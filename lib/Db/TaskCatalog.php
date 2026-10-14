<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

class TaskCatalog extends Entity
{
	protected $code;
	protected $label;
	protected $icon;
	protected $kind;
	protected $requiresQual;
	protected $active;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('code', 'string');
		$this->addType('label', 'string');
		$this->addType('icon', 'string');
		$this->addType('kind', 'string');
		$this->addType('requiresQual', 'string');
		$this->addType('active', 'integer');
	}
}