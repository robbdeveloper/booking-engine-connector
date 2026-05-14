<?php

declare(strict_types=1);

namespace BookingEngineConnector\Sync;

/**
 * Short-lived manual sync progress for the admin UI (transient-backed, not a durable log).
 */
final class SyncProgressReporter
{
	private const TRANSIENT_PREFIX = 'bec_sync_prog_';

	private const MAX_LINES = 50;

	private const TTL_SECONDS = 600;

	private int $userId;

	private string $runId;

	public function __construct(int $userId, string $runId)
	{
		$this->userId = $userId;
		$this->runId  = self::sanitizeRunId($runId);
	}

	public static function sanitizeRunId(string $runId): string
	{
		$runId = \strtolower(\trim($runId));
		$runId = \preg_replace('/[^a-z0-9\-]/', '', $runId) ?? '';

		return \mb_substr($runId, 0, 64);
	}

	public static function isValidRunId(string $runId): bool
	{
		$s = self::sanitizeRunId($runId);

		return $s !== '' && \strlen($s) >= 8;
	}

	public static function transientKey(int $userId, string $sanitizedRunId): string
	{
		return self::TRANSIENT_PREFIX . $userId . '_' . $sanitizedRunId;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function read(int $userId, string $runId): ?array
	{
		$key = self::transientKey($userId, self::sanitizeRunId($runId));
		$raw = \get_transient($key);
		if (! \is_array($raw)) {
			return null;
		}

		return $raw;
	}

	public function running(string $message): void
	{
		$this->merge(
			[
				'status'  => 'running',
				'message' => $message,
			]
		);
	}

	public function addLine(string $line): void
	{
		$line = \mb_substr(\wp_strip_all_tags($line), 0, 500);
		if ($line === '') {
			return;
		}

		$state = $this->readState();
		/** @var list<string> $lines */
		$lines   = isset($state['lines']) && \is_array($state['lines']) ? $state['lines'] : [];
		$lines[] = $line;
		if (\count($lines) > self::MAX_LINES) {
			$lines = \array_slice($lines, -self::MAX_LINES);
		}

		$this->persist(
			\array_merge(
				$state,
				[
					'lines'   => $lines,
					'message' => $line,
				]
			)
		);
	}

	/**
	 * @param array{created:int, updated:int, skipped:int, errors:list<string>} $result
	 */
	public function done(array $result): void
	{
		$state = $this->readState();
		$summary = \sprintf(
			/* translators: 1: created count, 2: updated, 3: skipped */
			\__('Finished: created %1$d, updated %2$d, skipped %3$d.', 'booking-engine-connector'),
			(int) ( $result['created'] ?? 0 ),
			(int) ( $result['updated'] ?? 0 ),
			(int) ( $result['skipped'] ?? 0 )
		);
		/** @var list<string> $lines */
		$lines   = isset($state['lines']) && \is_array($state['lines']) ? $state['lines'] : [];
		$lines[] = $summary;
		if (\count($lines) > self::MAX_LINES) {
			$lines = \array_slice($lines, -self::MAX_LINES);
		}
		if (! empty($result['errors']) && \is_array($result['errors'])) {
			foreach ($result['errors'] as $err) {
				$err = \mb_substr(\wp_strip_all_tags((string) $err), 0, 500);
				if ($err !== '') {
					$lines[] = $err;
				}
			}
			if (\count($lines) > self::MAX_LINES) {
				$lines = \array_slice($lines, -self::MAX_LINES);
			}
		}
		$this->persist(
			\array_merge(
				$state,
				[
					'status'  => 'done',
					'result'  => $result,
					'message' => \__('Sync finished.', 'booking-engine-connector'),
					'lines'   => $lines,
				]
			)
		);
	}

	public function fail(string $message): void
	{
		$message = \mb_substr(\wp_strip_all_tags($message), 0, 500);
		$state   = $this->readState();
		$this->persist(
			\array_merge(
				$state,
				[
					'status' => 'done',
					'result' => [
						'created' => 0,
						'updated' => 0,
						'skipped' => 0,
						'errors'  => [ $message ],
					],
					'message' => $message,
				]
			)
		);
	}

	public function setCounters(int $current, int $total): void
	{
		$state = $this->readState();
		$this->persist(
			\array_merge(
				$state,
				[
					'current' => \max(0, $current),
					'total'   => \max(0, $total),
				]
			)
		);
	}

	private function readState(): array
	{
		$key = self::transientKey($this->userId, $this->runId);
		$raw = \get_transient($key);
		if (! \is_array($raw)) {
			return $this->defaultState();
		}

		return \array_merge($this->defaultState(), $raw);
	}

	private function defaultState(): array
	{
		return [
			'status'  => 'running',
			'lines'   => [],
			'current' => 0,
			'total'   => 0,
			'message' => '',
			'result'  => null,
		];
	}

	/**
	 * @param array<string, mixed> $patch
	 */
	private function merge(array $patch): void
	{
		$state = $this->readState();
		$this->persist(\array_merge($state, $patch));
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function persist(array $state): void
	{
		$key = self::transientKey($this->userId, $this->runId);
		\set_transient($key, $state, self::TTL_SECONDS);
	}
}
