@extends('admin.layout')
@section('title', __('ui.admin_pages.product_import.title'))
@section('page-title', __('ui.admin_pages.product_import.title'))

@section('content')
    <section class="admin-card" aria-labelledby="product-import-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.product_import.eyebrow') }}</p>
                <h2 id="product-import-title">{{ __('ui.admin_pages.product_import.heading') }}</h2>
                <p>{{ __('ui.admin_pages.product_import.description') }}</p>
            </div>
            <div class="button-group">
                <a class="button button--primary" href="{{ route('admin.products.import.template') }}">{{ __('ui.admin_pages.product_import.download_template') }}</a>
                <a class="button button--secondary" href="{{ route('admin.products.index') }}">{{ __('ui.common.cancel') }}</a>
            </div>
        </div>

        @if(session('product_import_result'))
            @php($result = session('product_import_result'))
            <section class="workflow-step" aria-labelledby="product-import-result-title">
                <div class="workflow-step__heading">
                    <span class="workflow-step__index">✓</span>
                    <div>
                        <p class="workflow-step__label">{{ __('ui.admin_pages.product_import.result.eyebrow') }}</p>
                        <h3 id="product-import-result-title">{{ __('ui.admin_pages.product_import.result.heading') }}</h3>
                    </div>
                </div>
                <div class="summary-grid summary-grid--compact">
                    @foreach(['created', 'skipped_existing', 'skipped_duplicate', 'invalid', 'failed'] as $metric)
                        <article class="summary-card"><p class="summary-card__label">{{ __('ui.admin_pages.product_import.result.'.$metric) }}</p><p class="summary-card__value">{{ number_format((int) ($result[$metric] ?? 0), 0, ',', '.') }}</p></article>
                    @endforeach
                </div>
                <div class="workflow-actions"><a class="button button--primary" href="{{ route('admin.products.index') }}">{{ __('ui.admin_pages.product_import.view_products') }}</a></div>
            </section>
        @endif

        <div class="listing-import-workflow product-import-workflow">
            <section class="workflow-step" aria-labelledby="product-import-template-title">
                <div class="workflow-step__heading">
                    <span class="workflow-step__index">01</span>
                    <div>
                        <p class="workflow-step__label">{{ __('ui.admin_pages.product_import.step', ['step' => 1]) }}</p>
                        <h3 id="product-import-template-title">{{ __('ui.admin_pages.product_import.template.heading') }}</h3>
                        <p>{{ __('ui.admin_pages.product_import.template.help') }}</p>
                    </div>
                </div>
                <div class="workflow-actions"><a class="button button--primary" href="{{ route('admin.products.import.template') }}">{{ __('ui.admin_pages.product_import.download_template') }}</a></div>
                <details class="workflow-details">
                    <summary>{{ __('ui.admin_pages.product_import.field_guide.heading') }}</summary>
                    <dl class="field-guide">
                        @foreach(['category', 'canonical_name', 'brand', 'model', 'variant', 'sku_or_part_number', 'aliases', 'specification_source', 'is_active'] as $field)
                            <div><dt>{{ __('ui.admin_pages.product_import.field_guide.'.$field.'.label') }}</dt><dd>{{ __('ui.admin_pages.product_import.field_guide.'.$field.'.help') }}</dd></div>
                        @endforeach
                    </dl>
                </details>
            </section>

            <section class="workflow-step" aria-labelledby="product-import-upload-title">
                <div class="workflow-step__heading">
                    <span class="workflow-step__index">02</span>
                    <div>
                        <p class="workflow-step__label">{{ __('ui.admin_pages.product_import.step', ['step' => 2]) }}</p>
                        <h3 id="product-import-upload-title">{{ __('ui.admin_pages.product_import.upload.heading') }}</h3>
                        <p>{{ __('ui.admin_pages.product_import.upload.help') }}</p>
                    </div>
                </div>

                @if($errors->any())
                    <div class="alert alert--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif

                <form class="admin-form workflow-upload" method="POST" action="{{ route('admin.products.import.preview') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="form-field">
                        <label for="product-csv">{{ __('ui.admin_pages.product_import.file') }}</label>
                        <input id="product-csv" name="csv" type="file" accept=".csv,text/csv" required>
                        <small>{{ __('ui.admin_pages.product_import.file_help') }}</small>
                    </div>
                    <div class="form-actions"><button class="button button--primary" type="submit">{{ __('ui.admin_pages.product_import.validate_preview') }}</button></div>
                </form>
            </section>

            @isset($preview)
                <section class="workflow-step" aria-labelledby="product-import-preview-title">
                    <div class="workflow-step__heading">
                        <span class="workflow-step__index">03</span>
                        <div>
                            <p class="workflow-step__label">{{ __('ui.admin_pages.product_import.step', ['step' => 3]) }}</p>
                            <h3 id="product-import-preview-title">{{ __('ui.admin_pages.product_import.preview.heading') }}</h3>
                            <p>{{ __('ui.admin_pages.product_import.preview.help') }}</p>
                        </div>
                    </div>

                    <div class="summary-grid summary-grid--compact">
                        @foreach(['rows', 'ready', 'existing', 'invalid', 'duplicate'] as $metric)
                            <article class="summary-card"><p class="summary-card__label">{{ __('ui.admin_pages.product_import.metrics.'.$metric) }}</p><p class="summary-card__value">{{ number_format($preview['summary'][$metric], 0, ',', '.') }}</p></article>
                        @endforeach
                    </div>

                    <form method="POST" action="{{ route('admin.products.import.confirm') }}" data-confirm="{{ __('ui.admin_pages.product_import.confirm', ['count' => $preview['summary']['ready']]) }}">
                        @csrf
                        <input type="hidden" name="preview_token" value="{{ $previewToken }}">
                        <div class="table-scroll">
                            <table class="admin-table admin-table--wide product-import-preview">
                                <thead><tr><th>{{ __('ui.admin_pages.product_import.preview.row') }}</th><th>{{ __('ui.admin_pages.product_import.preview.canonical_name') }}</th><th>{{ __('ui.admin_pages.product_import.preview.category') }}</th><th>{{ __('ui.admin_pages.product_import.preview.brand_model') }}</th><th>{{ __('ui.admin_pages.product_import.preview.variant') }}</th><th>{{ __('ui.admin_pages.product_import.preview.sku') }}</th><th>{{ __('ui.admin_pages.product_import.preview.aliases') }}</th><th>{{ __('ui.admin_pages.product_import.preview.validation') }}</th><th>{{ __('ui.admin_pages.product_import.preview.notes') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                                <tbody>
                                    @foreach($preview['details'] as $detail)
                                        <tr>
                                            <td>{{ $detail['row_number'] }}</td>
                                            <td class="admin-table__question"><strong>{{ $detail['canonical_name'] ?: '—' }}</strong></td>
                                            <td>{{ $detail['category'] ?: '—' }}</td>
                                            <td>{{ $detail['brand'] ?: '—' }}<span class="table-meta">{{ $detail['model'] ?: '—' }}</span></td>
                                            <td>{{ $detail['variant'] ?: '—' }}</td>
                                            <td>{{ $detail['sku'] ?: '—' }}</td>
                                            <td class="product-import-preview__aliases">{{ is_array($detail['aliases']) ? implode(' · ', $detail['aliases']) : ($detail['aliases'] ?: '—') }}</td>
                                            <td><span class="badge badge--{{ $detail['status'] === 'ready' ? 'success' : ($detail['status'] === 'existing' ? 'neutral' : ($detail['status'] === 'duplicate' ? 'warning' : 'danger')) }}">{{ __('ui.admin_pages.product_import.status.'.$detail['status']) }}</span></td>
                                            <td>{{ __('ui.admin_pages.product_import.notes.'.$detail['note']) }}</td>
                                            <td>@if($detail['status'] === 'ready')<label class="check-field product-import-preview__approval"><input type="checkbox" name="approved_rows[]" value="{{ $detail['row_number'] }}" checked><span><strong>{{ __('ui.admin_pages.product_import.approve') }}</strong></span></label>@else<span class="table-meta">{{ __('ui.admin_pages.product_import.skip') }}</span>@endif</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        @if($preview['summary']['ready'] > 0)
                            <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.products.index') }}">{{ __('ui.common.cancel') }}</a><button class="button button--primary" type="submit">{{ __('ui.admin_pages.product_import.import_valid') }}</button></div>
                        @endif
                    </form>
                </section>
            @endisset
        </div>
    </section>
@endsection
