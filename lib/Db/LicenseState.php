<?php

declare(strict_types=1);

namespace OCA\CareCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getCustomerId()
 * @method void setCustomerId(string|null $customerId)
 * @method \DateTime getValidUntil()
 * @method void setValidUntil(\DateTime|null $validUntil)
 * @method int|null getMobileSeats()
 * @method void setMobileSeats(int|null $mobileSeats)
 * @method int|null getStations()
 * @method void setStations(int|null $stations)
 * @method int|null getBundle()
 * @method void setBundle(int|null $bundle)
 * @method \DateTime getKeyAppliedAt()
 * @method void setKeyAppliedAt(\DateTime|null $keyAppliedAt)
 * @method string|null getPayloadB64()
 * @method void setPayloadB64(string|null $payloadB64)
 * @method string|null getSignatureB64()
 * @method void setSignatureB64(string|null $signatureB64)
 * @method string|null getLicenseFingerprint()
 * @method void setLicenseFingerprint(string|null $licenseFingerprint)
 * @method string|null getBoundInstanceId()
 * @method void setBoundInstanceId(string|null $boundInstanceId)
 */
class LicenseState extends Entity
{
	protected $customerId;
	protected $validUntil;
	protected $mobileSeats;
	protected $stations;
	protected $bundle;
	protected $keyAppliedAt;
	protected $payloadB64;
	protected $signatureB64;
	protected $licenseFingerprint;
	protected $boundInstanceId;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('validUntil', 'date');
		$this->addType('mobileSeats', 'integer');
		$this->addType('stations', 'integer');
		$this->addType('bundle', 'integer');
		$this->addType('keyAppliedAt', 'datetime');
	}
}
