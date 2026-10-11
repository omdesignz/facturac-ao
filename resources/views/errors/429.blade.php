@extends('errors.layout')

@section('status', 'Erro 429 · Demasiados pedidos')
@section('stamp', 'moderac.ao')
@section('word', 'Moderação')
@section('stampwidth', '6.2')
@section('title', 'Vamos com mais calma')
@section('message', 'Recebemos demasiados pedidos deste dispositivo num curto espaço de tempo. É uma protecção contra abusos, não um problema com a sua conta.')
@section('retry', '1')

@section('actions')
    <a class="pill pill--ink" href="">Tentar outra vez</a>
    <a class="pill pill--outline" href="/">Ir para o início</a>
@endsection
