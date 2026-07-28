@if ($errors->any())
    <div class="alert alert--error" role="alert">
        <p>Periksa kembali data FAQ:</p>
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="admin-form__grid">
    <div class="form-field admin-form__full">
        <label for="title">Pertanyaan</label>
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
        <label for="content">Jawaban</label>
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
        <p class="form-field__hint">Pertanyaan dan jawaban digunakan bersama saat membuat embedding.</p>
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
        <legend>Status FAQ</legend>

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
                <small>FAQ dapat digunakan dalam retrieval.</small>
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
                <small>Prioritaskan sebagai suggested question.</small>
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
