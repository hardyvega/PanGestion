@extends('layouts.authenticated', ['title' => 'Nuevo rol | Almapan'])

@section('page')
    <section class="admin-page" aria-labelledby="role-create-title"><header class="admin-page-header"><div><p class="eyebrow">Administración</p><h1 id="role-create-title">Nuevo rol</h1><p class="admin-intro">Define un contenedor de permisos existentes.</p></div><a class="secondary-button inline-button" href="{{ route('admin.roles.index') }}">Volver a roles</a></header><section class="admin-panel">@include('administration.roles._form', ['role' => null, 'action' => route('admin.roles.store'), 'cancel' => route('admin.roles.index')])</section></section>
@endsection
