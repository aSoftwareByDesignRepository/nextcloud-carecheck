<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getDisplayName()
 * @method void setDisplayName(string|null $displayName)
 * @method string|null getQualification()
 * @method void setQualification(string|null $qualification)
 * @method int|null getActive()
 * @method void setActive(int|null $active)
 * @method int|null getTeamId()
 * @method void setTeamId(int|null $teamId)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime|null $createdAt)
 */
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