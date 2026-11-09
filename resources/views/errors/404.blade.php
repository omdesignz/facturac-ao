@extends('errors.layout')

@section('status', 'Erro 404 · Não encontrado')
@section('title', 'Não encontrámos esta página')
@section('message', 'O endereço pode ter mudado ou estar incompleto. Confirme o link ou volte ao início.')

@section('actions')
    <a class="pill pill--ink" href="/">Ir para o início</a>
    <a class="pill pill--outline" href="/" data-back>Voltar atrás</a>
@endsection
