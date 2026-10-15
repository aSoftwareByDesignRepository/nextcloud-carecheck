<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getTemplateId()
 * @method void setTemplateId(int|null $templateId)
 * @method \DateTime getDate()
 * @method void setDate(\DateTime|null $date)
 * @method string|null getExecutorRef()
 * @method void setExecutorRef(string|null $executorRef)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method int|null getRevision()
 * @method void setRevision(int|null $revision)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
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