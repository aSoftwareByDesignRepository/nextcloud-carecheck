<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method string|null getLabel()
 * @method void setLabel(string|null $label)
 * @method int|null getFileId()
 * @method void setFileId(int|null $fileId)
 * @method \DateTime|null getExpiresOn()
 * @method void setExpiresOn(\DateTime|null $expiresOn)
 * @method \DateTime|null getRemindedOn()
 * @method void setRemindedOn(\DateTime|null $remindedOn)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(string|null $createdBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
class ClientDoc extends Entity
{
	protected $clientId;
	protected $label;
	protected $fileId;
	protected $expiresOn;
	protected $remindedOn;
	protected $createdBy;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('label', 'string');
		$this->addType('fileId', 'integer');
		$this->addType('expiresOn', 'date');
		$this->addType('remindedOn', 'date');
		$this->addType('createdBy', 'string');
		$this->addType('createdAt', 'datetime');
	}
}
