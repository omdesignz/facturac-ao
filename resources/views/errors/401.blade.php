@extends('errors.layout')

@section('status', 'Erro 401 · Sem sessão')
@section('stamp', 'autenticac.ao')
@section('word', 'Autenticação')
@section('stampwidth', '6.9')
@section('title', 'Precisa de iniciar sessão')
@section('message', 'Esta página só está disponível depois de entrar na sua conta. Se a sessão terminou por inactividade, basta entrar outra vez.')

@section('actions')
    <a class="pill pill--ink" href="/login">Entrar</a>
    <a class="pill pill--outline" href="/">Ir para o início</a>
@endsection
