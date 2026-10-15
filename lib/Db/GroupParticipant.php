<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getSessionId()
 * @method void setSessionId(int|null $sessionId)
 * @method int|null getClientId()
 * @method void setClientId(int|null $clientId)
 * @method int|null getAttended()
 * @method void setAttended(int|null $attended)
 */
class GroupParticipant extends Entity
{
	protected $sessionId;
	protected $clientId;
	protected $attended;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('sessionId', 'integer');
		$this->addType('clientId', 'integer');
		$this->addType('attended', 'integer');
	}
}
