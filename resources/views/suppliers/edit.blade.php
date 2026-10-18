@extends('layouts.authenticated', ['title' => 'Editar proveedor | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="supplier-edit-title">
        <header class="articles-page-header"><div><p class="eyebrow">Administración</p><h1 id="supplier-edit-title">Editar proveedor</h1><p class="articles-intro">{{ $supplier->nombre }} · las relaciones y los costos históricos permanecen intactos.</p></div><a class="secondary-button inline-button" href="{{ route('suppliers.show', $supplier) }}">Volver al detalle</a></header>
        @if ($errors->any()) <div class="form-alert" role="alert">Revisa los campos marcados.</div> @endif
        <section class="articles-panel">@include('suppliers._form', ['supplier' => $supplier])</section>
    </section>
@endsection
