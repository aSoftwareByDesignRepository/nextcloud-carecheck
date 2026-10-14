<?php

declare(strict_types=1);

namespace OCA\CareCheck\Tests\Unit\Service;

use OCA\CareCheck\Constants;
use OCA\CareCheck\Exception\ApiException;
use OCA\CareCheck\Service\BundleValidator;
use PHPUnit\Framework\TestCase;

class BundleValidatorTest extends TestCase
{
	private function png(): string
	{
		return base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', 100));
	}

	public function testIdemKeyRequired(): void
	{
		$this->expectException(ApiException::class);
		BundleValidator::idemKey([]);
	}

	public function testIdemKeyAccepted(): void
	{
		$this->assertSame('abc-123', BundleValidator::idemKey(['idem_key' => 'abc-123']));
	}

	public function testStrictIdRejectsNonNumeric(): void
	{
		foreach (['2.9', '2e3', ' 5', 'abc', '-3'] as $bad) {
			try {
				BundleValidator::strictId(['client_id' => $bad], 'client_id');
				$this->fail("accepted {$bad}");
			} catch (ApiException) {
			}
		}
		$this->assertSame(42, BundleValidator::strictId(['client_id' => '42'], 'client_id'));
		$this->assertNull(BundleValidator::strictId([], 'client_id'));
	}

	public function testRefusedTaskNeedsReasonCode(): void
	{
		$fields = [];
		BundleValidator::tasks([['task_code' => 'med', 'status' => 'refused']], $fields);
		$this->assertArrayHasKey('tasks.0.reason_code', $fields);
	}

	public function testNotDoneWithReasonPasses(): void
	{
		$fields = [];
		BundleValidator::tasks([['task_code' => 'med', 'status' => 'not_done', 'reason_code' => 'refused']], $fields);
		$this->assertSame([], $fields);
	}

	public function testUnknownReasonCodeRejected(): void
	{
		$fields = [];
		BundleValidator::tasks([['task_code' => 'x', 'status' => 'done', 'reason_code' => 'made_up']], $fields);
		$this->assertArrayHasKey('tasks.0.reason_code', $fields);
	}

	public function testInvalidTaskStatusRejected(): void
	{
		$fields = [];
		BundleValidator::tasks([['status' => 'invented']], $fields);
		$this->assertArrayHasKey('tasks.0.status', $fields);
	}

	public function testSignedWithoutSignatureFails(): void
	{
		$fields = [];
		BundleValidator::signature(['sig_state' => 'signed'], $fields);
		$this->assertSame('required', $fields['signature']);
	}

	public function testSignedWithPngPasses(): void
	{
		$fields = [];
		$sig = BundleValidator::signature(['sig_state' => 'signed', 'signature' => $this->png()], $fields);
		$this->assertSame([], $fields);
		$this->assertNotNull($sig);
	}

	public function testRepresentativeNeedsSignerName(): void
	{
		$fields = [];
		BundleValidator::signature(['sig_state' => 'representative', 'signature' => $this->png()], $fields);
		$this->assertArrayHasKey('signer_name', $fields);
	}

	public function testNonImageSignatureRejected(): void
	{
		$fields = [];
		BundleValidator::signature(['sig_state' => 'signed', 'signature' => base64_encode('<?php echo 1; ?>123456')], $fields);
		$this->assertSame('invalid_format', $fields['signature']);
	}

	public function testOversizedSignatureRejected(): void
	{
		$fields = [];
		$big = base64_encode("\x89PNG\r\n\x1a\n" . str_repeat('x', Constants::SIG_MAX_BYTES));
		BundleValidator::signature(['sig_state' => 'signed', 'signature' => $big], $fields);
		$this->assertSame('invalid_format', $fields['signature']);
	}

	public function testGeoDroppedWhenToggleOff(): void
	{
		$this->assertNull(BundleValidator::geo(['geo' => ['lat' => 52.5, 'lon' => 13.4]], false));
	}

	public function testGeoAcceptedWhenOn(): void
	{
		$g = BundleValidator::geo(['geo' => ['lat' => 52.5, 'lon' => 13.4]], true);
		$this->assertSame(52.5, $g['lat']);
	}

	public function testMalformedGeoDropped(): void
	{
		$this->assertNull(BundleValidator::geo(['geo' => ['lat' => 99, 'lon' => 13.4]], true));
	}
}
