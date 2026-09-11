<form class="admin-form" method="POST" action="{{ $action }}" data-admin-form>
    @csrf
    @isset($method) @method($method) @endisset

    @if ($errors->any())
        <div class="alert alert--error" role="alert">
            <p>{{ __('ui.admin_pages.categories.check_data') }}</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="admin-form__grid">
        <div class="form-field">
            <label for="category-name">{{ __('ui.admin_pages.categories.name') }} <x-required-marker /></label>
            <input id="category-name" name="name" value="{{ old('name', $category->name ?? '') }}" required maxlength="255" @error('name') aria-invalid="true" @enderror>
            @error('name')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field">
            <label for="category-slug">{{ __('ui.admin_pages.categories.slug') }}</label>
            <input id="category-slug" name="slug" value="{{ old('slug', $category->slug ?? '') }}" maxlength="255" placeholder="{{ __('ui.admin_pages.common.auto_slug') }}" @error('slug') aria-invalid="true" @enderror>
            <p class="form-help">{{ __('ui.admin_pages.categories.slug_help') }}</p>
            @error('slug')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        @php($isSupportedRootEdit = isset($category) && $category->parent_id === null)
        <div class="form-field">
            <label for="category-parent">{{ __('ui.admin_pages.categories.parent') }} @if(! $isSupportedRootEdit)<x-required-marker />@endif</label>
            <select id="category-parent" name="parent_id" @required(! $isSupportedRootEdit) @error('parent_id') aria-invalid="true" @enderror>
                <option value="" @disabled(! $isSupportedRootEdit)>{{ $isSupportedRootEdit ? __('ui.admin_pages.categories.no_parent') : __('ui.admin_pages.categories.choose_parent') }}</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id ?? '') === (string) $parent->id)>{{ $parent->name }}</option>
                @endforeach
            </select>
            <p class="form-help">{{ __('ui.admin_pages.categories.parent_help') }}</p>
            @error('parent_id')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field">
            <label for="category-order">{{ __('ui.admin_pages.common.sort_order') }}</label>
            <input id="category-order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $category->sort_order ?? '') }}">
            <p class="form-help">{{ __('ui.admin_pages.categories.sort_order_help') }}</p>
            @error('sort_order')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field form-field--full">
            <label for="category-description">{{ __('ui.admin_pages.common.description') }}</label>
            <textarea id="category-description" name="description" maxlength="10000">{{ old('description', $category->description ?? '') }}</textarea>
            @error('description')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <fieldset class="form-options form-field--full">
            <legend>{{ __('ui.common.status') }}</legend>
            <input type="hidden" name="is_active" value="0">
            <label class="check-field">
                <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $category->is_active ?? true))>
                <span><strong>{{ __('ui.admin_pages.categories.active') }}</strong><small>{{ __('ui.admin_pages.categories.active_help') }}</small></span>
            </label>
        </fieldset>
    </div>

    <div class="form-actions">
        <a class="button button--secondary" href="{{ route('admin.categories.index') }}">{{ __('ui.common.cancel') }}</a>
        <button class="button button--primary" type="submit" data-busy-label="{{ __('ui.common.saving') }}">{{ $submitLabel }}</button>
    </div>
</form>
