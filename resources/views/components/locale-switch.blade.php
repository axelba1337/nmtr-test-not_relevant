<nav class="locale-switch" aria-label="{{ __('ui.locale.label') }}">
    @foreach (['id' => 'ID', 'en' => 'EN'] as $localeCode => $localeLabel)
        <form method="POST" action="{{ route('locale.update', $localeCode) }}" data-locale-switch-form>
            @csrf
            <button
                class="locale-switch__option {{ app()->isLocale($localeCode) ? 'is-active' : '' }}"
                type="submit"
                lang="{{ $localeCode }}"
                aria-label="{{ __('ui.locale.change_to', ['language' => __('ui.locale.names.'.$localeCode)]) }}"
                aria-pressed="{{ app()->isLocale($localeCode) ? 'true' : 'false' }}"
                @disabled(app()->isLocale($localeCode))
            >{{ $localeLabel }}</button>
        </form>
    @endforeach
</nav>
