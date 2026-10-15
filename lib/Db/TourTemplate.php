<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getName()
 * @method void setName(string|null $name)
 * @method string|null getWeekdaySet()
 * @method void setWeekdaySet(string|null $weekdaySet)
 * @method string|null getLeadRef()
 * @method void setLeadRef(string|null $leadRef)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method int|null getRevision()
 * @method void setRevision(int|null $revision)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 * @method \DateTime getUpdatedAt()
 * @method void setUpdatedAt(\DateTime|null $updatedAt)
 */
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