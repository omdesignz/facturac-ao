@extends('errors.layout')

@section('status', 'Erro 429 · Demasiados pedidos')
@section('title', 'Vamos com mais calma')
@section('message', 'Recebemos pedidos a mais deste dispositivo em pouco tempo. Aguarde um momento e tente de novo — é uma protecção contra abusos, não um problema com a sua conta.')

@section('actions')
    <a class="button" href="/dashboard">Voltar ao painel</a>
@endsection
