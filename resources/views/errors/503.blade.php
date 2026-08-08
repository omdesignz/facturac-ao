@extends('errors.layout')

@section('status', 'Erro 503 · Manutenção')
@section('title', 'Estamos a fazer uma actualização')
@section('message', 'A plataforma volta dentro de poucos minutos. Os seus dados e as facturas já emitidas não são afectados.')

@section('actions')
    <a class="button button--quiet" href="/">Tentar de novo</a>
@endsection
