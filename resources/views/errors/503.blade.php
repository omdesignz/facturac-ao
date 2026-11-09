@extends('errors.layout')

@section('status', ($assistantUnavailable ?? false) ? 'Erro 503 · Assistente' : 'Erro 503 · Manutenção')
@section('title', ($assistantUnavailable ?? false) ? 'O assistente está indisponível' : 'Estamos em manutenção')
@section('message', ($assistantUnavailable ?? false) ? 'Não foi possível abrir o assistente. Pode continuar a trabalhar nas restantes áreas da aplicação.' : 'A plataforma volta dentro de pouco tempo. Os documentos já emitidos estão seguros.')
@section('retry', '1')
@section('refresh', '1')
@section('support', '1')

@section('actions')
    <a class="pill pill--ink" href="">Tentar outra vez</a>
    @if ($assistantUnavailable ?? false)
        <a class="pill" href="/dashboard">Voltar a Hoje</a>
    @endif
@endsection
