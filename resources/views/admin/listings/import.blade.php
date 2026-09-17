@extends('admin.layout')
@section('title', __('ui.admin_pages.listing_import.title'))
@section('page-title', __('ui.admin_pages.listing_import.title'))

@section('content')
    <section class="admin-card" aria-labelledby="listing-import-title">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_import.eyebrow') }}</p>
                <h2 id="listing-import-title">{{ __('ui.admin_pages.listing_import.heading') }}</h2>
                <p>{{ __('ui.admin_pages.listing_import.description') }}</p>
            </div>
        </div>

        <div class="listing-workflow">
            <section class="workflow-step" aria-labelledby="workflow-products-title">
                <div class="workflow-step__heading">
                    <span class="workflow-step__index">01</span>
                    <div>
                        <p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.step', ['step' => 1]) }}</p>
                        <h3 id="workflow-products-title">{{ __('ui.admin_pages.listing_import.products.heading') }}</h3>
                        <p>{{ trans_choice('ui.admin_pages.listing_import.products.count', $targetProductCount, ['count' => number_format($targetProductCount, 0, ',', '.'), 'target' => $targetListingCount]) }}</p>
                    </div>
                </div>

                @if($targetProducts->isEmpty())
                    <div class="empty-state"><p>{{ __('ui.admin_pages.listing_import.products.empty') }}</p></div>
                @else
                    <div class="table-scroll workflow-targets">
                        <table class="admin-table">
                            <thead><tr><th>{{ __('ui.admin_pages.listing_coverage.product') }}</th><th>{{ __('ui.admin_pages.listing_coverage.category') }}</th><th>{{ __('ui.admin_pages.listing_coverage.current') }}</th><th>{{ __('ui.admin_pages.listing_import.products.target') }}</th><th>{{ __('ui.admin_pages.listing_import.products.priority') }}</th></tr></thead>
                            <tbody>
                                @foreach($targetProducts as $product)
                                    <tr>
                                        <td class="admin-table__question"><strong>{{ $product->name }}</strong><span>#{{ $product->id }}</span></td>
                                        <td>{{ $product->category?->name ?? '—' }}</td>
                                        <td>{{ number_format($product->active_listings_count, 0, ',', '.') }}</td>
                                        <td>{{ number_format($targetListingCount, 0, ',', '.') }}</td>
                                        <td><span class="badge badge--{{ $product->active_listings_count === 0 ? 'danger' : 'warning' }}">{{ $product->active_listings_count === 0 ? __('ui.admin_pages.listing_import.products.high') : __('ui.admin_pages.listing_import.products.needs_listing') }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="workflow-actions">
                    <a class="button button--secondary" href="{{ route('admin.listings.coverage') }}">{{ __('ui.admin_pages.listing_import.products.view') }}</a>
                    <a class="button button--secondary" href="{{ route('admin.listings.coverage.export') }}">{{ __('ui.admin_pages.listing_import.products.download') }}</a>
                    <button class="button button--primary" type="button" data-copy-target="#listing-research-prompt" data-copy-status="#listing-research-copy-status" data-copy-success="{{ __('ui.admin_pages.listing_import.prompt.copy_success') }}" data-copy-failure="{{ __('ui.admin_pages.listing_import.prompt.copy_failure') }}">{{ __('ui.admin_pages.listing_import.prompt.copy') }}</button>
                </div>
                <p class="workflow-helper workflow-helper--prompt-action">{{ __('ui.admin_pages.listing_import.prompt.action_help', ['count' => $researchPromptProductLimit]) }}</p>
                <p class="workflow-helper">{{ __('ui.admin_pages.listing_import.products.view_help') }}</p>
                <p class="workflow-helper">{{ __('ui.admin_pages.listing_import.products.target_help') }}</p>

                <details class="workflow-details prompt-panel">
                    <summary>{{ __('ui.admin_pages.listing_import.prompt.heading') }}</summary>
                    <div class="prompt-panel__section">
                        <p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.prompt.what_heading') }}</p>
                        <p class="workflow-helper">{{ __('ui.admin_pages.listing_import.prompt.help', ['count' => $researchPromptProductLimit]) }}</p>
                    </div>
                    <div class="prompt-panel__section">
                        <p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.prompt.how_heading') }}</p>
                        <ol class="prompt-panel__steps">
                            @foreach(__('ui.admin_pages.listing_import.prompt.steps') as $instruction)
                                <li>{{ $instruction }}</li>
                            @endforeach
                        </ol>
                    </div>
                    <div class="prompt-panel__section">
                        <p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.prompt.generated_heading') }}</p>
                        <div class="prompt-batch-summary">
                            <span>{{ __('ui.admin_pages.listing_import.prompt.batch_label') }}</span>
                            <strong>{{ __('ui.admin_pages.listing_import.prompt.batch_summary', ['current' => number_format($researchPromptProductCount, 0, ',', '.'), 'total' => number_format($targetProductCount, 0, ',', '.')]) }}</strong>
                        </div>
                    </div>
                    <textarea id="listing-research-prompt" class="prompt-panel__content" rows="12" readonly>{{ $researchPrompt }}</textarea>
                    <div class="prompt-panel__section prompt-panel__section--actions">
                        <p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.prompt.actions_heading') }}</p>
                        <div class="workflow-actions">
                            <button class="button button--secondary" type="button" data-copy-target="#listing-research-prompt" data-copy-status="#listing-research-copy-status" data-copy-success="{{ __('ui.admin_pages.listing_import.prompt.copy_success') }}" data-copy-failure="{{ __('ui.admin_pages.listing_import.prompt.copy_failure') }}">{{ __('ui.admin_pages.listing_import.prompt.copy_short') }}</button>
                            <a class="button button--text" href="{{ route('admin.listings.coverage.research-prompt') }}">{{ __('ui.admin_pages.listing_import.prompt.download') }}</a>
                            <output id="listing-research-copy-status" class="copy-status" aria-live="polite"></output>
                        </div>
                    </div>
                </details>
            </section>

            <div class="listing-workflow__split">
                <section class="workflow-step" aria-labelledby="workflow-find-title">
                    <div class="workflow-step__heading">
                        <span class="workflow-step__index">02</span>
                        <div><p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.step', ['step' => 2]) }}</p><h3 id="workflow-find-title">{{ __('ui.admin_pages.listing_import.find.heading') }}</h3><p>{{ __('ui.admin_pages.listing_import.find.help') }}</p></div>
                    </div>
                </section>

                <section class="workflow-step" aria-labelledby="workflow-prepare-title">
                    <div class="workflow-step__heading">
                        <span class="workflow-step__index">03</span>
                        <div><p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.step', ['step' => 3]) }}</p><h3 id="workflow-prepare-title">{{ __('ui.admin_pages.listing_import.prepare.heading') }}</h3><p>{{ __('ui.admin_pages.listing_import.prepare.help') }}</p></div>
                    </div>
                    <div class="workflow-actions"><a class="button button--primary" href="{{ route('admin.listings.import.template') }}">{{ __('ui.admin_pages.listing_import.download_template') }}</a></div>
                    <p class="workflow-helper">{{ __('ui.admin_pages.listing_import.prepare.template_help') }}</p>
                    <details class="workflow-details">
                        <summary>{{ __('ui.admin_pages.listing_import.field_guide.heading') }}</summary>
                        <dl class="field-guide">
                            @foreach(['platform', 'title', 'price', 'currency', 'condition', 'product_url', 'stock_status', 'price_type', 'optional'] as $field)
                                <div><dt>{{ __('ui.admin_pages.listing_import.field_guide.'.$field.'.label') }}</dt><dd>{{ __('ui.admin_pages.listing_import.field_guide.'.$field.'.help') }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </section>
            </div>

            <section class="workflow-step" aria-labelledby="workflow-upload-title">
                <div class="workflow-step__heading">
                    <span class="workflow-step__index">04</span>
                    <div><p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.step', ['step' => 4]) }}</p><h3 id="workflow-upload-title">{{ __('ui.admin_pages.listing_import.upload.heading') }}</h3><p>{{ __('ui.admin_pages.listing_import.upload.help') }}</p></div>
                </div>

                @if($errors->any())
                    <div class="alert alert--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form class="admin-form workflow-upload" method="POST" action="{{ route('admin.listings.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-field">
                        <label for="listing-csv">{{ __('ui.admin_pages.listing_import.file') }}</label>
                        <input id="listing-csv" name="csv" type="file" accept=".csv,text/csv" required>
                        <small>{{ __('ui.admin_pages.listing_import.file_help') }}</small>
                    </div>
                    <div class="form-actions"><button class="button button--primary" type="submit">{{ __('ui.admin_pages.listing_import.validate_preview') }}</button></div>
                </form>
            </section>

            @isset($preview)
                <section class="workflow-step" aria-labelledby="listing-preview-title">
                    <div class="workflow-step__heading">
                        <span class="workflow-step__index">05</span>
                        <div><p class="workflow-step__label">{{ __('ui.admin_pages.listing_import.step', ['step' => 5]) }}</p><h3 id="listing-preview-title">{{ __('ui.admin_pages.listing_import.preview_heading') }}</h3><p>{{ __('ui.admin_pages.listing_import.preview_help') }}</p></div>
                    </div>
                    <div class="summary-grid summary-grid--compact">
                        @foreach(['rows', 'valid', 'rejected', 'duplicate', 'matched', 'unmatched'] as $metric)
                            <article class="summary-card"><p class="summary-card__label">{{ __('ui.admin_pages.listing_import.metrics.'.$metric) }}</p><p class="summary-card__value">{{ number_format($preview['summary'][$metric], 0, ',', '.') }}</p></article>
                        @endforeach
                    </div>

                    @if($preview['details'] !== [])
                        <div class="table-scroll">
                            <table class="admin-table admin-table--wide">
                                <thead><tr><th>{{ __('ui.admin_pages.listing_import.preview.row') }}</th><th>Platform</th><th>{{ __('ui.admin_pages.listing_import.preview.title') }}</th><th>{{ __('ui.admin_pages.listing_import.preview.mapping') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.admin_pages.listing_import.preview.reason') }}</th></tr></thead>
                                <tbody>
                                    @foreach($preview['details'] as $detail)
                                        <tr>
                                            <td>{{ $detail['row_number'] }}</td>
                                            <td>{{ $detail['platform'] ? ucfirst($detail['platform']) : '—' }}</td>
                                            <td>{{ $detail['title'] ?? '—' }}</td>
                                            <td>@if($detail['product_mapping'])<span class="badge badge--success">{{ __('ui.admin_pages.listing_import.preview.matched', ['product' => $detail['product_mapping']]) }}</span>@elseif($detail['status'] === 'valid')<span class="badge badge--warning">{{ __('ui.admin_pages.listing_import.preview.unmatched') }}</span>@else—@endif</td>
                                            <td><span class="badge badge--{{ $detail['status'] === 'valid' ? 'success' : ($detail['status'] === 'duplicate' ? 'warning' : 'danger') }}">{{ __('ui.admin_pages.listing_import.preview.status.'.$detail['status']) }}</span></td>
                                            <td>{{ $detail['reason'] ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if($preview['summary']['valid'] > 0)
                        <form method="POST" action="{{ route('admin.listings.import.confirm') }}" data-confirm="{{ __('ui.admin_pages.listing_import.confirm') }}">
                            @csrf
                            <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                            <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.listings.index') }}">{{ __('ui.common.cancel') }}</a><button class="button button--primary" type="submit">{{ __('ui.admin_pages.listing_import.import_valid', ['count' => $preview['summary']['valid']]) }}</button></div>
                        </form>
                    @endif
                </section>
            @endisset
        </div>
    </section>
@endsection
