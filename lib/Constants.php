<?php

declare(strict_types=1);

namespace OCA\CareCheck;

final class Constants
{
	public const APP_ID = 'carecheck';

	// Errors
	public const ERR_NOT_FOUND = 'NOT_FOUND';
	public const ERR_VALIDATION = 'VALIDATION_ERROR';
	public const ERR_CONFLICT = 'CONFLICT';
	public const ERR_RATE_LIMITED = 'RATE_LIMITED';
	public const ERR_AUTH = 'UNAUTHORIZED';

	// Roles / refs — typed polymorphic actor refs (never bare ids)
	public const QUAL_FACH = 'fachkraft';
	public const QUAL_HILF = 'hilfskraft';
	public const QUAL_BETR = 'betreuer';
	public const QUALS = [self::QUAL_FACH, self::QUAL_HILF, self::QUAL_BETR];
	public const GROUP_ZENTRALE = 'carecheck-zentrale';
	public const GROUP_BUCHHALTUNG = 'carecheck-buchhaltung';
	public const GROUP_APP_ADMIN = 'carecheck-admin';
	public const GROUP_TEAMLEAD = 'carecheck-teamlead';

	// Stop / visit / notice enums
	public const STOP_KINDS = ['client', 'external'];
	public const TIME_KINDS = ['window', 'fixed', 'deadline'];
	public const STOP_STATUSES = ['pending', 'in_progress', 'done', 'skipped', 'uncovered'];
	public const VISIT_STATUSES = ['in_progress', 'done', 'rejected_review', 'done_corrected'];
	public const TASK_STATUSES = ['done', 'refused', 'not_done'];
	public const REASON_CODES = ['refused', 'not_home', 'hospital', 'deceased', 'other', 'cover_sick', 'sent_by_dispatch', 'emergency'];
	public const SIG_STATES = ['none', 'signed', 'representative', 'unable'];
	public const NOTICE_KINDS = ['incident', 'supply_needed', 'plan_change_proposal', 'info'];
	public const NOTICE_STATES = ['open', 'acked', 'escalated', 'false_alarm'];
	public const TPL_STATUSES = ['draft', 'published'];
	public const AUDIT_ACTIONS = ['read', 'tour_pull', 'list', 'export', 'break_glass', 'search', 'sig_read', 'journal_view', 'license_apply', 'notice_resolve_error', 'photo_add', 'photo_read', 'photo_del', 'voicenote_add', 'voicenote_transcribed', 'team_manage', 'group_session'];
	public const SKIP_REASONS = ['not_home', 'cancelled', 'refused_entry', 'other'];
	public const ABSENCE_KINDS = ['sick', 'vacation', 'other'];
	public const EXPORT_KINDS = ['visit_pdf', 'monthly_csv', 'monthly_pdf'];

	public const PAIR_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // unambiguous base32
	public const PAIR_CODE_LEN = 8;
	public const SIG_MAX_BYTES = 262144; // 256 KiB

	public static function notFoundPayload(): array
	{
		return ['success' => false, 'error' => self::ERR_NOT_FOUND, 'message' => 'Not found.', 'fields' => []];
	}
}
