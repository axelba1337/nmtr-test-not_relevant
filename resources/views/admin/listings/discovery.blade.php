@extends('admin.layout')
@section('title', __('ui.admin_pages.listing_discovery.title'))
@section('page-title', __('ui.admin_pages.listing_discovery.title'))

@section('content')
    <section class="admin-card" aria-labelledby="listing-discovery-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.eyebrow') }}</p><h2 id="listing-discovery-title">{{ __('ui.admin_pages.listing_discovery.heading') }}</h2><p>{{ __('ui.admin_pages.listing_discovery.description') }}</p></div>
            <form method="POST" action="{{ route('admin.listings.discovery.start') }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.start_confirm') }}">@csrf<button class="button button--primary" type="submit" @disabled(!$settings['enabled'])>{{ __('ui.admin_pages.listing_discovery.start') }}</button></form>
        </div>

        <div class="alert {{ $settings['enabled'] ? 'alert--info' : 'alert--warning' }}"><p>{{ $settings['enabled'] ? __('ui.admin_pages.listing_discovery.enabled_help') : __('ui.admin_pages.listing_discovery.disabled_help') }}</p></div>
        <dl class="data-list">
            @foreach(['enabled', 'provider', 'model', 'grounding', 'region', 'minimum', 'target', 'max_products', 'max_new', 'max_per_platform', 'approval', 'auto_import', 'schedule_enabled', 'schedule_cron'] as $setting)
                <div><dt>{{ __('ui.admin_pages.listing_discovery.settings.'.$setting) }}</dt><dd>@if(is_bool($settings[$setting])){{ $settings[$setting] ? __('ui.common.yes') : __('ui.common.no') }}@else{{ $settings[$setting] }}@endif</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="admin-card" aria-labelledby="discovery-runs-title">
        <div class="admin-card__heading"><div><h2 id="discovery-runs-title">{{ __('ui.admin_pages.listing_discovery.runs') }}</h2></div></div>
        @if($runs->isEmpty())<div class="empty-state"><p>{{ __('ui.admin_pages.listing_discovery.no_runs') }}</p></div>@else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>ID</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.admin_pages.listing_discovery.products_checked') }}</th><th>{{ __('ui.admin_pages.listing_discovery.candidates_found') }}</th><th>{{ __('ui.admin_pages.listing_discovery.valid_rejected') }}</th><th>{{ __('ui.admin_pages.listing_discovery.approved_pending') }}</th><th>{{ __('ui.common.updated') }}</th></tr></thead><tbody>
                @foreach($runs as $run)<tr><td>#{{ $run->id }}</td><td><span class="badge badge--{{ $run->status === 'failed' ? 'danger' : ($run->status === 'completed' ? 'success' : 'neutral') }}">{{ strtoupper($run->status) }}</span>@if($run->error_message)<span class="table-meta">{{ $run->error_message }}</span>@endif</td><td>{{ $run->products_checked }}</td><td>{{ $run->candidates_found }}</td><td>{{ $run->valid_candidates }} / {{ $run->rejected_candidates }}</td><td>{{ $run->approved_candidates_count }} / {{ $run->pending_candidates_count }}</td><td>{{ $run->finished_at?->translatedFormat('d M Y H:i') ?? $run->updated_at->translatedFormat('d M Y H:i') }}</td></tr>@endforeach
            </tbody></table></div>
        @endif
    </section>

    <section class="admin-card" aria-labelledby="discovery-candidates-title">
        <div class="admin-card__heading"><div><h2 id="discovery-candidates-title">{{ __('ui.admin_pages.listing_discovery.pending_candidates') }}</h2><p>{{ __('ui.admin_pages.listing_discovery.approval_help') }}</p></div></div>
        @if($candidates->isEmpty())<div class="empty-state"><p>{{ __('ui.admin_pages.listing_discovery.no_candidates') }}</p></div>@else
            <div class="table-scroll"><table class="admin-table admin-table--wide"><thead><tr><th>{{ __('ui.admin_pages.listing_coverage.product') }}</th><th>{{ __('ui.admin.listings') }}</th><th>Platform</th><th>{{ __('ui.admin_pages.listings.price') }}</th><th>{{ __('ui.admin_pages.listings.condition') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead><tbody>
                @foreach($candidates as $candidate)<tr><td>{{ $candidate->targetProduct?->name ?? '—' }}</td><td class="admin-table__question"><strong>{{ $candidate->title }}</strong><a class="table-source-link" href="{{ $candidate->product_url }}" target="_blank" rel="noopener noreferrer">{{ __('ui.common.open_source') }}</a></td><td>{{ $candidate->platform_slug }}</td><td>Rp{{ number_format($candidate->price, 0, ',', '.') }}</td><td>{{ __('ui.labels.'.$candidate->condition) }}</td><td><div class="table-actions"><form method="POST" action="{{ route('admin.listings.discovery.candidates.approve', $candidate) }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.approve_confirm') }}">@csrf<button class="button button--small button--primary" type="submit">{{ __('ui.admin_pages.listing_discovery.approve') }}</button></form><form method="POST" action="{{ route('admin.listings.discovery.candidates.reject', $candidate) }}" data-confirm="{{ __('ui.admin_pages.listing_discovery.reject_confirm') }}">@csrf<button class="button button--small button--danger" type="submit">{{ __('ui.admin_pages.listing_discovery.reject') }}</button></form></div></td></tr>@endforeach
            </tbody></table></div>
        @endif
    </section>
@endsection
