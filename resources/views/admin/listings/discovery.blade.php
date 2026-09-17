@extends('admin.layout')
@section('title', __('ui.admin_pages.listing_discovery.title'))
@section('page-title', __('ui.admin_pages.listing_discovery.title'))

@section('content')
    <section class="admin-card" aria-labelledby="listing-discovery-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.eyebrow') }}</p>
                <h2 id="listing-discovery-title">{{ __('ui.admin_pages.listing_discovery.heading') }}</h2>
                <p>{{ __('ui.admin_pages.listing_discovery.description') }}</p>
                <p class="table-meta">{{ __('ui.admin_pages.listing_discovery.provider_separation_help') }}</p>
            </div>
            <div class="discovery-start">
                <form method="POST" action="{{ route('admin.listings.discovery.start') }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.start_confirm') }}">
                    @csrf
                    <button class="button button--primary" type="submit" @disabled(!$settings['enabled'] || $activeRun)>
                        {{ $activeRun ? __('ui.admin_pages.listing_discovery.running') : __('ui.admin_pages.listing_discovery.start') }}
                    </button>
                </form>
                <p class="table-meta">{{ __('ui.admin_pages.listing_discovery.start_help', ['count' => $settings['max_products']]) }}</p>
            </div>
        </div>

        <div class="alert {{ $settings['enabled'] ? 'alert--info' : 'alert--warning' }}">
            <p>{{ $settings['enabled'] ? __('ui.admin_pages.listing_discovery.enabled_help') : __('ui.admin_pages.listing_discovery.disabled_help') }}</p>
        </div>

        <div class="discovery-summary-grid">
            <article class="discovery-summary" aria-labelledby="catalog-coverage-title">
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.catalog_coverage') }}</p>
                <h3 id="catalog-coverage-title">{{ __('ui.admin_pages.listing_discovery.catalog_status') }}</h3>
                <dl class="data-list">
                    <div><dt>{{ __('ui.admin_pages.listing_discovery.products_below_target') }}</dt><dd>{{ $catalogMetrics['below_target'] }}</dd></div>
                    <div><dt>{{ __('ui.admin_pages.listing_discovery.products_without_ready_to_use') }}</dt><dd>{{ $catalogMetrics['without_ready_to_use_listings'] }}</dd></div>
                    <div><dt>{{ __('ui.admin_pages.listing_discovery.settings.target') }}</dt><dd>{{ __('ui.admin_pages.listing_discovery.target_value', ['count' => $coverageTarget]) }}</dd></div>
                    <div><dt>{{ __('ui.admin_pages.listing_discovery.settings.max_products') }}</dt><dd>{{ __('ui.admin_pages.listing_discovery.products_per_run_value', ['count' => $settings['max_products']]) }}</dd></div>
                </dl>
            </article>

            <article class="discovery-summary" aria-labelledby="discovery-config-title">
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.configuration') }}</p>
                <h3 id="discovery-config-title">{{ __('ui.admin_pages.listing_discovery.provider_settings') }}</h3>
                <dl class="data-list">
                    @foreach(['provider', 'search', 'extraction', 'probe', 'platform_fallback', 'search_cache_hours', 'region', 'max_new', 'max_per_platform', 'approval', 'retry_cooldown_hours'] as $setting)
                        <div>
                            <dt>{{ __('ui.admin_pages.listing_discovery.settings.'.$setting) }}</dt>
                            <dd>
                                @if($setting === 'approval')
                                    {{ $settings[$setting] ? __('ui.admin_pages.listing_discovery.required') : __('ui.admin_pages.listing_discovery.not_required') }}
                                @elseif(in_array($setting, ['probe', 'platform_fallback'], true))
                                    {{ $settings[$setting] ? __('ui.admin_pages.listing_discovery.enabled') : __('ui.admin_pages.listing_discovery.disabled') }}
                                @elseif(in_array($setting, ['retry_cooldown_hours', 'search_cache_hours'], true))
                                    {{ __('ui.admin_pages.listing_discovery.cooldown_value', ['count' => $settings[$setting]]) }}
                                @else
                                    {{ $settings[$setting] }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </article>
        </div>
        <p class="discovery-helper">{{ __('ui.admin_pages.listing_discovery.coverage_batch_help') }}</p>
        <p class="discovery-helper">{{ __('ui.admin_pages.listing_discovery.credit_safety_help', ['count' => $settings['max_products']]) }}</p>
    </section>

    @if($activeRun)
        @php
            $initialTarget = max(0, (int) $activeRun->products_targeted);
            $initialChecked = min($initialTarget, max(0, (int) $activeRun->products_checked));
            $initialPercent = $initialTarget > 0 ? (int) floor(($initialChecked / $initialTarget) * 100) : 0;
        @endphp
        <section
            class="admin-card discovery-progress"
            aria-labelledby="discovery-progress-title"
            aria-live="polite"
            data-discovery-progress
            data-progress-url="{{ route('admin.listings.discovery.runs.progress', $activeRun) }}"
            data-poll-interval="3000"
            data-checked-template="{{ __('ui.admin_pages.listing_discovery.checked_of_target', ['checked' => ':checked', 'target' => ':target']) }}"
            data-waiting-text="{{ __('ui.admin_pages.listing_discovery.waiting') }}"
            data-polling-error="{{ __('ui.admin_pages.listing_discovery.polling_error') }}"
        >
            <div class="admin-card__heading">
                <div>
                    <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.current_run') }} #{{ $activeRun->id }}</p>
                    <h2 id="discovery-progress-title">{{ __('ui.admin_pages.listing_discovery.in_progress') }}</h2>
                </div>
                <span class="badge badge--info discovery-progress__status" data-progress-status>{{ strtoupper($activeRun->status) }}</span>
            </div>
            <div class="discovery-progress__heading">
                <strong data-progress-summary>{{ __('ui.admin_pages.listing_discovery.checked_of_target', ['checked' => $initialChecked, 'target' => $initialTarget]) }}</strong>
                <span data-progress-percent>{{ $initialPercent }}%</span>
            </div>
            <progress class="discovery-progress__bar" max="100" value="{{ $initialPercent }}" data-progress-bar>{{ $initialPercent }}%</progress>
            <dl class="data-list discovery-progress__metrics">
                <div><dt>{{ __('ui.admin_pages.listing_discovery.current_product') }}</dt><dd data-progress-product>{{ $activeCurrentProduct?->name ?? __('ui.admin_pages.listing_discovery.waiting') }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.listing_discovery.remaining') }}</dt><dd data-progress-remaining>{{ max(0, $initialTarget - $initialChecked) }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.listing_discovery.candidates_found') }}</dt><dd data-progress-candidates>{{ $activeRun->candidates_found }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.listing_discovery.valid') }}</dt><dd data-progress-valid>{{ $activeRun->valid_candidates }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.listing_discovery.rejected') }}</dt><dd data-progress-rejected>{{ $activeRun->rejected_candidates }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.listing_discovery.provider_failures') }}</dt><dd data-progress-failures>{{ $activeRun->provider_failures }}</dd></div>
            </dl>
            <p class="discovery-progress__message" data-progress-message></p>
        </section>
    @endif

    <section class="admin-card" aria-labelledby="discovery-runs-title">
        <div class="admin-card__heading"><div><h2 id="discovery-runs-title">{{ __('ui.admin_pages.listing_discovery.runs') }}</h2><p>{{ __('ui.admin_pages.listing_discovery.run_history_help') }}</p></div></div>
        <form class="filter-bar discovery-history-filter" method="GET" action="{{ route('admin.listings.discovery') }}">
            <div class="form-field">
                <label for="run-history-status">{{ __('ui.common.status') }}</label>
                <select id="run-history-status" name="run_status">
                    <option value="">{{ __('ui.common.all') }}</option>
                    @foreach($runStatuses as $status)
                        <option value="{{ $status }}" @selected($runFilters['status'] === $status)>{{ strtoupper($status) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="run-history-provider">{{ __('ui.admin_pages.listing_discovery.settings.provider') }}</label>
                <select id="run-history-provider" name="run_provider">
                    <option value="">{{ __('ui.common.all') }}</option>
                    @foreach($runProviders as $provider)
                        <option value="{{ $provider }}" @selected($runFilters['provider'] === $provider)>{{ ucfirst($provider) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="run-history-sort">{{ __('ui.admin_pages.listing_discovery.sort') }}</label>
                <select id="run-history-sort" name="run_sort">
                    <option value="newest" @selected($runFilters['sort'] === 'newest')>{{ __('ui.admin_pages.listing_discovery.sort_newest') }}</option>
                    <option value="oldest" @selected($runFilters['sort'] === 'oldest')>{{ __('ui.admin_pages.listing_discovery.sort_oldest') }}</option>
                </select>
            </div>
            <div class="filter-bar__actions">
                <button class="button button--primary" type="submit">{{ __('ui.common.apply') }}</button>
                <a class="button" href="{{ route('admin.listings.discovery') }}">{{ __('ui.common.reset') }}</a>
            </div>
        </form>
        @if($runs->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.listing_discovery.no_runs') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table admin-table--wide">
                    <thead><tr><th>ID</th><th>{{ __('ui.admin_pages.listing_discovery.settings.provider') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.admin_pages.listing_discovery.products_checked') }}</th><th>{{ __('ui.admin_pages.listing_discovery.candidates_found') }}</th><th>{{ __('ui.admin_pages.listing_discovery.valid_rejected') }}</th><th>{{ __('ui.admin_pages.listing_discovery.approved_pending') }}</th><th>{{ __('ui.admin_pages.listing_discovery.started_at') }}</th><th>{{ __('ui.admin_pages.listing_discovery.finished_at') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach($runs as $run)
                            <tr>
                                <td>#{{ $run->id }}</td>
                                <td>{{ ucfirst($run->provider) }}</td>
                                <td><span class="badge badge--{{ $run->status === 'failed' ? 'danger' : ($run->status === 'completed' ? 'success' : ($run->status === 'partial' ? 'warning' : 'neutral')) }}">{{ strtoupper($run->status) }}</span>@if($run->error_message)<span class="table-meta">{{ $run->error_message }}</span>@endif</td>
                                <td>{{ $run->products_checked }} / {{ $run->products_targeted }}</td>
                                <td>{{ $run->candidates_found }}</td>
                                <td>{{ $run->valid_candidates }} / {{ $run->rejected_candidates }}</td>
                                <td>{{ $run->approved_candidates_count }} / {{ $run->pending_candidates_count }}</td>
                                <td>{{ $run->started_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                                <td>{{ $run->finished_at?->translatedFormat('d M Y H:i') ?? '—' }}</td>
                                <td><a class="button button--small" href="{{ route('admin.listings.discovery.runs.show', $run) }}">{{ __('ui.admin_pages.listing_discovery.view_details') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="admin-card" aria-labelledby="discovery-candidates-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <h2 id="discovery-candidates-title">{{ __('ui.admin_pages.listing_discovery.pending_candidates') }}</h2>
                <p>{{ __('ui.admin_pages.listing_discovery.approval_help') }}</p>
            </div>
            @unless($candidates->isEmpty())
                <div class="candidate-bulk-actions">
                    <button
                        class="button button--primary"
                        type="button"
                        data-dialog-open="bulk-candidate-approval-dialog"
                    >
                        {{ __('ui.admin_pages.listing_discovery.approve_all_count', ['count' => $candidates->count()]) }}
                    </button>
                    <p class="table-meta">{{ __('ui.admin_pages.listing_discovery.approve_all_help') }}</p>
                </div>
            @endunless
        </div>
        @if($candidates->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.listing_discovery.no_candidates') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table admin-table--wide">
                    <thead><tr><th>{{ __('ui.admin_pages.listing_discovery.target_product') }}</th><th>{{ __('ui.admin_pages.listing_discovery.coverage') }}</th><th>{{ __('ui.admin.listings') }}</th><th>Platform</th><th>{{ __('ui.admin_pages.listings.price') }}</th><th>{{ __('ui.admin_pages.listings.condition') }}</th><th>{{ __('ui.admin_pages.listing_discovery.validation') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach($candidates as $candidate)
                            @php
                                $available = (int) ($candidate->targetProduct?->available_listings_count ?? 0);
                                $stillNeeded = max(0, $coverageTarget - $available);
                            @endphp
                            <tr>
                                <td><strong>{{ $candidate->targetProduct?->name ?? '—' }}</strong></td>
                                <td>{{ __('ui.admin_pages.listing_discovery.coverage_value', ['available' => $available, 'target' => $coverageTarget]) }}<span class="table-meta">{{ __('ui.admin_pages.listing_discovery.still_needed_value', ['count' => $stillNeeded]) }}</span></td>
                                <td class="admin-table__question"><strong>{{ $candidate->title }}</strong><a class="table-source-link" href="{{ $candidate->product_url }}" target="_blank" rel="noopener noreferrer">{{ __('ui.common.open_source') }}</a></td>
                                <td>{{ $candidate->platform_slug }}</td>
                                <td>Rp{{ number_format($candidate->price, 0, ',', '.') }}</td>
                                <td>{{ __('ui.labels.'.$candidate->condition) }}</td>
                                <td><span class="badge badge--success">{{ strtoupper($candidate->validation_status) }}</span></td>
                                <td><div class="table-actions"><form method="POST" action="{{ route('admin.listings.discovery.candidates.approve', $candidate) }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.approve_confirm') }}">@csrf<button class="button button--small button--primary" type="submit">{{ __('ui.admin_pages.listing_discovery.approve') }}</button></form><form method="POST" action="{{ route('admin.listings.discovery.candidates.reject', $candidate) }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.reject_confirm') }}">@csrf<button class="button button--small button--danger" type="submit">{{ __('ui.admin_pages.listing_discovery.reject') }}</button></form></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <dialog
                class="admin-dialog"
                id="bulk-candidate-approval-dialog"
                aria-labelledby="bulk-candidate-approval-title"
                data-admin-dialog
            >
                <form
                    class="admin-dialog__panel"
                    method="POST"
                    action="{{ route('admin.listings.discovery.candidates.approve-all') }}"
                    data-admin-form
                >
                    @csrf
                    @foreach($candidates as $candidate)
                        <input type="hidden" name="candidate_ids[]" value="{{ $candidate->id }}">
                    @endforeach
                    <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.pending_candidates') }}</p>
                    <h2 id="bulk-candidate-approval-title">{{ __('ui.admin_pages.listing_discovery.approve_all_confirm_title', ['count' => $candidates->count()]) }}</h2>
                    <p>{{ __('ui.admin_pages.listing_discovery.approve_all_confirm_body') }}</p>
                    <div class="admin-dialog__actions">
                        <button class="button button--secondary" type="button" data-dialog-close>
                            {{ __('ui.common.cancel') }}
                        </button>
                        <button class="button button--primary" type="submit">
                            {{ __('ui.admin_pages.listing_discovery.approve_all_submit', ['count' => $candidates->count()]) }}
                        </button>
                    </div>
                </form>
            </dialog>
        @endif
    </section>
@endsection
