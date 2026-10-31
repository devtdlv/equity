<?php

namespace App\Console\Commands;

use App\Enums\RecurringObligationStatus;
use App\Models\RecurringObligation;
use App\Models\RecurringOccurrence;
use App\Models\User;
use App\Services\RecurringObligationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Idempotently extends active recurring obligations through the three-month UTC horizon.
 *
 * Does not post ledger entries, create payment links, or modify paused/cancelled bills.
 * Safe to rerun; relies on RecurringObligationService slot uniqueness and locking.
 */
class RecurringRefreshCommand extends Command
{
    public const SIGNATURE_NAME = 'equity:recurring-refresh';

    public const CACHE_LOCK_KEY = 'equity:recurring-refresh';

    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 500;

    public const LOCK_SECONDS = 3600;

    /**
     * @var string
     */
    protected $signature = 'equity:recurring-refresh
                            {--batch-size=100 : Number of active obligations to load per chunk (1-500)}';

    /**
     * @var string
     */
    protected $description = 'Refresh the three-month UTC occurrence horizon for active recurring obligations';

    public function handle(RecurringObligationService $obligationService): int
    {
        $batchSize = $this->resolveBatchSize();
        if ($batchSize === null) {
            return self::FAILURE;
        }

        $lock = Cache::lock(self::CACHE_LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->error('Recurring horizon refresh is already running.');

            return self::FAILURE;
        }

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $occurrencesCreated = 0;

        try {
            RecurringObligation::query()
                ->where('status', RecurringObligationStatus::Active->value)
                ->orderBy('id')
                ->chunkById($batchSize, function ($obligations) use (
                    $obligationService,
                    &$processed,
                    &$succeeded,
                    &$failed,
                    &$occurrencesCreated,
                ): void {
                    foreach ($obligations as $obligation) {
                        $processed++;
                        $obligationId = (int) $obligation->id;

                        try {
                            $user = User::query()->find($obligation->user_id);
                            if ($user === null) {
                                throw new \RuntimeException('Owning user could not be resolved.');
                            }

                            $beforeCount = RecurringOccurrence::query()
                                ->where('obligation_id', $obligationId)
                                ->count();

                            $obligationService->generateOccurrences($user, $obligationId);

                            $afterCount = RecurringOccurrence::query()
                                ->where('obligation_id', $obligationId)
                                ->count();

                            $delta = $afterCount - $beforeCount;
                            if ($delta > 0) {
                                $occurrencesCreated += $delta;
                            }

                            $succeeded++;
                        } catch (Throwable $exception) {
                            $failed++;

                            $exceptionClass = $exception::class;

                            Log::warning('Recurring horizon refresh failed for obligation.', [
                                'obligation_id' => $obligationId,
                                'exception' => $exceptionClass,
                                'message' => $exception->getMessage(),
                            ]);

                            $this->warn("Failed obligation_id={$obligationId} ({$exceptionClass}).");
                        }
                    }
                });
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Recurring horizon refresh complete. processed=%d succeeded=%d failed=%d occurrences_created=%d',
            $processed,
            $succeeded,
            $failed,
            $occurrencesCreated,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveBatchSize(): ?int
    {
        $raw = $this->option('batch-size');
        if (! is_numeric($raw)) {
            $this->error('The --batch-size option must be an integer between 1 and '.self::MAX_BATCH_SIZE.'.');

            return null;
        }

        $batchSize = (int) $raw;
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            $this->error('The --batch-size option must be an integer between 1 and '.self::MAX_BATCH_SIZE.'.');

            return null;
        }

        return $batchSize;
    }
}
