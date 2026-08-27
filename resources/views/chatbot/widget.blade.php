<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
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
                        <p class="eyebrow">NMTR / SEMANTIC MARKET SEARCH</p>
                        <h1 id="chatbot-title">{{ config('app.name') }}</h1>
                    </div>
                    <div class="chat-header__actions">
                        <p class="chat-status" data-mono>
                            <span class="chat-status__dot" aria-hidden="true"></span>
                            Siap membantu
                        </p>
                        <a class="admin-link" href="{{ route('admin.login') }}">Admin</a>
                    </div>
                </div>
            </header>

            <section class="workspace-overview" aria-label="Kapabilitas sistem">
                <div class="workspace-overview__lead">
                    <p class="eyebrow">Natural language product intelligence</p>
                    <h2>Cari produk. Uji harga. Bandingkan sumber.</h2>
                    <p>Tulis kebutuhan Anda secara natural untuk menelusuri knowledge, data marketplace, dan sumber web yang tersedia.</p>
                </div>
                <dl class="system-matrix" data-mono>
                    <div><dt>ENGINE</dt><dd>SEMANTIC SEARCH</dd></div>
                    <div><dt>DATA</dt><dd>MARKETPLACE + WEB</dd></div>
                    <div><dt>OUTPUT</dt><dd>RANKED INTELLIGENCE</dd></div>
                </dl>
            </section>

            <form class="composer" data-form>
                <div class="workspace-grid">
                    <aside class="workspace-rail" aria-label="Filter pencarian">
                        <details class="quick-filters" data-filters open>
                            <summary>FILTER / PARAMETERS</summary>
                            <div class="quick-filters__grid">
                        <label>
                            <span>Mode</span>
                            <select name="mode" data-filter>
                                <option value="">Otomatis</option>
                                <option value="buyer">Buyer</option>
                                <option value="reseller">Reseller</option>
                            </select>
                        </label>
                        <label>
                            <span>Budget maksimum</span>
                            <input name="budget_max" type="number" min="0" step="1000" placeholder="Contoh: 2800000" data-filter>
                        </label>
                        <label>
                            <span>Kondisi</span>
                            <select name="condition" data-filter>
                                <option value="">Semua kondisi</option>
                                <option value="new">Baru</option>
                                <option value="open_box">Open box</option>
                                <option value="used">Bekas</option>
                                <option value="refurbished">Refurbished</option>
                                <option value="ex_display">Ex-display</option>
                                <option value="unknown">Tidak diketahui</option>
                            </select>
                        </label>
                        <label>
                            <span>Tipe seller</span>
                            <select name="seller_type" data-filter>
                                <option value="">Semua seller</option>
                                <option value="official">Official</option>
                                <option value="store">Toko</option>
                                <option value="reseller">Reseller</option>
                                <option value="individual">Perorangan</option>
                                <option value="unknown">Tidak diketahui</option>
                            </select>
                        </label>
                        <label class="quick-filters__location">
                            <span>Lokasi</span>
                            <input name="location" type="text" maxlength="120" placeholder="Contoh: Surabaya" data-filter>
                        </label>
                            </div>
                        </details>
                        <div class="rail-status" data-mono>
                            <span>SOURCE MODE</span><strong>HYBRID RETRIEVAL</strong>
                            <span>RANKING</span><strong>DETERMINISTIC</strong>
                        </div>
                    </aside>

                    <div class="console-panel">
                        <div class="transcript" data-transcript role="log" aria-live="polite" aria-relevant="additions">
                            <article class="message message--assistant">
                                <p class="message__label">NEOMATRA / SYSTEM READY</p>
                                <p class="message__body">Halo! Tanyakan apa saja atau sebutkan produk yang ingin Anda cari. Jika tersedia, saya akan memadukan sumber web, knowledge Neomatra, dan data marketplace untuk memberi jawaban yang jelas.</p>
                            </article>

                            <section class="suggestions" data-suggestions aria-labelledby="suggestions-title" hidden>
                                <p class="suggestions__label" id="suggestions-title">QUICK QUERY / Pertanyaan yang disarankan</p>
                                <div class="suggestions__list" data-suggestions-list></div>
                            </section>
                        </div>

                        <div class="composer-dock">
                            <label class="composer__label" for="chatbot-message">SEARCH / NATURAL LANGUAGE QUERY</label>
                            <div class="composer__controls">
                                <input id="chatbot-message" name="message" type="text" required maxlength="2000" autocomplete="off" placeholder="Contoh: RTX 3060 12 GB bekas Surabaya maksimal Rp2.800.000" data-input>
                                <button type="submit" data-submit>Kirim / Search →</button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <p class="chat-disclaimer">
                Data kondisi, seller, harga, dan margin bersifat informasional. Transaksi tetap dilakukan di platform sumber.
            </p>
        </section>
    </main>
</body>
</html>
