@props(['field', 'label'])

<details @if($errors->has($field) || $errors->has($field.'_occurrence') || old($field.'_occurrence')) open @endif>
    <summary>Si la hora se repite por el cambio de horario</summary>
    <label class="field-group">
        <span>{{ $label }}</span>
        <select class="form-input" name="{{ $field }}_occurrence">
            <option value="">Seleccionar solo si la hora se repite</option>
            <option value="primera" @selected(old($field.'_occurrence') === 'primera')>Primera vez que ocurre</option>
            <option value="segunda" @selected(old($field.'_occurrence') === 'segunda')>Segunda vez que ocurre</option>
        </select>
    </label>
</details>
