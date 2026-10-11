@extends('errors.layout')

@section('status', 'Erro 404 · Não encontrado')
@section('stamp', 'direcc.ao')
@section('word', 'Direcção')
@section('stampwidth', '4.7')
@section('title', 'Não encontrámos esta página')
@section('message', 'O endereço pode estar mal escrito, ou o documento que procura pode ter sido removido ou pertencer a outra empresa. Confirme o endereço ou volte ao início.')

@section('actions')
    <a class="pill pill--ink" href="/">Ir para o início</a>
    <a class="pill pill--outline" href="/" data-back>Voltar atrás</a>
@endsection
