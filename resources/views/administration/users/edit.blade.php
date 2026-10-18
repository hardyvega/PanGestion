@extends('layouts.authenticated', ['title' => 'Editar usuario | Almapan'])

@section('page')
    <section class="admin-page" aria-labelledby="user-edit-title">
        <header class="admin-page-header"><div><p class="eyebrow">Administración</p><h1 id="user-edit-title">Editar usuario</h1><p class="admin-intro">Actualiza los datos permitidos y la seguridad de la cuenta.</p></div><a class="secondary-button inline-button" href="{{ route('admin.users.show', $managedUser) }}">Volver al detalle</a></header>
        <section class="admin-panel">@include('administration.users._form', ['managedUser' => $managedUser, 'roles' => $roles, 'action' => route('admin.users.update', $managedUser), 'cancel' => route('admin.users.show', $managedUser)])</section>
    </section>
@endsection
