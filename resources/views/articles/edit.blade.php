@extends('layouts.authenticated', ['title' => 'Editar artículo | Almapan'])

@section('page')
    <section class="articles-page" aria-labelledby="article-edit-title">
        <header class="articles-page-header">
            <div><p class="eyebrow">Administración</p><h1 id="article-edit-title">Editar artículo</h1><p class="articles-intro">{{ $article->nombre }} · el stock actual permanece protegido.</p></div>
            <a class="secondary-button inline-button" href="{{ route('articles.show', $article) }}">Volver al detalle</a>
        </header>
        @if ($errors->any()) <div class="form-alert" role="alert">Revisa los campos marcados.</div> @endif
        <section class="articles-panel">
            <form class="article-form" method="POST" action="{{ route('articles.update', $article) }}" enctype="multipart/form-data">
                @csrf
                @method('PUT')
                @include('articles.form', ['article' => $article])
                <div class="article-form-actions"><button class="primary-button" type="submit">Guardar cambios</button></div>
            </form>
        </section>
    </section>
@endsection
