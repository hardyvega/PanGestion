@extends('layouts.authenticated', ['title' => 'Nuevo artículo | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="article-create-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="article-create-title">Nuevo artículo</h1><p class="articles-intro">Registra los datos maestros sin cargar stock directamente.</p></div>
            <a class="secondary-button inline-button" href="{{ route('articles.index') }}">Volver a artículos</a>
        </header>
        @if ($errors->any()) <div class="form-alert" role="alert">Revisa los campos marcados.</div> @endif
        <section class="articles-panel">
            <form class="article-form" method="POST" action="{{ route('articles.store') }}" enctype="multipart/form-data">
                @csrf
                @include('articles.form', ['article' => null])
                <div class="article-form-actions"><button class="primary-button" type="submit">Crear artículo</button></div>
            </form>
        </section>
    </section>
@endsection
