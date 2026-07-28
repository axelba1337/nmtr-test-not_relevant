<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="page-shell">
        <section
            class="chat-card"
            data-chatbot
            data-endpoint="{{ route('chatbot.query') }}"
            data-suggestions-endpoint="{{ route('chatbot.suggestions') }}"
            aria-labelledby="chatbot-title"
        >
            <header class="chat-header">
                <div class="chat-header__main">
                    <div>
                        <p class="eyebrow">Semantic assistant</p>
                        <h1 id="chatbot-title">{{ config('app.name') }}</h1>
                    </div>
                    <p class="chat-status">
                        <span class="chat-status__dot" aria-hidden="true"></span>
                        Siap membantu
                    </p>
                </div>
                <p class="chat-intro">Ajukan pertanyaan berdasarkan knowledge yang tersedia.</p>
            </header>

            <div
                class="transcript"
                data-transcript
                role="log"
                aria-live="polite"
                aria-relevant="additions"
            >
                <article class="message message--assistant">
                    <p class="message__label">Asisten</p>
                    <p class="message__body">Halo! Ada yang bisa saya bantu? Pilih salah satu pertanyaan di bawah atau tulis pertanyaan Anda sendiri.</p>
                </article>

                <section
                    class="suggestions"
                    data-suggestions
                    aria-labelledby="suggestions-title"
                    hidden
                >
                    <p class="suggestions__label" id="suggestions-title">Pertanyaan yang disarankan</p>
                    <div class="suggestions__list" data-suggestions-list></div>
                </section>
            </div>

            <form class="composer" data-form>
                <label class="composer__label" for="chatbot-message">Pesan</label>
                <div class="composer__controls">
                    <input
                        id="chatbot-message"
                        name="message"
                        type="text"
                        required
                        maxlength="2000"
                        autocomplete="off"
                        placeholder="Tulis pertanyaan Anda"
                        data-input
                    >
                    <button type="submit" data-submit>Kirim</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>
