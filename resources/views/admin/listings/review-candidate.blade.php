@extends('admin.layout')

@section('title', __('ui.admin_pages.listings.review_candidate'))
@section('page-title', __('ui.admin_pages.listings.review_candidate'))

@section('content')
    <section class="admin-card">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listings.unmatched_heading') }}</p>
                <h2>{{ $listing->title }}</h2>
                <p>{{ __('ui.admin_pages.listings.review_help') }}</p>
            </div>
            <a class="button button--secondary" href="{{ $listing->product_url }}" target="_blank" rel="noopener noreferrer">{{ __('ui.common.open_source') }}</a>
        </div>

        <dl class="candidate-source-grid">
            <div><dt>{{ __('ui.admin.platforms') }}</dt><dd>{{ $listing->platform?->name ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.item_price') }}</dt><dd>Rp{{ number_format($listing->price, 0, ',', '.') }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.condition') }}</dt><dd>{{ __('ui.labels.'.$listing->condition) }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.location') }}</dt><dd>{{ $listing->location ?: '—' }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.seller_name') }}</dt><dd>{{ $listing->seller_name ?: '—' }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.first_seen') }}</dt><dd>{{ $listing->first_seen_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listings.last_seen') }}</dt><dd>{{ ($listing->last_seen_at ?? $listing->last_synced_at)?->translatedFormat('d M Y H:i') ?? '—' }}</dd></div>
            @foreach($suggestions['raw_metadata'] as $field => $value)
                @if($value !== '')
                    <div><dt>{{ __('ui.validation.attributes.product.'.$field) }}</dt><dd>{{ $value }}</dd></div>
                @endif
            @endforeach
        </dl>
    </section>

    <div class="candidate-layout">
        <section class="admin-card admin-card--form">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.listings.new_candidate') }}</p><h2>{{ __('ui.admin_pages.listings.approve_new_product') }}</h2><p>{{ __('ui.admin_pages.listings.candidate_safety') }}</p></div></div>
            <form class="admin-form" method="POST" action="{{ route('admin.listings.candidate.approve', $listing) }}" data-admin-form>
                @csrf
                @if($errors->any())<div class="alert alert--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <div class="admin-form__grid">
                    <div class="form-field"><label for="candidate-category">{{ __('ui.admin.categories') }}</label><select id="candidate-category" name="category_id" required><option value="">{{ __('ui.admin_pages.products.choose_category') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $suggestions['category_id']) === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                    <div class="form-field"><label for="candidate-name">{{ __('ui.admin_pages.products.standard_name') }}</label><input id="candidate-name" name="name" value="{{ old('name', $suggestions['name']) }}" required maxlength="255"></div>
                    <div class="form-field"><label for="candidate-brand">{{ __('ui.admin_pages.products.brand') }}</label><input id="candidate-brand" name="brand" value="{{ old('brand', $suggestions['brand']) }}" maxlength="255"></div>
                    <div class="form-field"><label for="candidate-model">{{ __('ui.admin_pages.products.model') }}</label><input id="candidate-model" name="model" value="{{ old('model', $suggestions['model']) }}" maxlength="255"></div>
                    <div class="form-field"><label for="candidate-variant">{{ __('ui.admin_pages.products.variant') }}</label><input id="candidate-variant" name="variant" value="{{ old('variant', $suggestions['variant']) }}" maxlength="255"></div>
                    <div class="form-field"><label for="candidate-sku">{{ __('ui.admin_pages.products.sku') }}</label><input id="candidate-sku" name="sku_or_part_number" value="{{ old('sku_or_part_number', $suggestions['sku_or_part_number']) }}" maxlength="255"></div>
                    <div class="form-field"><label for="candidate-source-name">{{ __('ui.admin_pages.products.spec_source') }}</label><input id="candidate-source-name" name="spec_source_name" value="{{ old('spec_source_name') }}" maxlength="120"></div>
                    <div class="form-field"><label for="candidate-source-checked">{{ __('ui.admin_pages.products.last_verified') }}</label><input id="candidate-source-checked" name="spec_source_checked_at" type="datetime-local" value="{{ old('spec_source_checked_at') }}"></div>
                    <div class="form-field form-field--full"><label for="candidate-source-url">{{ __('ui.admin_pages.products.official_spec_url') }}</label><input id="candidate-source-url" name="spec_source_url" type="url" value="{{ old('spec_source_url') }}" maxlength="2048" placeholder="https://manufacturer.example/specifications"><p class="form-help">{{ __('ui.admin_pages.products.official_spec_help') }}</p></div>
                    <div class="form-field form-field--full"><label for="candidate-aliases">{{ __('ui.admin_pages.products.aliases') }}</label><textarea id="candidate-aliases" name="aliases" maxlength="10000">{{ old('aliases', implode(PHP_EOL, $suggestions['aliases'])) }}</textarea><p class="form-help">{{ __('ui.admin_pages.listings.alias_approval_help') }}</p></div>
                </div>
                <div class="form-actions"><button class="button button--primary" type="submit" data-busy-label="{{ __('ui.common.processing') }}">{{ __('ui.admin_pages.listings.approve_new_product') }}</button></div>
            </form>
        </section>

        <section class="admin-card admin-card--form">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.listings.existing_candidate') }}</p><h2>{{ __('ui.admin_pages.listings.link_existing') }}</h2><p>{{ __('ui.admin_pages.listings.link_existing_help') }}</p></div></div>
            <form class="admin-form" method="POST" action="{{ route('admin.listings.link-product', $listing) }}" data-admin-form>
                @csrf
                <div class="admin-form__grid">
                    <div class="form-field form-field--full"><label for="existing-product">{{ __('ui.admin_pages.listings.canonical_product') }}</label><select id="existing-product" name="product_id" required><option value="">{{ __('ui.admin_pages.listings.choose_product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · {{ $product->category?->name }}</option>@endforeach</select></div>
                    <div class="form-field form-field--full"><label for="existing-aliases">{{ __('ui.admin_pages.listings.optional_aliases') }}</label><textarea id="existing-aliases" name="aliases" maxlength="10000"></textarea><p class="form-help">{{ __('ui.admin_pages.listings.alias_approval_help') }}</p></div>
                </div>
                <div class="form-actions"><button class="button button--primary" type="submit" data-busy-label="{{ __('ui.common.processing') }}">{{ __('ui.admin_pages.listings.link_existing') }}</button></div>
            </form>
        </section>
    </div>
@endsection
