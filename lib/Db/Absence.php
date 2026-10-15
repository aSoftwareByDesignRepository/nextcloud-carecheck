<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getActorRef()
 * @method void setActorRef(string|null $actorRef)
 * @method \DateTime getFromDate()
 * @method void setFromDate(\DateTime|null $fromDate)
 * @method \DateTime getUntilDate()
 * @method void setUntilDate(\DateTime|null $untilDate)
 * @method string|null getKind()
 * @method void setKind(string|null $kind)
 * @method string|null getSource()
 * @method void setSource(string|null $source)
 * @method string|null getMarkedBy()
 * @method void setMarkedBy(string|null $markedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
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