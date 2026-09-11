@if ($errors->any())
    <div class="alert alert--error" role="alert">
        <p>{{ __('ui.admin_pages.knowledge.form.validation_intro') }}</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="admin-form__grid">
    <div class="form-field admin-form__full">
        <label for="title">{{ __('ui.admin_pages.knowledge.form.title') }}</label>
        <input
            id="title"
            name="title"
            type="text"
            value="{{ old('title', $item?->title) }}"
            required
            maxlength="255"
            autocomplete="off"
        >
        @error('title')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field admin-form__full">
        <label for="content">{{ __('ui.admin_pages.knowledge.form.content') }}</label>
        <textarea
            id="content"
            name="content"
            rows="10"
            required
            maxlength="50000"
        >{{ old('content', $item?->content) }}</textarea>
        @error('content')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
        <p class="form-help">{{ __('ui.admin_pages.knowledge.form.content_hint') }}</p>
    </div>

    <div class="form-field">
        <label for="category_id">{{ __('ui.admin_pages.knowledge.form.category') }}</label>
        <select id="category_id" name="category_id">
            <option value="">{{ __('ui.admin_pages.knowledge.common.no_category') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id) === (string) $category->id)>
                    {{ $category->name }}{{ $category->is_active ? '' : __('ui.admin_pages.knowledge.form.inactive_suffix') }}
                </option>
            @endforeach
        </select>
        @error('category_id')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    @php
        $keywordValue = old('keywords', $item?->keywords ?? []);
        $keywordValue = is_array($keywordValue)
            ? implode(', ', $keywordValue)
            : $keywordValue;
    @endphp
    <div class="form-field">
        <label for="keywords">{{ __('ui.admin_pages.knowledge.form.keywords') }}</label>
        <textarea id="keywords" name="keywords" rows="4" maxlength="4000" placeholder="{{ __('ui.admin_pages.knowledge.form.keywords_placeholder') }}">{{ $keywordValue }}</textarea>
        @error('keywords')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="source_name">{{ __('ui.admin_pages.knowledge.form.source_name') }}</label>
        <input id="source_name" name="source_name" type="text" maxlength="255" value="{{ old('source_name', $item?->source_name) }}">
        @error('source_name')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="source_url">{{ __('ui.admin_pages.knowledge.form.source_url') }}</label>
        <input id="source_url" name="source_url" type="url" maxlength="2048" value="{{ old('source_url', $item?->source_url) }}" placeholder="{{ __('ui.admin_pages.knowledge.form.source_url_placeholder') }}">
        @error('source_url')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="sort_order">{{ __('ui.admin_pages.knowledge.form.sort_order') }}</label>
        <input
            id="sort_order"
            name="sort_order"
            type="number"
            value="{{ old('sort_order', $item?->sort_order ?? 0) }}"
            required
            min="0"
            max="4294967295"
            inputmode="numeric"
        >
        @error('sort_order')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
        <p class="form-help">{{ __('ui.admin_pages.knowledge.form.sort_order_hint') }}</p>
    </div>

    <fieldset class="form-options">
        <legend>{{ __('ui.admin_pages.knowledge.form.status') }}</legend>

        <label class="check-field">
            <input type="hidden" name="is_active" value="0">
            <input
                name="is_active"
                type="checkbox"
                value="1"
                @checked((bool) old('is_active', $item?->is_active ?? true))
            >
            <span>
                <strong>{{ __('ui.admin_pages.knowledge.common.active') }}</strong>
                <small>{{ __('ui.admin_pages.knowledge.form.active_hint') }}</small>
            </span>
        </label>

        <label class="check-field">
            <input type="hidden" name="is_featured" value="0">
            <input
                name="is_featured"
                type="checkbox"
                value="1"
                @checked((bool) old('is_featured', $item?->is_featured ?? false))
            >
            <span>
                <strong>{{ __('ui.admin_pages.knowledge.form.featured') }}</strong>
                <small>{{ __('ui.admin_pages.knowledge.form.featured_hint') }}</small>
            </span>
        </label>
    </fieldset>
</div>

<div class="form-actions">
    <a class="button button--secondary" href="{{ route('admin.knowledge.index') }}">
        {{ __('ui.admin_pages.knowledge.form.cancel') }}
    </a>
    <button
        class="button button--primary"
        type="submit"
        data-disable-on-submit
    >
        {{ $submitLabel }}
    </button>
</div>
