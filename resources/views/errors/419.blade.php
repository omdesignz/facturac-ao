@extends('errors.layout')

@section('status', 'Erro 419 · Página expirada')
@section('stamp', 'expirac.ao')
@section('word', 'Expiração')
@section('stampwidth', '5.2')
@section('title', 'Esta página esteve aberta demasiado tempo')
@section('message', 'Por segurança, a página deixa de aceitar acções passado algum tempo. O que já estava guardado mantém-se, mas a última acção não foi concluída. Recarregue a página e repita-a.')

{{-- href="" is the current address, fetched again with GET. location.reload()
     would send a POST a second time, with the same expired token. --}}
@section('actions')
    <a class="pill pill--ink" href="">Recarregar a página</a>
    <a class="pill pill--outline" href="/">Ir para o início</a>
@endsection
