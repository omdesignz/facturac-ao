@extends('errors.layout')

@section('status', 'Erro 500 · Falha interna')
@section('stamp', 'interrupc.ao')
@section('word', 'Interrupção')
@section('stampwidth', '5.9')
@section('title', 'Algo correu mal do nosso lado')
@section('message', 'Não foi nada que tenha feito. A falha ficou registada. Os documentos já emitidos e o que tinha guardado antes mantêm-se, mas a última acção pode não ter sido concluída: confirme antes de a repetir. Se o problema continuar, indique ao apoio a referência abaixo.')
@section('reference', '1')
@section('support', '1')

@section('actions')
    <a class="pill pill--ink" href="">Tentar outra vez</a>
    <a class="pill pill--outline" href="/">Ir para o início</a>
@endsection
