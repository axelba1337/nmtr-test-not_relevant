<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/branding/neomatra-logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/branding/neomatra-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="page-shell">
        <section
            class="chat-card"
            data-chatbot
            data-endpoint="{{ route('chatbot.query') }}"
            data-suggestions-endpoint="{{ route('chatbot.suggestions') }}"
            data-locale="{{ app()->getLocale() }}"
            data-translations="{{ json_encode(__('ui.chat.js'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
            aria-labelledby="chatbot-title"
        >
            <header class="chat-header">
                <div class="chat-header__main">
                    <div class="chat-brand">
                        <img
                            class="chat-brand__logo"
                            src="{{ asset('images/branding/neomatra-logo.png') }}"
                            alt=""
                            aria-hidden="true"
                        >
                        <div class="chat-brand__copy">
                            <p class="eyebrow">NMTR / SEMANTIC MARKET SEARCH</p>
                            <h1 id="chatbot-title">{{ config('app.name') }}</h1>
                        </div>
                    </div>
                    <div class="chat-header__actions">
                        <x-locale-switch />
                        <p class="chat-status" data-mono>
                            <span class="chat-status__dot" aria-hidden="true"></span>
                            {{ __('ui.chat.status') }}
                        </p>
                        <a class="admin-link" href="{{ route('admin.login') }}">{{ __('ui.common.admin') }}</a>
                    </div>
                </div>
            </header>

            <section class="workspace-overview" aria-label="{{ __('ui.chat.capabilities') }}">
                <div class="workspace-overview__lead">
                    <p class="eyebrow">{{ __('ui.chat.lead_eyebrow') }}</p>
                    <h2>{{ __('ui.chat.lead_title') }}</h2>
                    <p>{{ __('ui.chat.lead_body') }}</p>
                </div>
                <dl class="system-matrix" data-mono>
                    <div><dt>{{ __('ui.chat.engine') }}</dt><dd>{{ __('ui.chat.semantic_search') }}</dd></div>
                    <div><dt>{{ __('ui.chat.data') }}</dt><dd>{{ __('ui.chat.marketplace_web') }}</dd></div>
                    <div><dt>{{ __('ui.chat.output') }}</dt><dd>{{ __('ui.chat.ranked_intelligence') }}</dd></div>
                </dl>
            </section>

            <form class="composer" data-form>
                <div class="workspace-grid">
                    <aside class="workspace-rail" aria-label="{{ __('ui.chat.filters_aria') }}">
                        <details class="quick-filters" data-filters open>
                            <summary>{{ __('ui.chat.filters') }}</summary>
                            <div class="quick-filters__grid">
                        <label>
                            <span>{{ __('ui.chat.max_budget') }}</span>
                            <input name="budget_max" type="number" min="0" step="1000" placeholder="{{ __('ui.chat.budget_example') }}" data-filter>
                        </label>
                        <label>
                            <span>{{ __('ui.chat.condition') }}</span>
                            <select name="condition" data-filter>
                                <option value="">{{ __('ui.chat.all_conditions') }}</option>
                                @foreach (\App\Models\Listing::conditions() as $condition)
                                    <option value="{{ $condition }}">{{ __('ui.labels.'.$condition) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="quick-filters__location">
                            <span>{{ __('ui.chat.location') }}</span>
                            <input name="location" type="text" maxlength="120" placeholder="{{ __('ui.chat.location_example') }}" data-filter>
                        </label>
                            </div>
                        </details>
                        <div class="rail-status" data-mono>
                            <span>{{ __('ui.chat.source_mode') }}</span><strong>{{ __('ui.chat.hybrid_retrieval') }}</strong>
                            <span>{{ __('ui.chat.ranking') }}</span><strong>{{ __('ui.chat.deterministic') }}</strong>
                        </div>
                    </aside>

                    <div class="console-panel">
                        <div class="transcript" data-transcript role="log" aria-live="polite" aria-relevant="additions">
                            <article class="message message--assistant">
                                <p class="message__label">{{ __('ui.chat.welcome_label') }}</p>
                                <p class="message__body">{{ __('ui.chat.welcome') }}</p>
                            </article>

                            <section class="suggestions" data-suggestions aria-labelledby="suggestions-title" hidden>
                                <p class="suggestions__label" id="suggestions-title">{{ __('ui.chat.suggestions') }}</p>
                                <div class="suggestions__list" data-suggestions-list></div>
                            </section>
                        </div>

                        <div class="composer-dock">
                            <label class="composer__label" for="chatbot-message">{{ __('ui.chat.input_label') }}</label>
                            <div class="composer__controls">
                                <input id="chatbot-message" name="message" type="text" required maxlength="2000" autocomplete="off" placeholder="{{ __('ui.chat.input_placeholder') }}" data-input>
                                <button type="submit" data-submit>{{ __('ui.chat.send') }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <p class="chat-disclaimer">
                {{ __('ui.chat.disclaimer') }}
            </p>
        </section>
    </main>
</body>
</html>
