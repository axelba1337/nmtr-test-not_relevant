@extends('admin.layout')
@section('title', __('ui.admin_pages.ranking.title'))
@section('page-title', __('ui.admin_pages.ranking.title'))

@section('content')
    <section class="admin-card admin-card--form" aria-labelledby="ranking-title">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.ranking.eyebrow') }}</p><h2 id="ranking-title">{{ __('ui.admin_pages.ranking.heading') }}</h2><p>{{ __('ui.admin_pages.ranking.description') }}</p></div></div>
        <div class="alert alert--warning"><p>{{ __('ui.admin_pages.ranking.warning') }}</p></div>
        <form method="POST" action="{{ route('admin.ranking.update') }}" data-admin-form>
            @csrf @method('PATCH')
            @if($errors->any())<div class="alert alert--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="admin-form__grid">
                <div class="form-field form-field--full"><label for="ranking-name">{{ __('ui.admin_pages.ranking.profile_name') }}</label><input id="ranking-name" name="name" value="{{ old('name', $profile->name) }}" required maxlength="255"></div>
                @foreach([
                    'relevance_weight' => [__('ui.admin_pages.ranking.weights.relevance.label'), __('ui.admin_pages.ranking.weights.relevance.help')],
                    'price_weight' => [__('ui.admin_pages.ranking.weights.price.label'), __('ui.admin_pages.ranking.weights.price.help')],
                    'condition_weight' => [__('ui.admin_pages.ranking.weights.condition.label'), __('ui.admin_pages.ranking.weights.condition.help')],
                    'seller_weight' => [__('ui.admin_pages.ranking.weights.seller.label'), __('ui.admin_pages.ranking.weights.seller.help')],
                    'freshness_weight' => [__('ui.admin_pages.ranking.weights.freshness.label'), __('ui.admin_pages.ranking.weights.freshness.help')],
                ] as $field => [$label, $help])
                    <div class="form-field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="number" min="0" max="1" step="0.0001" value="{{ old($field, $profile->{$field}) }}" required><p class="form-help">{{ $help }}</p>@error($field)<p class="form-error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
            <div class="ranking-total" data-ranking-total aria-live="polite">{{ __('ui.admin_pages.ranking.total_help') }}</div>
            <div class="form-actions"><button class="button button--primary" type="submit" data-busy-label="{{ __('ui.admin_pages.ranking.saving') }}">{{ __('ui.admin_pages.ranking.submit') }}</button></div>
        </form>
    </section>
@endsection
