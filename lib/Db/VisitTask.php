<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int|null getVisitId()
 * @method void setVisitId(int|null $visitId)
 * @method string|null getTaskCode()
 * @method void setTaskCode(string|null $taskCode)
 * @method string|null getStatus()
 * @method void setStatus(string|null $status)
 * @method string|null getReasonCode()
 * @method void setReasonCode(string|null $reasonCode)
 * @method string|null getValueEnc()
 * @method void setValueEnc(string|null $valueEnc)
 * @method string|null getCommentEnc()
 * @method void setCommentEnc(string|null $commentEnc)
 */
class VisitTask extends Entity
{
	protected $visitId;
	protected $taskCode;
	protected $status;
	protected $reasonCode;
	protected $valueEnc;
	protected $commentEnc;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('visitId', 'integer');
		$this->addType('taskCode', 'string');
		$this->addType('status', 'string');
		$this->addType('reasonCode', 'string');
		$this->addType('valueEnc', 'string');
		$this->addType('commentEnc', 'string');
	}
}