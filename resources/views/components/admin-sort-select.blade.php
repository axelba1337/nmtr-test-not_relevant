@props([
    'id',
    'sort' => \App\Support\AdminIndexSort::DEFAULT,
    'options' => \App\Support\AdminIndexSort::values(),
])

<div class="form-field">
    <label for="{{ $id }}">{{ __('ui.admin_pages.common.sort') }}</label>
    <select id="{{ $id }}" name="sort">
        @foreach ($options as $value)
            <option value="{{ $value }}" @selected($sort === $value)>
                {{ __('ui.admin_pages.common.sort_options.'.$value) }}
            </option>
        @endforeach
    </select>
</div>
