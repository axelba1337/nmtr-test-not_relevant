<form class="admin-form" method="POST" action="{{ $action }}" data-admin-form>
    @csrf
    @isset($method) @method($method) @endisset

    @if ($errors->any())
        <div class="alert alert--error" role="alert">
            <p>Periksa kembali data kategori.</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="admin-form__grid">
        <div class="form-field">
            <label for="category-name">Nama kategori</label>
            <input id="category-name" name="name" value="{{ old('name', $category->name ?? '') }}" required maxlength="255" @error('name') aria-invalid="true" @enderror>
            @error('name')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field">
            <label for="category-slug">Slug</label>
            <input id="category-slug" name="slug" value="{{ old('slug', $category->slug ?? '') }}" maxlength="255" placeholder="Dibuat otomatis jika kosong" @error('slug') aria-invalid="true" @enderror>
            @error('slug')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field">
            <label for="category-parent">Kategori induk</label>
            <select id="category-parent" name="parent_id" @error('parent_id') aria-invalid="true" @enderror>
                <option value="">Tanpa induk</option>
                @foreach ($parents as $parent)
                    <option value="{{ $parent->id }}" @selected((string) old('parent_id', $category->parent_id ?? '') === (string) $parent->id)>{{ $parent->name }}</option>
                @endforeach
            </select>
            @error('parent_id')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field">
            <label for="category-order">Urutan</label>
            <input id="category-order" name="sort_order" type="number" min="0" value="{{ old('sort_order', $category->sort_order ?? 0) }}" required>
            @error('sort_order')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <div class="form-field form-field--full">
            <label for="category-description">Deskripsi</label>
            <textarea id="category-description" name="description" maxlength="10000">{{ old('description', $category->description ?? '') }}</textarea>
            @error('description')<p class="form-error">{{ $message }}</p>@enderror
        </div>
        <fieldset class="form-options form-field--full">
            <legend>Status</legend>
            <input type="hidden" name="is_active" value="0">
            <label class="check-field">
                <input name="is_active" type="checkbox" value="1" @checked(old('is_active', $category->is_active ?? true))>
                <span><strong>Kategori aktif</strong><small>Dapat dipilih untuk produk dan knowledge baru.</small></span>
            </label>
        </fieldset>
    </div>

    <div class="form-actions">
        <a class="button button--secondary" href="{{ route('admin.categories.index') }}">Batal</a>
        <button class="button button--primary" type="submit" data-busy-label="Menyimpan…">{{ $submitLabel }}</button>
    </div>
</form>
