@if ($errors->any())
    <div class="alert alert--error" role="alert">
        <p>Periksa kembali data knowledge:</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="admin-form__grid">
    <div class="form-field admin-form__full">
        <label for="title">Judul</label>
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
        <label for="content">Isi knowledge</label>
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
        <p class="form-field__hint">Judul, kategori, kata kunci, isi, dan nama sumber digunakan saat membuat embedding.</p>
    </div>

    <div class="form-field">
        <label for="category_id">Kategori</label>
        <select id="category_id" name="category_id">
            <option value="">Tanpa kategori</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((string) old('category_id', $item?->category_id) === (string) $category->id)>
                    {{ $category->name }}{{ $category->is_active ? '' : ' (nonaktif)' }}
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
        <label for="keywords">Kata kunci / sinonim</label>
        <textarea id="keywords" name="keywords" rows="4" maxlength="4000" placeholder="Pisahkan dengan koma atau baris baru">{{ $keywordValue }}</textarea>
        @error('keywords')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="source_name">Nama sumber</label>
        <input id="source_name" name="source_name" type="text" maxlength="255" value="{{ old('source_name', $item?->source_name) }}">
        @error('source_name')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="source_url">URL sumber</label>
        <input id="source_url" name="source_url" type="url" maxlength="2048" value="{{ old('source_url', $item?->source_url) }}" placeholder="https://...">
        @error('source_url')
            <p class="form-field__error">{{ $message }}</p>
        @enderror
    </div>

    <div class="form-field">
        <label for="sort_order">Urutan</label>
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
        <p class="form-field__hint">Angka lebih kecil ditampilkan lebih dahulu.</p>
    </div>

    <fieldset class="form-options">
        <legend>Status knowledge</legend>

        <label class="check-field">
            <input type="hidden" name="is_active" value="0">
            <input
                name="is_active"
                type="checkbox"
                value="1"
                @checked((bool) old('is_active', $item?->is_active ?? true))
            >
            <span>
                <strong>Aktif</strong>
                <small>Knowledge dapat digunakan dalam retrieval.</small>
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
                <strong>Featured</strong>
                <small>Prioritaskan sebagai contoh pertanyaan.</small>
            </span>
        </label>
    </fieldset>
</div>

<div class="form-actions">
    <a class="button button--secondary" href="{{ route('admin.knowledge.index') }}">
        Batal
    </a>
    <button
        class="button button--primary"
        type="submit"
        data-disable-on-submit
    >
        {{ $submitLabel }}
    </button>
</div>
