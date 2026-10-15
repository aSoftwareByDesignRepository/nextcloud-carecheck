<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getTeamId()
 * @method void setTeamId(int|null $teamId)
 * @method string|null getUid()
 * @method void setUid(string|null $uid)
 */
class TeamLead extends Entity
{
	protected $teamId;
	protected $uid;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('teamId', 'integer');
		$this->addType('uid', 'string');
	}
}
