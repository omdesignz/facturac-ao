@extends('errors.layout')

@section('status', 'Erro 403 · Sem permissão')
@section('stamp', 'autorizac.ao')
@section('word', 'Autorização')
@section('stampwidth', '6.1')
@section('title', 'Esta área não é para o seu acesso')
@section('message', 'A sua conta não tem permissão para ver ou alterar isto. Quem pode conceder o acesso é um proprietário ou administrador da empresa: peça-lhe que reveja o seu papel.')
@section('support', '1')

@section('actions')
    <a class="pill pill--ink" href="/">Ir para o início</a>
    <a class="pill pill--outline" href="/" data-back>Voltar atrás</a>
@endsection
