@extends('layouts.authenticated', ['title' => 'Nuevo usuario | Almapan'])

@section('page')
    <section class="admin-page" aria-labelledby="user-create-title">
        <header class="admin-page-header"><div><p class="eyebrow">Administración</p><h1 id="user-create-title">Nuevo usuario</h1><p class="admin-intro">Crea una cuenta con un rol operativo.</p></div><a class="secondary-button inline-button" href="{{ route('admin.users.index') }}">Volver a usuarios</a></header>
        <section class="admin-panel">@include('administration.users._form', ['managedUser' => null, 'roles' => $roles, 'action' => route('admin.users.store'), 'cancel' => route('admin.users.index')])</section>
    </section>
@endsection
