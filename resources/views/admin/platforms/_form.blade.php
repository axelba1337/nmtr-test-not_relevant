<form class="admin-form" method="POST" action="{{ $action }}" data-admin-form>
    @csrf
    @method($method)

    @if($errors->any())
        <div class="alert alert--error" role="alert">
            <p>{{ __('ui.admin_pages.platforms.check_data') }}</p>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <dl class="data-list">
        <div><dt>{{ __('ui.admin_pages.platforms.name') }}</dt><dd>{{ $platform->name }}</dd></div>
        <div><dt>Slug</dt><dd><code>{{ $platform->slug }}</code></dd></div>
    </dl>

    <div class="admin-form__grid">
        <div class="form-field"><label for="platform-base-url">{{ __('ui.admin_pages.platforms.public_url') }}</label><input id="platform-base-url" name="base_url" type="url" value="{{ old('base_url', $platform->base_url) }}" maxlength="255" placeholder="https://example.com">@error('base_url')<p class="form-error">{{ $message }}</p>@enderror</div>
        <fieldset class="form-options"><legend>{{ __('ui.admin_pages.platforms.availability') }}</legend><input type="hidden" name="is_active" value="0"><label class="check-field"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $platform->is_active))><span><strong>{{ __('ui.admin_pages.platforms.active') }}</strong><small>{{ __('ui.admin_pages.platforms.active_help') }}</small></span></label></fieldset>
    </div>

    <p class="form-help">{{ __('ui.admin_pages.platforms.identity_locked') }}</p>
    <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.platforms.index') }}">{{ __('ui.common.cancel') }}</a><button class="button button--primary" type="submit" data-busy-label="{{ __('ui.common.saving') }}">{{ $submitLabel }}</button></div>
</form>
