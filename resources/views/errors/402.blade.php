@extends('errors.layout')

@section('status', 'Erro 402 · Subscrição')
@section('stamp', 'subscric.ao')
@section('word', 'Subscrição')
@section('stampwidth', '5.4')
@section('title', 'A subscrição precisa de atenção')
@section('message', 'Para usar esta área, a subscrição da empresa tem de estar regularizada. Assim que o pagamento for confirmado, o acesso é reposto automaticamente; se já pagou, a confirmação do banco pode demorar alguns minutos.')

@section('actions')
    <a class="pill pill--ink" href="/settings/billing">Ver subscrição</a>
    <a class="pill pill--outline" href="/">Ir para o início</a>
@endsection
