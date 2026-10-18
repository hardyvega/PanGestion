@extends('layouts.authenticated', ['title' => 'Editar rol | Almapan'])

@section('page')
    <section class="admin-page" aria-labelledby="role-edit-title"><header class="admin-page-header"><div><p class="eyebrow">Administración</p><h1 id="role-edit-title">Editar rol</h1><p class="admin-intro">Actualiza los datos del rol sin alterar su historial de permisos.</p></div><a class="secondary-button inline-button" href="{{ route('admin.roles.show', $role) }}">Volver al detalle</a></header><section class="admin-panel">@include('administration.roles._form', ['role' => $role, 'action' => route('admin.roles.update', $role), 'cancel' => route('admin.roles.show', $role)])</section></section>
@endsection
