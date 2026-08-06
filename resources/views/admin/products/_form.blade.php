@php
    $attributesValue = old('attributes', isset($product) && is_array($product->attributes) ? json_encode($product->attributes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
    $aliasesValue = old('aliases', isset($product) ? $product->aliases->pluck('alias')->implode(PHP_EOL) : '');
@endphp
<form class="admin-form" method="POST" action="{{ $action }}" data-admin-form>
    @csrf
    @isset($method) @method($method) @endisset
    @if($errors->any())<div class="alert alert--error" role="alert"><p>Periksa kembali data produk.</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

    <div class="admin-form__grid">
        <div class="form-field"><label for="product-category">Kategori</label><select id="product-category" name="category_id" required @error('category_id') aria-invalid="true" @enderror><option value="">Pilih kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id ?? '') === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (nonaktif)' }}</option>@endforeach</select>@error('category_id')<p class="form-error">{{ $message }}</p>@enderror</div>
        <div class="form-field"><label for="product-name">Nama standar produk</label><input id="product-name" name="name" value="{{ old('name', $product->name ?? '') }}" required maxlength="255" @error('name') aria-invalid="true" @enderror>@error('name')<p class="form-error">{{ $message }}</p>@enderror</div>
        <div class="form-field"><label for="product-brand">Merek</label><input id="product-brand" name="brand" value="{{ old('brand', $product->brand ?? '') }}" maxlength="255"></div>
        <div class="form-field"><label for="product-model">Model</label><input id="product-model" name="model" value="{{ old('model', $product->model ?? '') }}" maxlength="255"></div>
        <div class="form-field"><label for="product-variant">Varian</label><input id="product-variant" name="variant" value="{{ old('variant', $product->variant ?? '') }}" maxlength="255" placeholder="Contoh: 12 GB Desktop"></div>
        <div class="form-field"><label for="product-sku">SKU / Part Number</label><input id="product-sku" name="sku_or_part_number" value="{{ old('sku_or_part_number', $product->sku_or_part_number ?? '') }}" maxlength="255"></div>
        <div class="form-field form-field--full"><label for="product-aliases">Alias produk</label><textarea id="product-aliases" name="aliases" maxlength="10000" placeholder="Satu alias per baris">{{ $aliasesValue }}</textarea><p class="form-help">Gunakan satu alias per baris atau pisahkan dengan koma. Duplikat dinormalisasi otomatis.</p>@error('aliases')<p class="form-error">{{ $message }}</p>@enderror</div>
        <div class="form-field form-field--full"><label for="product-attributes">Atribut terstruktur (JSON)</label><textarea id="product-attributes" name="attributes" maxlength="20000" spellcheck="false" placeholder='{"capacity_gb": 12, "form_factor": "desktop"}'>{{ $attributesValue }}</textarea><p class="form-help">Gunakan object JSON untuk atribut kategori yang penting dalam pemeriksaan varian.</p>@error('attributes')<p class="form-error">{{ $message }}</p>@enderror</div>
        <fieldset class="form-options form-field--full"><legend>Status</legend><input type="hidden" name="is_active" value="0"><label class="check-field"><input name="is_active" type="checkbox" value="1" @checked(old('is_active', $product->is_active ?? true))><span><strong>Produk aktif</strong><small>Dapat dipakai pada pencarian dan pencocokan listing.</small></span></label></fieldset>
    </div>
    <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.products.index') }}">Batal</a><button class="button button--primary" type="submit" data-busy-label="Menyimpan…">{{ $submitLabel }}</button></div>
</form>
