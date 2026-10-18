@extends('layouts.authenticated', ['title' => 'Nuevo proveedor | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="supplier-create-title">
        <header class="articles-page-header"><div><p class="eyebrow">Administración</p><h1 id="supplier-create-title">Nuevo proveedor</h1><p class="articles-intro">Registra los datos maestros del proveedor.</p></div><a class="secondary-button inline-button" href="{{ route('suppliers.index') }}">Volver a proveedores</a></header>
        @if ($errors->any()) <div class="form-alert" role="alert">Revisa los campos marcados.</div> @endif
        <section class="articles-panel">@include('suppliers._form', ['supplier' => null])</section>
    </section>
@endsection
