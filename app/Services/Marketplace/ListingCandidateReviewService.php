<?php

namespace App\Services\Marketplace;

use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ListingCandidateReviewService
{
    public function __construct(
        private readonly MarketplaceCsvImportService $csv,
    ) {}

    public function approve(
        ListingCandidate $candidate,
        User $reviewer,
    ): Listing {
        if ($candidate->validation_status !== ListingCandidate::VALIDATION_VALID
            || $candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
            throw new RuntimeException('Candidate is not available for approval.');
        }

        return DB::transaction(function () use ($candidate, $reviewer): Listing {
            $validatedRow = data_get(
                $candidate->raw_payload,
                'validated_row',
            );

            if (! is_array($validatedRow)) {
                throw new RuntimeException('Candidate validation data is unavailable.');
            }

            $result = $this->csv->importRows([$validatedRow]);

            if (($result['counts']['failed'] ?? 1) > 0) {
                throw new RuntimeException('Candidate could not be imported.');
            }

            $listing = Listing::query()
                ->where('platform_id', $validatedRow['platform_id'] ?? null)
                ->where('product_url', $candidate->product_url)
                ->first();

            if (! $listing instanceof Listing) {
                throw new RuntimeException('Imported listing could not be located.');
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

    public function reject(
        ListingCandidate $candidate,
        User $reviewer,
    ): void {
        if ($candidate->review_status !== ListingCandidate::REVIEW_PENDING) {
            throw new RuntimeException('Candidate is not available for rejection.');
        }

        $candidate->forceFill([
            'reviewed_by' => $reviewer->id,
            'review_status' => ListingCandidate::REVIEW_REJECTED,
            'reviewed_at' => now(),
        ])->save();
    }
}
