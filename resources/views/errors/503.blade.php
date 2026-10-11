@extends('errors.layout')

@section('status', 'Erro 503 · Manutenção')
@section('stamp', 'manutenc.ao')
@section('word', 'Manutenção')
@section('stampwidth', '6.7')
@section('title', 'Estamos em manutenção')
@section('message', 'A plataforma volta dentro de pouco tempo. Os documentos já emitidos estão seguros.')
@section('retry', '1')
@section('refresh', '1')
@section('support', '1')

@section('actions')
    <a class="pill pill--ink" href="">Tentar outra vez</a>
@endsection
