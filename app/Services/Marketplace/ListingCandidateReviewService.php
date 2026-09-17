<?php

namespace App\Services\Marketplace;

use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ListingCandidateReviewService
{
    public function __construct(
        private readonly MarketplaceCsvImportService $csv,
    ) {}

    public function approve(
        ListingCandidate $candidate,
        User $reviewer,
        bool $requireTargetProduct = false,
    ): Listing {
        if ($candidate->validation_status !== ListingCandidate::VALIDATION_VALID
            || $candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
            throw new ListingCandidateReviewException(
                ListingCandidateReviewException::REASON_UNAVAILABLE,
                'Candidate is not available for approval.',
            );
        }

        return DB::transaction(function () use (
            $candidate,
            $reviewer,
            $requireTargetProduct,
        ): Listing {
            $candidate = ListingCandidate::query()
                ->lockForUpdate()
                ->find($candidate->getKey());

            if (! $candidate instanceof ListingCandidate
                || $candidate->validation_status !== ListingCandidate::VALIDATION_VALID
                || $candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_UNAVAILABLE,
                    'Candidate is no longer available for approval.',
                );
            }

            if ($requireTargetProduct
                && ($candidate->target_product_id === null
                    || ! $candidate->targetProduct()->exists())) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_VALIDATION,
                    'Candidate is no longer linked to a canonical Product.',
                );
            }

            $validatedRow = data_get(
                $candidate->raw_payload,
                'validated_row',
            );

            if (! is_array($validatedRow)) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_VALIDATION,
                    'Candidate validation data is unavailable.',
                );
            }

            if ($this->listingAlreadyExists($candidate, $validatedRow)) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_DUPLICATE,
                    'Candidate duplicates an existing Listing.',
                );
            }

            $result = $this->csv->importRows([$validatedRow]);

            if (($result['counts']['failed'] ?? 1) > 0) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_VALIDATION,
                    'Candidate could not be imported.',
                );
            }

            if (($result['counts']['created'] ?? 0) !== 1) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_DUPLICATE,
                    'Candidate did not create a new Listing.',
                );
            }

            $listing = Listing::query()
                ->where('platform_id', $validatedRow['platform_id'] ?? null)
                ->where('product_url', $candidate->product_url)
                ->first();

            if (! $listing instanceof Listing) {
                throw new ListingCandidateReviewException(
                    ListingCandidateReviewException::REASON_PROCESSING,
                    'Imported listing could not be located.',
                );
            }

            $candidate->forceFill([
                'listing_id' => $listing->id,
                'reviewed_by' => $reviewer->id,
                'review_status' => ListingCandidate::REVIEW_APPROVED,
                'reviewed_at' => now(),
            ])->save();

            return $listing;
        });
    }

    /**
     * @param  list<int>  $candidateIds
     * @return array{approved:int,duplicate:int,validation_failed:int,skipped:int,failed:int}
     */
    public function approveMany(array $candidateIds, User $reviewer): array
    {
        $summary = [
            'approved' => 0,
            'duplicate' => 0,
            'validation_failed' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $ids = collect($candidateIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(50)
            ->values();
        $candidates = ListingCandidate::query()
            ->with('targetProduct:id')
            ->whereKey($ids->all())
            ->get()
            ->keyBy(static fn (ListingCandidate $candidate): int => (int) $candidate->id);

        foreach ($ids as $id) {
            $candidate = $candidates->get($id);

            if (! $candidate instanceof ListingCandidate) {
                $summary['skipped']++;

                continue;
            }

            if ($candidate->validation_status !== ListingCandidate::VALIDATION_VALID) {
                $summary['validation_failed']++;

                continue;
            }

            if ($candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
                $summary['skipped']++;

                continue;
            }

            if ($candidate->targetProduct === null) {
                $summary['validation_failed']++;

                continue;
            }

            try {
                $this->approve($candidate, $reviewer, requireTargetProduct: true);
                $summary['approved']++;
            } catch (ListingCandidateReviewException $exception) {
                if ($exception->reason === ListingCandidateReviewException::REASON_DUPLICATE) {
                    $summary['duplicate']++;
                    $this->markInvalid($candidate, $reviewer, 'duplicate Listing');
                } elseif ($exception->reason === ListingCandidateReviewException::REASON_VALIDATION) {
                    $summary['validation_failed']++;
                    $this->markInvalid($candidate, $reviewer, 'final validation failed');
                } elseif ($exception->reason === ListingCandidateReviewException::REASON_UNAVAILABLE) {
                    $summary['skipped']++;
                } else {
                    $summary['failed']++;
                }
            } catch (Throwable $exception) {
                $summary['failed']++;
                report($exception);
            }
        }

        return $summary;
    }

    public function reject(
        ListingCandidate $candidate,
        User $reviewer,
    ): void {
        if ($candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
            throw new ListingCandidateReviewException(
                ListingCandidateReviewException::REASON_UNAVAILABLE,
                'Candidate is not available for rejection.',
            );
        }

        $candidate->forceFill([
            'reviewed_by' => $reviewer->id,
            'review_status' => ListingCandidate::REVIEW_REJECTED,
            'reviewed_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $validatedRow */
    private function listingAlreadyExists(
        ListingCandidate $candidate,
        array $validatedRow,
    ): bool {
        $platformId = (int) ($validatedRow['platform_id'] ?? 0);

        if ($platformId < 1) {
            return false;
        }

        $externalId = trim((string) data_get(
            $validatedRow,
            'values.external_listing_id',
        ));

        return Listing::query()
            ->where('platform_id', $platformId)
            ->where(function ($query) use ($candidate, $externalId): void {
                $query->where('product_url', $candidate->product_url);

                if ($externalId !== '') {
                    $query->orWhere('external_listing_id', $externalId);
                }
            })
            ->exists();
    }

    private function markInvalid(
        ListingCandidate $candidate,
        User $reviewer,
        string $reason,
    ): void {
        ListingCandidate::query()
            ->whereKey($candidate->getKey())
            ->where('review_status', ListingCandidate::REVIEW_PENDING)
            ->update([
                'validation_status' => ListingCandidate::VALIDATION_INVALID,
                'review_status' => ListingCandidate::REVIEW_INVALID,
                'rejection_reason' => $reason,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);
    }
}
