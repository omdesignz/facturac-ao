@extends('errors.layout')

@section('status', 'Erro 402 · Subscrição')
@section('title', 'A subscrição está por regularizar')
@section('message', 'Assim que o pagamento for confirmado, o acesso é reposto automaticamente. Se já pagou, pode levar alguns minutos até o banco nos confirmar.')

@section('actions')
    <a class="button" href="/settings/billing">Ver plano e cobrança</a>
    <a class="button button--quiet" href="/dashboard">Voltar ao painel</a>
@endsection
